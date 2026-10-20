<?php
header('Content-Type: text/plain; charset=utf-8');
require dirname(__DIR__) . '/includes/doctor_profile_fields.php';
$auth = file_get_contents(__DIR__ . '/auth.txt');
$auth = str_replace("require_once __DIR__ . '/doctor_profile_fields.php';", '', $auth);
eval($auth);
function check($ok, $label) { if (!$ok) throw new RuntimeException($label); echo "PASS $label\n"; }
foreach ([['role'=>'SECRETARY'], ['role'=>'ADMIN','username'=>'eshahabian'], ['role'=>'DOCTOR','name'=>'عطیه گارسچی'], ['role'=>'DOCTOR','username'=>'shgeranmaye']] as $u) check(workshop_can_add_member($u), 'authorized account');
foreach ([null, ['role'=>'DOCTOR','name'=>'دیگر'], ['role'=>'PATIENT','username'=>'eshahabian'], ['role'=>'SECRETARY','is_disabled'=>1], ['role'=>'ADMIN','name'=>'دیگر']] as $u) check(!workshop_can_add_member($u), 'denied account');
function cuid() { static $n=0; return 'test'.++$n; }
function workshop_active_doctor_join($a) { return ''; }
function workshop_patient_list_sql($a) { return '1=1'; }
function workshop_has_capacity($p,$w) { return $p->capacity; }
function workshop_is_archived($w) { return !empty($w['archived']); }
function format_fa_datetime($v) { return $v; }
function notify_role(...$args) {}
function notify_doctor_profile(...$args) {}
function workshop_notify_member_joined($p,$id) { $p->notices++; }
class TestDb extends PDO {
 public array $writes=[]; public bool $capacity=true; public bool $archived=false; public bool $duplicate=false; public string $existingStatus='CONFIRMED'; public int $price=100; public int $notices=0; public bool $transaction=false; public bool $fail=false;
 function __construct() {}
 public function prepare(string $query, array $options=[]): PDOStatement|false { return new TestStatement($this,$query); }
 public function beginTransaction(): bool {$this->transaction=true; return true;}
 public function commit(): bool {$this->transaction=false;return true;}
 public function rollBack(): bool {$this->transaction=false;$this->writes=[];return true;}
 public function inTransaction(): bool {return $this->transaction;}
}
class TestStatement extends PDOStatement {
 function __construct(public TestDb $db, public string $sql) {}
 public function execute(?array $params=null): bool {if(str_contains($this->sql,'INSERT INTO') || str_contains($this->sql,'UPDATE ')) {if($this->db->fail)throw new RuntimeException('test failure');$this->db->writes[]=[$this->sql,$params];}return true;}
 public function fetch(int $mode=PDO::FETCH_DEFAULT,int $cursorOrientation=PDO::FETCH_ORI_NEXT,int $cursorOffset=0): mixed {
  if(str_contains($this->sql,'FROM workshops')) return ['id'=>'w','price'=>$this->db->price,'starts_at'=>'2027-01-01','doctor_id'=>'d','title'=>'test','is_published'=>1,'archived'=>$this->db->archived];
  if(str_contains($this->sql,'FROM workshop_enrollments'))return $this->db->duplicate?['id'=>'existing','status'=>$this->db->existingStatus]:false;
  if(str_contains($this->sql,'FROM users'))return ['id'=>'p','name'=>'test'];
  return false;
 }
}
eval(file_get_contents(__DIR__.'/enroll.txt'));
foreach ([[false,100,'PENDING'],[true,100,'PAID'],[false,0,'PAID']] as [$paid,$amount,$status]) {
 $p=new TestDb();$p->price=$amount;workshop_enroll_by_staff($p,'w','p','s','staff',$paid);
 $payment=array_values(array_filter($p->writes,fn($x)=>str_contains($x[0],'INSERT INTO workshop_payments')))[0][1];
 check($payment[3]===$status,'payment status '.$status); check($p->notices===1,'member notice after commit'); check(!$p->inTransaction(),'transaction committed');
}
foreach (['duplicate','capacity','archived','fail'] as $case) {
 $p=new TestDb();if($case==='capacity')$p->capacity=false;else $p->$case=true;
 try{workshop_enroll_by_staff($p,'w','p','s','staff',false);throw new Exception('Expected rejection');}catch(RuntimeException $e){}
 check($p->notices===0,'no notice for '.$case);check(!$p->inTransaction(),'no open transaction '.$case);
}
function staff_log_action(...$args) {}
$p=new TestDb();$p->duplicate=true;workshop_remove_member($p,'existing',['id'=>'s','role'=>'SECRETARY']);
check(!$p->inTransaction(),'member removal committed');
check(count($p->writes)===1 && str_contains($p->writes[0][0],"status='CANCELLED'"),'removal preserves payment rows');
$p=new TestDb();try{workshop_remove_member($p,'x',['id'=>'d','role'=>'DOCTOR','name'=>'other']);throw new Exception('should reject');}catch(RuntimeException $e){}
check(!$p->inTransaction() && !$p->writes,'unauthorized removal denied');
$p=new TestDb();try{workshop_remove_member($p,'missing',['id'=>'s','role'=>'SECRETARY']);throw new Exception('should reject');}catch(RuntimeException $e){}
check(!$p->inTransaction(),'missing membership rolls back');
check(mb_strlen(workshop_member_sms_body(str_repeat('عنوان ',20)))<=70,'membership SMS fits one Persian segment');
$p=new TestDb();$p->duplicate=true;$p->existingStatus='CANCELLED';
$id=workshop_enroll_by_staff($p,'w','p','s','staff',false);
check($id==='existing','cancelled membership can rejoin');
check(count($p->writes)===1 && str_contains($p->writes[0][0],"UPDATE workshop_enrollments"),'rejoin preserves existing payments');
check($p->notices===1,'rejoin sends new membership notification');
check(!$p->inTransaction(),'rejoin commits');
echo "PASS member regression tests";
