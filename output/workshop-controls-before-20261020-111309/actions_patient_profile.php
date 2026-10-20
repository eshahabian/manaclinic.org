<?php
declare(strict_types=1);
$user = require_login(['PATIENT']);
require_once __DIR__ . '/../includes/mail.php';
csrf_verify();
$name = post('name');
$phone = post('phone') ?: null;
$email = mb_strtolower(trim(post('email')));
if ($name === '') {
    flash_set('error', 'نام الزامی است.');
    redirect('/dashboard/profile');
}
if (!mail_is_real_email($email)) {
    flash_set('error', 'یک ایمیل واقعی و معتبر وارد کنید.');
    redirect('/dashboard/profile');
}
$taken = $pdo->prepare('SELECT id FROM users WHERE LOWER(email)=? AND id<>? LIMIT 1');
$taken->execute([$email, $user['id']]);
if ($taken->fetch()) {
    flash_set('error', 'این ایمیل قبلاً برای حساب دیگری ثبت شده است.');
    redirect('/dashboard/profile');
}
$pdo->prepare('UPDATE users SET name=?, phone=?, email=? WHERE id=?')->execute([$name, $phone, $email, $user['id']]);
$_SESSION['user']['name'] = $name;
$_SESSION['user']['email'] = $email;
flash_set('success', 'پروفایل ذخیره شد.');
redirect('/dashboard/profile');
