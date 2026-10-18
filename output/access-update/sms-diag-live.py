from pathlib import Path
from ftplib import FTP
import io
import json
import secrets
import time
import urllib.request

root = Path('D:/Psychology')
cfg = json.loads((root / '.vscode/sftp.json').read_text(encoding='utf-8-sig'))
token = secrets.token_hex(32)
name = 'sms-diag-' + secrets.token_hex(12) + '.php'
helper = r'''<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || time() > __EXPIRY__ || !hash_equals('__TOKEN__', $_SERVER['HTTP_X_SETUP_TOKEN'] ?? '')) {
    http_response_code(404);
    exit;
}
try {
    $config = require __DIR__ . '/config.php';
    date_default_timezone_set($config['timezone'] ?? 'Asia/Tehran');
    foreach (['db', 'helpers', 'mail', 'outreach', 'workshop_sessions'] as $f) {
        require_once __DIR__ . '/includes/' . $f . '.php';
    }
    $pdo = db_connect($config);
    ensure_mail_schema($pdo);
    ensure_outreach_schema($pdo);
    $c = sms_connection_config($pdo);
    $out = [
        'ready' => sms_connection_ready($pdo),
        'enabled' => $c['enabled'],
        'sender' => $c['sender'],
        'has_api_key' => $c['api_key'] !== '',
        'has_user' => $c['username'] !== '',
        'has_pass' => $c['password'] !== '',
        'api_key_len' => strlen($c['api_key']),
        'worker_last' => mail_setting_get($pdo, 'sms_worker_last_run', ''),
        'cron_flag' => mail_setting_get($pdo, 'sms_cron_installed', '0'),
        'pending' => (int) $pdo->query("SELECT COUNT(*) FROM sms_outbox WHERE status='PENDING'")->fetchColumn(),
        'recent' => $pdo->query("SELECT phone,kind,status,due_at,sent_at,last_error,LEFT(body,80) AS body FROM sms_outbox ORDER BY created_at DESC LIMIT 8")->fetchAll(PDO::FETCH_ASSOC),
    ];
    $curl = curl_init('https://api.payamakapi.ir/api/v1/SMS/GetCredit');
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => ['Accept: application/json', 'X-API-KEY: ' . $c['api_key']],
    ]);
    $raw = curl_exec($curl);
    $http = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    $out['credit_http'] = $http;
    $out['credit_raw'] = is_string($raw) ? mb_substr($raw, 0, 500) : null;
    sms_run_worker($pdo);
    mail_setting_set($pdo, 'sms_worker_last_run', date('Y-m-d H:i:s'));
    $out['worker_ran'] = true;
    $out['pending_after'] = (int) $pdo->query("SELECT COUNT(*) FROM sms_outbox WHERE status='PENDING'")->fetchColumn();
    $out['recent_after'] = $pdo->query("SELECT phone,kind,status,due_at,sent_at,last_error,LEFT(body,80) AS body FROM sms_outbox ORDER BY created_at DESC LIMIT 8")->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($out, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
'''
helper = helper.replace('__TOKEN__', token).replace('__EXPIRY__', str(int(time.time()) + 600))

with FTP() as ftp:
    ftp.connect(cfg['host'], int(cfg.get('port', 21)), timeout=45)
    ftp.login(cfg['username'], cfg['password'])
    ftp.cwd('/public_html')
    original = io.BytesIO()
    ftp.retrbinary('RETR .htaccess', original.write)
    original = original.getvalue()
    modified = original.replace(
        b'RewriteEngine On',
        b'RewriteEngine On\nRewriteRule ^' + name.replace('.', r'\.').encode() + b'$ ' + name.encode() + b' [END]',
        1,
    )
    if modified == original:
        raise RuntimeError('htaccess insert failed')
    try:
        ftp.storbinary('STOR ' + name, io.BytesIO(helper.encode()))
        ftp.storbinary('STOR .htaccess', io.BytesIO(modified))
        req = urllib.request.Request(
            'https://manaclinic.org/' + name,
            data=b'',
            headers={'X-Setup-Token': token},
            method='POST',
        )
        with urllib.request.urlopen(req, timeout=120) as response:
            body = response.read().decode('utf-8', 'replace')
            out_path = root / 'output/access-update/sms-diag-live.json'
            out_path.write_text(body, encoding='utf-8')
            print('wrote', out_path, 'bytes', len(body.encode('utf-8')), flush=True)
            parsed = json.loads(body)
            summary = {
                'ready': parsed.get('ready'),
                'enabled': parsed.get('enabled'),
                'sender': parsed.get('sender'),
                'has_api_key': parsed.get('has_api_key'),
                'api_key_len': parsed.get('api_key_len'),
                'worker_last': parsed.get('worker_last'),
                'cron_flag': parsed.get('cron_flag'),
                'pending': parsed.get('pending'),
                'pending_after': parsed.get('pending_after'),
                'credit_http': parsed.get('credit_http'),
                'credit_raw': parsed.get('credit_raw'),
                'recent_status': [
                    {
                        'status': row.get('status'),
                        'kind': row.get('kind'),
                        'error': row.get('last_error'),
                    }
                    for row in (parsed.get('recent_after') or parsed.get('recent') or [])
                ],
            }
            print(json.dumps(summary, ensure_ascii=True), flush=True)
    finally:
        ftp.storbinary('STOR .htaccess', io.BytesIO(original))
        ftp.delete(name)
        print('cleanup ok', flush=True)
