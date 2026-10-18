from pathlib import Path
from ftplib import FTP
import io,json,secrets,time,urllib.request,hashlib
root=Path('D:/Psychology')
cfg=json.loads((root/'.vscode/sftp.json').read_text('utf-8-sig'))
token=secrets.token_hex(32);name='sms-setup-'+secrets.token_hex(12)+'.php'
helper=r'''<?php
header('Content-Type: application/json');header('Cache-Control: no-store');
if($_SERVER['REQUEST_METHOD']!=='POST'||time()>__EXPIRY__||!hash_equals('__TOKEN__',$_SERVER['HTTP_X_SETUP_TOKEN']??'')){http_response_code(404);exit;}
try{
 $config=require __DIR__.'/config.php';date_default_timezone_set($config['timezone']??'Asia/Tehran');
 foreach(['db','helpers','mail','outreach','workshop_sessions','login_otp'] as $file)require_once __DIR__.'/includes/'.$file.'.php';
 $pdo=db_connect($config);ensure_mail_schema($pdo);ensure_workshop_sessions_schema($pdo);ensure_outreach_schema($pdo);login_otp_schema($pdo);
 // Migrate and plan now while dispatch remains disabled by the incomplete configuration.
 sms_run_worker($pdo);mail_setting_set($pdo,'sms_worker_last_run',date('Y-m-d H:i:s'));
 $result=['schema'=>true,'worker'=>true,'cron'=>false,'shell_available'=>is_callable('exec')];
 if(is_callable('exec')){
  $php=null;
  foreach(['/opt/alt/php83/usr/bin/php','/opt/cpanel/ea-php83/root/usr/bin/php','/usr/local/bin/php','/usr/bin/php'] as $candidate){
   if(!is_file($candidate)||!is_executable($candidate))continue;
   $output=[];$rc=1;exec(escapeshellarg($candidate).' -r '.escapeshellarg('echo PHP_MAJOR_VERSION>=8 && PHP_SAPI==="cli" ? "CLI_OK" : "NO";').' 2>/dev/null',$output,$rc);
   if($rc===0&&implode('',$output)==='CLI_OK'){$php=$candidate;break;}
  }
  $result['cli_found']=$php!==null;
  if($php){
   $command=escapeshellarg($php).' '.escapeshellarg(__DIR__.'/sms-worker.php').' > /dev/null 2>&1';
   $existing=[];$rc=1;exec('crontab -l 2>/dev/null',$existing,$rc);
   $result['crontab_read_exit']=$rc;
   // Refuse unknown errors; code 1 is the standard empty crontab result.
   if($rc===0||($rc===1&&!$existing)){
    $present=false;foreach($existing as $line)if(str_contains($line,__DIR__.'/sms-worker.php'))$present=true;
    if(!$present){
     $temp=tempnam(sys_get_temp_dir(),'mana_sms_cron_');
     try{file_put_contents($temp,implode("\n",$existing)."\n* * * * * ".$command."\n");$out=[];exec('crontab '.escapeshellarg($temp).' 2>/dev/null',$out,$rc);}finally{unlink($temp);}
    }
    $verify=[];exec('crontab -l 2>/dev/null',$verify,$rc);
    foreach($verify as $line)if(str_contains($line,$command)&&preg_match('/^\* \* \* \* \* /',$line))$result['cron']=true;
   }
  }
 }
 mail_setting_set($pdo,'sms_cron_installed',$result['cron']?'1':'0');
 echo json_encode($result);
}catch(Throwable $e){http_response_code(500);echo json_encode(['error'=>'Setup did not complete']);}
'''.replace('__TOKEN__',token).replace('__EXPIRY__',str(int(time.time())+600))
with FTP() as ftp:
 ftp.connect(cfg['host'],int(cfg.get('port',21)),timeout=45);ftp.login(cfg['username'],cfg['password']);ftp.cwd('/public_html')
 original=io.BytesIO();ftp.retrbinary('RETR .htaccess',original.write);original=original.getvalue()
 modified=original.replace(b'RewriteEngine On',b'RewriteEngine On\nRewriteRule ^'+name.replace('.',r'\.').encode()+b'$ '+name.encode()+b' [END]',1)
 if modified==original:raise RuntimeError('No safe setup route insertion point')
 try:
  ftp.storbinary('STOR '+name,io.BytesIO(helper.encode()))
  ftp.storbinary('STOR .htaccess',io.BytesIO(modified))
  request=urllib.request.Request('https://manaclinic.org/'+name,data=b'',headers={'X-Setup-Token':token},method='POST')
  with urllib.request.urlopen(request,timeout=90) as response:
   result=json.load(response)
   print(json.dumps(result),flush=True)
   (root/'output/access-update/sms-host-status.json').write_text(json.dumps(result))
 finally:
  ftp.storbinary('STOR .htaccess',io.BytesIO(original))
  check=io.BytesIO();ftp.retrbinary('RETR .htaccess',check.write)
  if check.getvalue()!=original:raise RuntimeError('Restore verification failed')
  ftp.delete(name)
  print('Temporary setup route removed; original routing verified.',flush=True)
