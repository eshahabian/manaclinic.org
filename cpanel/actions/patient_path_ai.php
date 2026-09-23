<?php
declare(strict_types=1);

$user = require_login(['PATIENT']);
require_once __DIR__ . '/../includes/mana_path.php';
require_once __DIR__ . '/../includes/assistant.php';

mana_path_require_user($user);
ensure_mana_path_schema($pdo);

if (!assistant_enabled()) {
    flash_set('error', 'دستیار گفتگو فعلاً در دسترس نیست.');
    redirect('/dashboard/path/report');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify()) {
    flash_set('error', 'درخواست نامعتبر بود.');
    redirect('/dashboard/path/report');
}

$patientId = (string) $user['id'];
$profile = mana_path_load_profile($pdo, $patientId);
$report = mana_path2_report_data($pdo, $profile);
$pack = mana_path2_consult_pack($report);

try {
    $session = assistant_start_from_path_report($pdo, $patientId, $pack);
    $sid = (string) ($session['id'] ?? '');
    if ($sid === '') {
        throw new RuntimeException('جلسه ساخته نشد.');
    }
    redirect('/assistant?session=' . rawurlencode($sid));
} catch (Throwable $e) {
    flash_set('error', 'شروع تحلیل ممکن نشد. کمی بعد دوباره تلاش کن.');
    redirect('/dashboard/path/report');
}
