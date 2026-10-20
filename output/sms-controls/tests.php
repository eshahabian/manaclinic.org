<?php
// Runs entirely against fake settings and a fake PDO. Never contacts the SMS provider.
$source=file_get_contents(__DIR__.'/0.txt');$source=str_replace(['function sms_connection_ready(', 'function sms_iran_mobile(', 'function sms_provider_send('], ['function unused_connection_ready(', 'function unused_iran_mobile(', 'function unused_provider_send('], $source);eval(substr($source,strpos($source,'function sms_template_catalog')));
function sms_provider_send(array $c,string $p,string $b):array {throw new Exception('Unexpected provider send');}
$settings=[];$checks=0;
function mail_setting_get(PDO $p,string $k,?string $d=null):?string {global $settings;return $settings[$k]??$d;}
function sms_connection_ready(PDO $p):bool{return true;}
function ensure_outreach_schema(PDO $p):void{}
function sms_iran_mobile(string $p):?string{return preg_match('/^09[0-9]{9}$/',$p)?$p:null;}
function cuid():string{static $i=0;return 'fake'.++$i;}
function to_fa_digits(string $s):string{return $s;}
function e($s):string{return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');}
function url($s):string{return $s;}
function csrf_field():string{return '<input name="csrf_token" value="fake">';}
function format_fa_datetime($s):string{return (string)$s;}
function sms_render_page($t,$html):void{$GLOBALS['rendered']=$html;}
function expect($ok,$label):void{global $checks;if(!$ok)throw new Exception($label);$checks++;}
class FakeDb extends PDO {
 public array $jobs=[];public function __construct(){}
 public function prepare(string $q,array $o=[]):PDOStatement|false{return new FakeStmt($this,$q);}
 public function query(string $q,?int $m=null,mixed ...$a):PDOStatement|false{return new FakeStmt($this,$q);}
}
class FakeStmt extends PDOStatement {
 public function __construct(public FakeDb $db,public string $q){}
 public function execute(?array $a=null):bool{if(str_contains($this->q,'INSERT IGNORE INTO sms_outbox'))$this->db->jobs[]=$a;return true;}
 public function rowCount():int{return 1;}
 public function fetch(int $m=PDO::FETCH_DEFAULT,int $o=PDO::FETCH_ORI_NEXT,int $n=0):mixed{return ['id'=>'emad','phone'=>'09120000002'];}
 public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args):array{return [];}
}
$pdo=new FakeDb();
foreach(sms_template_catalog() as $kind=>$item){expect(sms_kind_recipient($kind)!=='','recipient '.$kind);expect(sms_template_error($kind,$item[1])==='','default valid '.$kind);}
expect(sms_kind_enabled($pdo,'appointment_booked'),'default appointment enabled');
expect(!sms_kind_enabled($pdo,'quiet'),'legacy quiet remains disabled');
$settings['sms_notify_workshop']='1';expect(sms_kind_enabled($pdo,'workshop_announcement'),'legacy workshop retained');
$settings['sms_kind_enabled_appointment_booked']='0';
expect(!sms_queue_extended($pdo,'09120000001','test','appointment_booked'),'disabled enqueue');
expect(sms_send_immediate($pdo,'09120000001','test','appointment_booked','x')['status']==='SKIPPED','disabled immediate');
expect(!sms_job_still_valid($pdo,['kind'=>'appointment_booked']),'disabled pending');
expect(count($pdo->jobs)===0,'no jobs and no provider call for disabled');
$settings['sms_kind_enabled_therapist_copy']='0';
sms_queue_extended($pdo,'09120000001','original','doctor_appointment_booked','key',null,null,['entity'=>'appointment_doctor']);expect(count($pdo->jobs)===1,'copy independently disabled');
$settings['sms_kind_enabled_therapist_copy']='1';$pdo->jobs=[];
sms_queue_extended($pdo,'09120000001','original','doctor_appointment_booked','key2',null,null,['entity'=>'appointment_doctor']);expect(count($pdo->jobs)===2&&$pdo->jobs[0][2]===$pdo->jobs[1][2],'copy matches source');
expect(sms_template_error('login_otp','hello')!=='','OTP requires code');
expect(sms_template_error('appointment_booked','hello {unknown}')!=='','unknown variable rejected');
expect(sms_template_error('appointment_booked','hello {datetime')!=='','malformed variable rejected');
expect(sms_template_error('appointment_booked',str_repeat('x',501))!=='','length validated');
$settings['sms_template_appointment_cancelled']='متن دلخواه {datetime}';
expect(sms_template_render($pdo,'appointment_cancelled',['datetime'=>'DATE'])==='متن دلخواه DATE','stored edit used verbatim');
expect(sms_template_render($pdo,'appointment_cancelled',['datetime'=>'DATE'],'تست {datetime}')==='تست DATE','draft test independent of stored text');
$settings['sms_template_workshop_media_added']='فایل: {message}';expect(sms_dynamic_body($pdo,'workshop_media_added','اصل')==='فایل: اصل','dynamic override applied');
$settings['sms_template_doctor_daily_digest']='برنامه {date}: {slots}';expect(sms_doctor_daily_digest_body('doc','DAY',['10:00','13:00'])==='برنامه DAY: 10:00، 13:00','digest edit used');
$page=file_get_contents(__DIR__.'/3.txt');eval(substr($page,strpos($page,'$catalog =')));
expect(substr_count($GLOBALS['rendered'],'data-sms-template>')===count(sms_template_catalog()),'all cards rendered');
expect(str_contains($GLOBALS['rendered'],'گیرنده ارسال خودکار'),'recipient labels rendered');
expect(str_contains($GLOBALS['rendered'],'eshahabian'),'specific copy recipient visible');
function staff_app_render($a,$b,$c,$html):void{$GLOBALS['appRendered']=$html;}
$smsControlsApp=true;eval(substr($page,strpos($page,'$catalog =')));
expect(str_contains($GLOBALS['appRendered'],'action="/app/sms"'),'staff app posts stay in app');
expect(str_contains($GLOBALS['appRendered'],'href="/app"'),'staff app return link');
$app=file_get_contents(__DIR__.'/6.txt');
expect(str_contains($app,"if ($"."path === '/app/sms')"),'staff app route');
expect(str_contains($app,'require_sms_settings_operator();'),'staff app manager authorization');
file_put_contents(__DIR__.'/preview.html',$GLOBALS['rendered']);
echo 'PASS '.$checks.' SMS controls checks; no provider calls';
