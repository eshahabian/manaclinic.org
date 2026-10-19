"""Deploy OTP SMS fix, clear bad DB template, verify live send. No secrets printed."""
from pathlib import Path
from ftplib import FTP
import io
import json
import secrets
import time
import urllib.request

root = Path('D:/Psychology')
cfg = json.loads((root / '.vscode/sftp.json').read_text(encoding='utf-8-sig'))

# 1) Deploy updated sms_service.php
files = ['includes/sms_service.php']
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

# 2) Clear old multiline OTP template + probe sms_provider_send via rendered login_otp
token = secrets.token_hex(32)
name = 'otp-fix-' + secrets.token_hex(8) + '.php'
helper = r'''<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || time() > __EXPIRY__ || !hash_equals('__TOKEN__', $_SERVER['HTTP_X_SETUP_TOKEN'] ?? '')) {
    http_response_code(404);
    exit;
}
$config = require __DIR__ . '/config.php';
date_default_timezone_set($config['timezone'] ?? 'Asia/Tehran');
foreach (['db', 'helpers', 'mail', 'outreach'] as $f) {
    require_once __DIR__ . '/includes/' . $f . '.php';
}
$pdo = db_connect($config);
ensure_mail_schema($pdo);
// Force catalog OTP text (old multiline DB templates are filtered by WebOne).
mail_setting_set($pdo, 'sms_template_login_otp', '');
$c = sms_connection_config($pdo);
$code = (string) random_int(100000, 999999);
$body = sms_template_render($pdo, 'login_otp', ['code' => $code]);
$phone = (string) ($pdo->query("SELECT phone FROM users WHERE phone LIKE '09%' ORDER BY updated_at DESC LIMIT 1")->fetchColumn() ?: '');
if ($phone === '') {
    echo json_encode(['error' => 'no phone'], JSON_UNESCAPED_UNICODE);
    exit;
}
$send = sms_provider_send($c, $phone, $body);
mail_setting_set($pdo, 'sms_otp_last_status', (string) $send['status']);
mail_setting_set($pdo, 'sms_otp_last_at', date('Y-m-d H:i:s'));
mail_setting_set($pdo, 'sms_otp_last_error', (string) ($send['error'] ?? ''));
$src = file_get_contents(__DIR__ . '/includes/sms_service.php');
echo json_encode([
    'ready' => sms_connection_ready($pdo),
    'sender' => $c['sender'],
    'key_prefix' => substr($c['api_key'], 0, 8),
    'body' => $body,
    'body_has_newline' => str_contains($body, "\n"),
    'phone_tail' => substr($phone, -4),
    'send' => $send,
    'code_has_haft_payload' => str_contains($src, "'From' => \$sender, 'ToNumber' => \$phone, 'Content' => \$body]")
        || str_contains($src, "['From' => \$sender, 'ToNumber' => \$phone, 'Content' => \$body]"),
    'code_has_short_otp' => str_contains($src, 'کد ورود شما {code}'),
], JSON_UNESCAPED_UNICODE);
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
        req = urllib.request.Request(
            'https://manaclinic.org/' + name,
            data=b'',
            headers={'X-Setup-Token': token, 'User-Agent': 'ManaOtpFix/1.0'},
            method='POST',
        )
        with urllib.request.urlopen(req, timeout=120) as response:
            body = response.read().decode('utf-8', 'replace')
            (root / 'output/access-update/deploy-otp-fix.json').write_text(body, encoding='utf-8')
            print(json.dumps(json.loads(body), ensure_ascii=True), flush=True)
    finally:
        ftp.storbinary('STOR .htaccess', io.BytesIO(original))
        try:
            ftp.delete(name)
        except Exception:
            pass
        print('cleanup ok', flush=True)
