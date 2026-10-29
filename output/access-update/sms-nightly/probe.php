<?php
declare(strict_types=1);
if(!hash_equals('TOKEN_HASH',hash('sha256',$_SERVER['HTTP_X_DIAGNOSTIC_TOKEN']??''))){http_response_code(404);exit;}
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
$root=dirname(__DIR__);$config=require $root.'/config.php';date_default_timezone_set('Asia/Tehran');foreach(['db','helpers','mail','outreach'] as $file)require_once $root.'/includes/'.$file.'.php';$pdo=db_connect($config);
$people=$pdo->query("SELECT dp.id, u.name,u.username,u.role,dp.is_active,dp.is_approved,COALESCE(u.is_disabled,0) AS disabled,CHAR_LENGTH(u.phone)>0 AS has_phone FROM doctor_profiles dp JOIN users u ON u.id=dp.user_id WHERE u.name LIKE '%گرانمایه%' OR u.name LIKE '%گارسچی%' OR u.name LIKE '%گرسچی%'")->fetchAll();
$cron=[];$rc=null;if(is_callable('exec')){exec('crontab -l 2>/dev/null',$cron,$rc);}
$matches=0;foreach($cron as $line)if(!str_starts_with(trim($line),'#')&&(str_contains($line,'sms-worker.php')||str_contains($line,'/cron/sms'))) $matches++;
$settings=[];foreach(['sms_worker_last_run','sms_cron_installed','sms_kind_enabled_doctor_appointment_booked','sms_kind_enabled_doctor_daily_digest','sms_kind_enabled_therapist_copy','sms_template_doctor_daily_digest'] as $key)$settings[$key]=mail_setting_get($pdo,$key,'');
echo json_encode(['now'=>date('c'),'timezone'=>date_default_timezone_get(),'people'=>$people,'settings'=>$settings,'shell_available'=>is_callable('exec'),'cron_matches'=>$matches,'cron_read_exit'=>$rc,'sms_ready'=>sms_connection_ready($pdo)],JSON_UNESCAPED_UNICODE);
