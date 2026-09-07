<?php
declare(strict_types=1);

$user = require_login(['PATIENT']);
require_once __DIR__ . '/../includes/workshops.php';
require_once __DIR__ . '/../includes/workshop_path.php';

ensure_workshop_schema($pdo);
$enrollmentId = post('enrollment_id');
$sessionId = post('session_id');
$back = '/dashboard/workshops/path?enrollment=' . rawurlencode($enrollmentId);

$ctx = workshop_path_load_patient($pdo, (string) ($user['id'] ?? ''), $enrollmentId);
if (!$ctx) {
    flash_set('error', 'مسیر این دوره در دسترس نیست.');
    redirect('/dashboard/workshops/mine');
}

$allowed = false;
foreach (($ctx['steps'] ?? []) as $step) {
    if ((string) ($step['id'] ?? '') === $sessionId && !empty($step['can_write_patient'])) {
        $allowed = true;
        break;
    }
}
if (!$allowed) {
    flash_set('error', 'برای این جلسه هنوز نمی‌توانید یادداشت بنویسید.');
    redirect($back . '#step-' . rawurlencode($sessionId));
}

try {
    workshop_path_save_note(
        $pdo,
        $enrollmentId,
        $sessionId,
        'patient',
        post('body'),
        (string) ($user['id'] ?? '')
    );
    flash_set('success', 'یادداشت جلسه ذخیره شد.');
} catch (RuntimeException $e) {
    flash_set('error', $e->getMessage());
}

redirect($back . '#step-' . rawurlencode($sessionId));
