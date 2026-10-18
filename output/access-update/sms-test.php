<?php
date_default_timezone_set('Asia/Tehran');
function mb_strlen($s){preg_match_all('/./us',$s,$m);return count($m[0]);}
function mb_substr($s,$start,$length=null){preg_match_all('/./us',$s,$m);return implode('',array_slice($m[0],$start,$length));}
$settings=[];
function mail_setting_get($pdo,$key,$fallback=''){return $GLOBALS['settings'][$key]??$fallback;}
require '/app/helpers.php';require '/app/outreach.php';
class SmsTestDatabase extends PDO{
 public array $states=[],$jobs=[],$sessions=[],$appointment=[],$enrollment=[];public bool $tx=false;
 public function __construct(){}
 public function inTransaction():bool{return $this->tx;}
 public function beginTransaction():bool{$this->tx=true;return true;}
 public function commit():bool{$this->tx=false;return true;}
 public function rollBack():bool{$this->tx=false;return true;}
 public function exec(string $sql):int|false{return 0;}
 public function prepare(string $sql,array $options=[]):PDOStatement|false{return new SmsTestStatement($this,$sql);}
 public function query(string $sql,?int $mode=null,mixed ...$args):PDOStatement|false{$s=new SmsTestStatement($this,$sql);$s->execute();return $s;}
}
class SmsTestStatement extends PDOStatement{
 private array $rows=[];private int $affected=0;
 public function __construct(private SmsTestDatabase $db,private string $sql){}
 public function execute(?array $p=null):bool{
  $p=$p??[];$this->rows=[];$this->affected=0;
  if(str_starts_with($this->sql,'SHOW '))$this->rows=[['exists'=>1]];
  elseif(str_starts_with($this->sql,'SELECT * FROM sms_entity_state'))$this->rows=isset($this->db->states[$p[0]])?[$this->db->states[$p[0]]]:[];
  elseif(str_starts_with($this->sql,'INSERT INTO sms_entity_state'))$this->db->states[$p[0]]=['fingerprint'=>$p[1],'last_status'=>$p[2]];
  elseif(str_starts_with($this->sql,'INSERT IGNORE INTO sms_outbox')){
   $key=$p[4]??$p[0];if(!isset($this->db->jobs[$key])){$this->db->jobs[$key]=['phone'=>$p[1],'body'=>$p[2],'kind'=>$p[3],'due_at'=>$p[5],'expires_at'=>$p[6],'context_json'=>$p[7]];$this->affected=1;}
  }
  elseif(str_starts_with($this->sql,'SELECT id,session_date'))$this->rows=$this->db->sessions;
  elseif(str_starts_with($this->sql,'SELECT starts_at,status,doctor_id'))$this->rows=$this->db->appointment?[$this->db->appointment]:[];
  elseif(str_starts_with($this->sql,'SELECT e.status,w.status'))$this->rows=$this->db->enrollment?[$this->db->enrollment]:[];
  elseif(str_starts_with($this->sql,'SELECT session_date')){foreach($this->db->sessions as $s)if($s['id']===$p[0])$this->rows=[['session_date'=>$s['session_date']]];}
  elseif(str_starts_with($this->sql,'SELECT phone FROM users'))$this->rows=[['phone'=>'09121234567'],['phone'=>'+989121234567'],['phone'=>'+971501234567']];
  return true;
 }
 public function fetch(int $mode=PDO::FETCH_DEFAULT,int $orientation=PDO::FETCH_ORI_NEXT,int $offset=0):mixed{return $this->rows[0]??false;}
 public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args):array{return $this->rows;}
 public function fetchColumn(int $column=0):mixed{return isset($this->rows[0])?array_values($this->rows[0])[$column]:false;}
 public function rowCount():int{return $this->affected;}
}
$checks=0;function check_sms($ok,$message){global $checks;if(!$ok)throw new RuntimeException($message);$checks++;}
foreach(['09121234567','+989121234567','00989121234567','989121234567','9121234567','۰۹۱۲۱۲۳۴۵۶۷'] as $phone)check_sms(sms_iran_mobile($phone)==='09121234567','Iran number normalization');
foreach(['+971501234567','00971501234567','+447700900123','123','+98912',''] as $phone)check_sms(sms_iran_mobile($phone)===null,'Foreign/invalid phone excluded');
foreach([['username'=>'eshahabian','role'=>'ADMIN'],['username'=>'shgeranmaye','role'=>'DOCTOR'],['name'=>'عطیه گارسچی','role'=>'DOCTOR']] as $u)check_sms(sms_operator_allowed($u),'Named operator allowed');
foreach([['role'=>'SECRETARY'],['username'=>'admin','role'=>'ADMIN'],['role'=>'DOCTOR','name'=>'Other']] as $u)check_sms(!sms_operator_allowed($u),'Other SMS operators denied');
check_sms(sms_settings_allowed(['username'=>'eshahabian','role'=>'ADMIN']),'Only Emad settings allowed');
foreach([['username'=>'shgeranmaye','role'=>'DOCTOR'],['name'=>'عطیه گارسچی','role'=>'DOCTOR'],['username'=>'admin','role'=>'ADMIN'],['role'=>'SECRETARY']] as $u)check_sms(!sms_settings_allowed($u),'Settings denied to every other operator');
$pdo=new SmsTestDatabase();$settings=['sms_enabled'=>'1','sms_api_key'=>'placeholder'];check_sms(!sms_connection_ready($pdo),'API key without sender cannot send');check_sms(sms_dispatch_pending($pdo)===0,'Unready connection does not dispatch');$settings['sms_sender']='9998621381';check_sms(sms_connection_ready($pdo),'API key and sender enable sending');$settings=['sms_enabled'=>'1','sms_sender'=>'100000','sms_username'=>'user','sms_password'=>'password'];check_sms(sms_connection_ready($pdo),'Username/password path still works');$settings['sms_enabled']='0';check_sms(!sms_connection_ready($pdo),'Disabled flag blocks sending');
$activated=date('Y-m-d H:i:s',time()-60);$start=date('Y-m-d H:i:s',time()+3*86400);
$a=['id'=>'appointment','status'=>'CONFIRMED','starts_at'=>$start,'ends_at'=>date('Y-m-d H:i:s',time()+3*86400+3600),'doctor_id'=>'doctor','created_at'=>date('Y-m-d H:i:s',time()-30),'updated_at'=>date('Y-m-d H:i:s'),'phone'=>'09121234567','doctor_name'=>'Doctor','session_mode'=>'ONLINE'];
sms_plan_appointment($pdo,$a,$activated);check_sms(count($pdo->jobs)===2,'Booking and day-before reminder queued');$jobs=array_values($pdo->jobs);check_sms(strtotime($jobs[1]['due_at'])===strtotime($start)-86400,'Appointment reminder exactly one day before');sms_plan_appointment($pdo,$a,$activated);check_sms(count($pdo->jobs)===2,'Repeated event scan does not duplicate');
$pdo->appointment=$a;check_sms(sms_job_still_valid($pdo,$jobs[1]),'Valid appointment reminder accepted');$pdo->appointment['status']='CANCELLED';check_sms(!sms_job_still_valid($pdo,$jobs[1]),'Cancelled appointment reminder suppressed');$a['status']='CANCELLED';sms_plan_appointment($pdo,$a,$activated);check_sms(count($pdo->jobs)===3,'Cancellation SMS queued');check_sms(sms_job_still_valid($pdo,array_values($pdo->jobs)[2]),'Cancellation SMS valid');
$a['id']='foreign';$a['phone']='+971501234567';$a['status']='CONFIRMED';sms_plan_appointment($pdo,$a,$activated);check_sms(count($pdo->jobs)===3,'Foreign booking and reminders excluded');
$pdo->sessions=[['id'=>'s1','session_date'=>date('Y-m-d',time()+5*86400)],['id'=>'s2','session_date'=>date('Y-m-d',time()+12*86400)]];
$w=['id'=>'workshop','enrollment_id'=>'enrollment','enrollment_status'=>'CONFIRMED','status'=>'PUBLISHED','starts_at'=>date('Y-m-d 15:00:00',time()+5*86400),'ends_at'=>date('Y-m-d 16:00:00',time()+13*86400),'enrolled_at'=>date('Y-m-d H:i:s'),'phone'=>'09121234567','location'=>'Test workshop address','type'=>'IN_PERSON','title'=>'Workshop'];
sms_plan_workshop($pdo,$w,$activated);check_sms(count($pdo->jobs)===6,'Workshop registration and every session reminder queued');$wj=array_values($pdo->jobs);check_sms(str_contains($wj[3]['body'],$w['location']),'Workshop place uses workshop data');check_sms(strtotime($wj[4]['due_at'])===strtotime($pdo->sessions[0]['session_date'].' 15:00:00')-86400,'Session reminder uses actual session date/time');sms_plan_workshop($pdo,$w,$activated);check_sms(count($pdo->jobs)===6,'Workshop jobs deduplicated');
$pdo->enrollment=['status'=>'CONFIRMED','workshop_status'=>'PUBLISHED']+$w;check_sms(sms_job_still_valid($pdo,$wj[4]),'Workshop session reminder valid');$pdo->enrollment['status']='CANCELLED';check_sms(!sms_job_still_valid($pdo,$wj[4]),'Cancelled enrollment suppresses reminders');$pdo->enrollment['status']='CONFIRMED';$pdo->enrollment['location']='Changed place';check_sms(!sms_job_still_valid($pdo,$wj[4]),'Stale workshop place suppressed');$w['location']='Changed place';sms_plan_workshop($pdo,$w,$activated);check_sms(count($pdo->jobs)===9,'Updated place gets fresh registration and reminder messages');
$manualDb=new SmsTestDatabase();check_sms(sms_broadcast_queue($manualDb,'patients','Test')===1,'Broadcast normalizes and deduplicates Iranian recipients');check_sms(!sms_queue_one($manualDb,'+447700900123','Test'),'Manual foreign SMS denied');
echo 'PASS '.$checks.' SMS phone, access, disabled-send, deduplication, appointment and workshop lifecycle checks';
