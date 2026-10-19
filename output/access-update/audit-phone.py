from pathlib import Path
from ftplib import FTP
import io,json,secrets,time,urllib.request,hashlib
root=Path('D:/Psychology')
cfg=json.loads((root/'.vscode/sftp.json').read_text('utf-8-sig'))
token=secrets.token_hex(32);name='sms-setup-'+secrets.token_hex(12)+'.php'
helper=r'''<?php
header('Content-Type: application/json');header('Cache-Control: no-store');
if($_SERVER['REQUEST_METHOD']!=='POST'||time()>__EXPIRY__||!hash_equals('__TOKEN__',$_SERVER['HTTP_X_SETUP_TOKEN']??'')){http_response_code(404);exit;}
try{
 $config=require __DIR__.'/config.php';date_default_timezone_set($config['timezone']??'Asia/Tehran');
 foreach(['db','helpers','mail','outreach','workshop_sessions','login_otp'] as $file)require_once __DIR__.'/includes/'.$file.'.php';
 $pdo=db_connect($config);login_otp_schema($pdo);
 $target='09302323986';
 $result=['matching_users'=>[],'phone_columns'=>[],'limits'=>[],'otp_last_status'=>mail_setting_get($pdo,'sms_otp_last_status',''),'otp_last_error'=>mail_setting_get($pdo,'sms_otp_last_error','')];
 foreach($pdo->query("SELECT id,username,phone FROM users WHERE phone IS NOT NULL") as $u)if(sms_iran_mobile((string)$u['phone'])===$target)$result['matching_users'][]=['id'=>$u['id'],'username'=>$u['username']];
 foreach($pdo->query("SELECT TABLE_NAME,COLUMN_NAME,IS_NULLABLE,DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND (COLUMN_NAME LIKE '%phone%' OR COLUMN_NAME LIKE '%mobile%')") as $c){
  $table=$c['TABLE_NAME'];$col=$c['COLUMN_NAME'];
  if(!preg_match('/^[a-zA-Z0-9_]+$/D',$table.$col))continue;
  $n=0;foreach($pdo->query("SELECT DISTINCT `$col` AS val FROM `$table` WHERE `$col` IS NOT NULL") as $v)if(sms_iran_mobile((string)$v['val'])===$target)$n++;
  if($n)$result['phone_columns'][]=$c;
 }
 $st=$pdo->prepare('SELECT started_at,hits FROM login_otp_limits WHERE bucket=?');$st->execute([hash('sha256','phone:'.$target)]);$result['phone_limit']=$st->fetch();$result['now']=time();
 $result['active_ip_limits']=$pdo->query('SELECT started_at,hits FROM login_otp_limits WHERE hits>=10 ORDER BY started_at DESC LIMIT 5')->fetchAll();
 echo json_encode($result);
}catch(Throwable $e){http_response_code(500);echo json_encode(['error'=>'Setup did not complete']);}
'''.replace('__TOKEN__',token).replace('__EXPIRY__',str(int(time.time())+600))
with FTP() as ftp:
 ftp.connect(cfg['host'],int(cfg.get('port',21)),timeout=45);ftp.login(cfg['username'],cfg['password']);ftp.cwd('/public_html')
 original=io.BytesIO();ftp.retrbinary('RETR .htaccess',original.write);original=original.getvalue()
 modified=original.replace(b'RewriteEngine On',b'RewriteEngine On\nRewriteRule ^'+name.replace('.',r'\.').encode()+b'$ '+name.encode()+b' [END]',1)
 if modified==original:raise RuntimeError('No safe setup route insertion point')
 try:
  ftp.storbinary('STOR '+name,io.BytesIO(helper.encode()))
  ftp.storbinary('STOR .htaccess',io.BytesIO(modified))
  request=urllib.request.Request('https://manaclinic.org/'+name,data=b'',headers={'X-Setup-Token':token},method='POST')
  with urllib.request.urlopen(request,timeout=90) as response:
   result=json.load(response)
   print(json.dumps(result),flush=True)
   (root/'output/access-update/sms-review-status.json').write_text(json.dumps(result))
 finally:
  ftp.storbinary('STOR .htaccess',io.BytesIO(original))
  check=io.BytesIO();ftp.retrbinary('RETR .htaccess',check.write)
  if check.getvalue()!=original:raise RuntimeError('Restore verification failed')
  ftp.delete(name)
  print('Temporary setup route removed; original routing verified.',flush=True)
