from pathlib import Path
import ftplib,io,json,subprocess,time,secrets,urllib.request
root=Path.cwd()
files=['includes/user_phone.php', 'includes/workshops.php', 'includes/workshop_add_member_form.php', 'includes/workshop_manage_cards.php', 'includes/workshop_peer_block.php', 'actions/workshop_add_member.php', 'pages/doctor/workshops.php', 'pages/secretary/workshops.php', 'includes/secretary_new_patient_fields.php', 'includes/secretary_patient.php', 'actions/register.php', 'actions/patient_profile.php', 'actions/secretary_users.php', 'assets/css/style.css', 'includes/layout.php']
cfg=json.loads((root/'.vscode/sftp.json').read_text(encoding='utf-8-sig'))
backup=root/'output'/('workshop-controls-before-'+time.strftime('%Y%m%d-%H%M%S'));backup.mkdir()
ftp=ftplib.FTP(cfg['host'],timeout=45);ftp.login(cfg['username'],cfg['password']);ftp.cwd('/public_html')
def read(n):
 b=io.BytesIO();ftp.retrbinary('RETR '+n,b.write);return b.getvalue()
def put(n,b): ftp.storbinary('STOR '+n,io.BytesIO(b))
def norm(b): return b.decode('utf-8-sig').replace('\r\n','\n')
def get(p):
 for attempt in range(3):
  try:
   with urllib.request.urlopen('https://manaclinic.org'+p,timeout=40) as response:return response.read().decode('utf-8-sig')
  except (TimeoutError,urllib.error.URLError) as e:
   if attempt==2:raise
   print('Retrying HTTP verification after transient connection failure',flush=True)

originals={}
for n in files:
 try: b=read(n)
 except ftplib.error_perm as e:
  if n!='includes/user_phone.php' or not str(e).startswith('550'):raise
  originals[n]=None;continue
 originals[n]=b;(backup/n.replace('/','_')).write_bytes(b)
 head=subprocess.run(['git','show','HEAD:cpanel/'+n],capture_output=True,check=True).stdout

 if norm(head)!=norm(b):raise RuntimeError('Remote changed; merge required: '+n)
 print('Baseline OK: '+n,flush=True)
tag='workshop-verify-'+secrets.token_hex(10);ftp.mkd(tag);staged=[];deployed=[]
try:
 print('Reusing successful PHP syntax and member regression checks; source unchanged.',flush=True)
 phonetag=tag+'-phones';ftp.mkd(phonetag)
 try:
  put(phonetag+'/phone.txt',(root/'cpanel/includes/user_phone.php').read_bytes())
  put(phonetag+'/index.php',(root/'output/access-update/user-phone-tests.php').read_bytes())
  results=get('/'+phonetag+'/');assert 'PASS phone regression tests' in results,results
  print(results,flush=True)
 finally:
  ftp.delete(phonetag+'/phone.txt');ftp.delete(phonetag+'/index.php');ftp.rmd(phonetag)
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
 registration=get('/register?check='+secrets.token_hex(4));assert 'data-phone-verification' not in registration;assert 'name="phone" id="phone" required' in registration
 print('PASS registration requires mobile and has no verification OTP',flush=True)
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
