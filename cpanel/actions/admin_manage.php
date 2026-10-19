<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/outreach.php';
require_once __DIR__ . '/../includes/mail.php';
require_once __DIR__ . '/../includes/clinic_manage.php';
$action = post('action');
if ($action==='save_sms_settings' || $action==='sms_doctor_digest_manual') require_sms_settings_operator();
elseif (in_array($action,['sms_test','sms_broadcast','save_sms_notify'],true)) require_sms_operator();
else require_site_admin();
ensure_mail_schema($pdo);
ensure_outreach_schema($pdo);

if ($action === 'save_messengers') {
    foreach (array_keys(clinic_messenger_catalog($pdo)) as $key) {
        $url = trim(post('url_' . $key));
        if ($url !== '' && !preg_match('#^https?://#i', $url)) {
            flash_set('error', 'آدرس پیام‌رسان باید با http:// یا https:// شروع شود.');
            redirect('/admin/messengers');
        }
        if (preg_match('/[\r\n\s]/', $url)) {
            flash_set('error', 'آدرس پیام‌رسان نامعتبر است.');
            redirect('/admin/messengers');
        }
        mail_setting_set($pdo, 'messenger_' . $key . '_on', isset($_POST['on_' . $key]) ? '1' : '0');
        mail_setting_set($pdo, 'messenger_' . $key . '_url', $url);
    }
    flash_set('success', 'دسترسی پیام‌رسان‌ها ذخیره شد.');
    redirect('/admin/messengers');
}

if ($action === 'sms_test') {
    $phone = normalize_phone(post('phone'));
    $body = trim(post('body'));
    if (sms_iran_mobile($phone) === null) {
        flash_set('error', 'فقط شماره موبایل ایران برای پیامک قابل استفاده است.');
        redirect('/admin/sms-test');
    }
    if ($body === '') {
        flash_set('error', 'متن پیامک را بنویسید.');
        redirect('/admin/sms-test');
    }
    sms_queue($pdo, $phone, $body, 'test');
    if (!sms_panel_enabled()) {
        flash_set('error', 'پیامک ذخیره شد؛ تا تکمیل خط و تنظیمات اتصال ارسال نمی‌شود.');
        redirect('/admin/sms-test');
    }
    // Without cron, test SMS would sit in PENDING forever — dispatch once now.
    sms_dispatch_pending($pdo, 5);
    $latest = $pdo->prepare("SELECT status,last_error FROM sms_outbox WHERE phone=? AND kind='test' ORDER BY created_at DESC LIMIT 1");
    $latest->execute([sms_iran_mobile($phone)]);
    $row = $latest->fetch();
    if ($row && $row['status'] === 'SENT') {
        flash_set('success', 'پیامک آزمایشی پذیرفته شد.');
    } elseif ($row && in_array($row['status'], ['FAILED', 'UNKNOWN'], true)) {
        flash_set('error', 'ارسال آزمایشی ناموفق بود' . (!empty($row['last_error']) ? (': ' . $row['last_error']) : '.'));
    } else {
        flash_set('success', 'پیامک آزمایشی در صف ارسال قرار گرفت.');
    }
    redirect('/admin/sms-test');
}

if ($action === 'save_sms_notify') {
    $templates=[];
    foreach(sms_template_catalog() as $kind=>$item){
        $text=trim(post('template_'.$kind));
        preg_match_all('/\{[^{}]+\}/u',$item[1],$matches);
        $extraPatient=in_array($kind,['appointment_booked','appointment_cancelled','appointment_reminder','workshop_registered','workshop_reminder','doctor_appointment_booked','doctor_appointment_cancelled'],true);
        $allowed=array_unique(array_merge($matches[0],$extraPatient?['{patient}']:[]));
        preg_match_all('/\{[^{}]+\}/u',$text,$used);
        if(mb_strlen($text)>500 || array_diff($used[0],$allowed) || ($kind==='login_otp' && $text!=='' && !str_contains($text,'{code}'))){
            flash_set('error','متن پیامک معتبر نیست؛ حداکثر ۵۰۰ نویسه و فقط متغیرهای راهنما مجاز است. کد ورود باید {code} داشته باشد.');redirect('/admin/sms-notify');
        }
        $templates[$kind]=$text;
    }
    foreach($templates as $kind=>$text)mail_setting_set($pdo,'sms_template_'.$kind,$text);

    foreach (['quiet', 'workshop', 'approval'] as $kind) {
        mail_setting_set($pdo, 'sms_notify_' . $kind, isset($_POST['notify_' . $kind]) ? '1' : '0');
    }
    flash_set('success', 'تنظیم پیامک اطلاع‌رسانی ذخیره شد.');
    redirect('/admin/sms-notify');
}

if ($action === 'sms_broadcast') {
    $audience = post('audience');
    $allowed = ['patients', 'doctors', 'secretaries', 'outreach', 'everyone'];
    $body = trim(post('body'));
    if (!in_array($audience, $allowed, true)) {
        flash_set('error', 'گیرندگان را انتخاب کنید.');
        redirect('/admin/sms-broadcast');
    }
    if ($body === '') {
        flash_set('error', 'متن پیامک را بنویسید.');
        redirect('/admin/sms-broadcast');
    }
    $count = sms_broadcast_queue($pdo, $audience, $body);
    flash_set('success', to_fa_digits((string) $count) . ' پیامک برای شماره‌های ایران در صف قرار گرفت.' . (sms_panel_enabled() ? '' : ' ارسال تا تکمیل تنظیمات خاموش است.'));
    redirect('/admin/sms-broadcast');
}

if ($action === 'sms_doctor_digest_manual') {
    $ymd = trim((string) post('date'));
    $doctorIds = $_POST['doctor_ids'] ?? [];
    if (!is_array($doctorIds)) {
        $doctorIds = [];
    }
    $doctorIds = array_values(array_filter(array_map('strval', $doctorIds)));
    $back = '/admin/sms-doctor-digest' . (preg_match('/^\d{4}-\d{2}-\d{2}$/', $ymd) ? ('?date=' . rawurlencode($ymd)) : '');
    $result = sms_send_doctor_digests_manual($pdo, $ymd, $doctorIds);
    $parts = [];
    if (($result['sent'] ?? 0) > 0) {
        $parts[] = to_fa_digits((string) $result['sent']) . ' پیامک ارسال شد';
    }
    if (($result['failed'] ?? 0) > 0) {
        $parts[] = to_fa_digits((string) $result['failed']) . ' ناموفق';
    }
    if (($result['skipped'] ?? 0) > 0) {
        $parts[] = to_fa_digits((string) $result['skipped']) . ' رد شد (بدون نوبت یا بدون موبایل)';
    }
    $detail = ($result['errors'] ?? []) !== [] ? (' ' . implode('؛ ', array_slice($result['errors'], 0, 4))) : '';
    if (($result['sent'] ?? 0) > 0 && ($result['failed'] ?? 0) === 0) {
        flash_set('success', implode(' · ', $parts) . '.' . $detail);
    } elseif (($result['sent'] ?? 0) > 0) {
        flash_set('error', implode(' · ', $parts) . '.' . $detail);
    } else {
        flash_set('error', ($parts !== [] ? implode(' · ', $parts) . '.' : 'ارسال انجام نشد.') . $detail);
    }
    redirect($back);
}

if ($action === 'save_sms_settings') {
    $sender=trim(post('sender'));
    if ($sender!=='' && !preg_match('/^\+?\d{4,20}$/D',$sender)) {
        flash_set('error','شماره خط فرستنده معتبر نیست.');redirect('/admin/sms-settings');
    }
    mail_setting_set($pdo,'sms_sender',$sender);
    mail_setting_set($pdo,'sms_username',trim(post('username')));
    foreach(['password','api_key'] as $name) if(trim(post($name))!=='') mail_setting_set($pdo,'sms_'.$name,trim(post($name)));
    mail_setting_set($pdo,'sms_enabled',isset($_POST['enabled'])?'1':'0');
    flash_set('success',sms_connection_ready($pdo)?'تنظیمات ذخیره شد؛ ارسال پیامک فعال است.':'تنظیمات ذخیره شد؛ تا تکمیل خط و کلید API (یا نام کاربری/رمز) ارسال خاموش می‌ماند.');
    redirect('/admin/sms-settings');
}

if ($action === 'save_gateway') {
    $merchant = trim(post('merchant_id'));
    if ($merchant !== '' && !preg_match('/^[a-zA-Z0-9-]{8,80}$/', $merchant)) {
        flash_set('error', 'شناسه پذیرنده زرین‌پال نامعتبر است.');
        redirect('/admin/gateway');
    }
    mail_setting_set($pdo, 'gateway_merchant_id', $merchant);
    mail_setting_set($pdo, 'gateway_sandbox', isset($_POST['sandbox']) ? '1' : '0');
    mail_setting_set($pdo, 'gateway_online', isset($_POST['online']) ? '1' : '0');
    flash_set('success', 'تنظیم درگاه پرداخت ذخیره شد.');
    redirect('/admin/gateway');
}

flash_set('error', 'درخواست نامعتبر است.');
redirect('/admin');
