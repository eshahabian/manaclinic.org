<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/admin_panel.php';
require_once __DIR__ . '/../../includes/staff_handover_copies.php';

$user = require_login(['ADMIN']);
mark_notifications_read_kinds($pdo, (string) $user['id'], ['handover_copy', 'handover']);

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

render_admin_page('پیام‌ها', staff_handover_copies_render($rows, '/admin/staff-messages', true));
