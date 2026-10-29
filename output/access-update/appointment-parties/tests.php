<?php
require __DIR__.'/helper.txt';
function e(string $s):string{return htmlspecialchars($s,ENT_QUOTES,'UTF-8');}
function url(string $s):string{return $s;}
function staff_app_is_eshahabian(array $u):bool{return ($u['username']??'')==='eshahabian';}
function staff_app_schedule_selected_day(string $s):DateTimeImmutable{$z=new DateTimeZone('Asia/Tehran');$d=DateTimeImmutable::createFromFormat('!Y-m-d',$s,$z);return $d&&$d->format('Y-m-d')===$s?$d:new DateTimeImmutable('today',$z);}
function to_jalali_label(string $s):string{return '۱۴۰۵/۰۸/۰۷';}
function format_price(int $n):string{return number_format($n).' تومان';}
function format_fa_datetime(string $s):string{return $s;}
$checks=0;function expect(bool $b,string $label):void{if(!$b)throw new Exception($label);$GLOBALS['checks']++;}
class PartyDb extends PDO {
 public array $calls=[];public bool $empty=false;public bool $fail=false;
 public function __construct(){}
 public function prepare(string $q,array $o=[]):PDOStatement|false{if($this->fail)throw new Exception('test-only loading failure');$this->calls[]=['sql'=>$q];return new PartyStmt($this,$q);}
}
class PartyStmt extends PDOStatement {
 public function __construct(public PartyDb $db,public string $q){}
 public function execute(?array $p=null):bool{$this->db->calls[array_key_last($this->db->calls)]['params']=$p;return true;}
 public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args):array{
  if($this->db->empty)return [];
  if(str_contains($this->q,'FROM wallets'))return [['patient_name'=>'مراجع نمونه طلبکار','amount'=>650000,'starts_at'=>'2026-10-29 09:15:00','doctor_name'=>'درمانگر نمونه']];
  return [['patient_name'=>'مراجع نمونه بدهکار','amount'=>450000,'starts_at'=>'2026-10-29 12:00:00','doctor_name'=>'درمانگر نمونه','staff_payment_note'=>'پرداخت در جلسه بعد','actor_name'=>'منشی نمونه'],['patient_name'=>'<script>alert(1)</script>','amount'=>250000,'starts_at'=>'2026-10-29 14:00:00','doctor_name'=>'<img src=x onerror=alert(2)>','staff_payment_note'=>'<b>unsafe</b>','actor_name'=>'ثبت‌کننده نمونه']];
 }
}
$p=new PartyDb();$secretary=['role'=>'SECRETARY','username'=>'secretary1'];
$html=staff_app_appointment_parties_html($p,$secretary,'2026-10-29',[]);
expect(str_contains($html,'بدهکاران')&&str_contains($html,'طلبکاران'),'Two financial tabs');
expect(substr_count($html,'<article class="sapp-row"')===2,'One card per debt');
expect(str_contains($html,'پرداخت در جلسه بعد')&&str_contains($html,'منشی نمونه'),'Booking note and actor retained');
expect(!str_contains($html,'<script>')&&!str_contains($html,'<img src=x')&&str_contains($html,'&lt;script&gt;'),'Patient content escaped');
expect($p->calls[0]['params']===['2026-10-29 00:00:00','2026-10-30 00:00:00'],'Bounded selected day');
expect(str_contains($p->calls[0]['sql'],"p.status='PENDING'")&&str_contains($p->calls[0]['sql'],"a.status<>'CANCELLED'")&&str_contains($p->calls[0]['sql'],"p.ref_id='LATER'"),'Preserve open deferred debt criteria');
expect(!str_contains($p->calls[0]['sql'],'LIMIT 80'),'Day not truncated');
expect(str_contains($html,'party_tab=debtors&amp;party_day=2026-10-28')&&str_contains($html,'party_tab=debtors&amp;party_day=2026-10-30'),'Day navigation preserves tab');
expect(str_contains($html,'day=2026-10-29&amp;party_tab=creditors&amp;party_day=2026-10-29#appointment-parties'),'Tab navigation preserves appointment and finance days');
$credit=staff_app_appointment_parties_html($p,['role'=>'ADMIN','username'=>'eshahabian'],'2026-10-29',['party_tab'=>'creditors','party_day'=>'2026-10-30']);
expect(substr_count($credit,'<article class="sapp-row"')===1&&str_contains($credit,'مراجع نمونه طلبکار'),'Credit card rendered for Emad');
expect(str_contains($credit,'آخرین تغییر کیف پول')&&str_contains($credit,'مانده فعلی'),'Current credit date meaning explained');
expect(str_contains($p->calls[1]['sql'],'w.balance>0')&&$p->calls[1]['params']===['2026-10-30 00:00:00','2026-10-31 00:00:00'],'Positive current balances filtered by update day');
expect(str_contains($credit,'day=2026-10-29&amp;party_tab=creditors&amp;party_day=2026-10-31'),'Independent finance day navigation');
$count=count($p->calls);expect(staff_app_appointment_parties_html($p,['role'=>'DOCTOR','username'=>'ordinary'],'2026-10-29',[])===''&&count($p->calls)===$count,'No financial queries for ordinary therapist');
expect(staff_app_appointment_parties_html($p,['role'=>'ADMIN','username'=>'other-admin'],'2026-10-29',[])===''&&count($p->calls)===$count,'Other admin not newly granted access');
$p->empty=true;$empty=staff_app_appointment_parties_html($p,$secretary,'2026-10-29',[]);expect(str_contains($empty,'بدهیِ پرداخت بعدی باز نیست')&&!str_contains($empty,'<article'),'Empty state');
$p->fail=true;$error=staff_app_appointment_parties_html($p,$secretary,'2026-10-29',[]);expect(str_contains($error,'بارگذاری این فهرست انجام نشد')&&!str_contains($error,'بدهیِ پرداخت بعدی باز نیست'),'Query error distinct from no debts');
$page=file_get_contents(__DIR__.'/page.txt');$a=strpos($page,"if ($"."section === 'appointments')");$b=strpos($page,"if ($"."section === 'rooms')",$a);$page=substr($page,$a,$b-$a);
expect(strpos($page,'<?= $bookForm ?>')<strpos($page,'echo staff_app_appointment_parties_html'),'Finance below booking form');
expect(!str_contains($page,'echo appointment_debts_html'),'Old ungrouped panel removed from app');
expect(strpos($page,'echo staff_app_appointment_parties_html')<strpos($page,'وقت‌های روز'),'Daily appointments preserved below finance');
echo json_encode(['checks'=>$checks,'debt_html'=>$html,'credit_html'=>$credit,'empty_html'=>$empty],JSON_UNESCAPED_UNICODE);
