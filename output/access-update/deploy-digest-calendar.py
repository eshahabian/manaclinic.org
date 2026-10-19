"""Deploy sms_doctor_digest.php calendar fix (ASCII jalali + busy-day red)."""
from pathlib import Path
from ftplib import FTP
import io
import json
import secrets
import time
import urllib.request

root = Path('D:/Psychology')
cfg = json.loads((root / '.vscode/sftp.json').read_text(encoding='utf-8-sig'))
local = root / 'cpanel/pages/admin/sms_doctor_digest.php'
remote = 'pages/admin/sms_doctor_digest.php'
text = local.read_text(encoding='utf-8')
if 'digest-busy' not in text or 'to_fa_digits($jalaliView)' in text:
    raise RuntimeError('calendar fix missing or still using Persian digits in value')
data = local.read_bytes()

with FTP() as ftp:
    ftp.connect(cfg['host'], int(cfg.get('port', 21)), timeout=60)
    ftp.login(cfg['username'], cfg['password'])
    ftp.cwd('/public_html')
    try:
        ftp.delete(remote)
    except Exception:
        pass
    ftp.storbinary('STOR ' + remote, io.BytesIO(data))
    v = io.BytesIO()
    ftp.retrbinary('RETR ' + remote, v.write)
    if v.getvalue() != data:
        raise RuntimeError('verify failed')
    print('ok', remote, flush=True)

token = secrets.token_hex(32)
name = 'cal-' + secrets.token_hex(8) + '.php'
helper = r'''<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || time() > __EXPIRY__ || !hash_equals('__TOKEN__', $_SERVER['HTTP_X_SETUP_TOKEN'] ?? '')) {
    http_response_code(404); exit;
}
$f = __DIR__.'/pages/admin/sms_doctor_digest.php';
if (function_exists('opcache_invalidate')) @opcache_invalidate($f, true);
$body = file_get_contents($f);
echo json_encode([
    'has_busy' => strpos($body, 'digest-busy') !== false,
    'ascii_value' => strpos($body, 'to_fa_digits($jalaliView)') === false,
    'has_min' => strpos($body, 'minDate:"today"') !== false,
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
            'https://manaclinic.ir/' + name,
            data=b'{}',
            headers={'X-Setup-Token': token, 'Content-Type': 'application/json'},
            method='POST',
        )
        with urllib.request.urlopen(req, timeout=30) as resp:
            print(resp.read().decode('utf-8'), flush=True)
    finally:
        try:
            ftp.delete(name)
        except Exception:
            pass
        ftp.storbinary('STOR .htaccess', io.BytesIO(original_ht))
        print('cleaned', flush=True)
