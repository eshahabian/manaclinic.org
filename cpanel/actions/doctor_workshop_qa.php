<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/doctor_panel.php';
require_once __DIR__ . '/../includes/workshops.php';
require_once __DIR__ . '/../includes/workshop_qa.php';

$ctx = require_doctor_profile($pdo);
ensure_workshop_schema($pdo);
$workshopId = post('workshop_id');
$parentId = post('parent_id');
$back = '/doctor/workshops/qa?id=' . rawurlencode($workshopId) . '#workshop-qa';

if (!workshop_qa_doctor_owns($pdo, (string) ($ctx['profile']['id'] ?? ''), $workshopId)) {
    flash_set('error', 'پرسش و پاسخ این دوره در دسترس نیست.');
    redirect('/doctor/workshops');
}

try {
    workshop_qa_save(
        $pdo,
        $workshopId,
        (string) ($ctx['user']['id'] ?? ''),
        'instructor',
        post('body'),
        $parentId !== '' ? $parentId : null
    );
    flash_set('success', $parentId !== '' ? 'پاسخ ثبت شد.' : 'پیام ثبت شد.');
} catch (RuntimeException $e) {
    flash_set('error', $e->getMessage());
}

redirect($back);
