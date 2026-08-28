<?php
declare(strict_types=1);

$name = post('name');
$email = mb_strtolower(post('email'));
$phone = post('phone') ?: null;
$password = (string) ($_POST['password'] ?? '');

if ($name === '' || $email === '' || strlen($password) < 6) {
    flash_set('error', 'اطلاعات ناقص است. رمز حداقل ۶ کاراکتر باشد.');
    redirect('/register');
}

$exists = $pdo->prepare('SELECT id FROM users WHERE email = ?');
$exists->execute([$email]);
if ($exists->fetch()) {
    flash_set('error', 'این ایمیل قبلاً ثبت شده است.');
    redirect('/register');
}

$id = cuid();
$pdo->prepare('INSERT INTO users (id,name,email,phone,password_hash,role) VALUES (?,?,?,?,?,?)')
    ->execute([$id, $name, $email, $phone, password_hash($password, PASSWORD_DEFAULT), 'PATIENT']);

login_user([
    'id' => $id,
    'name' => $name,
    'email' => $email,
    'role' => 'PATIENT',
]);
flash_set('success', 'ثبت‌نام با موفقیت انجام شد.');
redirect('/dashboard');
