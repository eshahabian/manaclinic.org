<?php
declare(strict_types=1);

$user = require_login(['PATIENT']);
require_once __DIR__ . '/../includes/workshops.php';
require_once __DIR__ . '/../includes/workshop_qa.php';

ensure_workshop_schema($pdo);
$enrollmentId = post('enrollment_id');
$workshopId = post('workshop_id');
$tab = post('tab') === 'private' ? 'private' : 'public';
$back = '/dashboard/workshops/path?enrollment=' . rawurlencode($enrollmentId) . '&qa=' . $tab . '#workshop-qa';

$enroll = workshop_qa_patient_enrollment($pdo, (string) ($user['id'] ?? ''), $workshopId);
if (!$enroll || (string) ($enroll['id'] ?? '') !== $enrollmentId) {
    flash_set('error', 'تالار این دوره در دسترس نیست.');
    redirect('/dashboard/workshops/mine');
}

$action = post('action') ?: 'post';
try {
    if ($action === 'like') {
        workshop_qa_toggle_like($pdo, $workshopId, post('post_id'), (string) ($user['id'] ?? ''));
        redirect($back);
    }
    $parentId = post('parent_id');
    $isPrivate = $tab === 'private' || post('is_private') === '1';
    workshop_qa_save(
        $pdo,
        $workshopId,
        (string) ($user['id'] ?? ''),
        'patient',
        post('body'),
        $parentId !== '' ? $parentId : null,
        $isPrivate,
        $isPrivate ? workshop_qa_doctor_user_id($pdo, $workshopId) : null
    );
    flash_set('success', $isPrivate ? 'پیام خصوصی ارسال شد.' : 'پیام در تالار نشست.');
} catch (RuntimeException $e) {
    flash_set('error', $e->getMessage());
}

redirect($back);
