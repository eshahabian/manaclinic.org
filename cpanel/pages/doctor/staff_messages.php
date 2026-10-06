<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/doctor_panel.php';
require_once __DIR__ . '/../../includes/staff_handover_copies.php';

$ctx = require_doctor_profile($pdo);
$userId = doctor_ctx_user_id($ctx);
require_once __DIR__ . '/../../includes/mentions.php';
$actorId = (string) (current_user()['id'] ?? '');
$msgTab = trim((string) ($_GET['tab'] ?? 'inbox'));
$mentionsOn = $msgTab === 'mentions';
$mentionCount = mentions_unread_count($pdo, $actorId);
$tabs = messages_mentions_tabs_html('/doctor/staff-messages', '/doctor/staff-messages?tab=mentions', $mentionsOn, $mentionCount);
if ($mentionsOn) {
    $html = '<h1>پیام‌ها</h1>' . $tabs . mentions_inbox_html($pdo, $actorId, 'DOCTOR');
} else {
    mark_notifications_read_kinds($pdo, $userId, ['handover_copy', 'handover']);
    $rows = fetch_staff_message_copies($pdo, $userId, 80);
    $html = staff_handover_copies_render($rows, '/doctor/staff-messages', false);
    $html = preg_replace('/<\/h1>/', '</h1>' . $tabs, $html, 1) ?: ($tabs . $html);
}
render_doctor_page('پیام‌ها', $html);
