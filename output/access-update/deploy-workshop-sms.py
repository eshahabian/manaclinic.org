"""Deploy workshop SMS features without changing appointment/OTP templates."""
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
    'includes/workshop_sms.php': root / 'cpanel/includes/workshop_sms.php',
    'includes/workshop_presenters.php': root / 'cpanel/includes/workshop_presenters.php',
    'includes/workshop_media.php': root / 'cpanel/includes/workshop_media.php',
    'includes/workshops.php': root / 'cpanel/includes/workshops.php',
    'includes/sms_service.php': root / 'cpanel/includes/sms_service.php',
}
sms = files['includes/sms_service.php'].read_text(encoding='utf-8')
assert 'مانا کلینیک: نوبت شما در {datetime} ثبت شد.' in sms
assert 'کد ورود شما {code}' in sms
assert 'مانا کلینیک: نوبت {patient} {datetime}' in sms
assert 'workshop_sms_queue_session_reflections' in sms
assert files['includes/workshop_sms.php'].is_file()

with FTP() as ftp:
    ftp.connect(cfg['host'], int(cfg.get('port', 21)), timeout=90)
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
name = 'wsms-' + secrets.token_hex(8) + '.php'
helper = r'''<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || time() > __EXPIRY__ || !hash_equals('__TOKEN__', $_SERVER['HTTP_X_SETUP_TOKEN'] ?? '')) {
    http_response_code(404); exit;
}
$config = require __DIR__.'/config.php';
$GLOBALS['config'] = $config;
date_default_timezone_set($config['timezone'] ?? 'Asia/Tehran');
foreach (['db','helpers','mail','outreach','workshop_sms','workshop_presenters'] as $f) {
    require_once __DIR__.'/includes/'.$f.'.php';
    if (function_exists('opcache_invalidate')) @opcache_invalidate(__DIR__.'/includes/'.$f.'.php', true);
}
$pdo = db_connect($config);
$sample = workshop_presenter_message([
    'title' => 'زوج درمانی',
    'starts_at' => '2026-10-22 18:00:00',
    'ends_at' => '2026-10-22 20:30:00',
    'session_interval' => 'WEEKLY',
    'type' => 'IN_PERSON',
    'location' => 'نشانی ثبت‌شدهٔ کارگاه',
], 'عماد شهابیان');
$reflect = workshop_sms_session_reflection_body('زوج درمانی', 'جلسه ۱');
$media = workshop_sms_media_added_body('زوج درمانی');
$cat = sms_template_catalog();
echo json_encode([
    'presenter_sample' => $sample,
    'reflect_sample' => $reflect,
    'media_sample' => $media,
    'emad_phone_ok' => workshop_sms_emad_phone($pdo) !== null,
    'has_reflect_fn' => function_exists('workshop_sms_queue_session_reflections'),
    'patient_booked' => $cat['appointment_booked'][1],
    'otp' => $cat['login_otp'][1],
], JSON_UNESCAPED_UNICODE);
'''
helper = helper.replace('__TOKEN__', token).replace('__EXPIRY__', str(int(time.time()) + 600))

with FTP() as ftp:
    ftp.connect(cfg['host'], int(cfg.get('port', 21)), timeout=60)
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
            'https://manaclinic.org/' + name,
            data=b'{}',
            headers={'X-Setup-Token': token, 'Content-Type': 'application/json'},
            method='POST',
        )
        with urllib.request.urlopen(req, timeout=45) as resp:
            out = resp.read().decode('utf-8')
            Path(r'D:/Psychology/output/access-update/deploy-workshop-sms.json').write_text(out, encoding='utf-8')
            print(out[:800], flush=True)
    finally:
        try:
            ftp.delete(name)
        except Exception:
            pass
        ftp.storbinary('STOR .htaccess', io.BytesIO(original_ht))
        print('cleaned', flush=True)
