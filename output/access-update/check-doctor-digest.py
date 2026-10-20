from pathlib import Path
import ftplib,json,io,secrets,urllib.request,hashlib
r=Path.cwd();c=json.loads((r/'.vscode/sftp.json').read_text(encoding='utf-8-sig'));token=secrets.token_hex(32)
source=r'''<?php
if(!hash_equals('TOKEN_HASH',hash('sha256',$_SERVER['HTTP_X_DIAGNOSTIC_TOKEN']??''))){http_response_code(403);exit;}
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
$root=dirname(__DIR__);$config=require $root.'/config.php';date_default_timezone_set('Asia/Tehran');require $root.'/includes/db.php';require $root.'/includes/helpers.php';require $root.'/includes/mail.php';require $root.'/includes/outreach.php';$p=db_connect($config);
$out=['now'=>date('Y-m-d H:i:s'),'ready'=>sms_connection_ready($p),'worker_last_run'=>mail_setting_get($p,'sms_worker_last_run'),'expected'=>[],'jobs'=>[]];
foreach(sms_doctor_digests_for_date($p,date('Y-m-d')) as $pack){$out['expected'][]=['name'=>$pack['name'],'slots'=>$pack['slot_count'],'valid_phone'=>$pack['phone']!==null];}
$q=$p->query("SELECT COALESCE(u.name,'unknown') AS name,s.status,JSON_UNQUOTE(JSON_EXTRACT(s.context_json,'$.digest_date')) AS digest_date,s.created_at,s.due_at,s.sent_at,s.attempts,s.provider_id,s.last_error,CHAR_LENGTH(s.body) AS body_length,LOCATE('گرامی',s.body)>0 AS has_greeting FROM sms_outbox s LEFT JOIN doctor_profiles dp ON dp.id=JSON_UNQUOTE(JSON_EXTRACT(s.context_json,'$.doctor_id')) LEFT JOIN users u ON u.id=dp.user_id WHERE s.kind='doctor_daily_digest' AND s.created_at>=DATE_SUB(CURDATE(),INTERVAL 7 DAY) ORDER BY s.created_at DESC LIMIT 80");
foreach($q->fetchAll() as $row){$error=(string)$row['last_error'];unset($row['last_error']);$row['error_class']=$error===''?'none':(str_contains($error,'اعتبار')?'credit':(str_contains($error,'شماره')?'phone':'provider_other'));$row['provider_id_present']=!empty($row['provider_id']);unset($row['provider_id']);$out['jobs'][]=$row;}
$out['worker_recent']=$p->query("SELECT status,COUNT(*) AS count,MAX(created_at) AS latest_created,MAX(sent_at) AS latest_sent FROM sms_outbox WHERE created_at>=DATE_SUB(NOW(),INTERVAL 2 DAY) GROUP BY status")->fetchAll();
echo json_encode($out,JSON_UNESCAPED_UNICODE);
?>'''
source=source.replace('TOKEN_HASH',hashlib.sha256(token.encode()).hexdigest())
f=ftplib.FTP(c['host'],timeout=45);f.login(c['username'],c['password']);f.cwd('/public_html');tag='private-sms-check-'+secrets.token_hex(12);f.mkd(tag)
try:
 f.storbinary('STOR '+tag+'/index.php',io.BytesIO(source.encode()))
 request=urllib.request.Request('https://manaclinic.org/'+tag+'/',headers={'X-Diagnostic-Token':token})
 with urllib.request.urlopen(request,timeout=45) as response:print(response.read().decode('utf-8-sig'))
finally:
 f.delete(tag+'/index.php');f.rmd(tag);f.quit()
