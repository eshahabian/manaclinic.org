<?php
declare(strict_types=1);
if (!hash_equals('TOKEN_HASH',hash('sha256',$_SERVER['HTTP_X_DIAGNOSTIC_TOKEN']??''))) {http_response_code(403);exit;}
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {http_response_code(405);exit;}
$root=dirname(__DIR__);$config=require $root.'/config.php';require $root.'/includes/db.php';require $root.'/includes/helpers.php';require $root.'/includes/user_phone.php';
$p=db_connect($config);$plan=json_decode('[{"source": "1f008475b2cb4843c1c79fcb", "target": "ae5b4c229636eb258d922c48", "name": "نازنین حسینی پور", "target_username": "nhoseynipoor1"}, {"source": "2cf71f656e2441b635ffac35", "target": "4150adb4876189617196c398", "name": "سمیه ثنایی", "target_username": "sthetwo"}, {"source": "42955c84b16ef33cd5d3fda9", "target": "d49fb302f9e83b931c3a9cc7", "name": "سعید حجتی", "target_username": "shjti"}, {"source": "51d105ffc3f68d34dba258f8", "target": "eb83a8b94ae31855d8d9f92d", "name": "پریسا قانع", "target_username": "pghana"}, {"source": "7081127fe666491be9fbf199", "target": "805d9bcaff67096f910cdeed", "name": "مهبد محمدي", "target_username": "mmohammadi"}, {"source": "695a8589e05dfe4acd373b1a", "target": "805d9bcaff67096f910cdeed", "name": "مهبد محمدي", "target_username": "mmohammadi"}, {"source": "8e8a49f83e5d5efbfa13ff9e", "target": "ca4c5630b6599748fdae8968", "name": "رضا پرستار", "target_username": "rprstar"}, {"source": "ab82f6a261390c5159ee2bfe", "target": "e3bbe6b3f7bd1b3fa6864cfa", "name": "سعید برزگری", "target_username": "sbrzgri"}]',true);$mode=$_POST['mode']??'dryrun';$locks=[];
function rows(PDO $p,string $sql,array $args=[]):array {$s=$p->prepare($sql);$s->execute($args);return $s->fetchAll();}
function run(PDO $p,string $sql,array $args=[]):void {$s=$p->prepare($sql);$s->execute($args);}
function nameKey(string $n):string {return preg_replace('/[\s\x{200c}]+/u','',strtr($n,['ي'=>'ی','ك'=>'ک']));}
function metrics(PDO $p):array {return ['users'=>(int)$p->query('SELECT COUNT(*) FROM users')->fetchColumn(),'appointments'=>(int)$p->query('SELECT COUNT(*) FROM appointments')->fetchColumn(),'transactions'=>(int)$p->query('SELECT COUNT(*) FROM wallet_transactions')->fetchColumn(),'wallets'=>$p->query('SELECT COALESCE(SUM(balance),0) AS b,COALESCE(SUM(held_balance),0) AS h FROM wallets')->fetch(),'enrollments'=>(int)$p->query('SELECT COUNT(*) FROM workshop_enrollments')->fetchColumn()];}
try {
 $s=$p->query("SELECT GET_LOCK('mana-account-merge',5)");if((int)$s->fetchColumn()!==1)throw new RuntimeException('Merge busy');$locks[]='mana-account-merge';
 $all=array_values(array_unique(array_merge(array_column($plan,'source'),array_column($plan,'target'))));sort($all);$marks=implode(',',array_fill(0,count($all),'?'));
 $users=rows($p,"SELECT * FROM users WHERE id IN ($marks) ORDER BY id",$all);$userMap=array_column($users,null,'id');
 foreach($plan as $pair){$a=$userMap[$pair['source']]??null;$b=$userMap[$pair['target']]??null;if(!$a||!$b||$a['role']!=='PATIENT'||$b['role']!=='PATIENT'||nameKey($a['name'])!==nameKey($b['name'])||user_phone_key((string)$a['phone'])!==user_phone_key((string)$b['phone']))throw new RuntimeException('Identity changed; no merge performed');$key='mana-phone:'.substr(hash('sha256',user_phone_key((string)$a['phone'])),0,48);if(!in_array($key,$locks,true)){$s=$p->prepare('SELECT GET_LOCK(?,5)');$s->execute([$key]);if((int)$s->fetchColumn()!==1)throw new RuntimeException('Phone busy');$locks[]=$key;}}
 $p->beginTransaction();$before=metrics($p);
 $columns=$p->query("SELECT TABLE_NAME,COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND (COLUMN_NAME REGEXP '(^|_)(user|patient|sender|recipient|actor|author|owner|created_by|updated_by|approved_by|recorded_by)(_id)?$' OR COLUMN_NAME LIKE '%user_id' OR COLUMN_NAME LIKE '%patient_id') ORDER BY TABLE_NAME,COLUMN_NAME")->fetchAll();
 $snapshot=['users'=>rows($p,"SELECT * FROM users WHERE id IN ($marks) ORDER BY id FOR UPDATE",$all),'references'=>[]];
 foreach($columns as $c){$t=$c['TABLE_NAME'];$col=$c['COLUMN_NAME'];$data=rows($p,"SELECT * FROM `$t` WHERE `$col` IN ($marks) ORDER BY `$col` FOR UPDATE",$all);if($data)$snapshot['references'][$t.'.'.$col]=$data;}
 $snapshot['wallet_transactions']=rows($p,"SELECT wt.* FROM wallet_transactions wt JOIN wallets w ON w.id=wt.wallet_id WHERE w.user_id IN ($marks) ORDER BY wt.id FOR UPDATE",$all);
 $digest=hash('sha256',json_encode($snapshot,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
 if($mode==='apply' && !hash_equals((string)($_POST['expected_digest']??''),$digest))throw new RuntimeException('Snapshot changed; rerun dryrun');
 $backupName='account-merge-'.date('Ymd-His').'-'.bin2hex(random_bytes(6)).'.json';$backup=$root.'/storage/'.$backupName;
 if(!is_dir($root.'/storage'))throw new RuntimeException('Private storage unavailable');
 $payload=json_encode(['plan'=>$plan,'snapshot'=>$snapshot,'digest'=>$digest,'metrics'=>$before],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
 if(file_put_contents($backup,$payload,LOCK_EX)!==strlen($payload))throw new RuntimeException('Backup failed');chmod($backup,0600);
 if(!hash_equals(hash('sha256',$payload),hash_file('sha256',$backup)))throw new RuntimeException('Backup verification failed');
 foreach($plan as $pair){$source=$pair['source'];$target=$pair['target'];
  $wallets=rows($p,'SELECT w.*,(SELECT COUNT(*) FROM wallet_transactions wt WHERE wt.wallet_id=w.id) AS tx_count FROM wallets w WHERE user_id IN (?,?) FOR UPDATE',[$source,$target]);
  if(count($wallets)!==2)throw new RuntimeException('Unexpected wallet count');
  $heavy=array_values(array_filter($wallets,fn($w)=>(int)$w['tx_count']>0||(int)$w['balance']!==0||(int)$w['held_balance']!==0));
  if(count($heavy)>1)throw new RuntimeException('Multiple financial wallets need separate reconciliation');
  $keep=$heavy[0]??array_values(array_filter($wallets,fn($w)=>$w['user_id']===$target))[0];
  foreach($wallets as $w){if($w['id']===$keep['id'])continue;if((int)$w['tx_count']!==0||(int)$w['balance']!==0||(int)$w['held_balance']!==0)throw new RuntimeException('Nonempty wallet would be removed');run($p,'DELETE FROM wallets WHERE id=?',[$w['id']]);}
  run($p,'UPDATE wallets SET user_id=? WHERE id=?',[$target,$keep['id']]);
  foreach(rows($p,'SELECT * FROM doctor_patient_charts WHERE patient_id=? FOR UPDATE',[$source]) as $chart){
   $other=rows($p,'SELECT * FROM doctor_patient_charts WHERE patient_id=? AND doctor_id=? FOR UPDATE',[$target,$chart['doctor_id']]);
   if(!$other){run($p,'UPDATE doctor_patient_charts SET patient_id=? WHERE id=?',[$target,$chart['id']]);continue;}
   $dest=$other[0];$fields=['history_text','first_name','last_name','therapist_name','birth_date','marital_status','chief_complaint','residence','family_history'];$preserved=[];
   foreach($fields as $f){if(!empty($chart[$f]) && $chart[$f]!==($dest[$f]??null)){if(empty($dest[$f]))$dest[$f]=$chart[$f];else $preserved[$f]=$chart[$f];}}
   if($preserved)$dest['history_text']=trim((string)$dest['history_text'])."\n\n[سوابق حساب ادغام‌شده ".$userMap[$source]['username']."]\n".json_encode($preserved,JSON_UNESCAPED_UNICODE);
   $set=implode(',',array_map(fn($f)=>"`$f`=?",$fields));$args=array_map(fn($f)=>$dest[$f]??null,$fields);$args[]=$dest['id'];run($p,"UPDATE doctor_patient_charts SET $set WHERE id=?",$args);run($p,'DELETE FROM doctor_patient_charts WHERE id=?',[$chart['id']]);
  }
  $quiet=rows($p,'SELECT * FROM patient_quiet_notices WHERE patient_id IN (?,?)',[$source,$target]);if(count($quiet)>1){run($p,'UPDATE patient_quiet_notices SET notified_at=? WHERE patient_id=?',[max(array_column($quiet,'notified_at')),$target]);run($p,'DELETE FROM patient_quiet_notices WHERE patient_id=?',[$source]);}
  foreach($columns as $c){$t=$c['TABLE_NAME'];$col=$c['COLUMN_NAME'];if(in_array($t,['wallets','doctor_patient_charts'],true))continue;
   if(in_array($t,['login_otp','password_reset_tokens'],true)){run($p,"DELETE FROM `$t` WHERE `$col`=?",[$source]);continue;}
   run($p,"UPDATE `$t` SET `$col`=? WHERE `$col`=?",[$target,$source]);
  }
  $latest=rows($p,'SELECT * FROM users WHERE id IN (?,?)',[$source,$target]);$m=array_column($latest,null,'id');
  foreach(['email','preferred_doctor_id','gender','referral_source'] as $f){if(array_key_exists($f,$m[$target]) && empty($m[$target][$f]) && !empty($m[$source][$f])){if($f==='email')run($p,'UPDATE users SET email=NULL WHERE id=?',[$source]);run($p,"UPDATE users SET `$f`=? WHERE id=?",[$m[$source][$f],$target]);}}
  foreach($columns as $c){if($c['TABLE_NAME']==='users')continue;$q=rows($p,"SELECT COUNT(*) AS n FROM `{$c['TABLE_NAME']}` WHERE `{$c['COLUMN_NAME']}`=?",[$source]);if((int)$q[0]['n']!==0)throw new RuntimeException('Unmigrated reference');}
  run($p,'DELETE FROM users WHERE id=?',[$source]);
 }
 $after=metrics($p);if($after['users']!==$before['users']-count($plan))throw new RuntimeException('User count mismatch');foreach(['appointments','transactions','wallets','enrollments'] as $key)if($before[$key]!==$after[$key])throw new RuntimeException('Invariant failed: '.$key);
 if($mode==='apply'){$p->commit();$status='committed';}else{$p->rollBack();$status='rolled_back';if(metrics($p)!==$before)throw new RuntimeException('Rollback metrics mismatch');}
 echo json_encode(['ok'=>true,'status'=>$status,'merged_accounts'=>count($plan),'people'=>count(array_unique(array_column($plan,'target'))),'snapshot_digest'=>$digest,'backup'=>$backupName,'preserved'=>['appointments','wallet_transactions','wallet_totals','enrollments'],'targets'=>array_values(array_unique(array_column($plan,'target_username')))],JSON_UNESCAPED_UNICODE);
}catch(Throwable $e){if($p->inTransaction())$p->rollBack();http_response_code(409);echo json_encode(['ok'=>false,'error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE);}finally{foreach(array_reverse($locks) as $lock){$s=$p->prepare('SELECT RELEASE_LOCK(?)');$s->execute([$lock]);}}
