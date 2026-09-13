<?php
declare(strict_types=1);

$user = require_login(['PATIENT']);
csrf_verify();

$notificationId = trim((string) ($_POST['notification_id'] ?? ''));
$markAll = !empty($_POST['mark_all']);

if ($markAll) {
    mark_notifications_read($pdo, (string) $user['id']);
    flash_set('success', 'همه پیام‌ها خوانده شدند.');
} elseif ($notificationId !== '') {
    mark_notifications_read($pdo, (string) $user['id'], $notificationId);
    flash_set('success', 'پیام خوانده شد.');
}

redirect('/dashboard/messages');
