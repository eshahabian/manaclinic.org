<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/mail.php';
require_login(['ADMIN']);

$action = post('action');
ensure_mail_schema($pdo);

if ($action === 'save_host') {
    $host = trim(post('smtp_host'));
    $allowed = array_keys(mail_host_options());
    if ($host === '' || (!in_array($host, $allowed, true) && !preg_match('/^[a-z0-9.-]+$/i', $host))) {
        flash_set('error', 'میزبان SMTP نامعتبر است.');
        redirect('/admin/mail');
    }
    mail_setting_set($pdo, 'mail_smtp_host', $host);
    if ($host === 'localhost') {
        mail_setting_set($pdo, 'mail_smtp_port', '25');
        mail_setting_set($pdo, 'mail_smtp_encryption', 'none');
    } elseif ($host === 'smtp.gmail.com') {
        mail_setting_set($pdo, 'mail_smtp_port', '587');
        mail_setting_set($pdo, 'mail_smtp_encryption', 'tls');
    } else {
        mail_setting_set($pdo, 'mail_smtp_port', '587');
        mail_setting_set($pdo, 'mail_smtp_encryption', 'tls');
    }
    mail_setting_set($pdo, 'mail_smtp_user', mail_default_from_email());
    mail_setting_set($pdo, 'mail_from_email', mail_default_from_email());
    flash_set('success', 'میزبان SMTP ذخیره شد.');
    redirect('/admin/mail');
}

if ($action === 'save_pass') {
    $pass = (string) ($_POST['smtp_pass'] ?? '');
    if ($pass === '') {
        flash_set('error', 'برای ذخیره، رمز جدید را بنویسید.');
        redirect('/admin/mail');
    }
    mail_setting_set($pdo, 'mail_smtp_pass', $pass);
    mail_setting_set($pdo, 'mail_smtp_user', mail_default_from_email());
    flash_set('success', 'رمز SMTP در دیتابیس ذخیره شد.');
    redirect('/admin/mail');
}

if ($action === 'clear_pass') {
    mail_setting_delete($pdo, 'mail_smtp_pass');
    flash_set('success', 'رمز دیتابیس پاک شد؛ در صورت وجود از config.php استفاده می‌شود.');
    redirect('/admin/mail');
}

if ($action === 'probe') {
    $result = mail_smtp_probe($pdo);
    $_SESSION['mail_probe_log'] = $result['log'];
    flash_set($result['ok'] ? 'success' : 'error', $result['detail']);
    redirect('/admin/mail');
}

if ($action === 'send_test') {
    $to = trim(post('test_to'));
    $_SESSION['mail_test_to'] = $to;
    if (!mail_is_real_email($to)) {
        flash_set('error', 'آدرس ایمیل تست معتبر نیست.');
        redirect('/admin/mail');
    }
    $html = mail_wrap_html(
        'ایمیل تست',
        '<p>این یک ایمیل تست از پنل ادمین مانا کلینیک است.</p>'
        . '<p>اگر این پیام را دیدید، تنظیمات SMTP درست کار می‌کند.</p>'
    );
    $result = mail_send($pdo, $to, 'تست ایمیل مانا کلینیک', $html, "این یک ایمیل تست از مانا کلینیک است.\n");
    if (!empty($result['log'])) {
        $_SESSION['mail_probe_log'] = (string) $result['log'];
    }
    if ($result['ok']) {
        flash_set('success', 'ایمیل تست به ' . $to . ' ارسال شد.');
    } else {
        flash_set('error', 'ارسال تست ناموفق: ' . ($result['error'] ?? 'خطای ناشناخته'));
    }
    redirect('/admin/mail');
}

if ($action === 'reset_throttle') {
    throttle_clear('forgot_password');
    throttle_clear('register');
    throttle_clear('login');
    flash_set('success', 'محدودیت درخواست IP شما ریست شد.');
    redirect('/admin/mail');
}

flash_set('error', 'درخواست نامعتبر است.');
redirect('/admin/mail');
