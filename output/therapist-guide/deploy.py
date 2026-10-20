from pathlib import Path
import ftplib, io, json, subprocess, time, secrets, urllib.request, hashlib, re
root=Path.cwd()
files=['assets/css/therapist-guide.css','assets/js/therapist-guide.js','includes/home_therapist_guide.php','pages/home.php']
backup=root/'output/therapist-guide'/('before-'+time.strftime('%Y%m%d-%H%M%S'));backup.mkdir()
cfg=json.loads((root/'.vscode/sftp.json').read_text(encoding='utf-8-sig'))
ftp=ftplib.FTP(cfg['host'],timeout=45);ftp.login(cfg['username'],cfg['password']);ftp.cwd('/public_html')
def read(n):
 b=io.BytesIO();ftp.retrbinary('RETR '+n,b.write);return b.getvalue()
def put(n,b):ftp.storbinary('STOR '+n,io.BytesIO(b))
def norm(b):return b.decode('utf-8-sig').replace('\r\n','\n')
def get(path,token=None):
 req=urllib.request.Request('https://manaclinic.org'+path,headers={'X-Mana-Verify':token} if token else {})
 with urllib.request.urlopen(req,timeout=45) as r:return r.read().decode('utf-8-sig')
originals={};deployed=[];staged=[];tag='guide-check-'+secrets.token_hex(12);token=secrets.token_hex(24)
try:
 for n in files:
  try:b=read(n)
  except ftplib.error_perm as e:
   if n=='pages/home.php' or not str(e).startswith('550'):raise
   originals[n]=None;continue
  originals[n]=b;(backup/n.replace('/','_')).write_bytes(b)
  if n=='pages/home.php':
   head=subprocess.run(['git','show','HEAD:cpanel/'+n],capture_output=True,check=True).stdout
   if norm(head)!=norm(b):raise RuntimeError('Remote home differs from local baseline; merge required')
  elif b!=(root/'cpanel'/n).read_bytes():raise RuntimeError('New target already exists with other content: '+n)
 print('PASS live baseline; backups saved',flush=True)
 ftp.mkd(tag)
 for i,n in enumerate(files):
  if n.endswith('.php'):
   target=tag+'/'+str(i)+'.txt';put(target,(root/'cpanel'/n).read_bytes());staged.append(target)
 paths='['+','.join("'"+n+"'" for n in files)+']'
 harness="<?php if(time()>"+str(int(time.time())+900)+" || !hash_equals('"+hashlib.sha256(token.encode()).hexdigest()+"', hash('sha256', $_SERVER['HTTP_X_MANA_VERIFY'] ?? ''))) {http_response_code(404);exit;} header('Content-Type:text/plain'); try { foreach(glob(__DIR__.'/*.txt') as $f) token_get_all(file_get_contents($f), TOKEN_PARSE); foreach("+paths+" as $f) if(function_exists('opcache_invalidate')) opcache_invalidate(dirname(__DIR__).'/'.$f,true); echo 'PASS PHP syntax and cache'; } catch(Throwable $e) {http_response_code(500);echo 'FAIL';}"
 put(tag+'/index.php',harness.encode());staged.append(tag+'/index.php')
 assert get('/'+tag+'/',token)=='PASS PHP syntax and cache';print('PASS staged PHP syntax',flush=True)
 for n in files:
  b=(root/'cpanel'/n).read_bytes();put(n,b);deployed.append(n);assert read(n)==b
 assert get('/'+tag+'/?refresh=1',token)=='PASS PHP syntax and cache'
 home=get('/?guide-check='+secrets.token_hex(5))
 markers=['class="home-assistant-tile"','id="home-workshop-heading"','id="mana-guide-title"','class="home-specialists-block"','class="home-articles-block"']
 positions=[home.index(m) for m in markers];assert positions==sorted(positions)
 payload=json.loads(re.search(r'<script type="application/json" id="mana-guide-data">(.*?)</script>',home,re.S).group(1))
 assert set(payload)=={'doctors','courses','domains','focus','directory','workshops','contact'}
 for d in payload['doctors']:assert set(d)=={'name','href','domains','focus'}
 for c in payload['courses']:assert set(c)=={'title'}
 assert 'data-guide-start' in home
 for n in files[:2]:assert get('/'+n+'?v=20261021a')==(root/'cpanel'/n).read_text(encoding='utf8')
 report={'status':'PASS','order':markers,'doctors':len(payload['doctors']),'courses':len(payload['courses']),'files':files}
 (backup/'verification.json').write_text(json.dumps(report,ensure_ascii=False,indent=2),encoding='utf8')
 print('PASS live section order, public data allowlist, CSS/JS byte match; '+str(len(payload['doctors']))+' public clinicians',flush=True)
except Exception:
 for n in reversed(deployed):
  if originals[n] is None:ftp.delete(n)
  else:put(n,originals[n])
 if deployed:
  try:get('/'+tag+'/?rollback=1',token)
  except Exception:pass
 raise
finally:
 for n in staged:
  try:ftp.delete(n)
  except Exception:pass
 if staged:
  ftp.rmd(tag)
  try:ftp.cwd(tag);raise RuntimeError('Temporary verifier remains')
  except ftplib.error_perm:pass
 ftp.quit()
