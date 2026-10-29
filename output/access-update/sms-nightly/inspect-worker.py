from pathlib import Path
import ftplib,json,io,secrets,hashlib,urllib.request,time
root=Path.cwd();d=root/'output/access-update/sms-nightly';c=json.loads((root/'.vscode/sftp.json').read_text(encoding='utf-8-sig'));token=secrets.token_hex(32);tag='private-worker-'+secrets.token_hex(16)
f=ftplib.FTP(c['host'],timeout=40);f.login(c['username'],c['password'])
log=io.BytesIO()
try:
 f.retrbinary('RETR /.mana-sms-worker.log',log.write)
 b=log.getvalue();print(json.dumps({'worker_log_found':True,'completed':b'SMS worker completed.' in b,'fatal_error':b'Fatal error' in b,'bytes':len(b)}),flush=True)
 if b'Fatal error' in b or b'Parse error' in b:
  (d/'worker-error.private.log').write_bytes(b)
except ftplib.error_perm:print(json.dumps({'worker_log_found':False}),flush=True)
f.cwd('/public_html');f.mkd(tag)
php=r"""<?php
if(time()>EXPIRY || !hash_equals('HASH',hash('sha256',$_SERVER['HTTP_X_DIAGNOSTIC_TOKEN']??''))){http_response_code(404);exit;}
header('Content-Type: application/json');header('Cache-Control: no-store');$root=dirname(__DIR__);$config=require $root.'/config.php';date_default_timezone_set('Asia/Tehran');foreach(['db','helpers','mail','outreach'] as $file)require_once $root.'/includes/'.$file.'.php';$pdo=db_connect($config);
echo json_encode(['now'=>date('Y-m-d H:i:s'),'worker_last'=>mail_setting_get($pdo,'sms_worker_last_run',''),'worker_stale'=>sms_worker_is_stale($pdo,3),'before_21_no_digest'=>sms_doctor_digest_window()===null,'doctor_booking_disabled'=>!sms_kind_enabled($pdo,'doctor_appointment_booked')]);
""".replace('HASH',hashlib.sha256(token.encode()).hexdigest()).replace('EXPIRY',str(int(time.time())+180))
try:
 f.storbinary('STOR '+tag+'/index.php',io.BytesIO(php.encode()))
 req=urllib.request.Request('https://manaclinic.org/'+tag+'/',headers={'X-Diagnostic-Token':token})
 with urllib.request.urlopen(req,timeout=45) as resp:res=json.load(resp)
 (d/'worker-verification.json').write_text(json.dumps(res,indent=2));print(json.dumps(res),flush=True)
finally:
 f.delete(tag+'/index.php');f.rmd(tag);f.quit()
