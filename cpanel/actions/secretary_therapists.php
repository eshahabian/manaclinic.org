<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/secretary_panel.php';
require_once __DIR__ . '/../includes/doctor_panel.php';
require_once __DIR__ . '/../includes/doctor_profile_fields.php';

$user = require_login(['SECRETARY', 'DOCTOR', 'ADMIN']);
if (!clinic_can_add_therapist($user)) {
    flash_set('error', 'این بخش فقط برای منشی‌ها، شیوا گرانمایه‌پور، عطیه گارسچی و عماد شهابیان است.');
    redirect('/login');
}
csrf_verify();
$back = safe_next_path(post('next'));
if ($back !== '/app/therapists' && $back !== '/secretary/therapists') {
    $back = '/secretary/therapists';
}

if (post('action') === 'create') {
    $fail = '/secretary/therapists?add=1';
    $firstName = trim((string) post('first_name'));
    $lastName = trim((string) post('last_name'));
    $nameEn = trim((string) post('name_en'));
    $surname = trim((string) post('surname'));
    $name = trim($firstName . ' ' . $lastName);
    $phone = normalize_phone((string) post('phone'));
    $email = mb_strtolower(trim((string) post('email')));
    $username = mb_strtolower(trim((string) post('username')));
    $password = (string) ($_POST['password'] ?? '');
    $passwordConfirm = (string) ($_POST['password_confirm'] ?? '');
    $gender = strtolower(trim((string) post('gender')));
    $bio = trim((string) post('bio'));
    $license = trim((string) post('license_no'));
    $degree = trim((string) post('degree'));
    $courses = trim((string) post('courses'));
    $yearDigits = preg_replace('/\D+/', '', normalize_input((string) post('started_year'))) ?? '';
    $year = $yearDigits === '' ? 0 : (int) $yearDigits;
    $priceDigits = preg_replace('/\D+/', '', normalize_input((string) post('session_price'))) ?? '';
    $price = $priceDigits === '' ? 0 : (int) $priceDigits;
    $approaches = doctor_profile_filter_keys((array) ($_POST['approaches'] ?? []), doctor_approach_options());
    $domains = doctor_profile_filter_keys((array) ($_POST['domains'] ?? []), doctor_domain_options());
    $focus = doctor_profile_filter_keys((array) ($_POST['focus'] ?? []), doctor_focus_options());
    $maxYear = doctor_current_jalali_year();
    $bioLen = mb_strlen($bio);
    $minPass = password_min_length();
    if ($nameEn === '' && $firstName !== '' && function_exists('transliterate_persian_name')) {
        $nameEn = transliterate_persian_name($pdo, $firstName, 'first');
    }
    if ($surname === '' && $lastName !== '' && function_exists('transliterate_persian_name')) {
        $surname = transliterate_persian_name($pdo, $lastName, 'last');
    }
    if ($username === '' && function_exists('username_base_from_names') && function_exists('unique_username')) {
        $username = unique_username($pdo, username_base_from_names($nameEn, $surname, $firstName, $lastName));
    }
    if ($firstName === '' || $lastName === '' || $nameEn === '' || $surname === '' || !is_valid_phone($phone)) {
        flash_set('error', 'نام، نام انگلیسی، نام خانوادگی و موبایل را کامل کنید.');
        redirect($fail);
    }
    if ($gender !== 'male' && $gender !== 'female') {
        flash_set('error', 'جنسیت را انتخاب کنید.');
        redirect($fail);
    }
    if (!function_exists('mail_is_real_email')) {
        require_once __DIR__ . '/../includes/mail.php';
    }
    if (!mail_is_real_email($email)) {
        flash_set('error', 'یک ایمیل واقعی وارد کنید.');
        redirect($fail);
    }
    if (strlen($password) < $minPass || $password !== $passwordConfirm || preg_match('/[^\x00-\x7F]/', $password)) {
        flash_set('error', 'رمز عبور انگلیسی باشد، حداقل ' . to_fa_digits((string) $minPass) . ' کاراکتر، و با تکرارش یکی باشد.');
        redirect($fail);
    }
    if (!preg_match('/^[a-z0-9._-]{3,32}$/', $username)) {
        flash_set('error', 'نام کاربری نامعتبر است.');
        redirect($fail);
    }
    if ($bioLen < 20 || $license === '' || !isset(doctor_degree_options()[$degree]) || doctor_courses_list($courses) === [] || $year < 1330 || $year > $maxYear || $price <= 0 || $approaches === [] || $domains === [] || $focus === []) {
        flash_set('error', 'مدرک، پروانه، سال شروع، رویکرد، حوزه، زمینه، دوره‌ها، معرفی و هزینه جلسه را کامل کنید.');
        redirect($fail);
    }
    $taken = $pdo->prepare('SELECT id FROM users WHERE LOWER(email)=? OR LOWER(username)=? OR phone=? LIMIT 1');
    $taken->execute([$email, $username, $phone]);
    if ($taken->fetch()) {
        flash_set('error', 'این ایمیل، نام کاربری یا موبایل قبلاً ثبت شده است.');
        redirect($fail);
    }
    $id = cuid();
    $pdo->prepare('INSERT INTO users (id,username,name,email,phone,password_hash,role,preferred_doctor_id,must_change_password) VALUES (?,?,?,?,?,?,?,?,1)')
        ->execute([$id, $username, $name, $email, $phone, password_hash($password, PASSWORD_DEFAULT), 'DOCTOR', null]);
    try {
        $pdo->prepare('UPDATE users SET gender=? WHERE id=?')->execute([$gender, $id]);
    } catch (Throwable $ignored) {
    }
    if (function_exists('user_remember_password_plain')) {
        user_remember_password_plain($pdo, $id, $password);
    }
    if (function_exists('remember_registration_name_transliterations')) {
        remember_registration_name_transliterations($pdo, $firstName, $lastName, $nameEn, $surname);
    }
    $profile = doctor_ensure_profile($pdo, $id, [
        'specialty' => implode('، ', doctor_profile_labels($domains, doctor_domain_options())),
        'bio' => $bio,
        'session_price' => $price,
        'is_approved' => 1,
        'is_active' => 1,
    ]);
    if (!$profile) {
        flash_set('error', 'حساب ساخته شد ولی پروفایل درمانگر ساخته نشد.');
        redirect('/secretary/therapists');
    }
    $avatarUrl = '';
    try {
        $avatarUrl = doctor_save_photo((string) ($profile['id'] ?? ''), $_FILES['avatar'] ?? []);
    } catch (RuntimeException $e) {
        flash_set('error', $e->getMessage());
        redirect('/secretary/therapists?open=' . rawurlencode((string) ($profile['id'] ?? '')));
    }
    $pdo->prepare('
      UPDATE doctor_profiles
      SET specialty=?, bio=?, avatar_url=?, session_price=?,
          approaches_json=?, domains_json=?, focus_json=?,
          license_no=?, started_year=?, courses=?, degree=?, profile_completed=?, is_active=1, is_approved=1
      WHERE id=?
    ')->execute([
        implode('، ', doctor_profile_labels($domains, doctor_domain_options())),
        $bio,
        $avatarUrl !== '' ? $avatarUrl : null,
        $price,
        json_encode($approaches, JSON_UNESCAPED_UNICODE),
        json_encode($domains, JSON_UNESCAPED_UNICODE),
        json_encode($focus, JSON_UNESCAPED_UNICODE),
        $license,
        $year,
        $courses,
        $degree,
        $avatarUrl !== '' ? 1 : 0,
        $profile['id'],
    ]);
    flash_set('success', 'درمانگر «' . $name . '» ساخته شد. نام کاربری: ' . $username);
    redirect('/secretary/therapists?open=' . rawurlencode((string) $profile['id']));
}

if (post('action') === 'update') {
    $doctorUserId = trim((string) post('user_id'));
    $fail = '/secretary/therapists?edit=' . rawurlencode($doctorUserId);
    $stmt = $pdo->prepare("SELECT id, name, username FROM users WHERE id=? AND role='DOCTOR' LIMIT 1");
    $stmt->execute([$doctorUserId]);
    $doctorUser = $stmt->fetch();
    if (!$doctorUser) {
        flash_set('error', 'درمانگر پیدا نشد.');
        redirect('/secretary/therapists');
    }
    ensure_doctor_profile_schema($pdo);
    $profileStmt = $pdo->prepare('SELECT id, avatar_url FROM doctor_profiles WHERE user_id=? LIMIT 1');
    $profileStmt->execute([$doctorUserId]);
    $existingProfile = $profileStmt->fetch() ?: [];
    $firstName = trim((string) post('first_name'));
    $lastName = trim((string) post('last_name'));
    $nameEn = trim((string) post('name_en'));
    $surname = trim((string) post('surname'));
    $name = trim($firstName . ' ' . $lastName);
    $phone = normalize_phone((string) post('phone'));
    $email = mb_strtolower(trim((string) post('email')));
    $username = mb_strtolower(trim((string) post('username')));
    $password = (string) ($_POST['password'] ?? '');
    $passwordConfirm = (string) ($_POST['password_confirm'] ?? '');
    $gender = strtolower(trim((string) post('gender')));
    $bio = trim((string) post('bio'));
    $license = trim((string) post('license_no'));
    $degree = trim((string) post('degree'));
    $courses = trim((string) post('courses'));
    $yearDigits = preg_replace('/\D+/', '', normalize_input((string) post('started_year'))) ?? '';
    $year = $yearDigits === '' ? 0 : (int) $yearDigits;
    $priceDigits = preg_replace('/\D+/', '', normalize_input((string) post('session_price'))) ?? '';
    $price = $priceDigits === '' ? 0 : (int) $priceDigits;
    $approaches = doctor_profile_filter_keys((array) ($_POST['approaches'] ?? []), doctor_approach_options());
    $domains = doctor_profile_filter_keys((array) ($_POST['domains'] ?? []), doctor_domain_options());
    $focus = doctor_profile_filter_keys((array) ($_POST['focus'] ?? []), doctor_focus_options());
    $maxYear = doctor_current_jalali_year();
    $bioLen = mb_strlen($bio);
    $minPass = password_min_length();
    if ($nameEn === '' && $firstName !== '' && function_exists('transliterate_persian_name')) {
        $nameEn = transliterate_persian_name($pdo, $firstName, 'first');
    }
    if ($surname === '' && $lastName !== '' && function_exists('transliterate_persian_name')) {
        $surname = transliterate_persian_name($pdo, $lastName, 'last');
    }
    if ($username === '') {
        $username = mb_strtolower(trim((string) ($doctorUser['username'] ?? '')));
    }
    if ($firstName === '' || $lastName === '' || $nameEn === '' || $surname === '' || !is_valid_phone($phone)) {
        flash_set('error', 'نام، نام انگلیسی، نام خانوادگی و موبایل را کامل کنید.');
        redirect($fail);
    }
    if ($gender !== 'male' && $gender !== 'female') {
        flash_set('error', 'جنسیت را انتخاب کنید.');
        redirect($fail);
    }
    if (!function_exists('mail_is_real_email')) {
        require_once __DIR__ . '/../includes/mail.php';
    }
    if (!mail_is_real_email($email)) {
        flash_set('error', 'یک ایمیل واقعی وارد کنید.');
        redirect($fail);
    }
    $changingPassword = $password !== '' || $passwordConfirm !== '';
    if ($changingPassword && (strlen($password) < $minPass || $password !== $passwordConfirm || preg_match('/[^\x00-\x7F]/', $password))) {
        flash_set('error', 'رمز عبور انگلیسی باشد، حداقل ' . to_fa_digits((string) $minPass) . ' کاراکتر، و با تکرارش یکی باشد.');
        redirect($fail);
    }
    if (!preg_match('/^[a-z0-9._-]{3,32}$/', $username)) {
        flash_set('error', 'نام کاربری نامعتبر است.');
        redirect($fail);
    }
    if ($bioLen < 20 || $license === '' || !isset(doctor_degree_options()[$degree]) || doctor_courses_list($courses) === [] || $year < 1330 || $year > $maxYear || $price <= 0 || $approaches === [] || $domains === [] || $focus === []) {
        flash_set('error', 'مدرک، پروانه، سال شروع، رویکرد، حوزه، زمینه، دوره‌ها، معرفی و هزینه جلسه را کامل کنید.');
        redirect($fail);
    }
    $taken = $pdo->prepare('SELECT id FROM users WHERE id<>? AND (LOWER(email)=? OR LOWER(username)=? OR phone=?) LIMIT 1');
    $taken->execute([$doctorUserId, $email, $username, $phone]);
    if ($taken->fetch()) {
        flash_set('error', 'این ایمیل، نام کاربری یا موبایل قبلاً ثبت شده است.');
        redirect($fail);
    }
    $pdo->prepare('UPDATE users SET username=?, name=?, email=?, phone=? WHERE id=?')
        ->execute([$username, $name, $email, $phone, $doctorUserId]);
    try {
        if (function_exists('ensure_users_gender_schema')) {
            ensure_users_gender_schema($pdo);
        }
        $pdo->prepare('UPDATE users SET gender=? WHERE id=?')->execute([$gender, $doctorUserId]);
    } catch (Throwable $ignored) {
    }
    if ($changingPassword) {
        $pdo->prepare('UPDATE users SET password_hash=?, must_change_password=1 WHERE id=?')
            ->execute([password_hash($password, PASSWORD_DEFAULT), $doctorUserId]);
        if (function_exists('user_remember_password_plain')) {
            user_remember_password_plain($pdo, $doctorUserId, $password);
        }
    }
    if (function_exists('remember_registration_name_transliterations')) {
        remember_registration_name_transliterations($pdo, $firstName, $lastName, $nameEn, $surname);
    }
    $profile = doctor_ensure_profile($pdo, $doctorUserId, [
        'specialty' => implode('، ', doctor_profile_labels($domains, doctor_domain_options())),
        'bio' => $bio,
        'session_price' => $price,
    ]);
    if (!$profile) {
        flash_set('error', 'پروفایل درمانگر ذخیره نشد.');
        redirect($fail);
    }
    $avatarUrl = (string) ($existingProfile['avatar_url'] ?? ($profile['avatar_url'] ?? ''));
    if (!empty($_FILES['avatar']['name'])) {
        try {
            $uploaded = doctor_save_photo((string) ($profile['id'] ?? ''), $_FILES['avatar'] ?? []);
            if ($uploaded !== '') {
                $avatarUrl = $uploaded;
            }
        } catch (RuntimeException $e) {
            flash_set('error', $e->getMessage());
            redirect($fail);
        }
    }
    if ($avatarUrl === '') {
        flash_set('error', 'عکس پروفایل لازم است.');
        redirect($fail);
    }
    $pdo->prepare('
      UPDATE doctor_profiles
      SET specialty=?, bio=?, avatar_url=?, session_price=?,
          approaches_json=?, domains_json=?, focus_json=?,
          license_no=?, started_year=?, courses=?, degree=?, profile_completed=1
      WHERE id=?
    ')->execute([
        implode('، ', doctor_profile_labels($domains, doctor_domain_options())),
        $bio,
        $avatarUrl,
        $price,
        json_encode($approaches, JSON_UNESCAPED_UNICODE),
        json_encode($domains, JSON_UNESCAPED_UNICODE),
        json_encode($focus, JSON_UNESCAPED_UNICODE),
        $license,
        $year,
        $courses,
        $degree,
        $profile['id'],
    ]);
    flash_set('success', 'اطلاعات «' . $name . '» ذخیره شد.');
    redirect('/secretary/therapists');
}

$doctorUserId = trim((string) post('user_id'));
$active = post('active') === '1';
$stmt = $pdo->prepare("SELECT id, name, role FROM users WHERE id=? AND role='DOCTOR' LIMIT 1");
$stmt->execute([$doctorUserId]);
$doctorUser = $stmt->fetch();
if (!$doctorUser) {
    flash_set('error', 'درمانگر پیدا نشد.');
    redirect($back);
}

if ($active) {
    $pdo->prepare('UPDATE users SET is_disabled=0 WHERE id=?')->execute([$doctorUserId]);
    $profile = doctor_ensure_profile($pdo, $doctorUserId, [
        'specialty' => 'روان‌شناسی',
        'is_approved' => 1,
        'is_active' => 1,
    ]);
    if (!$profile) {
        flash_set('error', 'پروفایل درمانگر ساخته نشد.');
        redirect($back);
    }
    $pdo->prepare('UPDATE doctor_profiles SET is_active=1, is_approved=1 WHERE user_id=?')->execute([$doctorUserId]);
    flash_set('success', '«' . (string) $doctorUser['name'] . '» فعال شد و پروفایلش آماده است.');
} else {
    $pdo->prepare('UPDATE doctor_profiles SET is_active=0 WHERE user_id=?')->execute([$doctorUserId]);
    flash_set('success', '«' . (string) $doctorUser['name'] . '» غیرفعال شد.');
}

redirect($back);
