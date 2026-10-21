from pathlib import Path
import ftplib,io,json,secrets,hashlib,time,subprocess,urllib.request,re
root=Path.cwd();cfg=json.loads((root/'.vscode/sftp.json').read_text(encoding='utf-8-sig'));files=['actions/therapist_guide_submit.php','includes/therapist_guide_questions.php','assets/js/therapist-guide.js','assets/css/therapist-guide.css','includes/home_therapist_guide.php']
backup=root/'output/therapist-guide-live-fix'/('soft-questions-'+time.strftime('%Y%m%d-%H%M%S'));backup.mkdir()
ftp=ftplib.FTP(cfg['host'],timeout=40);ftp.login(cfg['username'],cfg['password']);ftp.cwd('/public_html')
def read(n):
 b=io.BytesIO();ftp.retrbinary('RETR '+n,b.write);return b.getvalue()
def put(n,b):ftp.storbinary('STOR '+n,io.BytesIO(b))
def norm(b):return b.decode('utf-8-sig').replace('\r\n','\n')
originals={};deployed=[];temp=[];folder='guide-release-'+secrets.token_hex(12);token=secrets.token_hex(32);made=False
try:
 for n in files:
  before=read(n);baseline=subprocess.run(['git','show','HEAD:cpanel/'+n],check=True,capture_output=True).stdout
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
  if read(n)!=originals[n]:raise RuntimeError('Remote changed during release: '+n)
  put(n,(root/'cpanel'/n).read_bytes());deployed.append(n);assert read(n)==(root/'cpanel'/n).read_bytes()
 verify()
 with urllib.request.urlopen('https://manaclinic.org/?guide-release='+secrets.token_hex(6),timeout=40) as r:html=r.read().decode('utf-8-sig')
 assert 'therapist-guide.js?v=20261021m' in html
 with urllib.request.urlopen('https://manaclinic.org/assets/js/therapist-guide.js?v=20261021m',timeout=40) as r:live=r.read()
 assert live==(root/'cpanel/assets/js/therapist-guide.js').read_bytes()
 assert b'consentInput' not in live;assert b'version:data.version' in live
 assert 'aria-label="بستن راهنما"><svg' in html
 payload=json.loads(re.search(r'<script type="application/json" id="mana-guide-data">(.*?)</script>',html,re.S).group(1))
 assert payload['version']=='20261021m'
 assert all(len(q)==10 for q in payload['questions'].values())
 with urllib.request.urlopen('https://manaclinic.org/assets/css/therapist-guide.css?v=20261021m',timeout=40) as r:assert r.read()==(root/'cpanel/assets/css/therapist-guide.css').read_bytes()
 report={'status':'PASS','files':files,'version':'20261021m','staged_php_syntax':True,'live_byte_match':True,'neutral_question_copy':True,'centered_svg_close':True}
 (backup/'verification.json').write_text(json.dumps(report,indent=2),encoding='utf-8');print('PASS live question version, 5 paths, SVG close and CSS/JS byte match',flush=True)
except Exception:
 for n in reversed(deployed):
  if read(n)==(root/'cpanel'/n).read_bytes():put(n,originals[n])
 if deployed:
  try:verify()
  except Exception:pass
 raise
finally:
 for n in temp:ftp.delete(n)
 if made:ftp.rmd(folder)
 ftp.quit()
