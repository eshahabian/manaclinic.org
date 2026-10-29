from pathlib import Path
import ftplib,json,io,secrets,hashlib,urllib.request,time
root=Path.cwd();d=root/'output/access-update/sms-20-foreign';c=json.loads((root/'.vscode/sftp.json').read_text(encoding='utf-8-sig'))
files=['includes/sms_service.php','pages/admin/sms_settings.php','pages/admin/sms_doctor_digest.php','pages/cron_sms.php']
token=secrets.token_hex(32);tag='private-nightly-'+secrets.token_hex(16)
php=r"""<?php
declare(strict_types=1);
if(time()>EXPIRY || !hash_equals('TOKEN_HASH',hash('sha256',$_SERVER['HTTP_X_DIAGNOSTIC_TOKEN']??''))){http_response_code(404);exit;}
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');date_default_timezone_set('Asia/Tehran');
$files=['includes/sms_service.php','pages/admin/sms_settings.php','pages/admin/sms_doctor_digest.php','pages/cron_sms.php'];$phase=$_GET['phase']??'';
if($phase==='tests'){foreach($files as $i=>$file)token_get_all(file_get_contents(__DIR__.'/stage-'.$i.'.txt'),TOKEN_PARSE);eval(substr(file_get_contents(__DIR__.'/tests.txt'),5));exit;}
$root=dirname(__DIR__);foreach($files as $file)if(function_exists('opcache_invalidate'))opcache_invalidate($root.'/'.$file,true);
$config=require $root.'/config.php';foreach(['db','helpers','mail','outreach'] as $file)require_once $root.'/includes/'.$file.'.php';$pdo=db_connect($config);$GLOBALS['pdo']=$pdo;
$keys=['sms_kind_enabled_doctor_appointment_booked','sms_kind_enabled_doctor_daily_digest','sms_kind_enabled_therapist_copy'];
if($phase==='settings'){ $out=[];foreach($keys as $key)$out[$key]=mail_setting_get($pdo,$key,null);echo json_encode($out);exit;}
if($phase==='restore'){$old=json_decode(file_get_contents('php://input'),true);foreach($keys as $key){if($old[$key]===null)mail_setting_delete($pdo,$key);else mail_setting_set($pdo,$key,$old[$key]);}echo json_encode(['restored'=>true]);exit;}
if($phase==='apply'){mail_setting_set($pdo,$keys[0],'0');mail_setting_set($pdo,$keys[1],'1');mail_setting_set($pdo,$keys[2],'1');}
$hashes=[];foreach($files as $file)$hashes[$file]=hash_file('sha256',$root.'/'.$file);
$cli=[];foreach(['/opt/alt/php83/usr/bin/php','/opt/cpanel/ea-php83/root/usr/bin/php','/usr/local/bin/php','/usr/bin/php'] as $path)if(is_file($path)&&is_executable($path))$cli[]=$path;
$copy=[];foreach(['dprofile001mana','8e8e603d798ddbd2e8177cf0'] as $id)$copy[$id]=sms_doctor_digest_copy_allowed($pdo,$id);
$sample=sms_doctor_daily_digest_body('عطیه گارسچی','۱۲ مرداد ۱۴۰۵',['10:00','11:00','13:00','14:00']);
echo json_encode(['hashes'=>$hashes,'sample'=>$sample,'booking_to_doctor'=>sms_kind_enabled($pdo,'doctor_appointment_booked'),'nightly_enabled'=>sms_kind_enabled($pdo,'doctor_daily_digest'),'copy_enabled'=>sms_kind_enabled($pdo,'therapist_copy'),'copy_profiles'=>$copy,'cli'=>$cli,'root'=>$root,'php_version'=>PHP_VERSION,'worker_last'=>mail_setting_get($pdo,'sms_worker_last_run',''),'schedule'=>sms_doctor_digest_window(new DateTimeImmutable('today 20:00:00',new DateTimeZone('Asia/Tehran'))),'foreign_rejected'=>sms_iran_mobile('+447700900123')===null,'now'=>date('Y-m-d H:i:s')],JSON_UNESCAPED_UNICODE);
""".replace('TOKEN_HASH',hashlib.sha256(token.encode()).hexdigest()).replace('EXPIRY',str(int(time.time())+900))
f=ftplib.FTP(c['host'],timeout=45);f.login(c['username'],c['password']);f.cwd('/public_html');f.mkd(tag);changed=False;settings=None;original={}
def request(phase,data=None):
 req=urllib.request.Request('https://manaclinic.org/'+tag+'/?phase='+phase,data=json.dumps(data).encode() if data is not None else None,headers={'X-Diagnostic-Token':token,'Content-Type':'application/json'})
 with urllib.request.urlopen(req,timeout=60) as resp:return json.loads(resp.read().decode('utf-8-sig'))
try:
 for i,file in enumerate(files):
  buf=io.BytesIO();f.retrbinary('RETR '+file,buf.write);original[file]=buf.getvalue()
  expected=json.loads((root/'output/access-update/sms-nightly/verification.json').read_text(encoding='utf-8'))['hashes'][file]
  assert hashlib.sha256(original[file]).hexdigest()==expected,'Live drift '+file
  (d/('worker-fix-before-'+file.replace('/','_'))).write_bytes(original[file])
  f.storbinary('STOR '+tag+'/stage-'+str(i)+'.txt',io.BytesIO((root/'cpanel'/file).read_bytes()))
 f.storbinary('STOR '+tag+'/test-helpers.txt',io.BytesIO((root/'cpanel/includes/helpers.php').read_bytes()))
 f.storbinary('STOR '+tag+'/tests.txt',io.BytesIO((d/'tests.php').read_bytes()))
 f.storbinary('STOR '+tag+'/index.php',io.BytesIO(php.encode()))
 test=request('tests');(d/'tests.json').write_text(json.dumps(test,ensure_ascii=False,indent=2),encoding='utf-8');print(json.dumps(test,ensure_ascii=True),flush=True)
 assert test['checks']>=35 and test['provider_calls']==0
 settings=request('settings');(d/'settings-before.json').write_text(json.dumps(settings),encoding='utf-8')
 changed=True
 for file in files:f.storbinary('STOR '+file,io.BytesIO((root/'cpanel'/file).read_bytes()))
 result=request('verify')
 for file in files:assert result['hashes'][file]==hashlib.sha256((root/'cpanel'/file).read_bytes()).hexdigest(),file
 assert result['schedule']['due_at'].endswith('20:00:00') and result['schedule']['slot']=='night20' and result['foreign_rejected']
 assert result['sample']==test['sample'] and not result['booking_to_doctor'] and all(result['copy_profiles'].values()),result
 result=request('apply');assert result['nightly_enabled'] and result['copy_enabled'] and not result['booking_to_doctor']
 (d/'verification.json').write_text(json.dumps(result,ensure_ascii=False,indent=2),encoding='utf-8');print(json.dumps(result,ensure_ascii=True),flush=True)
except Exception:
 if changed:
  for file,data in original.items():f.storbinary('STOR '+file,io.BytesIO(data))
  if settings is not None:request('restore',settings)
  print('Restored files and settings',flush=True)
 raise
finally:
 for name in f.nlst(tag):
  try:f.delete(name)
  except Exception:pass
 f.rmd(tag);f.quit()
