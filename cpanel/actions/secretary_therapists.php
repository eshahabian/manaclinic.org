<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/secretary_panel.php';
require_once __DIR__ . '/../includes/doctor_panel.php';
require_once __DIR__ . '/../includes/doctor_profile_fields.php';
require_once __DIR__ . '/../includes/availability.php';

function secretary_therapist_optional_year(string $raw): ?int
{
    $digits = preg_replace('/\D+/', '', normalize_input($raw)) ?? '';
    if ($digits === '') {
        return null;
    }
    $year = (int) $digits;
    if ($year <= 0 || $year > 32767) {
        return null;
    }

    return $year;
}

function secretary_therapist_optional_price(string $raw): int
{
    $digits = preg_replace('/\D+/', '', normalize_input($raw)) ?? '';
    if ($digits === '') {
        return 0;
    }

    return max(0, (int) $digits);
}

function secretary_therapist_generated_password(): string
{
    $lower = 'abcdefghjkmnpqrstuvwxyz';
    $upper = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
    $digits = '23456789';
    $chars = [
        $upper[random_int(0, strlen($upper) - 1)],
        $digits[random_int(0, strlen($digits) - 1)],
    ];
    while (count($chars) < password_min_length()) {
        $chars[] = $lower[random_int(0, strlen($lower) - 1)];
    }
    shuffle($chars);

    return implode('', $chars);
}

function secretary_therapist_resolve_username(PDO $pdo, string $username, string $nameEn, string $surname, string $firstName, string $lastName, string $phone): string
{
    $username = mb_strtolower(trim($username));
    if ($username !== '') {
        return $username;
    }
    $made = '';
    if (function_exists('username_base_from_names') && function_exists('unique_username')) {
        $made = unique_username($pdo, username_base_from_names($nameEn, $surname, $firstName, $lastName));
    }
    if ($made !== '') {
        return $made;
    }
    $phoneDigits = preg_replace('/\D+/', '', $phone) ?? '';
    $fallback = 'dr' . substr($phoneDigits, -8);
    if (strlen($fallback) < 3) {
        $fallback = 'dr' . substr(bin2hex(random_bytes(4)), 0, 6);
    }
    $made = unique_username($pdo, substr($fallback, 0, 32));
    if ($made !== '') {
        return $made;
    }

    return 'dr' . substr(bin2hex(random_bytes(6)), 0, 10);
}

/** @return list<int> */
function secretary_therapist_presence_hours(string $from, string $to): array
{
    if (!preg_match('/^(\d{1,2}):(\d{2})$/', $from, $startMatch) || !preg_match('/^(\d{1,2}):(\d{2})$/', $to, $endMatch)) {
        return [];
    }
    $fromHour = (int) $startMatch[1];
    $toHour = (int) $endMatch[1];
    $allowed = appointment_presence_hours();
    if (!in_array($fromHour, $allowed, true) || !in_array($toHour, $allowed, true)) {
        return [];
    }

    return appointment_hours_range($fromHour, $toHour);
}

function secretary_therapist_apply_presence(PDO $pdo, string $profileId): void
{
    if ($profileId === '') {
        return;
    }

    $raw = trim((string) post('presence_draft_json'));
    if ($raw !== '') {
        $parsed = json_decode($raw, true);
        if (is_array($parsed)) {
            foreach ($parsed as $key => $item) {
                if (!is_array($item)) {
                    continue;
                }
                $weekday = (int) $key;
                if ($weekday < 0 || $weekday > 6) {
                    continue;
                }
                $hours = secretary_therapist_presence_hours(
                    trim((string) ($item['from'] ?? '')),
                    trim((string) ($item['to'] ?? ''))
                );
                if ($hours === []) {
                    continue;
                }
                doctor_availability_apply_weekday($pdo, $profileId, $weekday, $hours, 12);
            }

            return;
        }
    }

    // سازگاری با فرم قدیمی (چند روز + یک بازه مشترک)
    $hours = secretary_therapist_presence_hours(trim((string) post('presence_from')), trim((string) post('presence_to')));
    $days = $_POST['presence_days'] ?? [];
    if ($hours === [] || !is_array($days)) {
        return;
    }
    foreach ($days as $day) {
        $weekday = (int) $day;
        if ($weekday < 0 || $weekday > 6) {
            continue;
        }
        doctor_availability_apply_weekday($pdo, $profileId, $weekday, $hours, 12);
    }
}

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
    $year = secretary_therapist_optional_year((string) post('started_year'));
    $price = secretary_therapist_optional_price((string) post('session_price'));
    $approaches = doctor_profile_filter_keys((array) ($_POST['approaches'] ?? []), doctor_approach_options());
    $domains = doctor_profile_filter_keys((array) ($_POST['domains'] ?? []), doctor_domain_options());
    $focus = doctor_profile_filter_keys((array) ($_POST['focus'] ?? []), doctor_focus_options());
    $minPass = password_min_length();
    if ($nameEn === '' && $firstName !== '' && function_exists('transliterate_persian_name')) {
        $nameEn = transliterate_persian_name($pdo, $firstName, 'first');
    }
    if ($surname === '' && $lastName !== '' && function_exists('transliterate_persian_name')) {
        $surname = transliterate_persian_name($pdo, $lastName, 'last');
    }
    $typedUsername = $username !== '';
    $username = secretary_therapist_resolve_username($pdo, $username, $nameEn, $surname, $firstName, $lastName, $phone);
    if ($firstName === '' || $lastName === '' || !is_valid_phone($phone)) {
        flash_set('error', 'نام، نام خانوادگی و موبایل را کامل کنید.');
        redirect($fail);
    }
    if ($gender !== 'male' && $gender !== 'female') {
        $gender = '';
    }
    if (!function_exists('mail_is_real_email')) {
        require_once __DIR__ . '/../includes/mail.php';
    }
    if ($email !== '' && !mail_is_real_email($email)) {
        flash_set('error', 'یک ایمیل واقعی وارد کنید.');
        redirect($fail);
    }
    $generatedPassword = false;
    if ($password === '' && $passwordConfirm === '') {
        $password = secretary_therapist_generated_password();
        $generatedPassword = true;
    } elseif (strlen($password) < $minPass || $password !== $passwordConfirm || preg_match('/[^\x00-\x7F]/', $password)) {
        flash_set('error', 'رمز عبور انگلیسی باشد، حداقل ' . to_fa_digits((string) $minPass) . ' کاراکتر، و با تکرارش یکی باشد.');
        redirect($fail);
    }
    if ($typedUsername && !preg_match('/^[a-z0-9._-]{3,32}$/', $username)) {
        flash_set('error', 'نام کاربری نامعتبر است.');
        redirect($fail);
    }
    if (!preg_match('/^[a-z0-9._-]{3,32}$/', $username)) {
        $username = secretary_therapist_resolve_username($pdo, '', '', '', '', '', $phone);
    }
    if (!isset(doctor_degree_options()[$degree])) {
        $degree = '';
    }
    $takenSql = 'SELECT id FROM users WHERE LOWER(username)=? OR phone=?';
    $takenParams = [$username, $phone];
    if ($email !== '') {
        $takenSql .= ' OR LOWER(email)=?';
        $takenParams[] = $email;
    }
    $taken = $pdo->prepare($takenSql . ' LIMIT 1');
    $taken->execute($takenParams);
    if ($taken->fetch()) {
        flash_set('error', 'این ایمیل، نام کاربری یا موبایل قبلاً ثبت شده است.');
        redirect($fail);
    }
    $specialty = implode('، ', doctor_profile_labels($domains, doctor_domain_options()));
    $id = cuid();
    $pdo->prepare('INSERT INTO users (id,username,name,email,phone,password_hash,role,preferred_doctor_id,must_change_password) VALUES (?,?,?,?,?,?,?,?,1)')
        ->execute([$id, $username, $name, $email !== '' ? $email : null, $phone, password_hash($password, PASSWORD_DEFAULT), 'DOCTOR', null]);
    try {
        if (function_exists('ensure_users_gender_schema')) {
            ensure_users_gender_schema($pdo);
        }
        $pdo->prepare('UPDATE users SET gender=? WHERE id=?')->execute([$gender !== '' ? $gender : null, $id]);
    } catch (Throwable $ignored) {
    }
    if (function_exists('user_remember_password_plain')) {
        user_remember_password_plain($pdo, $id, $password);
    }
    if ($nameEn !== '' && $surname !== '' && function_exists('remember_registration_name_transliterations')) {
        remember_registration_name_transliterations($pdo, $firstName, $lastName, $nameEn, $surname);
    }
    $profile = doctor_ensure_profile($pdo, $id, [
        'specialty' => $specialty !== '' ? $specialty : 'روان‌شناسی',
        'bio' => $bio,
        'session_price' => $price > 0 ? $price : 3000000,
        'is_approved' => 1,
        'is_active' => 1,
    ]);
    if (!$profile) {
        flash_set('error', 'حساب ساخته شد ولی پروفایل درمانگر ساخته نشد.');
        redirect('/secretary/therapists');
    }
    $avatarUrl = '';
    if (!empty($_FILES['avatar']['name'])) {
        try {
            $avatarUrl = doctor_save_photo((string) ($profile['id'] ?? ''), $_FILES['avatar'] ?? []);
        } catch (RuntimeException $e) {
            flash_set('error', $e->getMessage());
            redirect('/secretary/therapists?open=' . rawurlencode((string) ($profile['id'] ?? '')));
        }
    }
    $completed = doctor_profile_is_complete([
        'avatar_url' => $avatarUrl,
        'approaches_json' => json_encode($approaches, JSON_UNESCAPED_UNICODE),
        'domains_json' => json_encode($domains, JSON_UNESCAPED_UNICODE),
        'focus_json' => json_encode($focus, JSON_UNESCAPED_UNICODE),
        'license_no' => $license,
        'started_year' => $year,
        'courses' => $courses,
        'degree' => $degree,
        'bio' => $bio,
    ]) ? 1 : 0;
    $pdo->prepare('
      UPDATE doctor_profiles
      SET specialty=?, bio=?, avatar_url=?, session_price=?,
          approaches_json=?, domains_json=?, focus_json=?,
          license_no=?, started_year=?, courses=?, degree=?, profile_completed=?, is_active=1, is_approved=1
      WHERE id=?
    ')->execute([
        $specialty,
        $bio,
        $avatarUrl !== '' ? $avatarUrl : null,
        $price,
        json_encode($approaches, JSON_UNESCAPED_UNICODE),
        json_encode($domains, JSON_UNESCAPED_UNICODE),
        json_encode($focus, JSON_UNESCAPED_UNICODE),
        $license !== '' ? $license : null,
        $year,
        $courses !== '' ? $courses : null,
        $degree !== '' ? $degree : null,
        $completed,
        $profile['id'],
    ]);
    $presenceNote = '';
    try {
        secretary_therapist_apply_presence($pdo, (string) ($profile['id'] ?? ''));
    } catch (Throwable $ignored) {
        $presenceNote = ' ساعات حضور ذخیره نشد؛ روزها و بازه را دوباره انتخاب کنید.';
    }
    $success = 'درمانگر «' . $name . '» ساخته شد. نام کاربری: ' . $username;
    if ($generatedPassword) {
        $success .= ' رمز عبور خالی بود و یک رمز موقت ساخته شد: ' . $password;
    }
    $success .= $presenceNote;
    flash_set('success', $success);
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
    $year = secretary_therapist_optional_year((string) post('started_year'));
    $price = secretary_therapist_optional_price((string) post('session_price'));
    $approaches = doctor_profile_filter_keys((array) ($_POST['approaches'] ?? []), doctor_approach_options());
    $domains = doctor_profile_filter_keys((array) ($_POST['domains'] ?? []), doctor_domain_options());
    $focus = doctor_profile_filter_keys((array) ($_POST['focus'] ?? []), doctor_focus_options());
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
    if ($firstName === '' || $lastName === '' || !is_valid_phone($phone)) {
        flash_set('error', 'نام، نام خانوادگی و موبایل را کامل کنید.');
        redirect($fail);
    }
    if ($gender !== 'male' && $gender !== 'female') {
        $gender = '';
    }
    if (!function_exists('mail_is_real_email')) {
        require_once __DIR__ . '/../includes/mail.php';
    }
    if ($email !== '' && !mail_is_real_email($email)) {
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
    if (!isset(doctor_degree_options()[$degree])) {
        $degree = '';
    }
    $takenSql = 'SELECT id FROM users WHERE id<>? AND (LOWER(username)=? OR phone=?';
    $takenParams = [$doctorUserId, $username, $phone];
    if ($email !== '') {
        $takenSql .= ' OR LOWER(email)=?';
        $takenParams[] = $email;
    }
    $taken = $pdo->prepare($takenSql . ') LIMIT 1');
    $taken->execute($takenParams);
    if ($taken->fetch()) {
        flash_set('error', 'این ایمیل، نام کاربری یا موبایل قبلاً ثبت شده است.');
        redirect($fail);
    }
    $specialty = implode('، ', doctor_profile_labels($domains, doctor_domain_options()));
    $pdo->prepare('UPDATE users SET username=?, name=?, email=?, phone=? WHERE id=?')
        ->execute([$username, $name, $email !== '' ? $email : null, $phone, $doctorUserId]);
    try {
        if (function_exists('ensure_users_gender_schema')) {
            ensure_users_gender_schema($pdo);
        }
        $pdo->prepare('UPDATE users SET gender=? WHERE id=?')->execute([$gender !== '' ? $gender : null, $doctorUserId]);
    } catch (Throwable $ignored) {
    }
    if ($changingPassword) {
        $pdo->prepare('UPDATE users SET password_hash=?, must_change_password=1 WHERE id=?')
            ->execute([password_hash($password, PASSWORD_DEFAULT), $doctorUserId]);
        if (function_exists('user_remember_password_plain')) {
            user_remember_password_plain($pdo, $doctorUserId, $password);
        }
    }
    if ($nameEn !== '' && $surname !== '' && function_exists('remember_registration_name_transliterations')) {
        remember_registration_name_transliterations($pdo, $firstName, $lastName, $nameEn, $surname);
    }
    $profile = doctor_ensure_profile($pdo, $doctorUserId, [
        'specialty' => $specialty !== '' ? $specialty : 'روان‌شناسی',
        'bio' => $bio,
        'session_price' => $price > 0 ? $price : 3000000,
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
    $completed = doctor_profile_is_complete([
        'avatar_url' => $avatarUrl,
        'approaches_json' => json_encode($approaches, JSON_UNESCAPED_UNICODE),
        'domains_json' => json_encode($domains, JSON_UNESCAPED_UNICODE),
        'focus_json' => json_encode($focus, JSON_UNESCAPED_UNICODE),
        'license_no' => $license,
        'started_year' => $year,
        'courses' => $courses,
        'degree' => $degree,
        'bio' => $bio,
    ]) ? 1 : 0;
    $pdo->prepare('
      UPDATE doctor_profiles
      SET specialty=?, bio=?, avatar_url=?, session_price=?,
          approaches_json=?, domains_json=?, focus_json=?,
          license_no=?, started_year=?, courses=?, degree=?, profile_completed=?
      WHERE id=?
    ')->execute([
        $specialty,
        $bio,
        $avatarUrl !== '' ? $avatarUrl : null,
        $price,
        json_encode($approaches, JSON_UNESCAPED_UNICODE),
        json_encode($domains, JSON_UNESCAPED_UNICODE),
        json_encode($focus, JSON_UNESCAPED_UNICODE),
        $license !== '' ? $license : null,
        $year,
        $courses !== '' ? $courses : null,
        $degree !== '' ? $degree : null,
        $completed,
        $profile['id'],
    ]);
    $presenceNote = '';
    try {
        secretary_therapist_apply_presence($pdo, (string) ($profile['id'] ?? ''));
    } catch (Throwable $ignored) {
        $presenceNote = ' ساعات حضور ذخیره نشد؛ روزها و بازه را دوباره انتخاب کنید.';
    }
    flash_set('success', 'اطلاعات «' . $name . '» ذخیره شد.' . $presenceNote);
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
