from pathlib import Path
import ftplib,json,io,secrets,urllib.request,hashlib
r=Path.cwd();c=json.loads((r/'.vscode/sftp.json').read_text(encoding='utf-8-sig'));token=secrets.token_hex(32)
source=r'''<?php
if(!hash_equals('TOKEN_HASH',hash('sha256',$_SERVER['HTTP_X_DIAGNOSTIC_TOKEN']??''))){http_response_code(403);exit;}
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
$root=dirname(__DIR__);$config=require $root.'/config.php';date_default_timezone_set('Asia/Tehran');require $root.'/includes/db.php';require $root.'/includes/helpers.php';require $root.'/includes/mail.php';require $root.'/includes/outreach.php';$p=db_connect($config);
$u=$p->query("SELECT id FROM users WHERE LOWER(username)='eshahabian' OR (name LIKE '%عماد%' AND name LIKE '%شهابیان%') LIMIT 1")->fetch();$out=['account_found'=>(bool)$u,'sms_connection_ready'=>sms_connection_ready($p)];
if($u){
$s=$p->prepare("SELECT id,status FROM workshop_enrollments WHERE patient_id=? ORDER BY enrolled_at DESC LIMIT 1");$s->execute([$u['id']]);$e=$s->fetch();$out['membership_status']=$e['status']??null;
if($e){$s=$p->prepare("SELECT status,last_error,attempts,provider_id FROM sms_outbox WHERE kind='workshop_registered' AND context_json LIKE ? ORDER BY created_at DESC LIMIT 1");$s->execute(['%"id":"'.$e['id'].'"%']);$job=$s->fetch();$out['sms_found']=(bool)$job;
if($job){$out['sms_status']=$job['status'];$out['attempts']=(int)$job['attempts'];$out['provider_id_present']=!empty($job['provider_id']);$err=(string)$job['last_error'];$out['error_class']=str_contains($err,'اعتبار')?'credit':(str_contains($err,'شماره')?'phone':(str_contains($err,'timeout')?'timeout':($err===''?'none':'provider_other')));if(preg_match('/(?:کد|code)\D{0,5}([0-9]{1,3})/u',$err,$m))$out['provider_error_code']=$m[1];}
}}
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
