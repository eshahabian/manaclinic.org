from pathlib import Path
import ftplib, json, io, secrets, hashlib, urllib.request, time
root=Path.cwd(); dest=root/'output/room-availability-fix'; dest.mkdir(exist_ok=True)
c=json.loads((root/'.vscode/sftp.json').read_text(encoding='utf-8-sig'))
token=secrets.token_hex(32); tag='private-room-check-'+secrets.token_hex(16)
f=ftplib.FTP(c['host'],timeout=40); f.login(c['username'],c['password']); f.cwd('/public_html')
for file in ['includes/clinic_rooms.php','actions/room_availability.php','includes/booking_room_availability.php','assets/js/booking-assist.js']:
 b=io.BytesIO(); f.retrbinary('RETR '+file,b.write); data=b.getvalue(); (dest/('before-'+file.replace('/','_'))).write_bytes(data)
 print(file, 'matches_local', data.replace(b'\r\n',b'\n')==(root/'cpanel'/file).read_bytes().replace(b'\r\n',b'\n'))
php=r'''<?php
if(time()>EXPIRY||!hash_equals('HASH',hash('sha256',$_SERVER['HTTP_X_DIAGNOSTIC_TOKEN']??''))){http_response_code(404);exit;}
header('Content-Type: application/json');header('Cache-Control: no-store');$root=dirname(__DIR__);$config=require $root.'/config.php';date_default_timezone_set('Asia/Tehran');require_once $root.'/includes/db.php';$pdo=db_connect($config);
$out=[];
try{$pdo->query("SELECT a.id AS appointment_id,a.room_no,a.starts_at,a.ends_at,a.status AS appt_status,a.patient_id,a.doctor_id,a.session_mode,pu.name AS patient_name,du.name AS doctor_name FROM appointments a LEFT JOIN users pu ON pu.id=a.patient_id LEFT JOIN doctor_profiles dp ON dp.id=a.doctor_id LEFT JOIN users du ON du.id=dp.user_id WHERE a.room_no BETWEEN 1 AND 3 LIMIT 1");$out['fallback_query']='ok';}catch(Throwable $e){$out['fallback_query']=$e->getMessage();}
$out['room_counts']=$pdo->query("SELECT room_no,COUNT(*) AS total FROM appointments WHERE status<>'CANCELLED' AND starts_at>=CURDATE() GROUP BY room_no")->fetchAll();
$out['server_date']=$pdo->query('SELECT CURDATE() AS day')->fetchColumn();
$out['target_appointments']=$pdo->query("SELECT room_no,status,COUNT(*) AS total FROM appointments WHERE starts_at>='2026-10-31' AND starts_at<'2026-11-01' GROUP BY room_no,status")->fetchAll();
$out['target_reservations']=$pdo->query("SELECT b.room_no,b.kind,a.status,COUNT(*) AS total FROM clinic_room_bookings b LEFT JOIN appointments a ON a.id=b.appointment_id WHERE b.starts_at<'2026-11-01' AND b.ends_at>'2026-10-31' GROUP BY b.room_no,b.kind,a.status")->fetchAll();
$out['doctor_target']=$pdo->query("SELECT a.room_no,a.session_mode,COUNT(*) AS total FROM appointments a JOIN doctor_profiles dp ON dp.id=a.doctor_id JOIN users du ON du.id=dp.user_id WHERE du.name LIKE '%گران%' AND a.starts_at>='2026-10-31' AND a.starts_at<'2026-11-01' AND a.status<>'CANCELLED' GROUP BY a.room_no,a.session_mode")->fetchAll();
foreach(['helpers','mail','outreach','clinic_rooms','booking_room_availability'] as $file)require_once $root.'/includes/'.$file.'.php';
$out['target_segments']=booking_room_segments(clinic_rooms_on_day($pdo,'2026-10-31'),2,'2026-10-31');
$out['days']=[];foreach($pdo->query("SELECT DISTINCT DATE(starts_at) AS day FROM appointments WHERE room_no=2 AND status<>'CANCELLED' AND starts_at>=CURDATE() ORDER BY day LIMIT 14")->fetchAll() as $day){$date=$day['day'];$rows=clinic_rooms_on_day($pdo,$date);$out['days'][]=['date'=>$date,'segments'=>booking_room_segments($rows,2,$date)];}
echo json_encode($out);
'''.replace('HASH',hashlib.sha256(token.encode()).hexdigest()).replace('EXPIRY',str(int(time.time())+180))
f.mkd(tag)
try:
 f.storbinary('STOR '+tag+'/index.php',io.BytesIO(php.encode()))
 req=urllib.request.Request('https://manaclinic.org/'+tag+'/',headers={'X-Diagnostic-Token':token})
 with urllib.request.urlopen(req,timeout=40) as r: result=json.load(r)
 print(json.dumps(result)); (dest/'inspection.json').write_text(json.dumps(result,indent=2))
finally:
 for name in ['index.php','error_log']:
  try:f.delete(tag+'/'+name)
  except ftplib.error_perm:pass
 f.rmd(tag);f.quit()
