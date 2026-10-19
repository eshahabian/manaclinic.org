<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/login_otp.php';
$next=safe_next_path(post('next'));
$retry='/login?method=sms'.($next?'&next='.rawurlencode($next):'');
if(!sms_connection_ready($pdo)){
    flash_set('error','ورود پیامکی پس از تکمیل تنظیمات اتصال پیامک فعال می‌شود. فعلاً با رمز عبور وارد شوید.');redirect($retry);
}
login_otp_schema($pdo);
$phone=sms_iran_mobile(post('phone'));

if(!login_otp_limit($pdo,'ip:'.client_ip(),10,3600) || !login_otp_limit($pdo,'phone:'.($phone??'invalid'),3,600)){
    flash_set('error','درخواست کد زیاد بود. چند دقیقه بعد دوباره تلاش کنید.');redirect($retry);
}
// Invalidate this browser's previous challenge, including on a failed request.
if(!empty($_SESSION['login_otp_id']))$pdo->prepare('UPDATE login_otp SET used=1 WHERE id=?')->execute([$_SESSION['login_otp_id']]);
$id=bin2hex(random_bytes(32));$_SESSION['login_otp_id']=$id;
// Compare canonical Iranian numbers, including older stored +98/Persian formats.
// A shared number must never choose an arbitrary account.
$user=null;
if($phone){
    $s=$pdo->query("SELECT id,phone FROM users WHERE phone IS NOT NULL AND phone<>''");
    foreach($s->fetchAll() as $candidate){
        if(sms_iran_mobile((string)$candidate['phone'])!==$phone || user_account_is_disabled($pdo,(string)$candidate['id']))continue;
        if($user!==null){$user=null;break;}
        $user=$candidate;
    }
}
if($phone && $user && sms_iran_mobile((string)$user['phone'])===$phone && !user_account_is_disabled($pdo,(string)$user['id'])){
    $code=(string)random_int(100000,999999);
    $pdo->prepare('INSERT INTO login_otp(id,user_id,code_hash,expires_at) VALUES(?,?,?,?)')->execute([$id,$user['id'],password_hash($code,PASSWORD_DEFAULT),date('Y-m-d H:i:s',time()+180)]);
    // Send immediately; never put authentication secrets in the visible SMS archive.
    $result=sms_provider_send(sms_connection_config($pdo),$phone,sms_template_render($pdo,'login_otp',['code'=>$code]));
    mail_setting_set($pdo,'sms_otp_last_status',(string)$result['status']);
    mail_setting_set($pdo,'sms_otp_last_at',date('Y-m-d H:i:s'));
    mail_setting_set($pdo,'sms_otp_last_error',(string)($result['error']??''));
    if($result['status']!=='SENT'){
        $pdo->prepare('UPDATE login_otp SET used=1 WHERE id=?')->execute([$id]);
        unset($_SESSION['login_otp_id']);
        flash_set('error','ارسال کد ورود با مشکل روبه‌رو شد. کمی بعد دوباره تلاش کنید یا با رمز عبور وارد شوید.');
        redirect($retry);
    }
    unset($code);
}
$pdo->exec('DELETE FROM login_otp WHERE expires_at<DATE_SUB(NOW(),INTERVAL 1 DAY)');
$pdo->exec('DELETE FROM login_otp_limits WHERE started_at<UNIX_TIMESTAMP()-86400');
flash_set('info','اگر شماره موبایل به یک حساب فعال متصل باشد، کد ورود ارسال می‌شود. کد ۳ دقیقه اعتبار دارد.');
redirect($retry);
