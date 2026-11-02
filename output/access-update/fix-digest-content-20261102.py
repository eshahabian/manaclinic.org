from pathlib import Path
from ftplib import FTP
import io,json,secrets,time,urllib.request
root=Path('D:/Psychology');cfg=json.loads((root/'.vscode/sftp.json').read_text('utf-8-sig'))
dest=root/'output/access-update/digest-content-fix-20261102';dest.mkdir(exist_ok=True)
token=secrets.token_hex(32);name='sms-digest-fix-'+secrets.token_hex(12)+'.php'
php=r'''<?php
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
if(($_SERVER['REQUEST_METHOD']??'')!=='POST'||time()>__EXPIRY__||!hash_equals('__TOKEN__',$_SERVER['HTTP_X_SETUP_TOKEN']??'')){http_response_code(404);exit;}
try{
$input=json_decode(file_get_contents('php://input'),true,512,JSON_THROW_ON_ERROR);
$c=require __DIR__.'/config.php';date_default_timezone_set($c['timezone']??'Asia/Tehran');
$pdo=new PDO(sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4',$c['db_host'],$c['db_name']),$c['db_user'],$c['db_pass'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
function mail_setting_get(PDO $pdo,string $key,?string $default=null):?string{
 $q=$pdo->prepare('SELECT setting_value FROM app_settings WHERE setting_key=?');$q->execute([$key]);$r=$q->fetch();return $r&&$r['setting_value']!==null?(string)$r['setting_value']:$default;
}
$current=mail_setting_get($pdo,'sms_template_doctor_daily_digest',null);
if($input['action']==='inspect'){
 token_get_all($input['source'],TOKEN_PARSE);
 echo json_encode(['template'=>$current,'syntax_ok'=>true]);exit;
}
if($input['action']!=='apply_and_test')throw new RuntimeException('Unknown action');
require_once __DIR__.'/includes/helpers.php';
if(function_exists('opcache_invalidate'))opcache_invalidate(__DIR__.'/includes/sms_service.php',true);
require_once __DIR__.'/includes/sms_service.php';
$newTemplate=$input['template'];
$pdo->beginTransaction();
$q=$pdo->prepare("SELECT setting_value FROM app_settings WHERE setting_key='sms_template_doctor_daily_digest' FOR UPDATE");$q->execute();$locked=$q->fetch();
$lockedValue=$locked&&$locked['setting_value']!==null?(string)$locked['setting_value']:null;
if($lockedValue!==$input['expected_template'])throw new RuntimeException('Template changed concurrently');
$pdo->prepare('INSERT INTO app_settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)')->execute(['sms_template_doctor_daily_digest',$newTemplate]);
$pdo->commit();
$times=['09:00','10:30','12:00','13:00','14:30','15:30','17:00','18:00','19:00'];
$body=sms_doctor_daily_digest_body('کاربر آزمایشی','۱۱ آبان ۱۴۰۵',$times);
if(str_contains($body,'در تاریخ')||str_contains($body,'ساعت'))throw new RuntimeException('Old labels remain');
foreach($times as $v)if(!str_contains($body,$v))throw new RuntimeException('Missing time');
// Exercise the actual renderer above 500 characters, without enqueueing.
$longTimes=[];for($i=0;$i<96;$i++)$longTimes[]=sprintf('%02d:%02d',intdiv($i,4),($i%4)*15);
$longBody=sms_doctor_daily_digest_body('کاربر آزمایشی','۱۱ آبان ۱۴۰۵',$longTimes);
if(mb_strlen($longBody)<500||!str_contains($longBody,'23:45'))throw new RuntimeException('Long digest lost its tail');
$p=sms_connection_config($pdo);
$marker=__DIR__.'/storage/__MARKER__';
$handle=fopen($marker,'x');if($handle===false)throw new RuntimeException('Final test already claimed; do not resend');fclose($handle);
$fields=['From'=>(string)$p['sender'],'ToNumber'=>'09302323986','Content'=>$body];
$ch=curl_init('https://api.payamakapi.ir/api/v1/SMS/Send');
curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>25,CURLOPT_HTTPHEADER=>['Content-Type: application/json','Accept: application/json','X-API-KEY: '.$p['api_key']],CURLOPT_POSTFIELDS=>json_encode($fields,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
$raw=curl_exec($ch);$http=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$error=curl_error($ch);curl_close($ch);
echo json_encode(['template_updated'=>true,'body'=>$body,'chars'=>mb_strlen($body),'long_render_chars'=>mb_strlen($longBody),'all_96_times_preserved'=>true,'http'=>$http,'curl_error'=>$error,'raw'=>$raw,'parsed'=>sms_provider_parse_response((string)$raw,$http,(string)$p['sender'])],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
}catch(Throwable $e){if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();http_response_code(500);echo json_encode(['error'=>$e->getMessage()]);}
'''.replace('__EXPIRY__',str(int(time.time())+600)).replace('__TOKEN__',token).replace('__MARKER__','sms-test-'+secrets.token_hex(16)+'.claim')
def get(ftp,path):
 b=io.BytesIO();ftp.retrbinary('RETR '+path,b.write);return b.getvalue()
def call(action,**kwargs):
 req=urllib.request.Request('https://manaclinic.org/'+name,data=json.dumps(dict(action=action,**kwargs)).encode(),headers={'X-Setup-Token':token,'Content-Type':'application/json'},method='POST')
 with urllib.request.urlopen(req,timeout=75) as res:return json.load(res)
with FTP() as ftp:
 ftp.connect(cfg['host'],int(cfg.get('port',21)),timeout=45);ftp.login(cfg['username'],cfg['password']);ftp.cwd('/public_html')
 remote='includes/sms_service.php';original=get(ftp,remote);local=(root/'cpanel'/remote).read_bytes()
 baseline=(root/'output/access-update/sms-body-capacity-20261102/before-includes_sms_service.php').read_bytes().decode('utf-8')
 # Compare against the exact source deployed in the preceding capacity fix.
 prior=local.decode('utf-8').replace('نوبت های رزرو شده {date}', 'نوبت های رزرو شده در تاریخ {date}',1)
 prior=prior.replace("    // Provider rejects the old date/time labels; keep every time on its own line.\n    $slots = $times;", "    $slots = array_map(static fn(string $time): string => 'ساعت ' . $time, $times);")
 prior=prior.replace('نوبت های رزرو شده " . $dayLabel','نوبت های رزرو شده در تاریخ " . $dayLabel',1)
 if prior.replace('\r\n','\n')!=original.decode('utf-8').replace('\r\n','\n'):raise RuntimeError('Live SMS source changed; merge required')
 (dest/'before-sms_service.php').write_bytes(original)
 ht=get(ftp,'.htaccess');modified=ht.replace(b'RewriteEngine On',b'RewriteEngine On\nRewriteRule ^'+name.replace('.',r'\.').encode()+b'$ '+name.encode()+b' [END]',1)
 if ht==modified:raise RuntimeError('No route insertion point')
 try:
  ftp.storbinary('STOR '+name,io.BytesIO(php.encode()));ftp.storbinary('STOR .htaccess',io.BytesIO(modified))
  before=call('inspect',source=local.decode('utf-8'))
  (dest/'template-backup.json').write_text(json.dumps(before,ensure_ascii=False),encoding='utf-8')
  old=before['template']
  new=(old if old and old.strip() else '{doctor} گرامی\nنوبت های رزرو شده در تاریخ {date} :\n{slots}').replace('در تاریخ ','').replace('ساعت ','')
  if get(ftp,remote)!=original:raise RuntimeError('Source changed concurrently')
  ftp.storbinary('STOR '+remote,io.BytesIO(local));assert get(ftp,remote)==local
  result=call('apply_and_test',expected_template=old,template=new)
  (dest/'result.json').write_text(json.dumps(result,ensure_ascii=False,indent=2),encoding='utf-8')
  print(json.dumps(result,ensure_ascii=True),flush=True)
 finally:
  if get(ftp,'.htaccess')!=modified:raise RuntimeError('Routing changed concurrently')
  ftp.storbinary('STOR .htaccess',io.BytesIO(ht));ftp.delete(name);assert get(ftp,'.htaccess')==ht
  print('Temporary helper removed; routing verified.',flush=True)
