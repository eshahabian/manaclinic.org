<?php
// Isolated tests with fake settings, database and provider: never sends an SMS.
$source=file_get_contents(__DIR__.'/stage-0.txt');
$source=str_replace(['function sms_connection_ready(', 'function sms_iran_mobile(', 'function sms_provider_send(', 'function sms_doctor_digests_for_date(', 'function sms_doctor_digest_window(', 'function sms_send_immediate('], ['function unused_connection_ready(', 'function unused_iran_mobile(', 'function unused_provider_send(', 'function unused_digests_for_date(', 'function actual_digest_window(', 'function unused_send_immediate('], $source);
eval(substr($source,strpos($source,'function sms_template_catalog')));
$settings=[];$checks=0;$sent=[];$clock=new DateTimeImmutable('today 21:00:00',new DateTimeZone('Asia/Tehran'));
function mail_setting_get(PDO $p,string $k,?string $d=null):?string {return $GLOBALS['settings'][$k]??$d;}
function sms_connection_ready(PDO $p):bool{return true;}
function ensure_outreach_schema(PDO $p):void{}
function sms_iran_mobile(string $p):?string{return preg_match('/^09[0-9]{9}$/',$p)?$p:null;}
function sms_provider_send(array $c,string $p,string $b):array{throw new Exception('Unexpected provider send');}
function sms_send_immediate(PDO $p,string $phone,string $body,string $kind,string $key,?string $expires=null,array $ctx=[]):array{$GLOBALS['sent'][]=[$phone,$kind,$body];return ['ok'=>true,'status'=>'SENT'];}
function cuid():string{static $i=0;return 'fake'.++$i;}
function format_fa_datetime(string $s):string{return $s;}
function to_fa_digits(string $s):string{return $s;}
function sms_doctor_digest_window(?DateTimeImmutable $now=null):?array{return actual_digest_window($now??$GLOBALS['clock']);}
function sms_doctor_digests_for_date(PDO $p,string $ymd,?array $ids=null):array{
 $out=[];foreach([['shiva','شیوا گرانمایه پور','09120000001'],['atieh','عطیه گارسچی','09120000002'],['other','درمانگر دیگر','09120000003']] as [$id,$name,$phone])$out[]=['doctor_id'=>$id,'name'=>$name,'phone'=>$phone,'body'=>sms_doctor_daily_digest_body($name,$ymd,['14:00','10:00','11:00','13:00']),'slot_count'=>4];return $out;
}
function expect(bool $ok,string $label):void{if(!$ok)throw new Exception($label);$GLOBALS['checks']++;}
class FakeDb extends PDO {
 public array $jobs=[];public int $lastCount=0;public function __construct(){}
 public function prepare(string $q,array $o=[]):PDOStatement|false{return new FakeStmt($this,$q);}
 public function query(string $q,?int $m=null,mixed ...$a):PDOStatement|false{return new FakeStmt($this,$q);}
}
class FakeStmt extends PDOStatement {
 public array $args=[];public function __construct(public FakeDb $db,public string $q){}
 public function execute(?array $a=null):bool{$this->args=$a??[];$this->db->lastCount=0;
  if(str_contains($this->q,'INSERT IGNORE INTO sms_outbox')){
   foreach($this->db->jobs as $job)if($job['dedup_key']===$a[4])return true;
   $this->db->jobs[]=['id'=>$a[0],'phone'=>$a[1],'body'=>$a[2],'kind'=>$a[3],'dedup_key'=>$a[4],'due_at'=>$a[5],'expires_at'=>$a[6],'context_json'=>$a[7]];$this->db->lastCount=1;
  }return true;}
 public function rowCount():int{return $this->db->lastCount;}
 public function fetchColumn(int $c=0):mixed{
  if(str_contains($this->q,'SELECT u.username'))return ['shiva'=>'shgeranmaye','atieh'=>'atieh','other'=>'someone'][$this->args[0]]??false;
  if(str_contains($this->q,'SELECT phone FROM users'))return '09120000009';
  return false;
 }
 public function fetch(int $m=PDO::FETCH_DEFAULT,int $o=PDO::FETCH_ORI_NEXT,int $n=0):mixed{
  if(str_contains($this->q,'SELECT id,phone FROM users'))return ['id'=>'emad','phone'=>'09120000009'];
  if(str_contains($this->q,'SELECT * FROM sms_outbox')){foreach($this->db->jobs as $j)if($j['id']===$this->args[0])return $j;return false;}
  if(str_contains($this->q,'FROM appointments'))return ['id'=>'appt','status'=>'CONFIRMED','starts_at'=>date('Y-m-d',strtotime('+1 day')).' 10:00:00','doctor_id'=>'shiva','phone'=>'09120000007','patient_name'=>'مراجع نمونه','doctor_name'=>'شیوا','doctor_phone'=>'09120000001'];
  return false;
 }
}
$pdo=new FakeDb();
expect(!sms_queue_extended($pdo,null,'test','workshop_reminder') && count($pdo->jobs)===0,'Null phone safely skipped without stopping worker');
expect(actual_digest_window(new DateTimeImmutable('2026-10-29 20:59:59',new DateTimeZone('Asia/Tehran')))===null,'No digest before 21');
$w=actual_digest_window(new DateTimeImmutable('2026-10-29 17:30:00',new DateTimeZone('UTC')));
expect($w['date']==='2026-10-30'&&$w['due_at']==='2026-10-29 21:00:00','21 Tehran boundary and tomorrow');
expect(actual_digest_window(new DateTimeImmutable('2026-10-30 00:00:00',new DateTimeZone('Asia/Tehran')))===null,'No morning digest');
expect(actual_digest_window(new DateTimeImmutable('2026-12-31 21:00:00',new DateTimeZone('Asia/Tehran')))['date']==='2027-01-01','Year rollover');
$settings['sms_kind_enabled_doctor_appointment_booked']='1';expect(!sms_kind_enabled($pdo,'doctor_appointment_booked'),'Legacy booking toggle cannot enable per-appointment therapist SMS');
expect(sms_kind_enabled($pdo,'appointment_booked')&&sms_kind_enabled($pdo,'doctor_appointment_cancelled'),'Patient booking and doctor cancellation retained');
$body=sms_doctor_daily_digest_body('عطیه گارسچی','۱۲ مرداد ۱۴۰۵',['14:00','10:00','11:00','13:00','10:00']);
expect($body==="عطیه گارسچی گرامی\nنوبت های رزرو شده در تاریخ ۱۲ مرداد ۱۴۰۵ :\nساعت 10:00\nساعت 11:00\nساعت 13:00\nساعت 14:00",'Exact multiline layout, sorted times and no duplicate');
$long=[];for($i=0;$i<48;$i++)$long[]=sprintf('%02d:%02d',intdiv($i,2),($i%2)*30);
expect(str_contains(sms_doctor_daily_digest_body('درمانگر','روز',$long),'ساعت 23:30'),'No tail slots truncated');
expect(sms_template_error('doctor_daily_digest',sms_template_catalog()['doctor_daily_digest'][1])==='','Template tokens valid');
expect(!sms_doctor_digest_copy_allowed($pdo,'unknown'),'Unknown profile is never copied');
expect(sms_doctor_digest_copy_allowed($pdo,'shiva')&&sms_doctor_digest_copy_allowed($pdo,'atieh')&&!sms_doctor_digest_copy_allowed($pdo,'other'),'Only Shiva and Atieh copied');
expect(sms_queue_doctor_daily_digests($pdo)===3,'Each therapist gets own original');
expect(count($pdo->jobs)===5,'Three originals and exactly two copies');
$originals=array_values(array_filter($pdo->jobs,fn($j)=>$j['kind']==='doctor_daily_digest'));$copies=array_values(array_filter($pdo->jobs,fn($j)=>$j['kind']==='therapist_copy'));
expect(array_column($originals,'phone')===['09120000001','09120000002','09120000003'],'Original recipients kept separate');
expect(count(array_filter($copies,fn($j)=>$j['phone']==='09120000009'))===2,'Only Emad receives copies');
foreach($copies as $copy){$ctx=json_decode($copy['context_json'],true);$source=array_values(array_filter($originals,fn($j)=>$j['id']===$ctx['source_id']))[0];expect($copy['body']===$source['body']&&$copy['due_at']===$source['due_at']&&$copy['expires_at']===$source['expires_at'],'Copy exactly matches original and schedule');}
expect(sms_queue_doctor_daily_digests($pdo)===0&&count($pdo->jobs)===5,'Repeated worker does not duplicate');
$clock=new DateTimeImmutable('today 20:59:59',new DateTimeZone('Asia/Tehran'));$pdo->jobs=[];expect(sms_queue_doctor_daily_digests($pdo)===0&&count($pdo->jobs)===0,'Worker before 21 queues nothing');
$clock=new DateTimeImmutable('today 21:00:00',new DateTimeZone('Asia/Tehran'));
expect(!sms_job_still_valid($pdo,['kind'=>'doctor_appointment_booked']),'Old pending per-appointment booking blocked');
foreach(['am','pm'] as $slot)expect(!sms_job_still_valid($pdo,['kind'=>'doctor_daily_digest','context_json'=>json_encode(['entity'=>'doctor_digest','digest_date'=>date('Y-m-d'),'slot'=>$slot])]),'Old '.$slot.' schedule blocked');
sms_notify_appointment_now($pdo,'appt','booked');expect(count($sent)===1&&$sent[0][1]==='appointment_booked','Booking sends only patient SMS');
$sent=[];sms_notify_appointment_now($pdo,'appt','cancelled');expect(array_column($sent,1)===['appointment_cancelled','doctor_appointment_cancelled'],'Cancellation still notifies patient and therapist');
echo json_encode(['checks'=>$checks,'sample'=>$body,'provider_calls'=>0],JSON_UNESCAPED_UNICODE);
