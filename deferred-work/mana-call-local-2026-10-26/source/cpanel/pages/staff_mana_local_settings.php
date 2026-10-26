<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/mana_local_call.php';
if (!staff_app_is_eshahabian($user ?? null)) { http_response_code(403); exit('دسترسی ندارید.'); }
header('Cache-Control: no-store');
$error='';$notice='';
if ($method==='POST') {
    csrf_verify();
    try {
        if (post('action')==='check_local') {
            mana_local_call_config();
            $notice='تنظیمات تماس داخلی کامل است. این بررسی جای تست واقعی صدا و تصویر را نمی‌گیرد.';
        } elseif (post('action')==='switch') {
            mana_call_set_mode($pdo,post('mode'));
            flash_set('success','روش تماس‌های جدید تغییر کرد. تماس جاری خودکار جابه‌جا نمی‌شود.');
            redirect('/app/mana-call-settings');
        } else throw new RuntimeException('درخواست نامعتبر است.');
    } catch (RuntimeException $e) { $error=$e->getMessage(); }
    catch (Throwable $e) { error_log('Local call settings: '.$e->getMessage()); $error='ذخیره تنظیمات انجام نشد.'; }
} elseif ($method!=='GET') { http_response_code(405);exit; }
$active=mana_call_mode($pdo);
$probeIce=null;
try { $probeIce=mana_local_call_ice($user); } catch (RuntimeException $e) { /* Keep current LiveKit available. */ }
ob_start(); ?>
<section class="panel stack" dir="rtl"><h1>روش تماس مانا</h1>
<p>روش تماس‌های جدید: <strong><?=$active==='local'?'سرور داخلی سایت':'LiveKit فعلی'?></strong></p>
<p>تنظیمات LiveKit تغییر نمی‌کند. انتخاب این صفحه برای تماس‌های جدید همه کاربران است؛ تماس جاری با روش قبلی ادامه می‌یابد. برای شروع دوبارهٔ همان جلسه، درمانگر تماس جدید را از فهرست مخاطبان آغاز کند.</p>
<p>روش داخلی به نصب یک‌بارهٔ سرویس TURN روی سرور سایت نیاز دارد. فرستادن فایل‌های PHP با GitHub به‌تنهایی این سرویس را نصب نمی‌کند.</p>
<p data-local-check-status role="status"><?=$active==='local'?'روش داخلی فعال است.':($probeIce?'در حال بررسی دسترسی مرورگر به واسط داخلی…':'تنظیمات خصوصی واسط داخلی هنوز آماده نیست.')?></p>
<?php if($notice!==''):?><p role="status"><?=e($notice)?></p><?php endif;?>
<?php if($error!==''):?><p role="alert"><?=e($error)?></p><?php endif;?>
<form method="post" action="<?=e(url('/app/mana-call-settings'))?>"><?=csrf_field()?>
<button class="btn btn-outline" name="action" value="check_local">بررسی تنظیمات سرور داخلی</button></form>
<?php foreach(['livekit'=>'LiveKit فعلی','local'=>'سرور داخلی سایت'] as $mode=>$label):?>
<form method="post" action="<?=e(url('/app/mana-call-settings'))?>"><?=csrf_field()?>
<input type="hidden" name="mode" value="<?=e($mode)?>">
<button class="btn btn-primary" name="action" value="switch" <?=$mode==='local'?'data-enable-local':''?> <?=$active===$mode||$mode==='local'?'disabled':''?>><?=$active===$mode?'فعال: ':'تماس‌های جدید با '?><?=e($label)?></button>
</form><?php endforeach;?>
<a href="<?=e(url('/app'))?>">بازگشت</a></section>
<?php if($probeIce && $active!=='local'):?>
<script type="application/json" data-local-check-config><?=json_encode($probeIce,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?></script>
<script src="<?=e(url('/assets/js/mana-call-local-check.js'))?>?v=20261025"></script>
<?php endif;?>
<?php staff_app_render('home','روش تماس مانا','ویژه عماد شهابیان',ob_get_clean());
