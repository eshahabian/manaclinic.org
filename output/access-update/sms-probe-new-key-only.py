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
name = 'sms-pk-' + secrets.token_hex(8) + '.php'
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
    foreach (['db', 'helpers', 'mail', 'outreach'] as $f) {
        require_once __DIR__ . '/includes/' . $f . '.php';
    }
    $pdo = db_connect($config);
    ensure_mail_schema($pdo);
    $private = require __DIR__ . '/config.sms.php';
    mail_setting_set($pdo, 'sms_sender', (string) ($private['sender'] ?? ''));
    mail_setting_set($pdo, 'sms_api_key', (string) ($private['api_key'] ?? ''));
    mail_setting_set($pdo, 'sms_enabled', '1');
    $c = sms_connection_config($pdo);
    $phoneRow = $pdo->query("SELECT phone FROM sms_outbox WHERE phone LIKE '09%' ORDER BY created_at DESC LIMIT 1")->fetchColumn();
    $phone = $phoneRow ? (string) $phoneRow : '09121234567';
    $ch = curl_init('https://api.payamakapi.ir/api/v1/SMS/GetCredit');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => ['Accept: application/json', 'X-API-KEY: ' . $c['api_key']],
    ]);
    $creditRaw = curl_exec($ch);
    $creditHttp = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $send = sms_provider_send($c, $phone, 'مانا کلینیک تست ' . date('H:i:s'));
    echo json_encode([
        'ready' => sms_connection_ready($pdo),
        'sender' => $c['sender'],
        'key_prefix' => substr($c['api_key'], 0, 8),
        'key_len' => strlen($c['api_key']),
        'credit_http' => $creditHttp,
        'credit' => is_string($creditRaw) ? mb_substr(trim($creditRaw), 0, 60) : null,
        'phone_tail' => substr($phone, -4),
        'send' => $send,
        'ip' => $_SERVER['SERVER_ADDR'] ?? null,
    ], JSON_UNESCAPED_UNICODE);
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
    rule = b'RewriteRule ^' + name.replace('.', r'\.').encode() + b'$ ' + name.encode() + b' [END]'
    modified = original.replace(b'RewriteEngine On', b'RewriteEngine On\n' + rule, 1)
    if modified == original:
        raise RuntimeError('htaccess insert failed')
    try:
        ftp.storbinary('STOR ' + name, io.BytesIO(helper.encode()))
        ftp.storbinary('STOR .htaccess', io.BytesIO(modified))
        time.sleep(1)
        req = urllib.request.Request(
            'https://manaclinic.org/' + name,
            data=b'',
            headers={'X-Setup-Token': token, 'User-Agent': 'ManaSmsProbe/1.0'},
            method='POST',
        )
        try:
            with urllib.request.urlopen(req, timeout=120) as response:
                body = response.read().decode('utf-8', 'replace')
        except urllib.error.HTTPError as e:
            body = e.read().decode('utf-8', 'replace')
            print('HTTP', e.code, flush=True)
            print(body[:500], flush=True)
            raise
        (root / 'output/access-update/sms-newkey-result.json').write_text(body, encoding='utf-8')
        print(json.dumps(json.loads(body), ensure_ascii=True), flush=True)
    finally:
        ftp.storbinary('STOR .htaccess', io.BytesIO(original))
        try:
            ftp.delete(name)
        except Exception:
            pass
        print('cleanup ok', flush=True)
