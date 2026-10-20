from pathlib import Path
import ftplib,json,io,secrets,urllib.request,hashlib
r=Path.cwd();c=json.loads((r/'.vscode/sftp.json').read_text(encoding='utf-8-sig'));token=secrets.token_hex(32)
source=r'''<?php
if(!hash_equals('TOKEN_HASH',hash('sha256',$_SERVER['HTTP_X_DIAGNOSTIC_TOKEN']??''))){http_response_code(403);exit;}
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
$root=dirname(__DIR__);$config=require $root.'/config.php';date_default_timezone_set('Asia/Tehran');require $root.'/includes/db.php';require $root.'/includes/helpers.php';require $root.'/includes/mail.php';require $root.'/includes/outreach.php';$p=db_connect($config);
eval(substr(file_get_contents(__DIR__.'/presenters.txt'),5));
$w=$p->query("SELECT * FROM workshops WHERE title LIKE '%زوج%' AND status='PUBLISHED' ORDER BY created_at DESC LIMIT 1")->fetch();if(!$w)throw new RuntimeException('Published couples workshop missing');
$u=$p->query("SELECT id,name,phone FROM users WHERE LOWER(username)='eshahabian' AND COALESCE(is_disabled,0)=0 LIMIT 1")->fetch();if(!$u)throw new RuntimeException('Target missing');
$phone=sms_iran_mobile($u['phone']);if(!$phone||!sms_connection_ready($p))throw new RuntimeException('SMS not ready');
$body="نمونه پیام اجراکننده:\n".workshop_presenter_message($w,$u['name']);
ensure_outreach_schema($p);$key='presenter-preview:'.hash('sha256',$body);sms_queue_extended($p,$phone,$body,'presenter_preview',$key,null,date('Y-m-d H:i:s',time()+86400));
$q=$p->prepare('SELECT * FROM sms_outbox WHERE dedup_key=?');$q->execute([hash('sha256',$key)]);$job=$q->fetch();
if(!$job)throw new RuntimeException('Queue record missing');
if($job['status']==='PENDING'&&(int)$p->query("SELECT GET_LOCK('mana_sms_dispatch',0)")->fetchColumn()){
 try{
  $q=$p->prepare("UPDATE sms_outbox SET status='PROCESSING',attempts=attempts+1,claimed_at=NOW() WHERE id=? AND status='PENDING'");$q->execute([$job['id']]);
  if($q->rowCount()){
   try{$result=sms_provider_send(sms_connection_config($p),$phone,$body);}catch(Throwable $e){$result=['status'=>'UNKNOWN'];}
   $p->prepare("UPDATE sms_outbox SET status=?,provider_id=?,last_error=?,sent_at=IF(?='SENT',NOW(),NULL) WHERE id=?")->execute([$result['status'],$result['id']??null,$result['error']??null,$result['status'],$job['id']]);
  }
 }finally{$p->query("SELECT RELEASE_LOCK('mana_sms_dispatch')");}
}
$q=$p->prepare('SELECT status,last_error FROM sms_outbox WHERE id=?');$q->execute([$job['id']]);$result=$q->fetch();
echo json_encode(['recipient'=>$u['name'],'body'=>$body,'status'=>$result['status'],'error_class'=>str_contains((string)$result['last_error'],'اعتبار')?'credit':($result['last_error']?'provider_other':'none')],JSON_UNESCAPED_UNICODE);
?>'''
source=source.replace('TOKEN_HASH',hashlib.sha256(token.encode()).hexdigest())
f=ftplib.FTP(c['host'],timeout=45);f.login(c['username'],c['password']);f.cwd('/public_html');tag='private-sms-check-'+secrets.token_hex(12);f.mkd(tag)
try:
 f.storbinary('STOR '+tag+'/presenters.txt',io.BytesIO((r/'cpanel/includes/workshop_presenters.php').read_bytes()))
 f.storbinary('STOR '+tag+'/index.php',io.BytesIO(source.encode()))
 request=urllib.request.Request('https://manaclinic.org/'+tag+'/',headers={'X-Diagnostic-Token':token})
 with urllib.request.urlopen(request,timeout=45) as response:print(response.read().decode('utf-8-sig'))
finally:
 f.delete(tag+'/presenters.txt');f.delete(tag+'/index.php');f.rmd(tag);f.quit()
