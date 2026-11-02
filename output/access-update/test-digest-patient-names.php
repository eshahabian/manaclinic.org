<?php
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
try{
$input=['mode'=>'test'];
foreach(['D:/Psychology/cpanel/includes/sms_service.php','D:/Psychology/cpanel/pages/admin/sms_doctor_digest.php'] as $path)token_get_all(file_get_contents($path),TOKEN_PARSE);
require_once 'D:/Psychology/cpanel/includes/helpers.php';
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
 require_once 'D:/Psychology/cpanel/includes/sms_service.php';
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