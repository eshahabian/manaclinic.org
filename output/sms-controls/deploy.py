from pathlib import Path
import ftplib,io,json,secrets,time,urllib.request
root=Path('D:/Psychology')
files=['includes/sms_service.php','actions/admin_manage.php','actions/login_otp_request.php','pages/admin/sms_notify.php','includes/admin_panel.php','includes/doctor_panel.php','pages/staff_app.php']
cfg=json.loads((root/'.vscode/sftp.json').read_text('utf-8-sig'))
tag='sms-controls-'+secrets.token_hex(12);token=secrets.token_hex(32)
backup=root/'output/sms-controls'/('remote-before-'+time.strftime('%Y%m%d-%H%M%S'));backup.mkdir()
def normalize(b):return b.decode('utf-8-sig').replace('\r\n','\n')
with ftplib.FTP() as ftp:
 ftp.connect(cfg['host'],int(cfg.get('port',21)),timeout=45);ftp.login(cfg['username'],cfg['password']);ftp.cwd('/public_html')
 # Remove the previous failed verification directory's PHP error log only.
 old='sms-controls-79645188897ba57bab40e7bf'
 try:
  entries=ftp.nlst(old)
  for entry in entries:
   if entry.rsplit('/',1)[-1]=='error_log':ftp.delete(old+'/error_log')
  ftp.rmd(old)
 except ftplib.error_perm:pass
 def get(n):
  b=io.BytesIO();ftp.retrbinary('RETR '+n,b.write);return b.getvalue()
 def put(n,b):ftp.storbinary('STOR '+n,io.BytesIO(b))
 originals={n:get(n) for n in files}
 for n,b in originals.items():
  (backup/n.replace('/','_')).write_bytes(b)
  if normalize(b)!=normalize((root/'output/sms-controls/app-baseline'/n).read_bytes()):raise RuntimeError('Remote changed: '+n)
 print('PASS remote baselines match all seven files',flush=True)
 ftp.mkd(tag);staged=[];deployed=[]
 guard="<?php if(time()>"+str(int(time.time())+900)+" || !hash_equals('"+token+"',$_SERVER['HTTP_X_VERIFY_TOKEN']??'')){http_response_code(404);exit;} "
 def request(mode):
  req=urllib.request.Request('https://manaclinic.org/'+tag+'/',headers={'X-Verify-Token':token,'X-Verify-Mode':mode})
  with urllib.request.urlopen(req,timeout=90) as r:return r.read().decode('utf-8-sig')
 try:
  for i,n in enumerate(files):
   path=tag+'/'+str(i)+'.txt';put(path,(root/'cpanel'/n).read_bytes());staged.append(path)
  tests=(root/'output/sms-controls/tests.php').read_text('utf-8-sig').removeprefix('<?php')
  put(tag+'/tests.txt',tests.encode());staged.append(tag+'/tests.txt')
  harness="if(($_SERVER['HTTP_X_VERIFY_MODE']??'')==='syntax'){foreach(glob(__DIR__.'/*.txt') as $f){token_get_all((basename($f)==='tests.txt'?'<?php ':'').file_get_contents($f),TOKEN_PARSE);}echo 'PASS PHP syntax';}elseif(($_SERVER['HTTP_X_VERIFY_MODE']??'')==='test'){eval(file_get_contents(__DIR__.'/tests.txt'));}elseif(($_SERVER['HTTP_X_VERIFY_MODE']??'')==='cache'){foreach("+json.dumps(files)+" as $f){if(function_exists('opcache_invalidate'))opcache_invalidate(dirname(__DIR__).'/'.$f,true);}echo 'PASS cache';}"
  put(tag+'/index.php',(guard+harness).encode());staged.append(tag+'/index.php')
  assert request('syntax')=='PASS PHP syntax';print('PASS PHP syntax',flush=True)
  result=request('test');assert result.startswith('PASS ') and 'SMS controls checks' in result,result;print(result,flush=True)
  staged.append(tag+'/preview.html')
  (root/'output/sms-controls/preview.html').write_bytes(get(tag+'/preview.html'))
  for n in files:
   put(n,(root/'cpanel'/n).read_bytes());deployed.append(n);assert get(n)==(root/'cpanel'/n).read_bytes()
  assert request('cache')=='PASS cache';print('PASS deployed seven files, byte verification, cache refreshed',flush=True)
  (backup/'verification.txt').write_text(result+'\nPHP syntax PASS\nDeployment byte verification PASS',encoding='utf-8')
 except Exception:
  for n in reversed(deployed):put(n,originals[n])
  raise
 finally:
  for n in reversed(staged):
   try:ftp.delete(n)
   except ftplib.error_perm:pass
  try:ftp.delete(tag+'/error_log')
  except ftplib.error_perm:pass
  ftp.rmd(tag)
  print('Temporary verification files removed',flush=True)
