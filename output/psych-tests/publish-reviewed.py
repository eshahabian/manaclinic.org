"""Publish the audited, tested changes with exact concurrent-change protection."""
from pathlib import Path
import ftplib,io,json,hashlib,secrets,time,urllib.request
root=Path('D:/Psychology')
audit_dir=sorted((root/'output/psych-tests').glob('remote-audit-*'))[-1]
audit=json.loads((audit_dir/'audit.json').read_text())
assert 'PASS 143 assessment checks' in (audit_dir/'tests.txt').read_text()
files=[n for n in audit if n.endswith(('.php','.css','.js'))]
digest=lambda b:hashlib.sha256(b).hexdigest()
for n in files:assert digest((root/'cpanel'/n).read_bytes())==audit[n]['local_hash'],'Local changed after testing: '+n
cfg=json.loads((root/'.vscode/sftp.json').read_text('utf-8-sig'))
tag='assessment-cache-'+secrets.token_hex(12);token=secrets.token_hex(32)
with ftplib.FTP() as ftp:
 ftp.connect(cfg['host'],int(cfg.get('port',21)),timeout=45);ftp.login(cfg['username'],cfg['password']);ftp.cwd('/public_html')
 def get(n):
  b=io.BytesIO();ftp.retrbinary('RETR '+n,b.write);return b.getvalue()
 def optional(n):
  try:return get(n)
  except ftplib.error_perm as ex:
   if str(ex).startswith('550'):return None
   raise
 def put(n,b):ftp.storbinary('STOR '+n,io.BytesIO(b))
 old={}
 for n in files:
  b=optional(n);h=digest(b) if b is not None else None
  assert h in [audit[n]['remote_hash'],audit[n]['local_hash']],'Production changed since audit: '+n
  old[n]=b
 print('PASS exact reviewed remote and tested local fingerprints',flush=True)
 deployed=[]
 ftp.mkd(tag)
 guard="<?php if(time()>"+str(int(time.time())+600)+" || !hash_equals('"+token+"',$_SERVER['HTTP_X_VERIFY_TOKEN']??'')){http_response_code(404);exit;} "
 cache="foreach(array("+','.join(json.dumps(n) for n in files)+") as $f)if(function_exists('opcache_invalidate'))opcache_invalidate(dirname(__DIR__).'/'.$f,true);echo 'PASS cache';"
 try:
  for n in files:
   local=(root/'cpanel'/n).read_bytes()
   if old[n]!=local:
    put(n,local);deployed.append(n);assert get(n)==local
  put(tag+'/index.php',(guard+cache).encode())
  req=urllib.request.Request('https://manaclinic.org/'+tag+'/',headers={'X-Verify-Token':token})
  assert urllib.request.urlopen(req,timeout=60).read().decode()=='PASS cache'
  for slug in ['bdi','bai','dass-21','ghq','scl-90','pss','neo','swls','mmpi','ybocs']:
   html=urllib.request.urlopen('https://manaclinic.org/tests/'+slug,timeout=60).read().decode('utf-8-sig')
   if slug=='bdi':assert 'data-bdi-questionnaire' in html and html.count('class="bdi-option"')==90
   else:assert 'این آزمون هنوز برای اجرا فعال نشده است.' in html and 'data-bdi-score' not in html and 'data-bdi-ai' not in html and 'همراه هوشمند' not in html
  html=urllib.request.urlopen('https://manaclinic.org/tests',timeout=60).read().decode('utf-8-sig')
  assert html.count('class="psych-test-tile-image"')==10
  (audit_dir/'publication.txt').write_text('PASS reviewed publication, exact byte verification, live ten-tile listing, BDI active, other nine disabled',encoding='utf-8')
  print('PASS published; ten images in tiles; BDI active; other nine forms and AI hidden',flush=True)
 except Exception:
  for n in reversed(deployed):
   if old[n] is not None:put(n,old[n])
   else:ftp.delete(n)
  raise
 finally:
  for n in ['index.php','error_log']:
   try:ftp.delete(tag+'/'+n)
   except ftplib.error_perm:pass
  ftp.rmd(tag)
  print('Temporary cache endpoint removed',flush=True)
