from pathlib import Path
import ftplib,json,io,secrets,hashlib,urllib.request,time,sys
root=Path.cwd();d=root/'output/access-update/appointment-parties';c=json.loads((root/'.vscode/sftp.json').read_text(encoding='utf-8-sig'))
files=['includes/staff_app_appointment_parties.php','pages/staff_app.php'];token=secrets.token_hex(32);tag='private-parties-'+secrets.token_hex(16)
f=ftplib.FTP(c['host'],timeout=45);f.login(c['username'],c['password']);f.cwd('/public_html');f.mkd(tag);original={};changed=False
php=r"""<?php
declare(strict_types=1);
if(time()>EXPIRY || !hash_equals('HASH',hash('sha256',$_SERVER['HTTP_X_DIAGNOSTIC_TOKEN']??''))){http_response_code(404);exit;}
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');date_default_timezone_set('Asia/Tehran');
if(($_GET['phase']??'')==='tests'){token_get_all(file_get_contents(__DIR__.'/helper.txt'),TOKEN_PARSE);token_get_all(file_get_contents(__DIR__.'/page.txt'),TOKEN_PARSE);eval(substr(file_get_contents(__DIR__.'/tests.txt'),5));exit;}
$root=dirname(__DIR__);$out=[];foreach(['includes/staff_app_appointment_parties.php','pages/staff_app.php'] as $file){if(function_exists('opcache_invalidate'))opcache_invalidate($root.'/'.$file,true);$out[$file]=hash_file('sha256',$root.'/'.$file);}$config=require $root.'/config.php';foreach(['db','helpers','mail','outreach'] as $file)require_once $root.'/includes/'.$file.'.php';$pdo=db_connect($config);require_once $root.'/includes/staff_app_appointment_parties.php';$day=new DateTimeImmutable('today',new DateTimeZone('Asia/Tehran'));$counts=[];foreach(['debtors','creditors'] as $side)$counts[$side]=count(staff_app_appointment_parties_rows($pdo,$side,$day));echo json_encode(['hashes'=>$out,'live_read_queries'=>true,'row_counts'=>$counts]);
""".replace('HASH',hashlib.sha256(token.encode()).hexdigest()).replace('EXPIRY',str(int(time.time())+600))
def request(phase):
 req=urllib.request.Request('https://manaclinic.org/'+tag+'/?phase='+phase,headers={'X-Diagnostic-Token':token})
 with urllib.request.urlopen(req,timeout=45) as r:return json.loads(r.read().decode('utf-8-sig'))
try:
 for file in files:
  buf=io.BytesIO()
  try:f.retrbinary('RETR '+file,buf.write);data=buf.getvalue()
  except ftplib.error_perm as e:
   if file!=files[0] or not str(e).startswith('550'):raise
   data=None
  original[file]=data
  if file==files[1]:assert data.replace(b'\r\n',b'\n')==(d/'baseline-pages_staff_app.php').read_bytes().replace(b'\r\n',b'\n'),'Live page drift'
  elif data is not None:raise RuntimeError('New helper already exists; merge required')
  if data is not None:(d/('before-'+file.replace('/','_'))).write_bytes(data)
 for name,local in [('helper.txt',root/'cpanel'/files[0]),('page.txt',root/'cpanel'/files[1]),('tests.txt',d/'tests.php')]:f.storbinary('STOR '+tag+'/'+name,io.BytesIO(local.read_bytes()))
 f.storbinary('STOR '+tag+'/index.php',io.BytesIO(php.encode()))
 hashes={file:hashlib.sha256((root/'cpanel'/file).read_bytes()).hexdigest() for file in files}
 if '--test-only' in sys.argv:
  res=request('tests');assert res['checks']>=20;res['hashes']=hashes
  (d/'tests.json').write_text(json.dumps(res,ensure_ascii=False,indent=2),encoding='utf-8')
  print(json.dumps({'checks':res['checks'],'fixtures_saved':True}),flush=True)
 else:
  qa=json.loads((d/'tests.json').read_text(encoding='utf-8'));assert qa['hashes']==hashes,'Source changed since tests'
  assert json.loads((d/'visual.json').read_text())['passed'],'Mobile QA required'
  changed=True
  for file in files:f.storbinary('STOR '+file,io.BytesIO((root/'cpanel'/file).read_bytes()))
  res=request('verify');assert res['hashes']==hashes
  (d/'verification.json').write_text(json.dumps(res,indent=2));print(json.dumps({'deployed':files,'hashes_verified':True}),flush=True)
except Exception:
 if changed:
  for file,data in original.items():
   if data is None:
    try:f.delete(file)
    except ftplib.error_perm:pass
   else:f.storbinary('STOR '+file,io.BytesIO(data))
  request('verify');print('Restored original files',flush=True)
 raise
finally:
 for name in ['helper.txt','page.txt','tests.txt','index.php','error_log']:
  try:f.delete(tag+'/'+name)
  except ftplib.error_perm:pass
 f.rmd(tag);f.quit()
