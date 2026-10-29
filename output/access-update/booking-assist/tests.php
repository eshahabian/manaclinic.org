<?php
$source=file_get_contents(__DIR__.'/file-0.txt');
foreach([0,1,2,3] as $i)token_get_all(file_get_contents(__DIR__.'/file-'.$i.'.txt'),TOKEN_PARSE);
require __DIR__.'/file-2.txt';
function e(string $s):string{return htmlspecialchars($s,ENT_QUOTES,'UTF-8');}
function url(string $s):string{return $s;}
function to_fa_digits(string $s):string{return $s;}
function csrf_field():string{return '<input name="csrf_token" value="fake-token" type="hidden">';}
function clinic_rooms_numbers():array{return [1,2,3];}
function clinic_room_label(int $n):string{return $n===4?'خارج از کلینیک':'اتاق '.$n;}
function appointment_normalize_session_mode(?string $s):string{return $s==='ONLINE'?'ONLINE':'IN_PERSON';}
function user_referral_options_html(...$a):string{return '<select id="legacy-referral"><option value="">انتخاب</option></select>';}
function appointment_minute_select_html(string $id,string $selected):string{$s='<div id="'.$id.'">';for($m=0;$m<60;$m+=5)$s.='<button type="button" data-minute="'.sprintf('%02d',$m).'"'.($m===0?' class="is-on"':'').'>'.$m.'</button>';return $s.'</div><p id="'.$id.'-readout" hidden></p>';}
$checks=0;function expect(bool $b,string $label):void{if(!$b)throw new Exception($label);$GLOBALS['checks']++;}
$rows=[['room_no'=>1,'kind'=>'BLOCK','starts_at'=>'2026-10-30 09:15:00','ends_at'=>'2026-10-30 10:45:00','patient_name'=>'PRIVATE'],['room_no'=>1,'starts_at'=>'2026-10-30 10:30:00','ends_at'=>'2026-10-30 11:00:00'],['room_no'=>2,'starts_at'=>'2026-10-30 08:00:00','ends_at'=>'2026-10-30 20:00:00'],['room_no'=>1,'kind'=>'APPOINTMENT','appt_status'=>'CANCELLED','starts_at'=>'2026-10-30 13:00:00','ends_at'=>'2026-10-30 15:00:00']];
$segments=booking_room_segments($rows,1,'2026-10-30');
expect($segments===[['start'=>'00:00','end'=>'09:15','busy'=>false],['start'=>'09:15','end'=>'11:00','busy'=>true],['start'=>'11:00','end'=>'24:00','busy'=>false]],'Exact free/busy intervals, merged overlap, cancellation and room isolation');
expect(!str_contains(json_encode($segments),'PRIVATE'),'No patient identity in response');
expect(booking_room_segments([],3,'2026-10-30')===[['start'=>'00:00','end'=>'24:00','busy'=>false]],'Empty room free all day');
expect(booking_room_segments([],4,'2026-10-30')===[],'Outside clinic excluded');
expect(booking_room_segments([],1,'2026-02-30')===[],'Invalid date rejected');
expect(booking_room_segments([['room_no'=>1,'starts_at'=>'2026-10-29 22:00:00','ends_at'=>'2026-10-31 02:00:00']],1,'2026-10-30')===[['start'=>'00:00','end'=>'24:00','busy'=>true]],'Cross-day clipping');
expect(booking_room_segments([['room_no'=>1,'starts_at'=>'2026-10-30 10:00:00','ends_at'=>'2026-10-30 09:00:00']],1,'2026-10-30')[0]['busy']===false,'Invalid interval ignored');
$w=$rows;$w[]=['room_no'=>1,'kind'=>'WORKSHOP','workshop_status'=>'CANCELLED','starts_at'=>'2026-10-30 18:00:00','ends_at'=>'2026-10-30 20:00:00'];expect(booking_room_segments($w,1,'2026-10-30')===$segments,'Cancelled workshop excluded');
$action=file_get_contents(__DIR__.'/file-3.txt');expect(str_contains($action,"require_login(['SECRETARY']);")&&str_contains($action,"header('Cache-Control: no-store')"),'Authenticated noncacheable endpoint');
$doctors=[['id'=>'d1','name'=>'درمانگر یک','specialty'=>'فردی','session_price'=>300000],['id'=>'d2','name'=>'درمانگر دو','specialty'=>'خانواده','session_price'=>400000]];
$patients=[['id'=>'p1','name'=>'مراجع نمونه','username'=>'private.user','phone'=>'09120000001','preferred_doctor_id'=>'d1','doctor_name'=>'درمانگر یک'],['id'=>'p2','name'=>'مراجع دوم','username'=>'hidden.user','phone'=>'09120000002','preferred_doctor_id'=>'d2','doctor_name'=>'درمانگر دو'],['id'=>'p3','name'=>'بدون درمانگر','phone'=>'09120000003'],['id'=>'p4','name'=>'سابقه ثابت','phone'=>'09120000004']];
class StableDb extends PDO{public string $sql='';public function __construct(){}public function query(string $q,?int $mode=null,mixed ...$args):PDOStatement|false{$this->sql=$q;return new StableStmt();}}
class StableStmt extends PDOStatement{public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$a):array{return [['patient_id'=>'p1','doctor_id'=>'d2'],['patient_id'=>'p4','doctor_id'=>'d2']];}}
$pdo=new StableDb();$a=strpos($source,'$bookingDoctors =');$b=strpos($source,'$preDoctorId =',$a);eval(substr($source,$a,$b-$a));
expect($patients[0]['preferred_doctor_id']==='d1','Saved assignment wins over history');expect($patients[3]['preferred_doctor_id']==='d2','Stable history suggests active doctor without DB write');expect(str_contains($pdo->sql,'COUNT(DISTINCT doctor_id)=1 AND COUNT(*)>=2'),'History requires multiple sessions with a single doctor');
$slotPrefillActive=false;$walletMap=[];$preDoctorId='';$preDate='';$preTime='';$preDateJalali='';$secretaryBookNext='/app/appointments?day=2026-10-30';$availByDoctor=['d1'=>['2026-10-30'],'d2'=>['2026-10-30']];
$session=file_get_contents(__DIR__.'/session.txt');foreach(['appointment_room_pick_html','appointment_session_mode_pick_html'] as $fn){$a=strpos($session,'function '.$fn.'(');$b=strpos($session,"\nfunction ",$a+1);eval(substr($session,$a,$b===false?null:$b-$a));}
$a=strpos($source,'ob_start();');$b=strpos($source,'$secretaryBookFormHtml = ob_get_clean();',$a);eval(substr($source,$a,$b-$a).'$secretaryBookFormHtml = ob_get_clean();');
$html=$secretaryBookFormHtml;
expect(!str_contains($html,'private.user')&&!str_contains($html,'hidden.user'),'No usernames in displayed or searchable patient labels');expect(str_contains($html,'مراجع نمونه · 09120000001 — درمانگر یک'),'Name phone and assigned therapist retained');expect(!str_contains($source,'دکتر'),'Booking labels consistently therapist');expect(str_contains($html,'id="sec-doctor-clear"')&&str_contains($html,'data-doctor="d1"'),'Clear button and assigned therapist metadata');expect(str_contains($html,'id="sec-room-availability"'),'Room status panel present');
$a=strpos($source,'$secretaryBookScripts =');eval(substr($source,$a));
expect(str_contains($secretaryBookScripts,'window.manaBookingRoomRefresh'),'Date refresh integrated with existing slots');
expect(str_contains(file_get_contents(__DIR__.'/file-1.txt'),"'GET /api/room-availability' => 'actions/room_availability.php'"),'Route registered');
echo json_encode(['checks'=>$checks,'html'=>$html,'scripts'=>$secretaryBookScripts,'segments'=>$segments],JSON_UNESCAPED_UNICODE);
