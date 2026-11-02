from pathlib import Path
from ftplib import FTP
import io, json, secrets, time, urllib.request, hashlib

root=Path('D:/Psychology')
dest=root/'output/access-update/sms-body-capacity-20261102'
dest.mkdir(exist_ok=True)
cfg=json.loads((root/'.vscode/sftp.json').read_text('utf-8-sig'))
token=secrets.token_hex(32); name='sms-capacity-'+secrets.token_hex(12)+'.php'
php=r'''<?php
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
if(($_SERVER['REQUEST_METHOD']??'')!=='POST'||time()>__EXPIRY__||!hash_equals('__TOKEN__',$_SERVER['HTTP_X_SETUP_TOKEN']??'')){http_response_code(404);exit;}
try{
$input=json_decode(file_get_contents('php://input'),true,512,JSON_THROW_ON_ERROR);
foreach(($input['sources']??[]) as $source) token_get_all($source,TOKEN_PARSE);
$c=require __DIR__.'/config.php';
$pdo=new PDO(sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4',$c['db_host'],$c['db_name']),$c['db_user'],$c['db_pass'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$column=$pdo->query("SHOW FULL COLUMNS FROM sms_outbox LIKE 'body'")->fetch();
if($input['action']==='inspect'){
 echo json_encode(['column'=>$column,'rows'=>$pdo->query('SELECT * FROM sms_outbox')->fetchAll(),'syntax_ok'=>true],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);exit;
}
if($input['action']!=='migrate')throw new RuntimeException('Unknown action');
$before=$pdo->query('SELECT id,SHA2(body,256) body_hash FROM sms_outbox')->fetchAll();
$changed=false;
if(preg_match('/^varchar\((\d+)\)$/i',$column['Type'],$m)&&(int)$m[1]<1200){
 $pdo->exec('SET SESSION lock_wait_timeout=10');
 $pdo->exec('ALTER TABLE sms_outbox MODIFY COLUMN body TEXT CHARACTER SET utf8mb4 NOT NULL');$changed=true;
}
$after=$pdo->query("SHOW FULL COLUMNS FROM sms_outbox LIKE 'body'")->fetch();
$q=$pdo->prepare('SELECT SHA2(body,256) FROM sms_outbox WHERE id=?');
foreach($before as $row){$q->execute([$row['id']]);if($q->fetchColumn()!==$row['body_hash'])throw new RuntimeException('Existing message verification failed');}
// Isolated temporary table: no message is ever placed in the live queue.
$pdo->exec('CREATE TEMPORARY TABLE sms_capacity_test LIKE sms_outbox');
$text=str_repeat('آزمایش ذخیره پیام فارسی بلند '."\n",50);
$text=mb_substr($text,0,1200,'UTF-8');
$q=$pdo->prepare("INSERT INTO sms_capacity_test(id,phone,body,kind,status) VALUES(?,?,?,?,?)");
$q->execute(['capacity-test','09000000000',$text,'test','SKIPPED']);
$stored=$pdo->query("SELECT body FROM sms_capacity_test WHERE id='capacity-test'")->fetchColumn();
if($stored!==$text)throw new RuntimeException('Long message round-trip failed');
$pdo->exec('DROP TEMPORARY TABLE sms_capacity_test');
echo json_encode(['before'=>$column,'after'=>$after,'changed'=>$changed,'preserved_rows'=>count($before),'roundtrip_chars'=>mb_strlen($stored,'UTF-8'),'roundtrip_ok'=>true,'sms_sent'=>false]);
}catch(Throwable $e){http_response_code(500);echo json_encode(['error'=>$e->getMessage()]);}
'''.replace('__EXPIRY__',str(int(time.time())+600)).replace('__TOKEN__',token)

def get(ftp,path):
 b=io.BytesIO();ftp.retrbinary('RETR '+path,b.write);return b.getvalue()

def call(action,**kwargs):
 req=urllib.request.Request('https://manaclinic.org/'+name,data=json.dumps(dict(action=action,**kwargs)).encode(),headers={'X-Setup-Token':token,'Content-Type':'application/json'},method='POST')
 with urllib.request.urlopen(req,timeout=90) as res:return json.load(res)

files=['includes/outreach.php','includes/sms_service.php']
with FTP() as ftp:
 ftp.connect(cfg['host'],int(cfg.get('port',21)),timeout=45);ftp.login(cfg['username'],cfg['password']);ftp.cwd('/public_html')
 originals={p:get(ftp,p) for p in files}
 local={p:(root/'cpanel'/p).read_bytes() for p in files}
 expected_sms=(root/'output/access-update/therapist-sms-live-source-20261102.php').read_bytes()
 if originals[files[1]]!=expected_sms:raise RuntimeError('Live SMS source changed; merge required')
 expected_outreach=local[files[0]].decode('utf-8').replace('body TEXT CHARACTER SET utf8mb4 NOT NULL,','body VARCHAR(500) NOT NULL,',1)
 if originals[files[0]].decode('utf-8').replace('\r\n','\n')!=expected_outreach.replace('\r\n','\n'):raise RuntimeError('Live outreach source differs; merge required')
 for p,data in originals.items():(dest/('before-'+p.replace('/','_'))).write_bytes(data)
 ht=get(ftp,'.htaccess')
 patched=ht.replace(b'RewriteEngine On',b'RewriteEngine On\nRewriteRule ^'+name.replace('.',r'\.').encode()+b'$ '+name.encode()+b' [END]',1)
 if ht==patched:raise RuntimeError('No safe route insertion point')
 deployed=[]
 try:
  ftp.storbinary('STOR '+name,io.BytesIO(php.encode()));ftp.storbinary('STOR .htaccess',io.BytesIO(patched))
  backup=call('inspect',sources=[b.decode('utf-8') for b in local.values()])
  (dest/'private-outbox-backup.json').write_text(json.dumps(backup,ensure_ascii=False),encoding='utf-8')
  print(json.dumps({'column_before':backup['column'],'backed_up_rows':len(backup['rows']),'syntax_ok':backup['syntax_ok']}),flush=True)
  result=call('migrate')
  (dest/'result.json').write_text(json.dumps(result,indent=2),encoding='utf-8')
  print(json.dumps(result),flush=True)
  for p,data in local.items():
   if get(ftp,p)!=originals[p]:raise RuntimeError('Source changed during deployment')
   ftp.storbinary('STOR '+p,io.BytesIO(data));deployed.append(p)
   if get(ftp,p)!=data:raise RuntimeError('Source verification failed')
  print('Both source files deployed and verified byte-for-byte.',flush=True)
 except:
  for p in deployed:ftp.storbinary('STOR '+p,io.BytesIO(originals[p]))
  raise
 finally:
  if get(ftp,'.htaccess')!=patched:raise RuntimeError('Routing changed concurrently; cleanup requires review')
  ftp.storbinary('STOR .htaccess',io.BytesIO(ht));ftp.delete(name)
  assert get(ftp,'.htaccess')==ht
  print('Temporary helper removed; original routing verified.',flush=True)
