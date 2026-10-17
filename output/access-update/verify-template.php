<?php
header('Content-Type: text/plain');
foreach (glob(__DIR__ . '/includes/check_TAG_*.php') as $file) { token_get_all(file_get_contents($file), TOKEN_PARSE); }
require __DIR__ . '/includes/check_TAG_includes_staff_app.php';
$doctor = ['role'=>'DOCTOR','name'=>'درمانگر دیگر','username'=>'other'];
$cases = [staff_app_allowed($doctor), staff_app_limited_doctor($doctor), !staff_app_allowed(['role'=>'PATIENT']), !staff_app_sees_clinic_schedule($doctor)];
foreach ([['role'=>'SECRETARY'],['role'=>'ADMIN','username'=>'eshahabian'],['role'=>'DOCTOR','username'=>'shgeranmaye'],['role'=>'DOCTOR','name'=>'عطیه گارسچی']] as $user) {
    $cases[] = staff_app_allowed($user) && !staff_app_limited_doctor($user) && staff_app_path_allowed($user,'/app/rooms','POST');
}
foreach (['/app','/app/appointments','/app/chat','/app/chat/'.str_repeat('a',24),'/app/chat/'.str_repeat('a',24).'/receipts','/app/file/'.str_repeat('b',24),'/app/presence'] as $path) { $cases[] = staff_app_path_allowed($doctor,$path,'GET'); }
foreach (['/app/rooms','/app/hours','/app/consult','/app/checklist','/app/petty-cash','/app/complaints','/app/balances','/app/therapists','/app/profile','/app/chat/archive'] as $path) {
    $cases[] = !staff_app_path_allowed($doctor,$path,'GET');
    $cases[] = !staff_app_path_allowed($doctor,$path,'POST');
}
$cases[] = !staff_app_path_allowed($doctor,'/app/appointments','POST');
$cases[] = staff_app_path_allowed($doctor,'/app/chat','POST');
if (in_array(false,$cases,true)) { http_response_code(500); exit('FAILED'); }
echo 'PASS '.count($cases).' permission checks; three PHP files parsed.';
