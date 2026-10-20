from pathlib import Path
import ftplib,json,io,secrets,urllib.request,hashlib
r=Path.cwd();c=json.loads((r/'.vscode/sftp.json').read_text(encoding='utf-8-sig'));token=secrets.token_hex(32)
source=r'''<?php
if(!hash_equals('TOKEN_HASH',hash('sha256',$_SERVER['HTTP_X_DIAGNOSTIC_TOKEN']??''))){http_response_code(403);exit;}
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
$root=dirname(__DIR__);$config=require $root.'/config.php';date_default_timezone_set('Asia/Tehran');require $root.'/includes/db.php';require $root.'/includes/helpers.php';require $root.'/includes/mail.php';require $root.'/includes/outreach.php';$p=db_connect($config);
$out=['now'=>date('Y-m-d H:i:s'),'last_worker'=>mail_setting_get($p,'sms_worker_last_run'),'presenters'=>$p->query("SELECT w.title,u.name,wp.assigned_at FROM workshop_presenters wp JOIN workshops w ON w.id=wp.workshop_id JOIN doctor_profiles dp ON dp.id=wp.doctor_id JOIN users u ON u.id=dp.user_id ORDER BY wp.assigned_at DESC LIMIT 10")->fetchAll(),'jobs'=>[]];
$q=$p->query("SELECT kind,status,created_at,due_at,attempts,sent_at,provider_id,last_error FROM sms_outbox WHERE kind IN ('workshop_presenter','workshop_presenter_copy') ORDER BY created_at DESC LIMIT 20");
foreach($q->fetchAll() as $r){$r['provider_id_present']=!empty($r['provider_id']);unset($r['provider_id']);$r['error_class']=$r['last_error']==='اعتبار پنل کافی نیست.'?'insufficient_credit':($r['last_error']?'other':'none');unset($r['last_error']);$out['jobs'][]=$r;}
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
