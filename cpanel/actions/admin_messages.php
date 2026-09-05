<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/admin_panel.php';
require_login(['ADMIN']);
csrf_verify();

$action = post('action');
$next = trim((string) ($_POST['next'] ?? '/admin/messages'));
if ($next === '' || !str_starts_with($next, '/admin/')) {
    $next = '/admin/messages';
}

if ($action === 'delete') {
    $id = post('notification_id');
    if ($id === '') {
        flash_set('error', 'پیام مشخص نیست.');
        redirect($next);
    }
    delete_notification($pdo, $id);
    flash_set('success', 'پیام حذف شد.');
    redirect($next);
}

if ($action === 'delete_all') {
    if ($next === '/admin/staff-messages') {
        $watcherIds = [];
        foreach (handover_copy_watchers($pdo) as $w) {
            $watcherIds[(string) $w['id']] = true;
        }
        $count = 0;
        foreach (fetch_staff_message_copies($pdo, null, 200) as $row) {
            if (!isset($watcherIds[(string) ($row['recipient_user_id'] ?? '')])) {
                continue;
            }
            delete_notification($pdo, (string) $row['id']);
            $count++;
        }
        flash_set('success', $count . ' پیام حذف شد.');
        redirect($next);
    }
    $count = delete_all_notifications($pdo);
    flash_set('success', $count . ' پیام حذف شد.');
    redirect($next);
}

flash_set('error', 'درخواست نامعتبر است.');
redirect($next);
