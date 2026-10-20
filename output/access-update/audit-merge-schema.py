from pathlib import Path
import ftplib,json,io,secrets,urllib.request,hashlib
r=Path.cwd();c=json.loads((r/'.vscode/sftp.json').read_text(encoding='utf-8-sig'));token=secrets.token_hex(32)
source=r'''<?php
if(!hash_equals('TOKEN_HASH',hash('sha256',$_SERVER['HTTP_X_DIAGNOSTIC_TOKEN']??''))){http_response_code(403);exit;}
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
$root=dirname(__DIR__);$config=require $root.'/config.php';require $root.'/includes/db.php';require $root.'/includes/helpers.php';require $root.'/includes/user_phone.php';$p=db_connect($config);
$out=[];
$out['columns']=$p->query("SELECT TABLE_NAME,COLUMN_NAME,COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND (COLUMN_NAME REGEXP '(^|_)(user|patient|sender|recipient|actor|author|owner|created_by|updated_by|approved_by|recorded_by)(_id)?$' OR COLUMN_NAME LIKE '%user_id' OR COLUMN_NAME LIKE '%patient_id') ORDER BY TABLE_NAME,ORDINAL_POSITION")->fetchAll();
$out['unique_indexes']=$p->query("SELECT TABLE_NAME,INDEX_NAME,GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS cols FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND NON_UNIQUE=0 GROUP BY TABLE_NAME,INDEX_NAME")->fetchAll();
$out['wallet_schema']=$p->query('SHOW CREATE TABLE wallets')->fetch();
$out['wallet_transaction_schema']=$p->query('SHOW CREATE TABLE wallet_transactions')->fetch();
echo json_encode($out,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);
?>'''
source=source.replace('TOKEN_HASH',hashlib.sha256(token.encode()).hexdigest())
f=ftplib.FTP(c['host'],timeout=45);f.login(c['username'],c['password']);f.cwd('/public_html');tag='private-sms-check-'+secrets.token_hex(12);f.mkd(tag)
try:
 f.storbinary('STOR '+tag+'/index.php',io.BytesIO(source.encode()))
 request=urllib.request.Request('https://manaclinic.org/'+tag+'/',headers={'X-Diagnostic-Token':token})
 with urllib.request.urlopen(request,timeout=45) as response:data=response.read().decode('utf-8-sig')
 (r/'output/access-update/duplicate-users-schema.json').write_text(data,encoding='utf-8');print("Schema audit saved")
finally:
 f.delete(tag+'/index.php');f.rmd(tag);f.quit()
