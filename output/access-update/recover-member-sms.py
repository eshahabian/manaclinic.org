from pathlib import Path
import ftplib,json,io,secrets,urllib.request,hashlib
r=Path.cwd();c=json.loads((r/'.vscode/sftp.json').read_text(encoding='utf-8-sig'));token=secrets.token_hex(32)
source=r'''<?php
if(!hash_equals('TOKEN_HASH',hash('sha256',$_SERVER['HTTP_X_DIAGNOSTIC_TOKEN']??''))){http_response_code(403);exit;}
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
$root=dirname(__DIR__);$config=require $root.'/config.php';date_default_timezone_set('Asia/Tehran');require $root.'/includes/db.php';require $root.'/includes/helpers.php';require $root.'/includes/mail.php';require $root.'/includes/outreach.php';$p=db_connect($config);
$u=$p->query("SELECT id FROM users WHERE LOWER(username)='eshahabian' OR (name LIKE '%عماد%' AND name LIKE '%شهابیان%') LIMIT 1")->fetch();$out=['account_found'=>(bool)$u,'sms_connection_ready'=>sms_connection_ready($p)];
if($u){
$s=$p->prepare("SELECT w.*,e.id AS enrollment_id,e.status AS enrollment_status FROM workshop_enrollments e JOIN workshops w ON w.id=e.workshop_id WHERE e.patient_id=? ORDER BY e.enrolled_at DESC LIMIT 1");$s->execute([$u['id']]);$e=$s->fetch();
if($e && $e['enrollment_status']==='CONFIRMED') {
$s=$p->prepare("SELECT status,attempts FROM sms_outbox WHERE kind='workshop_registered' AND context_json LIKE ? ORDER BY created_at DESC LIMIT 1");$s->execute(['%"id":"'.$e['enrollment_id'].'"%']);$job=$s->fetch();
if($job && $job['status']==='FAILED' && (int)$job['attempts']===1){
 require $root.'/includes/workshops.php';
 $s=$p->prepare('SELECT phone FROM users WHERE id=?');$s->execute([$u['id']]);$phone=(string)$s->fetchColumn();
 $detailsHash=hash('sha256',json_encode([$e['starts_at'],$e['location'],$e['type'],$e['title']]));
 $fingerprint=hash('sha256',json_encode([$e['enrollment_status'],$detailsHash,$e['status']]));
 $key='enrollment:'.$e['enrollment_id'].':registered:'.$fingerprint;
 $context=['entity'=>'enrollment','id'=>$e['enrollment_id'],'starts_at'=>$e['starts_at'],'details_hash'=>$detailsHash];
 $s=$p->prepare("UPDATE sms_outbox SET body=? WHERE dedup_key=? AND status='FAILED' AND attempts=1");$s->execute([workshop_member_sms_body((string)$e['title']),hash('sha256',$key)]);
 $result=sms_send_immediate($p,$phone,workshop_member_sms_body((string)$e['title']),'workshop_registered',$key,date('Y-m-d H:i:s',time()+86400),$context);
 $out=['retry_performed'=>true,'status'=>$result['status'],'accepted'=>!empty($result['ok']),'error_class'=>str_contains((string)($result['error']??''),'اعتبار')?'credit':'other'];
}else{$out=['retry_performed'=>false,'status'=>$job['status']??'MISSING'];}
}else{$out=['retry_performed'=>false,'reason'=>'no active membership'];}
}
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
