from pathlib import Path
from ftplib import FTP
import io,json,secrets,time,urllib.request
root=Path('D:/Psychology');cfg=json.loads((root/'.vscode/sftp.json').read_text('utf-8-sig'))
token=secrets.token_hex(32);name='sms-format-'+secrets.token_hex(12)+'.php'
php=r'''<?php
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
if(($_SERVER['REQUEST_METHOD']??'')!=='POST'||time()>__EXPIRY__||!hash_equals('__TOKEN__',$_SERVER['HTTP_X_SETUP_TOKEN']??'')){http_response_code(404);exit;}
try{
$c=require __DIR__.'/config.php';date_default_timezone_set($c['timezone']??'Asia/Tehran');
$pdo=new PDO(sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4',$c['db_host'],$c['db_name']),$c['db_user'],$c['db_pass'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$p=require __DIR__.'/config.sms.php';
$q=$pdo->query("SELECT setting_key,setting_value FROM app_settings WHERE setting_key IN ('sms_api_key','sms_sender','sms_enabled')");
foreach($q as $r){if($r['setting_value']!==null&&$r['setting_value']!=='')$p[substr($r['setting_key'],4)]=$r['setting_value'];}
if(($p['enabled']??'')!=='1'||empty($p['api_key'])||empty($p['sender']))throw new RuntimeException('SMS connection not ready');
require_once __DIR__.'/includes/sms_service.php';
$messages=[
 'date_prefix'=>'در تاریخ',
 'date_words'=>'۱۱ آبان ۱۴۰۵',
 'date_numeric'=>'۱۴۰۵/۰۸/۱۱',
 'newline'=>"کاربر آزمایشی\nگرامی",
 'long_plain'=>str_repeat('کاربر آزمایشی گرامی ',8),
 'time_ascii'=>'ساعت 09:00',
 'time_persian'=>'ساعت ۰۹:۰۰',
 'digest_fixed'=>'',
];
$out=['now'=>date('Y-m-d H:i:s'),'destination_tail'=>'3986','results'=>[]];
foreach($messages as $label=>$body){
 if($out['results'])sleep(16);
 if($label==='digest_fixed'){
  $failed=function($k)use(&$out){$j=json_decode((string)$out['results'][$k]['raw'],true);return ($j['resultCode']??null)===7;};
  $sep=$failed('newline')?' ':"\n";
  $date=$failed('date_words')&&!$failed('date_numeric')?'۱۴۰۵/۰۸/۱۱':'۱۱ آبان ۱۴۰۵';
  $slots=['۰۹:۰۰','۱۰:۳۰','۱۲:۰۰','۱۳:۰۰','۱۴:۳۰','۱۵:۳۰','۱۷:۰۰','۱۸:۰۰','۱۹:۰۰'];
  $body='کاربر آزمایشی گرامی'.$sep.'نوبت های رزرو شده '.($failed('date_prefix')?'':'در تاریخ ').$date.' :'.$sep.implode($sep,array_map(fn($v)=>'ساعت '.$v,$slots));
 }
 $fields=['From'=>(string)$p['sender'],'ToNumber'=>'09302323986','Content'=>$body];
 $ch=curl_init('https://api.payamakapi.ir/api/v1/SMS/Send');
 curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>25,CURLOPT_HTTPHEADER=>['Content-Type: application/json','Accept: application/json','X-API-KEY: '.$p['api_key']],CURLOPT_POSTFIELDS=>json_encode($fields,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)]);
 $raw=curl_exec($ch);$http=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$error=curl_error($ch);curl_close($ch);
 $out['results'][$label]=['body'=>$body,'chars'=>mb_strlen($body,'UTF-8'),'http'=>$http,'curl_error'=>$error,'raw'=>$raw,'site_parsed'=>sms_provider_parse_response((string)$raw,$http,(string)$p['sender'])];
}
echo json_encode($out,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
}catch(Throwable $e){http_response_code(500);echo json_encode(['error'=>$e->getMessage()]);}
'''.replace('__EXPIRY__',str(int(time.time())+600)).replace('__TOKEN__',token)
def get(ftp,path):
 b=io.BytesIO();ftp.retrbinary('RETR '+path,b.write);return b.getvalue()
with FTP() as ftp:
 ftp.connect(cfg['host'],int(cfg.get('port',21)),timeout=45);ftp.login(cfg['username'],cfg['password']);ftp.cwd('/public_html')
 ht=get(ftp,'.htaccess');modified=ht.replace(b'RewriteEngine On',b'RewriteEngine On\nRewriteRule ^'+name.replace('.',r'\.').encode()+b'$ '+name.encode()+b' [END]',1)
 if ht==modified:raise RuntimeError('No safe routing insertion point')
 try:
  ftp.storbinary('STOR '+name,io.BytesIO(php.encode()));ftp.storbinary('STOR .htaccess',io.BytesIO(modified))
  req=urllib.request.Request('https://manaclinic.org/'+name,data=b'',headers={'X-Setup-Token':token},method='POST')
  with urllib.request.urlopen(req,timeout=300) as res:out=json.load(res)
  (root/'output/access-update/test-sms-format-20261102.json').write_text(json.dumps(out,ensure_ascii=False,indent=2),encoding='utf-8')
  print(json.dumps(out,ensure_ascii=True),flush=True)
 finally:
  if get(ftp,'.htaccess')!=modified:raise RuntimeError('Concurrent routing change; cleanup requires review')
  ftp.storbinary('STOR .htaccess',io.BytesIO(ht));ftp.delete(name);assert get(ftp,'.htaccess')==ht
  print('Temporary helper removed; routing verified.',flush=True)
