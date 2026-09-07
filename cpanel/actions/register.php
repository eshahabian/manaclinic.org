<?php
declare(strict_types=1);

// تغییر نوع حساب فقط برای نمایش فرم (بدون ثبت)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['submit_register'])) {
    $role = post('role') === 'DOCTOR' ? 'DOCTOR' : 'PATIENT';
    redirect('/register?role=' . $role);
}

$firstName = trim(post('first_name'));
$lastName = trim(post('last_name'));
$role = post('role') === 'DOCTOR' ? 'DOCTOR' : 'PATIENT';
$nameEn = trim(post('name_en'));
$surname = trim(post('surname'));
if ($nameEn === '' && $firstName !== '') {
    $nameEn = transliterate_persian_name($pdo, $firstName, 'first');
}
if ($surname === '' && $lastName !== '') {
    $surname = transliterate_persian_name($pdo, $lastName, 'last');
}
$name = trim($firstName . ' ' . $lastName);
$username = mb_strtolower(post('username'));
if ($username === '' && $role !== 'DOCTOR') {
    $base = username_base_from_names($nameEn, $surname, $firstName, $lastName);
    $username = unique_username($pdo, $base);
}
$phone = $role === 'DOCTOR' ? '' : normalize_phone(post('phone'));
$password = (string) ($_POST['password'] ?? '');
$passwordConfirm = (string) ($_POST['password_confirm'] ?? '');

$minPass = password_min_length();
if ($firstName === '' || $lastName === '' || $username === '' || strlen($password) < $minPass) {
    flash_set('error', 'نام، نام خانوادگی، نام کاربری و رمز عبور الزامی است. رمز حداقل ' . to_fa_digits((string) $minPass) . ' کاراکتر باشد.');
    redirect('/register?role=' . $role);
}
if ($role !== 'DOCTOR' && ($nameEn === '' || $surname === '' || $phone === '')) {
    flash_set('error', 'همه فیلدها الزامی هستند. رمز حداقل ' . to_fa_digits((string) $minPass) . ' کاراکتر باشد.');
    redirect('/register?role=' . $role);
}
if ($role !== 'DOCTOR' && !is_valid_phone($phone)) {
    flash_set('error', 'شماره موبایل معتبر نیست. شماره ایران یا بین‌المللی وارد کنید.');
    redirect('/register?role=' . $role);
}
if (preg_match('/[^\x00-\x7F]/', $password) || preg_match('/[^\x00-\x7F]/', $passwordConfirm)) {
    flash_set('error', 'رمز عبور را با صفحه‌کلید انگلیسی وارد کنید.');
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
if ($role === 'DOCTOR') {
    $taken = $pdo->prepare('SELECT id FROM users WHERE username=? LIMIT 1');
    $taken->execute([$username]);
    if ($taken->fetch()) {
        flash_set('error', 'این نام کاربری قبلاً استفاده شده است.');
        redirect('/register?role=DOCTOR');
    }
}
throttle_guard_page('register', 6, 600, '/register?role=' . $role, 'ثبت‌نام‌های پشت‌سرهم زیاد بود. چند دقیقه بعد دوباره تلاش کنید.');
throttle_hit('register', 600);

$username = unique_username($pdo, $username);
if ($username === '') {
    flash_set('error', 'ساخت نام کاربری ممکن نشد. نام انگلیسی را تغییر دهید.');
    redirect('/register?role=' . $role);
}

require_once __DIR__ . '/../includes/wallet.php';

$id = cuid();
$email = $username . '@manaclinic.local';

if ($role === 'DOCTOR') {
    $pdo->prepare('INSERT INTO users (id,username,name,email,phone,password_hash,role,preferred_doctor_id,must_change_password) VALUES (?,?,?,?,?,?,?,?,0)')
        ->execute([$id, $username, $name, $email, $phone, password_hash($password, PASSWORD_DEFAULT), 'DOCTOR', null]);
    $pdo->prepare('INSERT INTO doctor_profiles (id,user_id,specialty,bio,session_price,is_approved,is_active) VALUES (?,?,?,?,?,?,?)')
        ->execute([cuid(), $id, '', '', 3000000, 0, 0]);
    ensure_wallet($pdo, $id);
    remember_registration_name_transliterations($pdo, $firstName, $lastName, $nameEn, $surname);
    flash_set('success', 'درخواست ثبت‌نام شما ثبت شد. پس از تأیید مدیر سایت می‌توانید وارد شوید.');
    redirect('/login');
}

$pdo->prepare('INSERT INTO users (id,username,name,email,phone,password_hash,role,preferred_doctor_id,must_change_password) VALUES (?,?,?,?,?,?,?,?,0)')
    ->execute([$id, $username, $name, $email, $phone, password_hash($password, PASSWORD_DEFAULT), 'PATIENT', null]);

ensure_wallet($pdo, $id);

remember_registration_name_transliterations($pdo, $firstName, $lastName, $nameEn, $surname);

notify_role(
    $pdo,
    'SECRETARY',
    'ثبت‌نام مراجعه‌کننده جدید',
    "مراجعه‌کننده «{$name}» ثبت‌نام کرد. درمانگر را بعداً با ایشان هماهنگ کنید.",
    '/secretary/appointments',
    'appointment'
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
$next = safe_next_path(post('next'));
if ($next) {
    redirect($next);
}
redirect('/dashboard');
