<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/login_otp.php';
$next=safe_next_path(post('next'));
$retry='/login'.($next?'?next='.rawurlencode($next):'');
if(!sms_connection_ready($pdo)){
    flash_set('error','ورود پیامکی پس از تکمیل تنظیمات اتصال پیامک فعال می‌شود. فعلاً با رمز عبور وارد شوید.');redirect($retry);
}
login_otp_schema($pdo);
$phone=sms_iran_mobile(post('phone'));
$username=mb_strtolower(normalize_input(post('username')));
if(!login_otp_limit($pdo,'ip:'.client_ip(),10,3600) || !login_otp_limit($pdo,'phone:'.($phone??'invalid'),3,600)){
    flash_set('error','درخواست کد زیاد بود. چند دقیقه بعد دوباره تلاش کنید.');redirect($retry);
}
// Invalidate this browser's previous challenge, including on a failed request.
if(!empty($_SESSION['login_otp_id']))$pdo->prepare('UPDATE login_otp SET used=1 WHERE id=?')->execute([$_SESSION['login_otp_id']]);
$id=bin2hex(random_bytes(32));$_SESSION['login_otp_id']=$id;
$s=$pdo->prepare('SELECT * FROM users WHERE username=? LIMIT 1');$s->execute([$username]);$user=$s->fetch();
if($phone && $user && sms_iran_mobile((string)$user['phone'])===$phone && !user_account_is_disabled($pdo,(string)$user['id'])){
    $code=(string)random_int(100000,999999);
    $pdo->prepare('INSERT INTO login_otp(id,user_id,code_hash,expires_at) VALUES(?,?,?,?)')->execute([$id,$user['id'],password_hash($code,PASSWORD_DEFAULT),date('Y-m-d H:i:s',time()+180)]);
    // Send immediately; never put authentication secrets in the visible SMS archive.
    $result=sms_provider_send(sms_connection_config($pdo),$phone,"مانا کلینیک\nکد ورود: ".$code."\nاعتبار: ۳ دقیقه\nاین کد را در اختیار دیگران نگذارید.");
    if($result['status']!=='SENT')$pdo->prepare('UPDATE login_otp SET used=1 WHERE id=?')->execute([$id]);
    unset($code);
}
$pdo->exec('DELETE FROM login_otp WHERE expires_at<DATE_SUB(NOW(),INTERVAL 1 DAY)');
$pdo->exec('DELETE FROM login_otp_limits WHERE started_at<UNIX_TIMESTAMP()-86400');
flash_set('info','اگر نام کاربری و موبایل ثبت‌شده مطابقت داشته باشند، کد ورود ارسال می‌شود. کد ۳ دقیقه اعتبار دارد.');
redirect($retry);
