<?php
declare(strict_types=1);

$user = require_login(['SECRETARY']);
csrf_verify();
$body = trim((string) ($_POST['body'] ?? ''));

try {
    $count = handover_send($pdo, (string) $user['id'], (string) $user['name'], $body);
    flash_set('success', "پیام برای {$count} همکار ارسال شد.");
} catch (Throwable $e) {
    flash_set('error', $e->getMessage());
}

redirect('/secretary/messages?msg=colleague');
