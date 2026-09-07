<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/doctor_panel.php';
$ctx = require_doctor_profile($pdo);
$p = $ctx['profile'];

$name = trim(post('name'));
$bio = trim(post('bio'));
$license = trim(post('license_no'));
$degree = trim(post('degree'));
$courses = trim(post('courses'));
$year = (int) post('started_year');
$price = (int) post('session_price');
$approaches = doctor_profile_filter_keys((array) ($_POST['approaches'] ?? []), doctor_approach_options());
$domains = doctor_profile_filter_keys((array) ($_POST['domains'] ?? []), doctor_domain_options());
$focus = doctor_profile_filter_keys((array) ($_POST['focus'] ?? []), doctor_focus_options());
$maxYear = doctor_current_jalali_year();
$bioLen = function_exists('mb_strlen') ? mb_strlen($bio) : strlen($bio);

if ($name === '' || $bioLen < 20 || $license === '' || !isset(doctor_degree_options()[$degree]) || doctor_courses_list($courses) === [] || $year < 1330 || $year > $maxYear || $price <= 0 || $approaches === [] || $domains === [] || $focus === []) {
    flash_set('error', 'همه فیلدهای پروفایل الزامی است. حداقل یک مورد از هر فهرست چندانتخابی را برگزینید.');
    redirect('/doctor/profile');
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
    redirect('/doctor/profile');
}

if ($avatarUrl === '') {
    flash_set('error', 'عکس پروفایل الزامی است.');
    redirect('/doctor/profile');
}

$specialty = implode('، ', doctor_profile_labels($domains, doctor_domain_options()));
$completed = 1;

$ownerId = doctor_ctx_user_id($ctx) ?: (string) ($p['user_id'] ?? $ctx['user']['id'] ?? '');
$pdo->prepare('UPDATE users SET name=? WHERE id=?')->execute([$name, $ownerId]);
if (empty($ctx['admin_mode']) && $ownerId === (string) ($ctx['user']['id'] ?? '')) {
    $_SESSION['user']['name'] = $name;
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
redirect('/doctor/profile');
