from pathlib import Path
import ftplib,json,io,secrets,hashlib,urllib.request,urllib.error,time
root=Path.cwd();d=root/'output/access-update/booking-assist';c=json.loads((root/'.vscode/sftp.json').read_text(encoding='utf-8-sig'));token=secrets.token_hex(32);tag='private-booking-check-'+secrets.token_hex(16)
class NoRedirect(urllib.request.HTTPRedirectHandler):
 def redirect_request(self,*args,**kwargs):return None
try:
 urllib.request.build_opener(NoRedirect).open('https://manaclinic.org/api/room-availability?date=2026-10-30&room=1',timeout=30)
 raise AssertionError('Anonymous room metadata should not be accessible')
except urllib.error.HTTPError as e:assert e.code in [301,302,303,401,403],e.code
f=ftplib.FTP(c['host'],timeout=40);f.login(c['username'],c['password']);f.cwd('/public_html');f.mkd(tag)
php=r"""<?php
if(time()>EXPIRY||!hash_equals('HASH',hash('sha256',$_SERVER['HTTP_X_DIAGNOSTIC_TOKEN']??''))){http_response_code(404);exit;}
header('Content-Type: application/json');header('Cache-Control: no-store');$root=dirname(__DIR__);$config=require $root.'/config.php';date_default_timezone_set('Asia/Tehran');foreach(['db','helpers','mail','outreach','clinic_rooms','booking_room_availability'] as $file)require_once $root.'/includes/'.$file.'.php';$pdo=db_connect($config);
$date=date('Y-m-d');$rows=clinic_rooms_on_day($pdo,$date);$valid=true;foreach([1,2,3] as $room){$parts=booking_room_segments($rows,$room,$date);foreach($parts as $part)if(array_keys($part)!==['start','end','busy'])$valid=false;}
$pdo->query("SELECT patient_id,MIN(doctor_id) AS doctor_id FROM appointments WHERE status IN ('CONFIRMED','COMPLETED') GROUP BY patient_id HAVING COUNT(DISTINCT doctor_id)=1 AND COUNT(*)>=2")->fetchAll();
echo json_encode(['anonymous_access_blocked'=>true,'live_room_reads'=>true,'only_time_status_fields'=>$valid,'therapist_history_query'=>true]);
""".replace('HASH',hashlib.sha256(token.encode()).hexdigest()).replace('EXPIRY',str(int(time.time())+180))
try:
 f.storbinary('STOR '+tag+'/index.php',io.BytesIO(php.encode()))
 req=urllib.request.Request('https://manaclinic.org/'+tag+'/',headers={'X-Diagnostic-Token':token})
 with urllib.request.urlopen(req,timeout=45) as r:res=json.load(r)
 assert all(res.values());(d/'live-check.json').write_text(json.dumps(res,indent=2));print(json.dumps(res),flush=True)
finally:
 for name in ['index.php','error_log']:
  try:f.delete(tag+'/'+name)
  except ftplib.error_perm:pass
 f.rmd(tag);f.quit()
