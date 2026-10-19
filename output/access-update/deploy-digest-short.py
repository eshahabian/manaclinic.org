"""Deploy only sms_service.php digest short-body fix. Verify patient templates untouched."""
from pathlib import Path
from ftplib import FTP
import io
import json
import secrets
import time
import urllib.error
import urllib.request

root = Path('D:/Psychology')
cfg = json.loads((root / '.vscode/sftp.json').read_text(encoding='utf-8-sig'))
local = root / 'cpanel/includes/sms_service.php'
remote = 'includes/sms_service.php'
text = local.read_text(encoding='utf-8')
if 'مانا کلینیک: نوبت شما در {datetime} ثبت شد.' not in text:
    raise RuntimeError('patient booked changed')
if 'کد ورود شما {code}' not in text:
    raise RuntimeError('otp changed')
if 'مانا کلینیک: نوبت {patient} {datetime}' not in text:
    raise RuntimeError('doctor booked changed')
fn = text[text.find('function sms_doctor_daily_digest_body'):text.find('function sms_doctor_digests_for_date')]
if 'گرامی' in fn or 'doctorName .' in fn or '$doctorName .' in fn:
    raise RuntimeError('digest body still includes name')
if 'مانا کلینیک: نوبت ' not in fn:
    raise RuntimeError('short digest prefix missing')
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
name = 'dgs-' + secrets.token_hex(8) + '.php'
helper = r'''<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || time() > __EXPIRY__ || !hash_equals('__TOKEN__', $_SERVER['HTTP_X_SETUP_TOKEN'] ?? '')) {
    http_response_code(404); exit;
}
$f = __DIR__.'/includes/sms_service.php';
if (function_exists('opcache_invalidate')) @opcache_invalidate($f, true);
require_once $f;
$sample = sms_doctor_daily_digest_body('عطیه گارسچی', '۲۹ مهر ۱۴۰۵', ['10:00','11:00','13:00']);
$cat = sms_template_catalog();
echo json_encode([
    'digest_sample' => $sample,
    'digest_len' => mb_strlen($sample),
    'has_doctor_name' => (mb_strpos($sample, 'عطیه') !== false),
    'patient_booked' => $cat['appointment_booked'][1],
    'otp' => $cat['login_otp'][1],
    'doctor_booked' => $cat['doctor_appointment_booked'][1],
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
        try:
            with urllib.request.urlopen(req, timeout=40) as resp:
                print(resp.read().decode('utf-8'), flush=True)
        except urllib.error.HTTPError as e:
            print('helper_http', e.code, e.read()[:300], flush=True)
        except Exception as e:
            print('helper_err', type(e).__name__, e, flush=True)
    finally:
        try:
            ftp.delete(name)
        except Exception:
            pass
        ftp.storbinary('STOR .htaccess', io.BytesIO(original_ht))
        print('cleaned', flush=True)
