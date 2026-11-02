<?php
require 'D:/Psychology/cpanel/includes/helpers.php';
require 'D:/Psychology/cpanel/includes/sms_service.php';
$pdo=null;
echo json_encode(['body'=>sms_doctor_daily_digest_body('درمانگر نمونه','۱۱ آبان ۱۴۰۵',['09:30','10:30','13:00'],['09:30 — مراجع نمونه اول','10:30 — مراجع نمونه دوم','13:00 — مراجع نمونه سوم'])],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
