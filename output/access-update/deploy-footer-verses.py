from pathlib import Path
import ftplib,io,json,subprocess,time,secrets,urllib.request
root=Path.cwd()
files=['includes/footer_verse_bounds.php']+['assets/img/hafez/verse-%02d.png'%i for i in range(27,35)]
cfg=json.loads((root/'.vscode/sftp.json').read_text(encoding='utf-8-sig'))
backup=root/'output'/('footer-verses-before-'+time.strftime('%Y%m%d-%H%M%S'));backup.mkdir()
ftp=ftplib.FTP(cfg['host'],timeout=45);ftp.login(cfg['username'],cfg['password']);ftp.cwd('/public_html')
def read(n):
 b=io.BytesIO();ftp.retrbinary('RETR '+n,b.write);return b.getvalue()
def put(n,b): ftp.storbinary('STOR '+n,io.BytesIO(b))
def norm(b): return b.decode('utf-8-sig').replace('\r\n','\n')
def get(p):
 with urllib.request.urlopen('https://manaclinic.org'+p,timeout=45) as r:return r.read().decode('utf-8-sig')
originals={}
for n in files:
 try: b=read(n)
 except ftplib.error_perm as e:
  if not n.startswith('assets/img/hafez/verse-') or not str(e).startswith('550'):raise
  originals[n]=None;continue
 originals[n]=b;(backup/n.replace('/','_')).write_bytes(b)
 head=subprocess.run(['git','show','HEAD:cpanel/'+n],capture_output=True,check=True).stdout

 if norm(head)!=norm(b):raise RuntimeError('Remote changed; merge required: '+n)
 print('Baseline OK: '+n,flush=True)
tag='workshop-verify-'+secrets.token_hex(10);ftp.mkd(tag);staged=[];deployed=[]
try:
 for i,n in enumerate(files):
  if not n.endswith('.php'):continue
  t=tag+'/'+str(i)+'.txt';put(t,(root/'cpanel'/n).read_bytes());staged.append(t)
 harness=b'<?php header("Content-Type: text/plain; charset=utf-8"); try { foreach(glob(__DIR__."/*.txt") as $f) { token_get_all(file_get_contents($f), TOKEN_PARSE); } echo "PASS PHP syntax"; } catch(Throwable $e) { http_response_code(500); echo $e->getMessage(); }'
 put(tag+'/index.php',harness);staged.append(tag+'/index.php')
 assert get('/'+tag+'/').strip()=='PASS PHP syntax'
 print('PASS PHP syntax',flush=True)
 for n in files:
  b=(root/'cpanel'/n).read_bytes();put(n,b);deployed.append(n)
  assert read(n)==b
  print('Deployed: '+n,flush=True)
 invalidate = '<?php header("Content-Type: text/plain"); $files = ' + '[' + ','.join("'"+n+"'" for n in files) + ']; foreach ($files as $f) { if(function_exists("opcache_invalidate")) opcache_invalidate(dirname(__DIR__)."/".$f, true); } echo "cache refreshed";'
 cachetag=tag+'-cache';ftp.mkd(cachetag)
 try:
  put(cachetag+'/index.php',invalidate.encode())
  print(get('/'+cachetag+'/'),flush=True)
 finally:
  ftp.delete(cachetag+'/index.php');ftp.rmd(cachetag)
 time.sleep(2)
 home=get('/?workshop-check='+secrets.token_hex(4));archive=get('/workshops?check='+secrets.token_hex(4))
 import re
 def cards(s):return re.findall(r'data-workshop-banner\s+data-title="([^"]*)"',s)
 h=cards(home);a=cards(archive)
 assert len(h)<=3,(len(h),h)
 assert h==a[:3],(h,a)
 assert '/workshops' in home
 assert 'id="home-workshop-modal"' in archive
 (backup/'verification.json').write_text(json.dumps({'home_cards':h,'archive_cards':a,'syntax':'PASS'},ensure_ascii=False,indent=2),encoding='utf-8')
 print('PASS public home/archive: '+str(len(h))+' / '+str(len(a))+' cards; order matches',flush=True)
 response=get('/doctor/workshops'); assert 'member-patient' not in response
 print('PASS anonymous access does not expose member form',flush=True)
except Exception:
 for n in reversed(deployed):
  if originals[n] is None:ftp.delete(n)
  else:put(n,originals[n])
 raise
finally:
 for n in staged:ftp.delete(n)
 ftp.rmd(tag);ftp.quit()
