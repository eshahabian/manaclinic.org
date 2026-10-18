from pathlib import Path
from ftplib import FTP, error_perm
import json, io, subprocess, time, sys

root=Path('D:/Psychology')
cfg=json.loads((root/'.vscode/sftp.json').read_text(encoding='utf-8-sig'))
files=[
 'includes/sms_service.php','config.sms.php','includes/login_otp.php','actions/login_otp_request.php',
 'pages/admin/sms_settings.php','sms-worker.php','includes/db.php','includes/outreach.php',
 'includes/admin_panel.php','includes/doctor_panel.php','actions/admin_manage.php',
 'pages/admin/sms_test.php','pages/admin/sms_broadcast.php','pages/admin/sms_notify.php',
 'pages/secretary/patients.php','actions/secretary_users.php','actions/login.php','includes/auth_gate.php','index.php',
]
if len(sys.argv)>1:
 requested=sys.argv[1:]
 if any(name not in files for name in requested):raise RuntimeError('Unexpected deployment path')
 files=requested
backup=root/'output/access-update'/('sms-before-'+time.strftime('%Y%m%d-%H%M%S'))
backup.mkdir(parents=True,exist_ok=True)
with FTP() as ftp:
 ftp.connect(cfg['host'],int(cfg.get('port',21)),timeout=45)
 ftp.login(cfg['username'],cfg['password'])
 ftp.cwd('/public_html')
 for name in files:
  old=io.BytesIO()
  try:
   ftp.retrbinary('RETR '+name,old.write)
   dest=backup/name;dest.parent.mkdir(parents=True,exist_ok=True);dest.write_bytes(old.getvalue())
  except error_perm as e:
   if not str(e).startswith('550'):raise
  payload=(root/'cpanel'/name).read_bytes()
  ftp.storbinary('STOR '+name,io.BytesIO(payload))
  verify=io.BytesIO();ftp.retrbinary('RETR '+name,verify.write)
  if verify.getvalue()!=payload:raise RuntimeError('Upload verification failed: '+name)
  print('Verified '+name,flush=True)
print('SMS and OTP deployment verified.',flush=True)
