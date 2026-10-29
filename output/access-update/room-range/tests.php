<?php
$source=file_get_contents(__DIR__.'/page.txt');token_get_all($source,TOKEN_PARSE);
function e(string $s):string{return htmlspecialchars($s,ENT_QUOTES,'UTF-8');}
function url(string $s):string{return $s;}
function to_fa_digits(string $s):string{return strtr($s,['1'=>'۱','2'=>'۲','3'=>'۳']);}
function to_jalali_label(string $s):string{return '۱۴۰۵/۰۸/۰۷';}
function csrf_field():string{return '<input type="hidden" name="csrf_token" value="test-only">';}
function clinic_room_label(int $n):string{return 'اتاق '.to_fa_digits((string)$n);}
function clinic_room_purpose(array $r):string{return $r['title'];}
function format_fa_time(string $s):string{return date('H:i',strtotime($s));}
function clinic_rooms_jalali_input(string $s):string{return '۱۴۰۵/۰۸/۰۷';}
$checks=0;function expect(bool $ok,string $label):void{if(!$ok)throw new Exception($label);$GLOBALS['checks']++;}
$a=strpos($source,'    <section class="panel" id="room-reservations"');$b=strpos($source,"    <?php\n    staff_app_render('rooms'",$a);$template=substr($source,$a,$b-$a);
$roomDay='2030-10-29';$roomPrev='2030-10-28';$roomNext='2030-10-30';$roomJalali='۱۴۰۹/۰۸/۰۷';$numbers=[1,2,3];$clockChoices=['09:00','09:30','10:00','11:00'];$canManageApp=true;$error='';
$roomOpt=['patient_id'=>'<option value="patient-test">مراجع نمونه</option>','doctor_id'=>'<option value="doctor-test">درمانگر نمونه</option>','workshop_session_id'=>'<option value="workshop-test">کارگاه نمونه</option>'];
$byRoom=[];foreach($numbers as $n)$byRoom[$n]=[['id'=>'booking-'.$n,'room_no'=>$n,'starts_at'=>'2030-10-29 14:00:00','ends_at'=>'2030-10-29 15:00:00','kind'=>'BLOCK','title'=>'رزرو موجود اتاق '.$n,'note'=>'یادداشت نمونه']];
$fixtures=[];
foreach($numbers as $activeRoom){ob_start();eval('?>'.$template);$html=ob_get_clean();
 expect(substr_count($html,'class="sapp-check-tab')===4,'Tab group plus three tabs');
 expect(substr_count($html,'aria-current="page"')===1,'One active room');
 expect(substr_count($html,'value="assign"')===1,'One booking form');
 expect(strpos($html,'value="assign"')<strpos($html,'رزروهای ثبت‌شده'),'Form precedes reservations');
 expect(str_contains($html,'name="room_no" value="'.$activeRoom.'"'),'Room fixed to active tab');
 foreach($numbers as $n)expect(str_contains($html,'رزرو موجود اتاق '.$n)===($n===$activeRoom),'Other room reservations hidden');
 expect(str_contains($html,'day=2030-10-28&amp;room='.$activeRoom),'Day navigation retains room');
 expect(substr_count($html,'name="room_tab" value="'.$activeRoom.'"')===3,'Create edit release retain room');
 expect(str_contains($html,'name="action" value="update"')&&str_contains($html,'value="release_hour"'),'Existing management forms retained');
 $fixtures['room'.$activeRoom]=$html;
}
$activeRoom=1;$canManageApp=false;ob_start();eval('?>'.$template);$read=ob_get_clean();expect(!str_contains($read,'<form')&&str_contains($read,'رزرو موجود اتاق 1'),'Read only stays read only');
$canManageApp=true;$byRoom[1]=[];ob_start();eval('?>'.$template);$empty=ob_get_clean();expect(str_contains($empty,'در این روز رزروی نیست')&&str_contains($empty,'value="assign"'),'Empty room still bookable');$fixtures['empty']=$empty;
$activeRoom=2;
$a=strpos($source,"        $"."GLOBALS['pageScripts'] =",strpos($source,"if ($"."section === 'rooms')"));$b=strpos($source,"\n    }\n    $"."roomOpt",$a);eval(substr($source,$a,$b-$a));$scripts=$GLOBALS['pageScripts'];
expect(str_contains($scripts,'&room="+2'),'Date picker preserves room');
expect(str_contains($source,"in_array($"."activeRoom, $"."numbers, true)"),'Invalid room validation retained');
$baseline=file_get_contents(__DIR__.'/baseline.txt');$a=strpos($baseline,"if ($"."method === 'POST' && $"."path === '/app/rooms')");$b=strpos($baseline,"\nif ($"."method === 'POST'",$a+1);$old=substr($baseline,$a,$b-$a);$a=strpos($source,"if ($"."method === 'POST' && $"."path === '/app/rooms')");$b=strpos($source,"\nif ($"."method === 'POST'",$a+1);$new=substr($source,$a,$b-$a);
foreach(['csrf_verify();','if (!$canManageApp)','clinic_rooms_assign(','clinic_rooms_update(','clinic_rooms_release_hour('] as $guard)expect(substr_count($old,$guard)===substr_count($new,$guard),'Booking protection preserved '.$guard);
$clockSource=file_get_contents(__DIR__.'/clock.txt');
foreach(['clinic_rooms_parse_clock','clinic_rooms_hour_end'] as $fn){$a=strpos($clockSource,'function '.$fn.'(');$b=strpos($clockSource,"\nfunction ",$a+1);eval(substr($clockSource,$a,$b===false?null:$b-$a));}
foreach(['14:30'=>'14:30','۱۴:۳۰'=>'14:30','۸'=>'08:00','9.15'=>'09:15'] as $raw=>$expected)expect(clinic_rooms_parse_clock((string)$raw)===$expected,'Manual time parsed '.$raw);
expect(clinic_rooms_parse_clock('24:00')===null&&clinic_rooms_parse_clock('12:60')===null,'Invalid time rejected');
expect(clinic_rooms_hour_end('۱۴:۳۰')==='15:30','Legacy one-hour helper still works');
expect(!str_contains($fixtures['room1'],'<select class="input" name="start_time"')&&substr_count($fixtures['room1'],'type="text" name="start_time"')===2,'Create and edit allow manual time');
expect(str_contains($fixtures['room1'],'value="14:00"'),'Existing booking time preserved in edit');
foreach($fixtures as $html)expect(str_contains($html,'name="end_time"')&&str_contains($html,'ساعت اتمام'),'Separate manual end-time field present');
expect(!str_contains($fixtures['room1'],'name="hour_only"'),'New forms do not force one hour');
expect(str_contains($fixtures['room1'],'name="end_time" dir="ltr" autocomplete="off" maxlength="5" list="room-clock-options" placeholder="16:00" required value="15:00"'),'Edit prefills original end time');
expect(str_contains($source,"post('hour_only') === '1' && trim((string) post('end_time')) === ''"),'Explicit end is never overridden by legacy flag');
$canManageApp=true;$activeRoom=1;$byRoom[1]=[['id'=>'long-booking','room_no'=>1,'starts_at'=>'2030-10-29 14:30:00','ends_at'=>'2030-10-29 17:00:00','slice_start'=>'2030-10-29 15:30:00','slice_end'=>'2030-10-29 16:30:00','kind'=>'BLOCK','title'=>'رزرو چندساعته']];ob_start();eval('?>'.$template);$longHtml=ob_get_clean();
expect(str_contains($longHtml,'value="update"')&&str_contains($longHtml,'value="14:30"')&&str_contains($longHtml,'value="17:00"'),'Multi-hour reservation editable with full original range');
$fixtures['long']=$longHtml;
echo json_encode(['checks' =>$checks,'fixtures'=>$fixtures,'scripts'=>$scripts],JSON_UNESCAPED_UNICODE);
