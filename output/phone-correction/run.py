from pathlib import Path
import ftplib,io,json,secrets,time,urllib.request
root=Path('D:/Psychology');cfg=json.loads((root/'.vscode/sftp.json').read_text('utf-8-sig'))
body=(root/'output/phone-correction/task.php').read_text('utf-8-sig')
tag='phone-maint-'+secrets.token_hex(12);token=secrets.token_hex(32)
guard="<?php if(time()>"+str(int(time.time())+300)+" || !hash_equals('"+token+"',$_SERVER['HTTP_X_VERIFY_TOKEN']??'')){http_response_code(404);exit;} header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store'); "
with ftplib.FTP() as ftp:
 ftp.connect(cfg['host'],int(cfg.get('port',21)),timeout=45);ftp.login(cfg['username'],cfg['password']);ftp.cwd('/public_html');ftp.mkd(tag)
 try:
  ftp.storbinary('STOR '+tag+'/index.php',io.BytesIO((guard+body).encode()))
  req=urllib.request.Request('https://manaclinic.org/'+tag+'/',headers={'X-Verify-Token':token})
  print(urllib.request.urlopen(req,timeout=60).read().decode('utf-8-sig'))
 finally:
  for name in ['index.php','error_log']:
   try:ftp.delete(tag+'/'+name)
   except ftplib.error_perm:pass
  ftp.rmd(tag)

