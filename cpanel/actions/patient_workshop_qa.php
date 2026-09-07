<?php
declare(strict_types=1);

$user = require_login(['PATIENT']);
require_once __DIR__ . '/../includes/workshops.php';
require_once __DIR__ . '/../includes/workshop_qa.php';

ensure_workshop_schema($pdo);
$enrollmentId = post('enrollment_id');
$workshopId = post('workshop_id');
$parentId = post('parent_id');
$back = '/dashboard/workshops/path?enrollment=' . rawurlencode($enrollmentId) . '#workshop-qa';

$enroll = workshop_qa_patient_enrollment($pdo, (string) ($user['id'] ?? ''), $workshopId);
if (!$enroll || (string) ($enroll['id'] ?? '') !== $enrollmentId) {
    flash_set('error', 'پرسش و پاسخ این دوره در دسترس نیست.');
    redirect('/dashboard/workshops/mine');
}

try {
    workshop_qa_save(
        $pdo,
        $workshopId,
        (string) ($user['id'] ?? ''),
        'patient',
        post('body'),
        $parentId !== '' ? $parentId : null
    );
    flash_set('success', $parentId !== '' ? 'پاسخ ثبت شد.' : 'پرسش ثبت شد.');
} catch (RuntimeException $e) {
    flash_set('error', $e->getMessage());
}

redirect($back);
