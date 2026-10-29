from pathlib import Path
import ftplib,json,io,secrets,hashlib,urllib.request
root=Path.cwd(); backup=root/'output/access-update/emad-full-access'
c=json.loads((root/'.vscode/sftp.json').read_text(encoding='utf-8-sig'))
files=['includes/staff_app.php','includes/petty_cash.php','pages/staff_app.php','includes/session_complaints.php']
token=secrets.token_hex(32); tag='private-access-'+secrets.token_hex(16)
php=r"""<?php
declare(strict_types=1);
if(!hash_equals('HASH',hash('sha256',$_SERVER['HTTP_X_DIAGNOSTIC_TOKEN']??''))){http_response_code(403);exit;}
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
$root=dirname(__DIR__);$config=require $root.'/config.php';
if(($_GET['phase']??'')!=='preflight' && function_exists('opcache_invalidate')){foreach(['includes/staff_app.php','includes/petty_cash.php','pages/staff_app.php','includes/session_complaints.php'] as $file)opcache_invalidate($root.'/'.$file,true);}
require_once $root.'/includes/db.php';$pdo=db_connect($config);$GLOBALS['pdo']=$pdo;
$files=['includes/staff_app.php','includes/petty_cash.php','pages/staff_app.php','includes/session_complaints.php'];
$users=$pdo->query("SELECT id,username,name,role,is_disabled FROM users WHERE LOWER(username)='eshahabian'")->fetchAll();
if(count($users)!==1 || !str_contains($users[0]['name'],'عماد') || !str_contains($users[0]['name'],'شهابیان') || (int)$users[0]['is_disabled']!==0 || $users[0]['role']!=='ADMIN'){http_response_code(409);echo json_encode(['error'=>'account verification failed','accounts'=>$users],JSON_UNESCAPED_UNICODE);exit;}
if(($_GET['phase']??'')==='preflight'){
 foreach($files as $i=>$file){token_get_all(file_get_contents(__DIR__.'/stage-'.$i.'.txt'),TOKEN_PARSE);}
 echo json_encode(['account'=>$users[0]['username'],'name'=>$users[0]['name'],'role'=>$users[0]['role'],'syntax'=>'PASS'],JSON_UNESCAPED_UNICODE);exit;
}
foreach($files as $file){if(function_exists('opcache_invalidate'))opcache_invalidate($root.'/'.$file,true);}
require_once $root.'/includes/helpers.php';require_once $root.'/includes/auth.php';require_once $root.'/includes/staff_app.php';require_once $root.'/includes/petty_cash.php';require_once $root.'/includes/session_complaints.php';
$u=$users[0];$tests=['app'=>staff_app_allowed($u),'petty_view'=>petty_cash_staff_app_allowed($u),'petty_write'=>petty_cash_user_can_enter($u),'feedback_review'=>session_feedback_can_review($u)];
foreach(['/app','/app/appointments','/app/rooms','/app/chat','/app/hours','/app/consult','/app/checklist','/app/petty-cash','/app/complaints','/app/balances','/app/therapists','/app/sms','/app/therapist-guide','/video-call'] as $path){$tests[$path]=staff_app_home_tile_allowed($u,$path);}
$doctor=['id'=>'access-test','username'=>'ordinary-doctor','name'=>'Ordinary Doctor','role'=>'DOCTOR'];
$tests['doctor_stays_limited']=!staff_app_path_allowed($doctor,'/app/rooms','GET')&&!staff_app_home_tile_allowed($doctor,'/app/balances')&&!petty_cash_staff_app_allowed($doctor)&&!session_feedback_can_review($doctor);
$tests['patient_stays_denied']=!staff_app_allowed(['username'=>'ordinary-patient','role'=>'PATIENT','name'=>'Ordinary Patient']);
$hashes=[];foreach($files as $file)$hashes[$file]=hash_file('sha256',$root.'/'.$file);
echo json_encode(['account'=>$u['username'],'tests'=>$tests,'hashes'=>$hashes],JSON_UNESCAPED_UNICODE);
""".replace('HASH',hashlib.sha256(token.encode()).hexdigest())
f=ftplib.FTP(c['host'],timeout=45);f.login(c['username'],c['password']);f.cwd('/public_html');f.mkd(tag)
original={}; changed=False

def request(phase):
 req=urllib.request.Request('https://manaclinic.org/'+tag+'/?phase='+phase,headers={'X-Diagnostic-Token':token})
 with urllib.request.urlopen(req,timeout=60) as resp:return json.loads(resp.read().decode('utf-8-sig'))
try:
 for i,file in enumerate(files):
  buf=io.BytesIO();f.retrbinary('RETR '+file,buf.write);original[file]=buf.getvalue();(backup/file.replace('/','_')).write_bytes(original[file])
  local=(root/'cpanel'/file).read_bytes()
  # Abort if unrelated live differences would be overwritten.
  import subprocess
  baseline=subprocess.check_output(['git','show','HEAD:cpanel/'+file],cwd=root)
  assert original[file].replace(b'\r\n',b'\n')==baseline.replace(b'\r\n',b'\n'),'Live drift: '+file
  f.storbinary('STOR '+tag+'/stage-'+str(i)+'.txt',io.BytesIO(local))
 f.storbinary('STOR '+tag+'/index.php',io.BytesIO(php.encode()))
 print(json.dumps(request('preflight'),ensure_ascii=False),flush=True)
 changed=True
 for file in files:f.storbinary('STOR '+file,io.BytesIO((root/'cpanel'/file).read_bytes()))
 result=request('verify')
 assert all(result['tests'].values()),result
 for file in files:assert result['hashes'][file]==hashlib.sha256((root/'cpanel'/file).read_bytes()).hexdigest(),file
 (backup/'verification.json').write_text(json.dumps(result,ensure_ascii=False,indent=2),encoding='utf-8')
 print(json.dumps(result,ensure_ascii=False),flush=True)
except Exception:
 if changed:
  for file,data in original.items():f.storbinary('STOR '+file,io.BytesIO(data))
  try:request('verify')
  except Exception:pass
  print('Restored originals',flush=True)
 raise
finally:
 for name in f.nlst(tag):
  try:f.delete(name)
  except Exception:pass
 try:f.rmd(tag)
 except Exception:pass
 f.quit()
