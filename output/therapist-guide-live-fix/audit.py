from pathlib import Path
import json,ftplib,io,secrets,hashlib,time,urllib.request
root=Path.cwd();cfg=json.loads((root/'.vscode/sftp.json').read_text(encoding='utf-8-sig'))
token=secrets.token_hex(32);folder='guide-audit-'+secrets.token_hex(12)
php=r'''<?php
if(time()>EXPIRES || !hash_equals('TOKENHASH',hash('sha256',$_SERVER['HTTP_X_MANA_VERIFY']??''))){http_response_code(404);exit;}
header('Content-Type: application/json');header('Cache-Control: no-store');
try {
 $base=dirname(__DIR__);$config=require $base.'/config.php';
 $pdo=new PDO('mysql:host='.$config['db_host'].';dbname='.$config['db_name'].';charset=utf8mb4',$config['db_user'],$config['db_pass'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
 require_once $base.'/includes/helpers.php';require_once $base.'/includes/auth.php';require_once $base.'/includes/doctor_profile_fields.php';require_once $base.'/includes/therapist_guide_results.php';require_once $base.'/includes/collaboration.php';
 $exists=(bool)$pdo->query("SHOW TABLES LIKE 'therapist_guide_results'")->fetchColumn();
 $report=['table_exists'=>$exists];
 if($exists){$report['stored']=$pdo->query('SELECT COUNT(*) AS total,MAX(created_at) AS last_created FROM therapist_guide_results')->fetch();}
 $ids=collaboration_recipient_ids($pdo);$report['recipients']=[];
 foreach($ids as $id){$q=$pdo->prepare('SELECT id,username,role,is_disabled FROM users WHERE id=?');$q->execute([$id]);$u=$q->fetch();if($u){$allowed=therapist_guide_can_read($pdo,$u);unset($u['id']);$u['guide_allowed']=$allowed;$report['recipients'][]=$u;}}
 $bank=require $base.'/includes/therapist_guide_questions.php';$report['snapshot_checks']=[];
 foreach($bank as $domain=>$questions){$answers=[];foreach($questions as $q){$answers[$q['key']]=$q['options'][0][0];}$answers['domain']=$domain;try{$snapshot=therapist_guide_snapshot($answers);$report['snapshot_checks'][$domain]=['ok'=>count($snapshot['answers'])===10,'summary_lines'=>count($snapshot['summary'])];}catch(Throwable $e){$report['snapshot_checks'][$domain]=['ok'=>false,'error_class'=>get_class($e)];}}
 echo json_encode($report);
}catch(Throwable $e){http_response_code(500);echo json_encode(['failed'=>get_class($e),'message'=>$e->getMessage()]);}
'''.replace('EXPIRES',str(int(time.time())+600)).replace('TOKENHASH',hashlib.sha256(token.encode()).hexdigest())
ftp=ftplib.FTP(cfg['host'],timeout=40);ftp.login(cfg['username'],cfg['password']);ftp.cwd('/public_html');ftp.mkd(folder)
try:
 ftp.storbinary('STOR '+folder+'/index.php',io.BytesIO(php.encode()))
 req=urllib.request.Request('https://manaclinic.org/'+folder+'/',headers={'X-Mana-Verify':token})
 try:
  with urllib.request.urlopen(req,timeout=40) as r:body=r.read().decode()
 except urllib.error.HTTPError as e:body=e.read().decode()
 print(body)
 (root/'output/therapist-guide-live-fix/audit.json').write_text(body,encoding='utf-8')
finally:
 ftp.delete(folder+'/index.php');ftp.rmd(folder);ftp.quit()
