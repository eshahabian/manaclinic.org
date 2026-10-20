from pathlib import Path
import ftplib,json,io,secrets,urllib.request,hashlib
r=Path.cwd();c=json.loads((r/'.vscode/sftp.json').read_text(encoding='utf-8-sig'));token=secrets.token_hex(32)
source=r'''<?php
if(!hash_equals('TOKEN_HASH',hash('sha256',$_SERVER['HTTP_X_DIAGNOSTIC_TOKEN']??''))){http_response_code(403);exit;}
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
$root=dirname(__DIR__);$config=require $root.'/config.php';require $root.'/includes/db.php';require $root.'/includes/helpers.php';require $root.'/includes/user_phone.php';$p=db_connect($config);
$groups=[];foreach($p->query("SELECT id,name,username,role,phone,is_disabled,created_at FROM users WHERE phone IS NOT NULL AND phone<>''")->fetchAll() as $u){$k=user_phone_key((string)$u['phone']);if($k!==null)$groups[$k][]=$u;}
$out=['groups'=>[]];
foreach($groups as $k=>$users){if(count($users)<2)continue;$g=['phone_suffix'=>substr((string)$k,-4),'accounts'=>[]];foreach($users as $u){unset($u['phone']);foreach(['appointments'=>'patient_id','workshop_enrollments'=>'patient_id','wallets'=>'user_id','doctor_profiles'=>'user_id'] as $t=>$col){$q=$p->prepare("SELECT COUNT(*) FROM `$t` WHERE `$col`=?");$q->execute([$u['id']]);$u[$t.'_count']=(int)$q->fetchColumn();}$g['accounts'][]=$u;}$out['groups'][]=$g;}
$out['references']=$p->query("SELECT TABLE_NAME,COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE REFERENCED_TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME='users'")->fetchAll();
echo json_encode($out,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);
?>'''
source=source.replace('TOKEN_HASH',hashlib.sha256(token.encode()).hexdigest())
f=ftplib.FTP(c['host'],timeout=45);f.login(c['username'],c['password']);f.cwd('/public_html');tag='private-sms-check-'+secrets.token_hex(12);f.mkd(tag)
try:
 f.storbinary('STOR '+tag+'/index.php',io.BytesIO(source.encode()))
 request=urllib.request.Request('https://manaclinic.org/'+tag+'/',headers={'X-Diagnostic-Token':token})
 with urllib.request.urlopen(request,timeout=45) as response:data=response.read().decode('utf-8-sig')
 (r/'output/access-update/duplicate-users-audit.json').write_text(data,encoding='utf-8');print(data)
finally:
 f.delete(tag+'/index.php');f.rmd(tag);f.quit()
