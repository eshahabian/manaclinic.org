from pathlib import Path
import ftplib,json,io,secrets,hashlib,urllib.request,time,sys
root=Path.cwd();d=root/'output/access-update/booking-assist';c=json.loads((root/'.vscode/sftp.json').read_text(encoding='utf-8-sig'))
files=['pages/secretary/book.php','index.php','includes/booking_room_availability.php','actions/room_availability.php','assets/js/booking-assist.js'];token=secrets.token_hex(32);tag='private-booking-'+secrets.token_hex(16)
f=ftplib.FTP(c['host'],timeout=45);f.login(c['username'],c['password']);f.cwd('/public_html');f.mkd(tag);original={};changed=False
php=r"""<?php
if(time()>EXPIRY||!hash_equals('HASH',hash('sha256',$_SERVER['HTTP_X_DIAGNOSTIC_TOKEN']??''))){http_response_code(404);exit;}
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');date_default_timezone_set('Asia/Tehran');
if(($_GET['phase']??'')==='tests'){eval(substr(file_get_contents(__DIR__.'/tests.txt'),5));exit;}
$root=dirname(__DIR__);$out=[];foreach(['pages/secretary/book.php','index.php','includes/booking_room_availability.php','actions/room_availability.php','assets/js/booking-assist.js'] as $file){if(function_exists('opcache_invalidate')&&str_ends_with($file,'.php'))opcache_invalidate($root.'/'.$file,true);$out[$file]=is_file($root.'/'.$file)?hash_file('sha256',$root.'/'.$file):null;}echo json_encode(['hashes'=>$out]);
""".replace('HASH',hashlib.sha256(token.encode()).hexdigest()).replace('EXPIRY',str(int(time.time())+900))
def req(phase):
 q=urllib.request.Request('https://manaclinic.org/'+tag+'/?phase='+phase,headers={'X-Diagnostic-Token':token})
 with urllib.request.urlopen(q,timeout=45) as r:raw=r.read().decode('utf-8-sig')
 try:return json.loads(raw)
 except json.JSONDecodeError:(d/'test-error.txt').write_text(raw,encoding='utf-8');raise

def put_atomic(file,content):
 tmp=file+'.deploy-'+token[:12];f.storbinary('STOR '+tmp,io.BytesIO(content));f.rename(tmp,file)
try:
 for i,file in enumerate(files):
  buf=io.BytesIO()
  try:f.retrbinary('RETR '+file,buf.write);data=buf.getvalue()
  except ftplib.error_perm as e:
   if i<2 or not str(e).startswith('550'):raise
   data=None
  original[file]=data
  if i<2:assert data.replace(b'\r\n',b'\n')==(d/('baseline-'+file.replace('/','_'))).read_bytes().replace(b'\r\n',b'\n'),'Live drift '+file
  elif data is not None:raise RuntimeError('New file already exists '+file)
  if data is not None:(d/('before-'+file.replace('/','_'))).write_bytes(data)
  f.storbinary('STOR '+tag+'/file-'+str(i)+'.txt',io.BytesIO((root/'cpanel'/file).read_bytes()))
 for name,content in [('tests.txt',(d/'tests.php').read_bytes()),('session.txt',(root/'cpanel/includes/appointment_session.php').read_bytes()),('index.php',php.encode())]:f.storbinary('STOR '+tag+'/'+name,io.BytesIO(content))
 hashes={file:hashlib.sha256((root/'cpanel'/file).read_bytes()).hexdigest() for file in files}
 if '--test-only' in sys.argv:
  res=req('tests');assert res['checks']>=19;res['hashes']=hashes;(d/'tests.json').write_text(json.dumps(res,ensure_ascii=False),encoding='utf-8');print(json.dumps({'checks':res['checks'],'fixtures_saved':True}),flush=True)
 else:
  assert json.loads((d/'tests.json').read_text(encoding='utf-8'))['hashes']==hashes
  assert json.loads((d/'visual.json').read_text())['passed']
  changed=True
  for i in [2,3,4,0,1]:put_atomic(files[i],(root/'cpanel'/files[i]).read_bytes())
  res=req('verify');assert res['hashes']==hashes;(d/'verification.json').write_text(json.dumps(res,indent=2));print('Deployed and hash-verified booking assistance',flush=True)
except Exception:
 if changed:
  for file,data in original.items():
   if data is None:
    try:f.delete(file)
    except ftplib.error_perm:pass
   else:put_atomic(file,data)
  req('verify');print('Restored original files',flush=True)
 raise
finally:
 for name in ['file-'+str(i)+'.txt' for i in range(len(files))]+['tests.txt','session.txt','index.php','error_log']:
  try:f.delete(tag+'/'+name)
  except ftplib.error_perm:pass
 f.rmd(tag);f.quit()
