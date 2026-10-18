<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$config=require __DIR__.'/config.php';
date_default_timezone_set($config['timezone']??'Asia/Tehran');
require_once __DIR__.'/includes/db.php';
require_once __DIR__.'/includes/helpers.php';
require_once __DIR__.'/includes/mail.php';
require_once __DIR__.'/includes/outreach.php';
require_once __DIR__.'/includes/workshop_sessions.php';
$pdo=db_connect($config);
ensure_mail_schema($pdo);
ensure_workshop_sessions_schema($pdo);
sms_run_worker($pdo);
mail_setting_set($pdo,'sms_worker_last_run',date('Y-m-d H:i:s'));
echo "SMS worker completed.\n";
