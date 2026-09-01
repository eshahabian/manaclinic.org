<?php
declare(strict_types=1);

// تغییر نوع حساب فقط برای نمایش فرم (بدون ثبت)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['submit_register'])) {
    $role = post('role') === 'DOCTOR' ? 'DOCTOR' : 'PATIENT';
    redirect('/register?role=' . $role);
}

$firstName = trim(post('first_name'));
$lastName = trim(post('last_name'));
$nameEn = trim(post('name_en'));
$surname = trim(post('surname'));
if ($nameEn === '' && $firstName !== '') {
    $nameEn = transliterate_persian_name($firstName);
}
if ($surname === '' && $lastName !== '') {
    $surname = transliterate_persian_name($lastName);
}
$name = trim($firstName . ' ' . $lastName);
$username = mb_strtolower(post('username'));
if ($username === '') {
    $base = username_base_from_names($nameEn, $surname, $firstName, $lastName);
    $username = unique_username($pdo, $base);
}
$phone = trim(post('phone'));
$password = (string) ($_POST['password'] ?? '');
$passwordConfirm = (string) ($_POST['password_confirm'] ?? '');
$role = post('role') === 'DOCTOR' ? 'DOCTOR' : 'PATIENT';
$specialty = post('specialty');
$preferredDoctorId = post('preferred_doctor_id') ?: null;

if ($firstName === '' || $lastName === '' || $nameEn === '' || $surname === '' || $username === '' || $phone === '' || strlen($password) < 6) {
    flash_set('error', 'همه فیلدها الزامی هستند. رمز حداقل ۶ کاراکتر باشد.');
    redirect('/register?role=' . $role);
}
if (!preg_match('/^09[0-9]{9}$/', $phone)) {
    flash_set('error', 'شماره موبایل معتبر نیست (مثال: 09123456789).');
    redirect('/register?role=' . $role);
}
if ($password !== $passwordConfirm) {
    flash_set('error', 'رمز عبور و تکرار آن یکسان نیست.');
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

if ($role === 'PATIENT') {
    if ($preferredDoctorId === null || $preferredDoctorId === '') {
        flash_set('error', 'لطفاً درمانگر خود را انتخاب کنید.');
        redirect('/register?role=PATIENT');
    }
    $doc = $pdo->prepare('SELECT id FROM doctor_profiles WHERE id=? AND is_active=1 AND is_approved=1');
    $doc->execute([$preferredDoctorId]);
    if (!$doc->fetch()) {
        flash_set('error', 'درمانگر انتخاب‌شده معتبر نیست.');
        redirect('/register?role=PATIENT');
    }
} else {
    $preferredDoctorId = null;
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
    $pdo->prepare('INSERT INTO users (id,username,name,email,phone,password_hash,role,preferred_doctor_id,must_change_password) VALUES (?,?,?,?,?,?,?,?,0)')
        ->execute([$id, $username, $name, $email, $phone, password_hash($password, PASSWORD_DEFAULT), 'DOCTOR', null]);
    $pdo->prepare('INSERT INTO doctor_profiles (id,user_id,specialty,bio,session_price,is_approved,is_active) VALUES (?,?,?,?,?,?,?)')
        ->execute([cuid(), $id, $specialty, '', 3000000, 0, 0]);
    flash_set('success', 'درخواست ثبت‌نام شما ثبت شد. پس از تأیید مدیر سایت می‌توانید وارد شوید.');
    redirect('/login');
}

$pdo->prepare('INSERT INTO users (id,username,name,email,phone,password_hash,role,preferred_doctor_id,must_change_password) VALUES (?,?,?,?,?,?,?,?,0)')
    ->execute([$id, $username, $name, $email, $phone, password_hash($password, PASSWORD_DEFAULT), 'PATIENT', $preferredDoctorId]);

$docNameStmt = $pdo->prepare('SELECT u.name FROM doctor_profiles dp JOIN users u ON u.id = dp.user_id WHERE dp.id = ?');
$docNameStmt->execute([$preferredDoctorId]);
$doctorName = (string) ($docNameStmt->fetchColumn() ?: 'درمانگر');

notify_role(
    $pdo,
    'SECRETARY',
    'ثبت‌نام مراجعه‌کننده جدید',
    "مراجعه‌کننده «{$name}» ثبت‌نام کرد و درمانگر «{$doctorName}» را انتخاب کرد.",
    '/secretary/appointments'
);
notify_doctor_profile(
    $pdo,
    $preferredDoctorId,
    'مراجعه‌کننده جدید',
    "مراجعه‌کننده «{$name}» شما را به‌عنوان درمانگر خود انتخاب کرد و ثبت‌نام نمود.",
    '/doctor/patients/' . $id
);

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
