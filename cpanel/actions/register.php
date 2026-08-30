<?php
declare(strict_types=1);

// تغییر نوع حساب فقط برای نمایش فرم (بدون ثبت)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['submit_register'])) {
    $role = post('role') === 'DOCTOR' ? 'DOCTOR' : 'PATIENT';
    redirect('/register?role=' . $role);
}

$name = post('name');
$username = mb_strtolower(post('username'));
$phone = post('phone') ?: null;
$password = (string) ($_POST['password'] ?? '');
$role = post('role') === 'DOCTOR' ? 'DOCTOR' : 'PATIENT';
$specialty = post('specialty');

if ($name === '' || $username === '' || strlen($password) < 6) {
    flash_set('error', 'اطلاعات ناقص است. رمز حداقل ۶ کاراکتر باشد.');
    redirect('/register?role=' . $role);
}
if (!preg_match('/^[a-z0-9._-]{3,32}$/', $username)) {
    flash_set('error', 'نام کاربری نامعتبر است.');
    redirect('/register?role=' . $role);
}
if ($role === 'DOCTOR' && $specialty === '') {
    flash_set('error', 'برای ثبت‌نام به‌عنوان درمانگر، تخصص الزامی است.');
    redirect('/register?role=DOCTOR');
}

$exists = $pdo->prepare('SELECT id FROM users WHERE username = ?');
$exists->execute([$username]);
if ($exists->fetch()) {
    flash_set('error', 'این نام کاربری قبلاً ثبت شده است.');
    redirect('/register?role=' . $role);
}

$id = cuid();
$email = $username . '@manaclinic.local';

if ($role === 'DOCTOR') {
    $pdo->prepare('INSERT INTO users (id,username,name,email,phone,password_hash,role,must_change_password) VALUES (?,?,?,?,?,?,?,0)')
        ->execute([$id, $username, $name, $email, $phone, password_hash($password, PASSWORD_DEFAULT), 'DOCTOR']);
    $pdo->prepare('INSERT INTO doctor_profiles (id,user_id,specialty,bio,session_price,is_approved,is_active) VALUES (?,?,?,?,?,?,?)')
        ->execute([cuid(), $id, $specialty, '', 3000000, 0, 0]);
    flash_set('success', 'درخواست ثبت‌نام شما ثبت شد. پس از تأیید مدیر سایت می‌توانید وارد شوید.');
    redirect('/login');
}

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
