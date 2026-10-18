"""Deploy SMS text/config, force DB api_key to match config.sms.php, probe Send."""
from pathlib import Path
from ftplib import FTP
import io
import json
import re
import secrets
import time
import urllib.request

root = Path('D:/Psychology')
cfg = json.loads((root / '.vscode/sftp.json').read_text(encoding='utf-8-sig'))
sms_cfg = (root / 'cpanel/config.sms.php').read_text(encoding='utf-8')
api_key = re.search(r"'api_key'\s*=>\s*'([^']+)'", sms_cfg).group(1)
sender = re.search(r"'sender'\s*=>\s*'([^']+)'", sms_cfg).group(1)

files = [
    'includes/sms_service.php',
    'config.sms.php',
    'pages/admin/sms_settings.php',
]
backup = root / 'output/access-update' / ('sms-before-' + time.strftime('%Y%m%d-%H%M%S'))
backup.mkdir(parents=True, exist_ok=True)

with FTP() as ftp:
    ftp.connect(cfg['host'], int(cfg.get('port', 21)), timeout=45)
    ftp.login(cfg['username'], cfg['password'])
    ftp.cwd('/public_html')
    for name in files:
        old = io.BytesIO()
        try:
            ftp.retrbinary('RETR ' + name, old.write)
            dest = backup / name
            dest.parent.mkdir(parents=True, exist_ok=True)
            dest.write_bytes(old.getvalue())
        except Exception:
            pass
        payload = (root / 'cpanel' / name).read_bytes()
        ftp.storbinary('STOR ' + name, io.BytesIO(payload))
        verify = io.BytesIO()
        ftp.retrbinary('RETR ' + name, verify.write)
        if verify.getvalue() != payload:
            raise RuntimeError('verify failed: ' + name)
        print('Verified', name, flush=True)

token = secrets.token_hex(32)
name = 'sms-newkey-' + secrets.token_hex(12) + '.php'
# Embed key only in ephemeral helper; response never echoes full key.
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
    mail_setting_set($pdo, 'sms_sender', '__SENDER__');
    mail_setting_set($pdo, 'sms_api_key', '__API_KEY__');
    mail_setting_set($pdo, 'sms_enabled', '1');
    $c = sms_connection_config($pdo);
    $phone = (string) $pdo->query("SELECT phone FROM sms_outbox WHERE phone LIKE '09%' ORDER BY created_at DESC LIMIT 1")->fetchColumn();
    if ($phone === '') {
        $phone = '09121234567';
    }
    $creditCh = curl_init('https://api.payamakapi.ir/api/v1/SMS/GetCredit');
    curl_setopt_array($creditCh, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => ['Accept: application/json', 'X-API-KEY: ' . $c['api_key']],
    ]);
    $creditRaw = curl_exec($creditCh);
    $creditHttp = (int) curl_getinfo($creditCh, CURLINFO_HTTP_CODE);
    curl_close($creditCh);
    $probeBody = 'مانا کلینیک: تست ' . date('H:i:s');
    $send = sms_provider_send($c, $phone, $probeBody);
    echo json_encode([
        'ready' => sms_connection_ready($pdo),
        'sender' => $c['sender'],
        'api_key_len' => strlen($c['api_key']),
        'api_key_prefix' => substr($c['api_key'], 0, 8),
        'credit_http' => $creditHttp,
        'credit_raw' => is_string($creditRaw) ? mb_substr(trim($creditRaw), 0, 80) : null,
        'phone_tail' => substr($phone, -4),
        'send' => $send,
        'server_ip' => $_SERVER['SERVER_ADDR'] ?? null,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
'''
helper = (
    helper.replace('__TOKEN__', token)
    .replace('__EXPIRY__', str(int(time.time()) + 600))
    .replace('__SENDER__', sender)
    .replace('__API_KEY__', api_key)
)

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
            (root / 'output/access-update/sms-newkey-result.json').write_text(body, encoding='utf-8')
            print(json.dumps(json.loads(body), ensure_ascii=True), flush=True)
    finally:
        ftp.storbinary('STOR .htaccess', io.BytesIO(original))
        ftp.delete(name)
        print('cleanup ok', flush=True)
