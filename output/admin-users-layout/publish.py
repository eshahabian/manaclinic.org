from pathlib import Path
import ftplib,io,json,secrets,time,urllib.request
root=Path('D:/Psychology');work=root/'output/admin-users-layout'
files={'pages/admin/users.php':'users.php','pages/tests.php':'tests.php','assets/css/psych-tests.css':'psych-tests.css','includes/admin_user_search.php':None}
cfg=json.loads((root/'.vscode/sftp.json').read_text('utf-8-sig'))
tag='layout-check-'+secrets.token_hex(10);token=secrets.token_hex(32)
def norm(b):return b.replace(b'\r\n',b'\n') if b is not None else None
with ftplib.FTP() as ftp:
 ftp.connect(cfg['host'],int(cfg.get('port',21)),timeout=45);ftp.login(cfg['username'],cfg['password']);ftp.cwd('/public_html')
 def get(n):
  b=io.BytesIO()
  try:ftp.retrbinary('RETR '+n,b.write);return b.getvalue()
  except ftplib.error_perm as ex:
   if str(ex).startswith('550'):return None
   raise
 def put(n,b):ftp.storbinary('STOR '+n,io.BytesIO(b))
 old={n:get(n) for n in files}
 for n,baseline in files.items():
  expected=(work/'baseline'/baseline).read_bytes() if baseline else None
  assert norm(old[n]) in [norm(expected),norm((root/'cpanel'/n).read_bytes())], 'Remote divergence: '+n
  if old[n] is not None:
   dest=work/'remote-backup'/n;dest.parent.mkdir(parents=True,exist_ok=True);dest.write_bytes(old[n])
 ftp.mkd(tag)
 guard="<?php if(time()>"+str(int(time.time())+600)+" || !hash_equals('"+token+"',$_SERVER['HTTP_X_VERIFY_TOKEN']??'')){http_response_code(404);exit;} "
 checks=r"""
foreach(['users.php','tests.php','search.php'] as $f) token_get_all(file_get_contents(__DIR__.'/'.$f),TOKEN_PARSE);
require __DIR__.'/search.php';
$users=[['id'=>1,'name'=>'علی کیانی','username'=>'Ali.Test','phone'=>'09121234567'],['id'=>2,'name'=>'مریم احمدی','username'=>'maryam','phone'=>'+989351234567']];
$cases=['علي'=>[1],'كياني'=>[1],'علیکیانی'=>[1],'ALI.TEST'=>[1],'۰۹۱۲۱۲۳۴۵۶۷'=>[1],'+98 912 123 4567'=>[1],'00989121234567'=>[1],'۰۹۳۵'=>[2],'mary'=>[2],'ناشناس'=>[],' '=>[1,2],'---'=>[]];
foreach($cases as $q=>$expected){$got=array_column(admin_user_search_filter($users,$q),'id');if($got!==$expected)throw new Exception('Search failed');}
echo 'PASS syntax and 12 search cases';
"""
 deployed=[]
 try:
  for src,dest in [('pages/admin/users.php','users.php'),('pages/tests.php','tests.php'),('includes/admin_user_search.php','search.php')]:put(tag+'/'+dest,(root/'cpanel'/src).read_bytes())
  put(tag+'/index.php',(guard+checks).encode())
  def call():return urllib.request.urlopen(urllib.request.Request('https://manaclinic.org/'+tag+'/',headers={'X-Verify-Token':token}),timeout=60).read().decode()
  result=call();assert result=='PASS syntax and 12 search cases',result;print(result,flush=True)
  for n in files:
   assert get(n)==old[n],'Changed during verification: '+n
  for n in files:
   put(n,(root/'cpanel'/n).read_bytes());deployed.append(n);assert get(n)==(root/'cpanel'/n).read_bytes()
  cache="foreach("+json.dumps(list(files))+" as $f)if(function_exists('opcache_invalidate'))opcache_invalidate(dirname(__DIR__).'/'.$f,true);echo 'PASS cache';"
  put(tag+'/index.php',(guard+cache).encode());assert call()=='PASS cache'
  html=urllib.request.urlopen('https://manaclinic.org/tests',timeout=60).read().decode('utf-8-sig');assert 'class="psych-tests-grid"' in html and html.count('class="psych-test-tile-image"')==10
  css=urllib.request.urlopen('https://manaclinic.org/assets/css/psych-tests.css?v=2',timeout=60).read().decode();assert 'repeat(4,minmax(0,1fr))' in css
  (work/'verification.txt').write_text(result+'; PASS live 10 tiles and four-column CSS; exact deployment verified',encoding='utf-8');print('PASS deployed and live layout verified',flush=True)
 except Exception:
  for n in reversed(deployed):
   if old[n] is None:ftp.delete(n)
   else:put(n,old[n])
  raise
 finally:
  for n in ['index.php','users.php','tests.php','search.php','error_log']:
   try:ftp.delete(tag+'/'+n)
   except ftplib.error_perm:pass
  ftp.rmd(tag)
