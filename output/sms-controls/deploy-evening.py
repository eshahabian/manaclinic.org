from pathlib import Path
import ftplib,io,json,secrets,time,urllib.request
root=Path('D:/Psychology'); name='includes/sms_service.php'
cfg=json.loads((root/'.vscode/sftp.json').read_text('utf-8-sig'))
tag='sms-evening-'+secrets.token_hex(12);token=secrets.token_hex(32)
backup=root/'output/sms-controls'/('evening-before-'+time.strftime('%Y%m%d-%H%M%S'));backup.mkdir()
with ftplib.FTP() as ftp:
 ftp.connect(cfg['host'],int(cfg.get('port',21)),timeout=45);ftp.login(cfg['username'],cfg['password']);ftp.cwd('/public_html')
 def get(n):
  b=io.BytesIO();ftp.retrbinary('RETR '+n,b.write);return b.getvalue()
 def put(n,b):ftp.storbinary('STOR '+n,io.BytesIO(b))
 original=get(name);(backup/'sms_service.php').write_bytes(original)
 local=(root/'cpanel'/name).read_bytes()
 # Ensure remote has not diverged from the pre-edit local version.
 old=local.decode().replace('۲۰','۲۲').replace('$hour >= 20','$hour >= 22').replace("' 20:00:00'","' 22:00:00'")
 old=old.replace("هر درمانگر دارای نوبت تأییدشده؛ برنامه فردا ساعت ۲۲ به وقت تهران","هر درمانگر دارای نوبت تأییدشده در همان روز")
 start="return ($context['digest_date'] ?? '') === date('Y-m-d')"
 extra=";" 
 a=old.index(start);b=old.index(';',a)
 old=old[:a]+start+';'+old[b+1:]
 assert original==local,'Remote must match already deployed change'
 ftp.mkd(tag)
 guard="<?php if(time()>"+str(int(time.time())+900)+" || !hash_equals('"+token+"',$_SERVER['HTTP_X_VERIFY_TOKEN']??'')){http_response_code(404);exit;} "
 harness=r"""
$mode=$_SERVER['HTTP_X_VERIFY_MODE']??'';
if($mode==='syntax'){token_get_all(file_get_contents(__DIR__.'/source.txt'),TOKEN_PARSE);echo 'PASS syntax';exit;}
if($mode==='test'){
$s=file_get_contents(__DIR__.'/source.txt');
eval(substr($s,strpos($s,'function sms_template_catalog')));
function mail_setting_get(PDO $p,string $k,?string $d=null):?string{return $d;}
class FakeDb extends PDO{function __construct(){}}
$p=new FakeDb();date_default_timezone_set('Asia/Tehran');
foreach(['am','pm'] as $slot){if(!sms_job_still_valid($p,['kind'=>'doctor_daily_digest','context_json'=>json_encode(['entity'=>'doctor_digest','digest_date'=>date('Y-m-d'),'slot'=>$slot])]))throw new Exception('today rejected');}
$valid=sms_job_still_valid($p,['kind'=>'doctor_daily_digest','context_json'=>json_encode(['entity'=>'doctor_digest','digest_date'=>date('Y-m-d',strtotime('+1 day')),'slot'=>'pm'])]);
if($valid!==((int)date('G')>=20))throw new Exception('tomorrow eligibility');
if(sms_job_still_valid($p,['kind'=>'doctor_daily_digest','context_json'=>json_encode(['entity'=>'doctor_digest','digest_date'=>date('Y-m-d',strtotime('-1 day')),'slot'=>'pm'])]))throw new Exception('stale digest accepted');
echo 'PASS digest validation';exit;}
if($mode==='apply'){
$config=require dirname(__DIR__).'/config.php';require dirname(__DIR__).'/includes/db.php';require dirname(__DIR__).'/includes/mail.php';$pdo=db_connect($config);
$kinds=['appointment_booked','doctor_appointment_booked','doctor_appointment_cancelled','workshop_session_reflect'];
ensure_mail_schema($pdo);$pdo->beginTransaction();foreach($kinds as $kind){mail_setting_set($pdo,'sms_kind_enabled_'.$kind,'0');$pdo->prepare("UPDATE sms_outbox SET status='SKIPPED',last_error='این نوع پیامک توسط مدیر غیرفعال شد.' WHERE kind=? AND status='PENDING'")->execute([$kind]);} $pdo->commit();
foreach($kinds as $kind)if(mail_setting_get($pdo,'sms_kind_enabled_'.$kind)!=='0')throw new Exception('Setting mismatch');
if(function_exists('opcache_invalidate'))opcache_invalidate(dirname(__DIR__).'/includes/sms_service.php',true);
echo json_encode(['ok'=>true,'disabled'=>$kinds,'worker_last_run'=>mail_setting_get($pdo,'sms_worker_last_run'),'timezone'=>$config['timezone']??null,'session_gc_seconds'=>ini_get('session.gc_maxlifetime')]);}
"""
 def req(mode):
  r=urllib.request.Request('https://manaclinic.org/'+tag+'/',headers={'X-Verify-Token':token,'X-Verify-Mode':mode})
  return urllib.request.urlopen(r,timeout=60).read().decode('utf-8-sig')
 deployed=False
 try:
  put(tag+'/source.txt',local);put(tag+'/index.php',(guard+harness).encode())
  print(req('syntax'),flush=True);result=req('test');assert result=='PASS digest validation',result;print(result,flush=True)
  assert get(name)==local
  result=req('apply');assert json.loads(result)['ok'];print(result,flush=True);(backup/'result.json').write_text(result)
 except Exception:
  if deployed:put(name,original)
  raise
 finally:
  for n in ['source.txt','index.php','error_log']:
   try:ftp.delete(tag+'/'+n)
   except ftplib.error_perm:pass
  ftp.rmd(tag)
