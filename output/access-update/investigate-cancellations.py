from pathlib import Path
from ftplib import FTP
import io,json,secrets,time,urllib.request,hashlib
root=Path('D:/Psychology')
cfg=json.loads((root/'.vscode/sftp.json').read_text('utf-8-sig'))
token=secrets.token_hex(32);name='sms-setup-'+secrets.token_hex(12)+'.php'
helper='<?php\nheader(\'Content-Type: application/json\');header(\'Cache-Control: no-store\');\nif($_SERVER[\'REQUEST_METHOD\']!==\'POST\'||time()>__EXPIRY__||!hash_equals(\'__TOKEN__\',$_SERVER[\'HTTP_X_SETUP_TOKEN\']??\'\')){http_response_code(404);exit;}\ntry{\n$config=require __DIR__.\'/config.php\';date_default_timezone_set($config[\'timezone\']??\'Asia/Tehran\');require_once __DIR__.\'/includes/db.php\';$pdo=db_connect($config);\n$pdo->exec(\'SET TRANSACTION READ ONLY\');$pdo->beginTransaction();\n$out=[\'now\'=>date(\'Y-m-d H:i:s\')];\n$queries=[\n\'user\'=>"SELECT id,username,name,role,RIGHT(phone,4) phone_suffix FROM users WHERE LOWER(username)=\'eshahabian\'",\n\'sms_summary\'=>"SELECT kind,status,COUNT(*) n,MIN(created_at) first_created,MAX(sent_at) last_sent FROM sms_outbox WHERE created_at>=DATE_SUB(NOW(),INTERVAL 2 DAY) GROUP BY kind,status",\n\'copies\'=>"SELECT s.id,s.kind,s.status,s.created_at,s.sent_at,s.body,s.context_json FROM sms_outbox s JOIN users u ON s.phone=u.phone WHERE LOWER(u.username)=\'eshahabian\' ORDER BY s.created_at DESC LIMIT 65",\n\'cancelled\'=>"SELECT a.id,a.status,a.starts_at,a.created_at,a.updated_at,a.cancelled_at,a.cancel_reason,a.cancelled_by_user_id,actor.name actor_name,(SELECT COUNT(*) FROM payments p WHERE p.appointment_id=a.id AND p.status=\'PAID\') paid_count FROM appointments a LEFT JOIN users actor ON actor.id=a.cancelled_by_user_id WHERE a.status=\'CANCELLED\' ORDER BY a.updated_at DESC LIMIT 65",\n\'notifications\'=>"SELECT n.title,n.body,n.created_at,n.kind FROM notifications n JOIN users u ON u.id=n.recipient_user_id WHERE LOWER(u.username)=\'eshahabian\' ORDER BY n.created_at DESC LIMIT 15",\n\'logs\'=>"SELECT l.action,l.target_id,l.created_at,u.name actor FROM secretary_action_log l LEFT JOIN users u ON u.id=l.user_id WHERE l.created_at>=DATE_SUB(NOW(),INTERVAL 2 DAY) AND (l.action LIKE \'%cancel%\' OR l.action LIKE \'%reject%\') ORDER BY l.created_at DESC LIMIT 40"\n];foreach($queries as $k=>$q){try{$out[$k]=$pdo->query($q)->fetchAll(PDO::FETCH_ASSOC);}catch(Throwable $e){$out[$k]=[\'error\'=>$e->getMessage()];}}\n$pdo->rollBack();echo json_encode($out,JSON_UNESCAPED_UNICODE);\n}catch(Throwable $e){http_response_code(500);echo json_encode([\'error\'=>$e->getMessage()]);}\n'.replace('__TOKEN__',token).replace('__EXPIRY__',str(int(time.time())+600))
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
   (root/'output/access-update/cancellation-investigation.json').write_text(json.dumps(result))
 finally:
  ftp.storbinary('STOR .htaccess',io.BytesIO(original))
  check=io.BytesIO();ftp.retrbinary('RETR .htaccess',check.write)
  if check.getvalue()!=original:raise RuntimeError('Restore verification failed')
  ftp.delete(name)
  print('Temporary setup route removed; original routing verified.',flush=True)
