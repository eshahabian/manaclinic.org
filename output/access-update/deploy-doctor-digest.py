"""Deploy doctor daily digest SMS (07:00). Patient templates untouched."""
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
    'pages/admin/sms_settings.php': root / 'cpanel/pages/admin/sms_settings.php',
    'pages/admin/sms_notify.php': root / 'cpanel/pages/admin/sms_notify.php',
}
sms = files['includes/sms_service.php'].read_text(encoding='utf-8')
if 'sms_queue_doctor_daily_digests' not in sms:
    raise RuntimeError('digest function missing')
if 'مانا کلینیک: نوبت شما در {datetime} ثبت شد.' not in sms:
    raise RuntimeError('patient template changed')
if 'format_fa_day_long' not in files['includes/helpers.php'].read_text(encoding='utf-8'):
    raise RuntimeError('day long helper missing')

with FTP() as ftp:
    ftp.connect(cfg['host'], int(cfg.get('port', 21)), timeout=60)
    ftp.login(cfg['username'], cfg['password'])
    ftp.cwd('/public_html')
    for remote, local in files.items():
        data = local.read_bytes()
        try:
            ftp.delete(remote)
        except Exception:
            pass
        ftp.storbinary('STOR ' + remote, io.BytesIO(data))
        v = io.BytesIO()
        ftp.retrbinary('RETR ' + remote, v.write)
        if v.getvalue() != data:
            raise RuntimeError('verify failed ' + remote)
        print('ok', remote, flush=True)

token = secrets.token_hex(32)
name = 'dig-' + secrets.token_hex(8) + '.php'
helper = r'''<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || time() > __EXPIRY__ || !hash_equals('__TOKEN__', $_SERVER['HTTP_X_SETUP_TOKEN'] ?? '')) {
    http_response_code(404); exit;
}
foreach (['sms_service.php','helpers.php'] as $f) {
    if (function_exists('opcache_invalidate')) @opcache_invalidate(__DIR__.'/includes/'.$f, true);
}
$config = require __DIR__.'/config.php';
date_default_timezone_set($config['timezone'] ?? 'Asia/Tehran');
foreach (['db','helpers','mail','outreach'] as $f) require_once __DIR__.'/includes/'.$f.'.php';
$sample = sms_doctor_daily_digest_body('عطیه کارسچی', format_fa_day_long(date('Y-m-d').' 12:00:00'), ['10:00','11:00','13:00','14:00']);
echo json_encode([
    'hour' => (int)date('G'),
    'tz' => date_default_timezone_get(),
    'sample' => $sample,
    'patient_booked' => sms_template_catalog()['appointment_booked'][1],
    'has_queue_fn' => function_exists('sms_queue_doctor_daily_digests'),
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
    try:
        ftp.storbinary('STOR ' + name, io.BytesIO(helper.encode('utf-8')))
        ftp.storbinary('STOR .htaccess', io.BytesIO(modified))
        time.sleep(2)
        req = urllib.request.Request(
            'https://manaclinic.org/' + name, data=b'1',
            headers={'X-Setup-Token': token, 'User-Agent': 'ManaDigest/1.0', 'Content-Type': 'application/x-www-form-urlencoded'},
            method='POST',
        )
        with urllib.request.urlopen(req, timeout=90) as response:
            body = response.read().decode('utf-8', 'replace')
        (root / 'output/access-update/deploy-doctor-digest.json').write_text(body, encoding='utf-8')
        print(json.dumps(json.loads(body), ensure_ascii=True), flush=True)
    finally:
        ftp.storbinary('STOR .htaccess', io.BytesIO(original_ht))
        try:
            ftp.delete(name)
        except Exception:
            pass
        print('cleanup ok', flush=True)
