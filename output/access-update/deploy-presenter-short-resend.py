"""Deploy short presenter SMS + resend for زوج درمانی workshop."""
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
    'includes/workshop_presenters.php': root / 'cpanel/includes/workshop_presenters.php',
    'actions/workshop_presenters_save.php': root / 'cpanel/actions/workshop_presenters_save.php',
}
sms = (root / 'cpanel/includes/sms_service.php').read_text(encoding='utf-8')
assert 'مانا کلینیک: نوبت شما در {datetime} ثبت شد.' in sms
assert 'workshop_presenters_notify' in files['includes/workshop_presenters.php'].read_text(encoding='utf-8')

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
name = 'prs-' + secrets.token_hex(8) + '.php'
helper = r'''<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || time() > __EXPIRY__ || !hash_equals('__TOKEN__', $_SERVER['HTTP_X_SETUP_TOKEN'] ?? '')) {
    http_response_code(404); exit;
}
$config = require __DIR__.'/config.php';
$GLOBALS['config'] = $config;
date_default_timezone_set($config['timezone'] ?? 'Asia/Tehran');
foreach (['db','helpers','mail','outreach','workshop_presenters','workshop_sms'] as $f) {
    $path = __DIR__.'/includes/'.$f.'.php';
    if (function_exists('opcache_invalidate')) @opcache_invalidate($path, true);
    require_once $path;
}
$pdo = db_connect($config);
$wid = 'ff9e3c4fa52100b080a306a7';
$w = $pdo->prepare('SELECT * FROM workshops WHERE id=?'); $w->execute([$wid]); $workshop = $w->fetch();
$sample = $workshop ? workshop_presenter_message($workshop, 'تست') : null;
$result = workshop_presenters_notify($pdo, $wid);
echo json_encode([
    'sample' => $sample,
    'sample_len' => $sample !== null ? mb_strlen($sample) : null,
    'notify' => $result,
    'patient_booked_ok' => str_contains(file_get_contents(__DIR__.'/includes/sms_service.php'), 'مانا کلینیک: نوبت شما در {datetime} ثبت شد.'),
], JSON_UNESCAPED_UNICODE);
'''
helper = helper.replace('__TOKEN__', token).replace('__EXPIRY__', str(int(time.time()) + 900))

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
            headers={'X-Setup-Token': token, 'Content-Type': 'application/json', 'User-Agent': 'Mozilla/5.0'},
            method='POST',
        )
        with urllib.request.urlopen(req, timeout=180) as resp:
            out = resp.read().decode('utf-8')
            Path(r'D:/Psychology/output/access-update/deploy-presenter-short-resend.json').write_text(out, encoding='utf-8')
            print('wrote', len(out), flush=True)
    finally:
        try:
            ftp.delete(name)
        except Exception:
            pass
        ftp.storbinary('STOR .htaccess', io.BytesIO(original_ht))
        print('cleaned', flush=True)
