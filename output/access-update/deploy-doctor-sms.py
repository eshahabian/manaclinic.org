"""Deploy doctor appointment SMS (patient templates untouched). Render-only verify."""
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
    'actions/admin_manage.php': root / 'cpanel/actions/admin_manage.php',
    'pages/admin/sms_notify.php': root / 'cpanel/pages/admin/sms_notify.php',
}
sms_text = files['includes/sms_service.php'].read_text(encoding='utf-8')
if 'doctor_appointment_booked' not in sms_text:
    raise RuntimeError('doctor template missing')
if 'مانا کلینیک: نوبت شما در {datetime} ثبت شد.' not in sms_text:
    raise RuntimeError('patient template changed unexpectedly')

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
name = 'doc-sms-' + secrets.token_hex(8) + '.php'
helper = r'''<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || time() > __EXPIRY__ || !hash_equals('__TOKEN__', $_SERVER['HTTP_X_SETUP_TOKEN'] ?? '')) {
    http_response_code(404); exit;
}
if (function_exists('opcache_invalidate')) {
    @opcache_invalidate(__DIR__.'/includes/sms_service.php', true);
}
$config = require __DIR__.'/config.php';
date_default_timezone_set($config['timezone'] ?? 'Asia/Tehran');
foreach (['db','helpers','mail','outreach'] as $f) require_once __DIR__.'/includes/'.$f.'.php';
$pdo = db_connect($config);
$row = $pdo->query("SELECT a.id,u.name AS patient_name,u.phone AS patient_phone,d.name AS doctor_name,d.phone AS doctor_phone,a.starts_at
    FROM appointments a
    JOIN users u ON u.id=a.patient_id
    JOIN doctor_profiles dp ON dp.id=a.doctor_id
    JOIN users d ON d.id=dp.user_id
    ORDER BY a.updated_at DESC LIMIT 1")->fetch();
$when = is_array($row) ? format_fa_datetime((string)$row['starts_at']) : format_fa_datetime(date('Y-m-d H:i:s'));
$patientBody = sms_template_render($pdo,'appointment_booked',['datetime'=>$when]);
$doctorBody = sms_template_render($pdo,'doctor_appointment_booked',[
    'patient'=>(string)($row['patient_name']??'عماد شهابیان'),
    'datetime'=>$when,
]);
echo json_encode([
    'has_notify'=>function_exists('sms_notify_appointment_now'),
    'patient_body'=>$patientBody,
    'patient_len'=>mb_strlen($patientBody),
    'doctor_body'=>$doctorBody,
    'doctor_len'=>mb_strlen($doctorBody),
    'sample_patient'=>is_array($row)?(string)$row['patient_name']:'',
    'sample_doctor'=>is_array($row)?(string)$row['doctor_name']:'',
    'doctor_phone_tail'=>is_array($row)&&$row['doctor_phone']?substr(preg_replace('/\D+/','',(string)$row['doctor_phone']),-4):null,
    'patient_unchanged'=>str_starts_with($patientBody,'مانا کلینیک: نوبت شما در'),
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
            headers={'X-Setup-Token': token, 'User-Agent': 'ManaDocSms/1.0', 'Content-Type': 'application/x-www-form-urlencoded'},
            method='POST',
        )
        with urllib.request.urlopen(req, timeout=90) as response:
            body = response.read().decode('utf-8', 'replace')
        (root / 'output/access-update/deploy-doctor-sms.json').write_text(body, encoding='utf-8')
        print(json.dumps(json.loads(body), ensure_ascii=True), flush=True)
    finally:
        ftp.storbinary('STOR .htaccess', io.BytesIO(original_ht))
        try:
            ftp.delete(name)
        except Exception:
            pass
        print('cleanup ok', flush=True)
