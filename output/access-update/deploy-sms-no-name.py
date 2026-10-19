"""Deploy no-name SMS templates and clear DB overrides."""
from pathlib import Path
from ftplib import FTP
import io
import json
import secrets
import time
import urllib.request

root = Path('D:/Psychology')
cfg = json.loads((root / '.vscode/sftp.json').read_text(encoding='utf-8-sig'))
local = (root / 'cpanel/includes/sms_service.php').read_bytes()
if 'نوبت شما در {datetime} ثبت شد'.encode('utf-8') not in local:
    raise RuntimeError('no-name template missing')
if b'{patient} \xd8\xb9\xd8\xb2\xdb\x8c\xd8\xb2' in local or 'عزیز\\n'.encode('utf-8') in local:
    # allow only in comments; catalog must not have patient عزیز
    catalog_start = local.find(b'function sms_template_catalog')
    catalog_end = local.find(b'function sms_template_render')
    chunk = local[catalog_start:catalog_end]
    if '{patient}'.encode() in chunk:
        raise RuntimeError('catalog still has {patient}')

with FTP() as ftp:
    ftp.connect(cfg['host'], int(cfg.get('port', 21)), timeout=45)
    ftp.login(cfg['username'], cfg['password'])
    ftp.cwd('/public_html')
    remote = 'includes/sms_service.php'
    try:
        ftp.delete(remote)
    except Exception:
        pass
    ftp.storbinary('STOR ' + remote, io.BytesIO(local))
    verify = io.BytesIO()
    ftp.retrbinary('RETR ' + remote, verify.write)
    if verify.getvalue() != local:
        raise RuntimeError('verify failed')
    print('deployed sms_service.php', flush=True)

token = secrets.token_hex(32)
name = 'sms-nn-' + secrets.token_hex(8) + '.php'
helper = r'''<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || time() > __EXPIRY__ || !hash_equals('__TOKEN__', $_SERVER['HTTP_X_SETUP_TOKEN'] ?? '')) {
    http_response_code(404);
    exit;
}
$p = __DIR__ . '/includes/sms_service.php';
if (function_exists('opcache_invalidate')) { @opcache_invalidate($p, true); @opcache_reset(); }
$config = require __DIR__ . '/config.php';
date_default_timezone_set($config['timezone'] ?? 'Asia/Tehran');
foreach (['db','helpers','mail','outreach'] as $f) require_once __DIR__.'/includes/'.$f.'.php';
$pdo = db_connect($config);
ensure_mail_schema($pdo);
foreach (['appointment_booked','appointment_cancelled','appointment_reminder','workshop_registered','workshop_reminder'] as $k) {
    mail_setting_set($pdo, 'sms_template_'.$k, '');
}
$sample = sms_template_render($pdo, 'appointment_booked', [
    'datetime' => format_fa_sms_datetime(date('Y-m-d H:i:s', time()+86400)),
    'patient' => 'عماد شهابیان',
]);
echo json_encode([
    'sample' => $sample,
    'len' => mb_strlen($sample),
    'has_name' => str_contains($sample, 'عماد') || str_contains($sample, 'عزیز'),
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
            headers={'X-Setup-Token': token, 'User-Agent': 'ManaSmsNoName/1.0', 'Content-Type': 'application/x-www-form-urlencoded'},
            method='POST',
        )
        with urllib.request.urlopen(req, timeout=90) as response:
            body = response.read().decode('utf-8', 'replace')
        Path('D:/Psychology/output/access-update/deploy-sms-no-name.json').write_text(body, encoding='utf-8')
        data = json.loads(body)
        print(json.dumps(data, ensure_ascii=True), flush=True)
    finally:
        ftp.storbinary('STOR .htaccess', io.BytesIO(original_ht))
        try:
            ftp.delete(name)
        except Exception:
            pass
        print('cleanup ok', flush=True)
