<?php
declare(strict_types=1);

$name = post('name');
$username = mb_strtolower(post('username'));
$phone = post('phone') ?: null;
$password = (string) ($_POST['password'] ?? '');

if ($name === '' || $username === '' || strlen($password) < 6) {
    flash_set('error', 'اطلاعات ناقص است. رمز حداقل ۶ کاراکتر باشد.');
    redirect('/register');
}
if (!preg_match('/^[a-z0-9._-]{3,32}$/', $username)) {
    flash_set('error', 'نام کاربری نامعتبر است.');
    redirect('/register');
}

$exists = $pdo->prepare('SELECT id FROM users WHERE username = ?');
$exists->execute([$username]);
if ($exists->fetch()) {
    flash_set('error', 'این نام کاربری قبلاً ثبت شده است.');
    redirect('/register');
}

$id = cuid();
$email = $username . '@manaclinic.local';
$pdo->prepare('INSERT INTO users (id,username,name,email,phone,password_hash,role,must_change_password) VALUES (?,?,?,?,?,?,?,0)')
    ->execute([$id, $username, $name, $email, $phone, password_hash($password, PASSWORD_DEFAULT), 'PATIENT']);

login_user([
    'id' => $id,
    'name' => $name,
    'email' => $email,
    'username' => $username,
    'role' => 'PATIENT',
    'must_change_password' => 0,
]);
flash_set('success', 'ثبت‌نام با موفقیت انجام شد.');
redirect('/dashboard');
