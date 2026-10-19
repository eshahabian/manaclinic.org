"""Restore SMS files from last full backup onto live site."""
from pathlib import Path
from ftplib import FTP
import io
import json
import secrets
import time
import urllib.request

root = Path('D:/Psychology')
cfg = json.loads((root / '.vscode/sftp.json').read_text(encoding='utf-8-sig'))

files = {
    'includes/sms_service.php': root / 'cpanel/includes/sms_service.php',
    'includes/helpers.php': root / 'cpanel/includes/helpers.php',
    'includes/outreach.php': root / 'cpanel/includes/outreach.php',
}

sms_text = files['includes/sms_service.php'].read_text(encoding='utf-8')
if 'نوبت شما با {doctor}' not in sms_text:
    raise RuntimeError('sms_service does not look like backup catalog')
if 'sms_notify_appointment_now' in sms_text:
    raise RuntimeError('sms_service still has post-backup notify helper')
if 'format_fa_sms_datetime' in files['includes/helpers.php'].read_text(encoding='utf-8'):
    raise RuntimeError('helpers still has format_fa_sms_datetime')

with FTP() as ftp:
    ftp.connect(cfg['host'], int(cfg.get('port', 21)), timeout=60)
    ftp.login(cfg['username'], cfg['password'])
    ftp.cwd('/public_html')
    for remote, local_path in files.items():
        data = local_path.read_bytes()
        try:
            ftp.delete(remote)
        except Exception:
            pass
        ftp.storbinary('STOR ' + remote, io.BytesIO(data))
        verify = io.BytesIO()
        ftp.retrbinary('RETR ' + remote, verify.write)
        if verify.getvalue() != data:
            raise RuntimeError('verify failed: ' + remote)
        print('ok', remote, flush=True)

# Clear DB template overrides + opcache + sample render
token = secrets.token_hex(32)
name = 'sms-bak-' + secrets.token_hex(8) + '.php'
helper = r'''<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || time() > __EXPIRY__ || !hash_equals('__TOKEN__', $_SERVER['HTTP_X_SETUP_TOKEN'] ?? '')) {
    http_response_code(404);
    exit;
}
foreach (['sms_service.php','helpers.php','outreach.php'] as $f) {
    $p = __DIR__ . '/includes/' . $f;
    if (function_exists('opcache_invalidate')) { @opcache_invalidate($p, true); }
}
if (function_exists('opcache_reset')) { @opcache_reset(); }
$config = require __DIR__ . '/config.php';
date_default_timezone_set($config['timezone'] ?? 'Asia/Tehran');
foreach (['db','helpers','mail','outreach'] as $f) require_once __DIR__.'/includes/'.$f.'.php';
$pdo = db_connect($config);
ensure_mail_schema($pdo);
foreach (['appointment_booked','appointment_cancelled','appointment_reminder','workshop_registered','workshop_reminder','workshop_announcement','login_otp'] as $k) {
    mail_setting_set($pdo, 'sms_template_'.$k, '');
}
$sample = sms_template_render($pdo, 'appointment_booked', [
    'doctor' => 'دکتر تست',
    'datetime' => format_fa_datetime(date('Y-m-d H:i:s', time()+86400)),
    'status' => 'ثبت شد.',
]);
$c = sms_connection_config($pdo);
echo json_encode([
    'sample' => $sample,
    'has_notify_now' => function_exists('sms_notify_appointment_now'),
    'has_sms_datetime' => function_exists('format_fa_sms_datetime'),
    'sender' => $c['sender'] ?? '',
    'enabled' => $c['enabled'] ?? '',
    'has_api_key' => trim((string)($c['api_key'] ?? '')) !== '',
], JSON_UNESCAPED_UNICODE);
'''
helper = helper.replace('__TOKEN__', token).replace('__EXPIRY__', str(int(time.time()) + 600))

with FTP() as ftp:
    ftp.connect(cfg['host'], int(cfg.get('port', 21)), timeout=45)
    ftp.login(cfg['username'], cfg['password'])
    ftp.cwd('/public_html')
    original = io.BytesIO()
    ftp.retrbinary('RETR .htaccess', original.write)
    original_ht = original.getvalue()
    rule = b'RewriteRule ^' + name.replace('.', r'\.').encode() + b'$ ' + name.encode() + b' [END]'
    modified = original_ht.replace(b'RewriteEngine On', b'RewriteEngine On\n' + rule, 1)
    if modified == original_ht:
        raise RuntimeError('htaccess insert failed')
    try:
        ftp.storbinary('STOR ' + name, io.BytesIO(helper.encode('utf-8')))
        ftp.storbinary('STOR .htaccess', io.BytesIO(modified))
        time.sleep(2)
        req = urllib.request.Request(
            'https://manaclinic.org/' + name,
            data=b'1',
            headers={'X-Setup-Token': token, 'User-Agent': 'ManaSmsBackup/1.0', 'Content-Type': 'application/x-www-form-urlencoded'},
            method='POST',
        )
        with urllib.request.urlopen(req, timeout=90) as response:
            body = response.read().decode('utf-8', 'replace')
        out = root / 'output/access-update/deploy-sms-from-backup.json'
        out.write_text(body, encoding='utf-8')
        print(json.dumps(json.loads(body), ensure_ascii=True), flush=True)
    finally:
        ftp.storbinary('STOR .htaccess', io.BytesIO(original_ht))
        try:
            ftp.delete(name)
        except Exception:
            pass
        print('cleanup ok', flush=True)
