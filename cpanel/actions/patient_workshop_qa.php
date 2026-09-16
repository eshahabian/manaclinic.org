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
    if ($action === 'like' || $action === 'dislike') {
        workshop_qa_toggle_vote($pdo, $workshopId, post('post_id'), (string) ($user['id'] ?? ''), $action);
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
    if (function_exists('mentions_capture_ids')) {
        $rawIds = preg_split('/\s*,\s*/', trim((string) ($_POST['mention_ids'] ?? ''))) ?: [];
        $snippet = mb_substr(trim((string) ($_POST['body'] ?? '')), 0, 180);
        mentions_capture_ids(
            $pdo,
            (string) ($user['id'] ?? ''),
            $rawIds,
            $snippet,
            'workshop_qa',
            $workshopId,
            '/dashboard/workshops/path?enrollment=' . rawurlencode($enrollmentId) . '#workshop-qa',
            $workshopId
        );
    }
    flash_set('success', $isPrivate ? 'پیام خصوصی ارسال شد.' : 'پیام در تالار نشست.');
} catch (RuntimeException $e) {
    flash_set('error', $e->getMessage());
}

redirect($back);
