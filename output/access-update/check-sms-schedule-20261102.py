from pathlib import Path
from ftplib import FTP
import io,json,secrets,time,urllib.request,hashlib
root=Path('D:/Psychology');cfg=json.loads((root/'.vscode/sftp.json').read_text('utf-8-sig'));token=secrets.token_hex(32);name='sms-schedule-'+secrets.token_hex(12)+'.php'
php=r'''<?php
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
if(($_SERVER['REQUEST_METHOD']??'')!=='POST'||time()>__EXPIRY__||!hash_equals('__TOKEN__',$_SERVER['HTTP_X_SETUP_TOKEN']??'')){http_response_code(404);exit;}
try{
$c=require __DIR__.'/config.php';date_default_timezone_set($c['timezone']??'Asia/Tehran');
$pdo=new PDO(sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4',$c['db_host'],$c['db_name']),$c['db_user'],$c['db_pass'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$pdo->exec("SET time_zone='+03:30'");$pdo->exec('SET TRANSACTION READ ONLY');$pdo->beginTransaction();
function mail_setting_get(PDO $pdo,string $key,?string $default=null):?string{$q=$pdo->prepare('SELECT setting_value FROM app_settings WHERE setting_key=?');$q->execute([$key]);$r=$q->fetch();return $r&&$r['setting_value']!==null?(string)$r['setting_value']:$default;}
require_once __DIR__.'/includes/helpers.php';require_once __DIR__.'/includes/sms_service.php';
$tomorrow=date('Y-m-d',strtotime('+1 day'));
$out=['now'=>date('Y-m-d H:i:s'),'tomorrow'=>$tomorrow,'ready'=>sms_connection_ready($pdo),'worker_last'=>mail_setting_get($pdo,'sms_worker_last_run',''),'cron_installed'=>mail_setting_get($pdo,'sms_cron_installed',''),'digest_enabled'=>sms_kind_enabled($pdo,'doctor_daily_digest'),'reminder_enabled'=>sms_kind_enabled($pdo,'appointment_reminder'),'digest_window_20'=>sms_doctor_digest_window(new DateTimeImmutable(date('Y-m-d').' 20:00:00',new DateTimeZone('Asia/Tehran')))];
$out['reminder_template']=mail_setting_get($pdo,'sms_template_appointment_reminder','');
$out['digest_template']=mail_setting_get($pdo,'sms_template_doctor_daily_digest','');
$packs=sms_doctor_digests_for_date($pdo,$tomorrow);$out['tomorrow_doctors']=[];
foreach($packs as $p)$out['tomorrow_doctors'][]=['valid_phone'=>$p['phone']!==null,'appointments'=>$p['slot_count'],'body_chars'=>mb_strlen($p['body']),'named_slots'=>isset($p['slots']),'labels_filtered'=>str_contains($p['body'],'در تاریخ')||str_contains($p['body'],'ساعت')];
$q=$pdo->prepare("SELECT id,starts_at,created_at,status FROM appointments WHERE DATE(starts_at)=? ORDER BY starts_at");$q->execute([$tomorrow]);$appts=$q->fetchAll();$out['tomorrow_patients']=[];
$jobs=$pdo->prepare("SELECT status,due_at,sent_at,last_error FROM sms_outbox WHERE kind='appointment_reminder' AND context_json LIKE ? ORDER BY created_at DESC LIMIT 1");
foreach($appts as $a){$jobs->execute(['%"id":"'.$a['id'].'"%']);$job=$jobs->fetch();$eligible=$a['status']==='CONFIRMED'&&strtotime($a['created_at'])<=strtotime($a['starts_at'])-86400;$out['tomorrow_patients'][]=['starts_at'=>$a['starts_at'],'status'=>$a['status'],'created_at'=>$a['created_at'],'reminder_eligible'=>$eligible,'expected_reminder'=>date('Y-m-d H:i:s',strtotime($a['starts_at'])-86400),'latest_job'=>$job?:null];}
$out['recent_reminders']=$pdo->query("SELECT status,COUNT(*) n,MAX(sent_at) last_sent,MAX(created_at) last_created FROM sms_outbox WHERE kind='appointment_reminder' AND created_at>=NOW()-INTERVAL 7 DAY GROUP BY status")->fetchAll();
$out['queue_due']=$pdo->query("SELECT kind,status,COUNT(*) n FROM sms_outbox WHERE status IN ('PENDING','PROCESSING','UNKNOWN') GROUP BY kind,status")->fetchAll();
$sms=sms_connection_config($pdo);$pdo->rollBack();
$ch=curl_init('https://api.payamakapi.ir/api/v1/SMS/GetCredit');curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>20,CURLOPT_HTTPHEADER=>['Accept: application/json','X-API-KEY: '.$sms['api_key']]]);$credit=curl_exec($ch);$http=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);$out['credit']=['http'=>$http,'raw'=>$credit];
echo json_encode($out,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
}catch(Throwable $e){if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();http_response_code(500);echo json_encode(['error'=>$e->getMessage()]);}
'''.replace('__TOKEN__',token).replace('__EXPIRY__',str(int(time.time())+600))
def get(f,p):
 b=io.BytesIO();f.retrbinary('RETR '+p,b.write);return b.getvalue()
with FTP() as f:
 f.connect(cfg['host'],int(cfg.get('port',21)),timeout=45);f.login(cfg['username'],cfg['password']);f.cwd('/public_html')
 live=get(f,'includes/sms_service.php');local=(root/'cpanel/includes/sms_service.php').read_bytes()
 (root/'output/access-update/sms-schedule-live-source-20261102.php').write_bytes(live)
 ht=get(f,'.htaccess');mod=ht.replace(b'RewriteEngine On',b'RewriteEngine On\nRewriteRule ^'+name.replace('.',r'\.').encode()+b'$ '+name.encode()+b' [END]',1)
 if ht==mod:raise RuntimeError('No safe routing insertion point')
 try:
  f.storbinary('STOR '+name,io.BytesIO(php.encode()));f.storbinary('STOR .htaccess',io.BytesIO(mod))
  req=urllib.request.Request('https://manaclinic.org/'+name,data=b'',headers={'X-Setup-Token':token},method='POST')
  with urllib.request.urlopen(req,timeout=75) as r:out=json.load(r)
  out['live_matches_local']=live.replace(b'\r\n',b'\n')==local.replace(b'\r\n',b'\n')
  (root/'output/access-update/sms-schedule-check-20261102.json').write_text(json.dumps(out,ensure_ascii=False,indent=2),encoding='utf-8')
  print(json.dumps(out,ensure_ascii=True),flush=True)
 finally:
  if get(f,'.htaccess')!=mod:raise RuntimeError('Concurrent routing change')
  f.storbinary('STOR .htaccess',io.BytesIO(ht));f.delete(name);assert get(f,'.htaccess')==ht
  print('Temporary helper removed; original routing verified.',flush=True)
