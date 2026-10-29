from pathlib import Path
import ftplib,json,io,secrets,hashlib,urllib.request,time,sys
root=Path.cwd();d=root/'output/access-update/room-range';c=json.loads((root/'.vscode/sftp.json').read_text(encoding='utf-8-sig'));token=secrets.token_hex(32);tag='private-rooms-'+secrets.token_hex(16);file='pages/staff_app.php';data=(root/'cpanel'/file).read_bytes();digest=hashlib.sha256(data).hexdigest()
f=ftplib.FTP(c['host'],timeout=45);f.login(c['username'],c['password']);f.cwd('/public_html');f.mkd(tag);changed=False
php=r"""<?php
if(time()>EXPIRY||!hash_equals('HASH',hash('sha256',$_SERVER['HTTP_X_DIAGNOSTIC_TOKEN']??''))){http_response_code(404);exit;}
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');date_default_timezone_set('Asia/Tehran');
if(($_GET['phase']??'')==='tests'){eval(substr(file_get_contents(__DIR__.'/tests.txt'),5));exit;}
$p=dirname(__DIR__).'/pages/staff_app.php';if(function_exists('opcache_invalidate'))opcache_invalidate($p,true);echo json_encode(['hash'=>hash_file('sha256',$p)]);
""".replace('HASH',hashlib.sha256(token.encode()).hexdigest()).replace('EXPIRY',str(int(time.time())+600))
def req(phase):
 q=urllib.request.Request('https://manaclinic.org/'+tag+'/?phase='+phase,headers={'X-Diagnostic-Token':token})
 with urllib.request.urlopen(q,timeout=45) as r:raw=r.read().decode('utf-8-sig')
 try:return json.loads(raw)
 except json.JSONDecodeError:(d/'test-error.txt').write_text(raw,encoding='utf-8');raise
try:
 buf=io.BytesIO();f.retrbinary('RETR '+file,buf.write);original=buf.getvalue();assert original.replace(b'\r\n',b'\n')==(d/'baseline.php').read_bytes().replace(b'\r\n',b'\n'),'Live page changed'
 (d/'before-live.php').write_bytes(original)
 for name,content in [('page.txt',data),('baseline.txt',(d/'baseline.php').read_bytes()),('clock.txt',(root/'cpanel/includes/clinic_rooms.php').read_bytes()),('tests.txt',(d/'tests.php').read_bytes()),('index.php',php.encode())]:f.storbinary('STOR '+tag+'/'+name,io.BytesIO(content))
 if '--test-only' in sys.argv:
  res=req('tests');assert res['checks']>=58;res['hash']=digest;(d/'tests.json').write_text(json.dumps(res,ensure_ascii=False),encoding='utf-8');print(json.dumps({'checks':res['checks'],'fixtures_saved':True}),flush=True)
 else:
  assert json.loads((d/'tests.json').read_text(encoding='utf-8'))['hash']==digest
  assert json.loads((d/'visual.json').read_text())['passed']
  changed=True;f.storbinary('STOR '+file,io.BytesIO(data));res=req('verify');assert res['hash']==digest;(d/'verification.json').write_text(json.dumps(res));print('Deployed and verified room tabs',flush=True)
except Exception:
 if changed:f.storbinary('STOR '+file,io.BytesIO(original));req('verify');print('Restored original page',flush=True)
 raise
finally:
 for name in ['page.txt','baseline.txt','tests.txt','clock.txt','index.php','error_log']:
  try:f.delete(tag+'/'+name)
  except ftplib.error_perm:pass
 f.rmd(tag);f.quit()
