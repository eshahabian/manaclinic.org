<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/admin_panel.php';
require_once __DIR__ . '/../../includes/staff_handover_copies.php';

$user = require_login(['ADMIN']);
require_once __DIR__ . '/../../includes/mentions.php';
$actorId = (string) ($user['id'] ?? '');
$msgTab = trim((string) ($_GET['tab'] ?? 'inbox'));
$mentionsOn = $msgTab === 'mentions';
$mentionCount = mentions_unread_count($pdo, $actorId);
$tabs = messages_mentions_tabs_html('/admin/staff-messages', '/admin/staff-messages?tab=mentions', $mentionsOn, $mentionCount);
if ($mentionsOn) {
    render_admin_page('پیام‌ها', '<h1>پیام‌ها</h1>' . $tabs . mentions_inbox_html($pdo, $actorId, 'ADMIN'));
    return;
}
mark_notifications_read_kinds($pdo, $actorId, ['handover_copy', 'handover']);

$watcherIds = [];
foreach (handover_copy_watchers($pdo) as $w) {
    $watcherIds[(string) $w['id']] = true;
}
$all = fetch_staff_message_copies($pdo, null, 150);
$rows = [];
foreach ($all as $n) {
    if (isset($watcherIds[(string) ($n['recipient_user_id'] ?? '')])) {
        $rows[] = $n;
    }
}

$html = staff_handover_copies_render($rows, '/admin/staff-messages', true);
$html = preg_replace('/<\/h1>/', '</h1>' . $tabs, $html, 1) ?: ($tabs . $html);
render_admin_page('پیام‌ها', $html);
