<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/doctor_panel.php';
require_once __DIR__ . '/../includes/workshops.php';
require_once __DIR__ . '/../includes/workshop_path.php';

$ctx = require_doctor_profile($pdo);
ensure_workshop_schema($pdo);
$enrollmentId = post('enrollment_id');
$sessionId = post('session_id');
$back = '/doctor/workshops/path?enrollment=' . rawurlencode($enrollmentId);

$pathCtx = workshop_path_load_doctor($pdo, (string) ($ctx['profile']['id'] ?? ''), $enrollmentId);
if (!$pathCtx) {
    flash_set('error', 'مسیر این ثبت‌نام در دسترس نیست.');
    redirect('/doctor/workshops');
}

$found = false;
foreach (($pathCtx['steps'] ?? []) as $step) {
    if ((string) ($step['id'] ?? '') === $sessionId) {
        $found = true;
        break;
    }
}
if (!$found) {
    flash_set('error', 'جلسه یافت نشد.');
    redirect($back);
}

try {
    workshop_path_save_note(
        $pdo,
        $enrollmentId,
        $sessionId,
        'instructor',
        post('body'),
        (string) ($ctx['user']['id'] ?? '')
    );
    flash_set('success', 'یادداشت خصوصی ذخیره شد.');
} catch (RuntimeException $e) {
    flash_set('error', $e->getMessage());
}

redirect($back . '#step-' . rawurlencode($sessionId));
