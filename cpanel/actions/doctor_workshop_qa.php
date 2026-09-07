<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/doctor_panel.php';
require_once __DIR__ . '/../includes/workshops.php';
require_once __DIR__ . '/../includes/workshop_qa.php';

$ctx = require_doctor_profile($pdo);
ensure_workshop_schema($pdo);
$workshopId = post('workshop_id');
$tab = post('tab') === 'private' ? 'private' : 'public';
$back = '/doctor/workshops/qa?id=' . rawurlencode($workshopId) . '&qa=' . $tab . '#workshop-qa';

if (!workshop_qa_doctor_owns($pdo, (string) ($ctx['profile']['id'] ?? ''), $workshopId)) {
    flash_set('error', 'تالار این دوره در دسترس نیست.');
    redirect('/doctor/workshops');
}

$action = post('action') ?: 'post';
try {
    if ($action === 'like' || $action === 'dislike') {
        workshop_qa_toggle_vote($pdo, $workshopId, post('post_id'), doctor_ctx_user_id($ctx), $action);
        redirect($back);
    }
    $parentId = post('parent_id');
    $isPrivate = $tab === 'private' || post('is_private') === '1';
    $audience = null;
    if ($isPrivate && $parentId !== '') {
        $p = $pdo->prepare('SELECT author_user_id, audience_user_id, author_kind FROM workshop_qa_posts WHERE id=? AND workshop_id=? LIMIT 1');
        $p->execute([$parentId, $workshopId]);
        $row = $p->fetch();
        if (is_array($row)) {
            $audience = (string) ($row['author_kind'] ?? '') === 'instructor'
                ? (string) ($row['audience_user_id'] ?? '')
                : (string) ($row['author_user_id'] ?? '');
        }
    }
    workshop_qa_save(
        $pdo,
        $workshopId,
        doctor_ctx_user_id($ctx),
        'instructor',
        post('body'),
        $parentId !== '' ? $parentId : null,
        $isPrivate,
        $audience
    );
    flash_set('success', $isPrivate ? 'پاسخ خصوصی ارسال شد.' : 'پیام در تالار نشست.');
} catch (RuntimeException $e) {
    flash_set('error', $e->getMessage());
}

redirect($back);
