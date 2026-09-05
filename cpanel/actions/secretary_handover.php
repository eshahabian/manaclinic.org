<?php
declare(strict_types=1);

$user = require_login(['SECRETARY']);
$toUserId = post('to_user_id');
$body = trim((string) ($_POST['body'] ?? ''));

try {
    handover_send($pdo, (string) $user['id'], (string) $user['name'], $toUserId, $body);
    flash_set('success', 'پیام تحویل شیفت ارسال شد. یک نسخه برای دکتر و ادمین هم رفت.');
} catch (Throwable $e) {
    flash_set('error', $e->getMessage());
}

redirect('/secretary/messages');
