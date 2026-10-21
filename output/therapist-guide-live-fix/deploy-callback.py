from pathlib import Path
import ftplib,io,json,secrets,hashlib,time,subprocess,urllib.request,re
root=Path.cwd();cfg=json.loads((root/'.vscode/sftp.json').read_text(encoding='utf-8-sig'));files=['includes/consult_requests.php','actions/consult_request_done.php','actions/consult_request_reopen.php','actions/therapist_guide_callback.php','index.php','assets/js/therapist-guide.js','includes/home_therapist_guide.php']
backup=root/'output/therapist-guide-live-fix'/('callback-'+time.strftime('%Y%m%d-%H%M%S'));backup.mkdir()
ftp=ftplib.FTP(cfg['host'],timeout=40);ftp.login(cfg['username'],cfg['password']);ftp.cwd('/public_html')
def read(n):
 b=io.BytesIO();ftp.retrbinary('RETR '+n,b.write);return b.getvalue()
def put(n,b):ftp.storbinary('STOR '+n,io.BytesIO(b))
def norm(b):return b.decode('utf-8-sig').replace('\r\n','\n')
originals={};deployed=[];temp=[];folder='guide-release-'+secrets.token_hex(12);token=secrets.token_hex(32);made=False
try:
 for n in files:
  try:before=read(n)
  except ftplib.error_perm as e:
   if n=='actions/therapist_guide_callback.php' and str(e).startswith('550'):originals[n]=None;continue
   raise
  prior=root/'output/therapist-guide-live-fix/before-20261021-072844'/n.replace('/','_')
  baseline=prior.read_bytes() if prior.exists() else subprocess.run(['git','show','HEAD:cpanel/'+n],check=True,capture_output=True).stdout
  if norm(before)!=norm(baseline):raise RuntimeError('Remote baseline changed: '+n)
  originals[n]=before;(backup/n.replace('/','_')).write_bytes(before)
 ftp.mkd(folder);made=True
 for i,n in enumerate(files):
  if n.endswith('.php'):
   target=folder+'/'+str(i)+'.txt';put(target,(root/'cpanel'/n).read_bytes());temp.append(target)
 paths='['+','.join("'"+n+"'" for n in files)+']'
 php="<?php if(time()>"+str(int(time.time())+600)+" || !hash_equals('"+hashlib.sha256(token.encode()).hexdigest()+"',hash('sha256',$_SERVER['HTTP_X_MANA_VERIFY']??''))){http_response_code(404);exit;}header('Cache-Control:no-store');header('Content-Type:text/plain');try{foreach(glob(__DIR__.'/*.txt') as $f) token_get_all(file_get_contents($f),TOKEN_PARSE);foreach("+paths+" as $f)if(function_exists('opcache_invalidate'))opcache_invalidate(dirname(__DIR__).'/'.$f,true);echo 'PASS';}catch(Throwable $e){http_response_code(500);echo 'FAIL';}"
 put(folder+'/index.php',php.encode());temp.append(folder+'/index.php')
 def verify():
  req=urllib.request.Request('https://manaclinic.org/'+folder+'/?v='+secrets.token_hex(4),headers={'X-Mana-Verify':token})
  with urllib.request.urlopen(req,timeout=40) as r:assert r.read()==b'PASS'
 verify();print('PASS staged PHP parse and baseline',flush=True)
 for n in files:
  if originals[n] is not None and read(n)!=originals[n]:raise RuntimeError('Remote changed during release: '+n)
  put(n,(root/'cpanel'/n).read_bytes());deployed.append(n);assert read(n)==(root/'cpanel'/n).read_bytes()
 verify()
 with urllib.request.urlopen('https://manaclinic.org/?guide-release='+secrets.token_hex(6),timeout=40) as r:html=r.read().decode('utf-8-sig')
 assert 'therapist-guide.js?v=20261021l' in html
 with urllib.request.urlopen('https://manaclinic.org/assets/js/therapist-guide.js?v=20261021l',timeout=40) as r:live=r.read()
 assert live==(root/'cpanel/assets/js/therapist-guide.js').read_bytes()
 assert b'consentInput' not in live;assert b'function callbackForm()' in live;assert b'fetch(data.callback' in live
 report={'status':'PASS','files':files,'version':'20261021l','staged_php_syntax':True,'live_byte_match':True,'optional_callback':True}
 (backup/'verification.json').write_text(json.dumps(report,indent=2),encoding='utf-8');print('PASS live version and optional callback form',flush=True)
except Exception:
 for n in reversed(deployed):
  if read(n)==(root/'cpanel'/n).read_bytes():
   if originals[n] is None:ftp.delete(n)
   else:put(n,originals[n])
 if deployed:
  try:verify()
  except Exception:pass
 raise
finally:
 for n in temp:ftp.delete(n)
 if made:ftp.rmd(folder)
 ftp.quit()
