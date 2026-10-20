<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/doctor_panel.php';
$ctx = require_doctor_profile($pdo);
$p = $ctx['profile'];
require_once __DIR__ . '/../includes/user_phone.php';
$phone = normalize_phone(post('phone'));
if (!is_valid_phone($phone)) {
    flash_set('error', 'شماره موبایل معتبر الزامی است.');
    redirect(!empty($doctorProfileEmbedded) ? '/app/profile' : '/doctor/profile');
}

$name = trim(post('name'));
$bio = trim(post('bio'));
$license = trim(post('license_no'));
$degree = trim(post('degree'));
$courses = trim(post('courses'));
$year = (int) post('started_year');
$price = parse_user_money(post('session_price'));
$approaches = doctor_profile_filter_keys((array) ($_POST['approaches'] ?? []), doctor_approach_options());
$domains = doctor_profile_filter_keys((array) ($_POST['domains'] ?? []), doctor_domain_options());
$focus = doctor_profile_filter_keys((array) ($_POST['focus'] ?? []), doctor_focus_options());
$maxYear = doctor_current_jalali_year();

if ($name === '' || $license === '' || !isset(doctor_degree_options()[$degree]) || doctor_courses_list($courses) === [] || $year < 1330 || $year > $maxYear || $price <= 0 || $approaches === [] || $domains === [] || $focus === []) {
    flash_set('error', 'همه فیلدهای پروفایل به‌جز «درباره من» الزامی است. حداقل یک مورد از هر فهرست چندانتخابی را برگزینید.');
    redirect(!empty($doctorProfileEmbedded) ? '/app/profile' : '/doctor/profile');
}

$avatarUrl = trim((string) ($p['avatar_url'] ?? ''));
try {
    $uploaded = doctor_save_photo((string) ($p['id'] ?? ''), $_FILES['avatar'] ?? []);
    if ($uploaded !== '') {
        if ($avatarUrl !== $uploaded) {
            doctor_delete_photo_file($avatarUrl);
        }
        $avatarUrl = $uploaded;
    }
} catch (RuntimeException $e) {
    flash_set('error', $e->getMessage());
    redirect(!empty($doctorProfileEmbedded) ? '/app/profile' : '/doctor/profile');
}

if ($avatarUrl === '') {
    flash_set('error', 'عکس پروفایل الزامی است.');
    redirect(!empty($doctorProfileEmbedded) ? '/app/profile' : '/doctor/profile');
}

$specialty = implode('، ', doctor_profile_labels($domains, doctor_domain_options()));
$completed = 1;

$ownerId = doctor_ctx_user_id($ctx) ?: (string) ($p['user_id'] ?? $ctx['user']['id'] ?? '');
try {
    $existingContact = $pdo->prepare('SELECT phone FROM users WHERE id=?');
    $existingContact->execute([$ownerId]);
    $oldPhone = (string) ($existingContact->fetchColumn() ?: '');
    if (user_phone_key($phone) === user_phone_key($oldPhone)) {
        // Existing shared family numbers explicitly retained by clinic policy remain editable.
        $pdo->prepare('UPDATE users SET name=? WHERE id=?')->execute([$name, $ownerId]);
    } else {
        user_phone_write($pdo, $phone, $ownerId, fn ($phone) =>
            $pdo->prepare('UPDATE users SET name=?, phone=? WHERE id=?')->execute([$name, $phone, $ownerId])
        );
    }
} catch (RuntimeException $e) {
    flash_set('error', $e->getMessage());
    redirect(!empty($doctorProfileEmbedded) ? '/app/profile' : '/doctor/profile');
}
if (empty($ctx['admin_mode']) && $ownerId === (string) ($ctx['user']['id'] ?? '')) {
    $_SESSION['user']['name'] = $name;
    $_SESSION['user']['phone'] = $phone;
}
$pdo->prepare('
  UPDATE doctor_profiles
  SET specialty=?, bio=?, avatar_url=?, session_price=?,
      approaches_json=?, domains_json=?, focus_json=?,
      license_no=?, started_year=?, courses=?, degree=?, profile_completed=?
  WHERE id=?
')->execute([
    $specialty,
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
    $completed,
    $p['id'],
]);
flash_set('success', 'پروفایل ذخیره شد.');
redirect(!empty($doctorProfileEmbedded) ? '/app/profile' : '/doctor/profile');
