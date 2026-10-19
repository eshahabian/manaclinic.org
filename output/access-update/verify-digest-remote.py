from pathlib import Path
from ftplib import FTP
import io
import json
import re

root = Path('D:/Psychology')
cfg = json.loads((root / '.vscode/sftp.json').read_text(encoding='utf-8-sig'))
v = io.BytesIO()
with FTP() as ftp:
    ftp.connect(cfg['host'], int(cfg.get('port', 21)), timeout=60)
    ftp.login(cfg['username'], cfg['password'])
    ftp.cwd('/public_html')
    ftp.retrbinary('RETR includes/sms_service.php', v.write)
t = v.getvalue().decode('utf-8')
assert 'مانا کلینیک: نوبت شما در {datetime} ثبت شد.' in t
assert 'کد ورود شما {code}' in t
assert 'مانا کلینیک: نوبت {patient} {datetime}' in t
fn = t[t.find('function sms_doctor_daily_digest_body'):t.find('function sms_doctor_digests_for_date')]
print('remote_bytes', len(t))
print('has_garami', 'گرامی' in fn)
print('prefix_ok', 'مانا کلینیک: نوبت ' in fn)
m = re.search(r"\$prefix = '([^']+)'", fn)
print('prefix', m.group(1) if m else 'n/a')
print('patient_otp_doctor_ok', True)
