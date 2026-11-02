from pathlib import Path
from ftplib import FTP
import io,json,secrets,time,urllib.request,subprocess
root=Path('D:/Psychology');cfg=json.loads((root/'.vscode/sftp.json').read_text('utf-8-sig'))
dest=root/'output/access-update/digest-patient-names-20261102';dest.mkdir(exist_ok=True)
token=secrets.token_hex(32);suffix=secrets.token_hex(12);name='digest-names-'+suffix+'.php';stage='storage/digest-names-'+suffix+'.php'
php=r'''<?php
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
if(($_SERVER['REQUEST_METHOD']??'')!=='POST'||time()>__EXPIRY__||!hash_equals('__TOKEN__',$_SERVER['HTTP_X_SETUP_TOKEN']??'')){http_response_code(404);exit;}
try{
$input=json_decode(file_get_contents('php://input'),true,512,JSON_THROW_ON_ERROR);
foreach(['__STAGE__','__STAGE__.admin.php'] as $path)token_get_all(file_get_contents(__DIR__.'/'.$path),TOKEN_PARSE);
require_once __DIR__.'/includes/helpers.php';
function mail_setting_get(PDO $pdo,string $key,?string $default=null):?string{
 if($pdo instanceof DigestNamesPDO)return $default;
 $q=$pdo->prepare('SELECT setting_value FROM app_settings WHERE setting_key=?');$q->execute([$key]);$r=$q->fetch();return $r&&$r['setting_value']!==null?(string)$r['setting_value']:$default;
}
class DigestNamesStatement extends PDOStatement{
 public array $rows;public array $params=[];
 public function __construct(array $rows){$this->rows=$rows;}
 public function execute(?array $params=null):bool{$this->params=$params??[];return true;}
 public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args):array{return array_values(array_filter($this->rows,fn($r)=>count($this->params)<2||in_array($r['doctor_id'],array_slice($this->params,1),true)));}
}
class DigestNamesPDO extends PDO{
 public function __construct(public array $rows){}
 public function prepare(string $query,array $options=[]):PDOStatement|false{
  foreach(['patient.name AS patient_name','patient.id = a.patient_id',"a.status = 'CONFIRMED'",'a.doctor_id'] as $required)if(!str_contains($query,$required))throw new RuntimeException('Query missing required scope');
  return new DigestNamesStatement($this->rows);
 }
}
if($input['mode']==='test'){
 require_once __DIR__.'/__STAGE__';
 $pdo=new DigestNamesPDO([
  ['doctor_id'=>'d1','doctor_name'=>'درمانگر یک','doctor_phone'=>'09120000001','starts_at'=>'2026-11-03 09:30:00','patient_name'=>"مراجع\nاول"],
  ['doctor_id'=>'d1','doctor_name'=>'درمانگر یک','doctor_phone'=>'09120000001','starts_at'=>'2026-11-03 09:30:00','patient_name'=>'مراجع دوم'],
  ['doctor_id'=>'d1','doctor_name'=>'درمانگر یک','doctor_phone'=>'09120000001','starts_at'=>'2026-11-03 09:30:00','patient_name'=>'مراجع دوم'],
  ['doctor_id'=>'d1','doctor_name'=>'درمانگر یک','doctor_phone'=>'09120000001','starts_at'=>'2026-11-03 10:30:00','patient_name'=>''],
  ['doctor_id'=>'d2','doctor_name'=>'درمانگر دو','doctor_phone'=>'09120000002','starts_at'=>'2026-11-03 11:00:00','patient_name'=>'مراجع سوم'],
 ]);
 $packs=sms_doctor_digests_for_date($pdo,'2026-11-03');$checks=[];
 $checks['two_doctors']=count($packs)===2;
 $checks['same_time_and_identical_labels_preserved']=$packs[0]['slot_count']===4&&count($packs[0]['slots'])===4&&substr_count($packs[0]['body'],'09:30 — مراجع دوم')===2;
 $checks['name_whitespace_normalized']=str_contains($packs[0]['body'],'09:30 — مراجع اول');
 $checks['missing_name_reported']=str_contains($packs[0]['body'],'10:30 — نام ثبت نشده');
 $checks['patients_scoped_to_doctor']=!str_contains($packs[0]['body'],'مراجع سوم')&&!str_contains($packs[1]['body'],'مراجع اول');
 $checks['doctor_filter']=count(sms_doctor_digests_for_date($pdo,'2026-11-03',['d2']))===1;
 $checks['empty_selection']=sms_doctor_digests_for_date($pdo,'2026-11-03',[])===[];
 $checks['no_filtered_labels']=!str_contains($packs[0]['body'],'در تاریخ')&&!str_contains($packs[0]['body'],'ساعت');
 $checks['legacy_time_only_call']=str_contains(sms_doctor_daily_digest_body('نمونه','روز',['10:00','09:00','10:00']),"09:00\n10:00");
 if(in_array(false,$checks,true))throw new RuntimeException('Behavior checks failed: '.json_encode($checks));
 echo json_encode(['ok'=>true,'checks'=>$checks,'sample'=>$packs[0]['body'],'syntax_ok'=>true],JSON_UNESCAPED_UNICODE);exit;
}
if($input['mode']!=='live')throw new RuntimeException('Unknown mode');
if(function_exists('opcache_invalidate')){opcache_invalidate(__DIR__.'/includes/sms_service.php',true);opcache_invalidate(__DIR__.'/pages/admin/sms_doctor_digest.php',true);}
require_once __DIR__.'/includes/sms_service.php';
$c=require __DIR__.'/config.php';date_default_timezone_set($c['timezone']??'Asia/Tehran');
$pdo=new PDO(sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4',$c['db_host'],$c['db_name']),$c['db_user'],$c['db_pass'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$pdo->exec("SET time_zone='+03:30'");$pdo->exec('SET TRANSACTION READ ONLY');$pdo->beginTransaction();
$packs=sms_doctor_digests_for_date($pdo,date('Y-m-d'));$summary=[];
foreach($packs as $p){foreach($p['slots'] as $line)if(!preg_match('/^\d{2}:\d{2} — .+/u',$line))throw new RuntimeException('Live slot lacks patient name');$summary[]=['appointments'=>$p['slot_count'],'named_slots'=>count($p['slots']),'body_chars'=>mb_strlen($p['body'])];}
$pdo->rollBack();echo json_encode(['ok'=>true,'live_readonly_preview'=>$summary,'sms_sent'=>false]);
}catch(Throwable $e){if(isset($pdo)&&!($pdo instanceof DigestNamesPDO)&&$pdo->inTransaction())$pdo->rollBack();http_response_code(500);echo json_encode(['error'=>$e->getMessage()]);}
'''.replace('__TOKEN__',token).replace('__EXPIRY__',str(int(time.time())+900)).replace('__STAGE__',stage)
files=['includes/sms_service.php','pages/admin/sms_doctor_digest.php']
def get(f,p):
 b=io.BytesIO();f.retrbinary('RETR '+p,b.write);return b.getvalue()
def call(mode,**kwargs):
 req=urllib.request.Request('https://manaclinic.org/'+name,data=json.dumps(dict(mode=mode,**kwargs)).encode(),headers={'X-Setup-Token':token,'Content-Type':'application/json'},method='POST')
 with urllib.request.urlopen(req,timeout=75) as r:return json.load(r)
with FTP() as f:
 f.connect(cfg['host'],int(cfg.get('port',21)),timeout=60);f.login(cfg['username'],cfg['password']);f.cwd('/public_html')
 originals={p:get(f,p) for p in files};local={p:(root/'cpanel'/p).read_bytes() for p in files}
 for p in files:
  baseline=subprocess.run(['git','show','HEAD:cpanel/'+p],cwd=root,check=True,capture_output=True).stdout
  if originals[p].replace(b'\r\n',b'\n')!=baseline.replace(b'\r\n',b'\n'):raise RuntimeError('Live baseline differs for '+p)
  (dest/('before-'+p.replace('/','_'))).write_bytes(originals[p])
 ht=get(f,'.htaccess');mod=ht.replace(b'RewriteEngine On',b'RewriteEngine On\nRewriteRule ^'+name.replace('.',r'\.').encode()+b'$ '+name.encode()+b' [END]',1)
 if mod==ht:raise RuntimeError('No safe route insertion point')
 deployed=[]
 try:
  f.storbinary('STOR '+stage,io.BytesIO(local[files[0]]));f.storbinary('STOR '+stage+'.admin.php',io.BytesIO(local[files[1]]));f.storbinary('STOR '+name,io.BytesIO(php.encode()));f.storbinary('STOR .htaccess',io.BytesIO(mod))
  tests=call('test');print(json.dumps(tests,ensure_ascii=True),flush=True)
  (dest/'tests.json').write_text(json.dumps(tests,ensure_ascii=False,indent=2),encoding='utf-8')
  if not tests.get('ok'):raise RuntimeError('Tests failed')
  for p in files:
   if get(f,p)!=originals[p]:raise RuntimeError('Concurrent live source change')
   f.storbinary('STOR '+p,io.BytesIO(local[p]));deployed.append(p);assert get(f,p)==local[p]
  verified=call('live');(dest/'live-verification.json').write_text(json.dumps(verified,indent=2),encoding='utf-8');print(json.dumps(verified),flush=True)
 except:
  for p in deployed:f.storbinary('STOR '+p,io.BytesIO(originals[p]))
  raise
 finally:
  if get(f,'.htaccess')!=mod:raise RuntimeError('Concurrent routing change')
  f.storbinary('STOR .htaccess',io.BytesIO(ht));f.delete(name);f.delete(stage);f.delete(stage+'.admin.php');assert get(f,'.htaccess')==ht
  print('Temporary helpers removed; routing verified.',flush=True)
