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
 $c=sms_connection_config($pdo);
 $ch=curl_init('https://api.payamakapi.ir/api/v1/SMS/GetCredit');curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>25,CURLOPT_HTTPHEADER=>['Accept: application/json','X-API-KEY: '.$c['api_key']]]);$raw=curl_exec($ch);$http=curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
 $result=['credit_http'=>$http,'credit_response'=>json_decode((string)$raw,true),'sender'=>$c['sender']];
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
