<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/outreach.php';
require_once __DIR__ . '/../includes/mail.php';
require_once __DIR__ . '/../includes/clinic_manage.php';
require_site_admin();

$action = post('action');
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
    if (!is_valid_phone($phone)) {
        flash_set('error', 'شماره موبایل معتبر نیست.');
        redirect('/admin/sms-test');
    }
    if ($body === '') {
        flash_set('error', 'متن پیامک را بنویسید.');
        redirect('/admin/sms-test');
    }
    sms_queue($pdo, $phone, $body, 'test');
    flash_set('success', 'پیامک در صف ماند. تا وقتی پنل پیامک آماده نشود ارسال نمی‌شود.');
    redirect('/admin/sms-test');
}

if ($action === 'save_sms_notify') {
    foreach (['quiet', 'workshop', 'approval'] as $kind) {
        mail_setting_set($pdo, 'sms_notify_' . $kind, isset($_POST['notify_' . $kind]) ? '1' : '0');
    }
    flash_set('success', 'تنظیم پیامک اطلاع‌رسانی ذخیره شد. ارسال واقعی هنوز خاموش است.');
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
    flash_set('success', to_fa_digits((string) $count) . ' پیامک در صف ماند و تا آماده شدن پنل ارسال نمی‌شود.');
    redirect('/admin/sms-broadcast');
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
