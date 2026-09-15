<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/secretary_to_admin.php';

$user = require_login(['SECRETARY']);
csrf_verify();

$body = (string) ($_POST['body'] ?? '');

try {
    secretary_to_admin_send($pdo, (string) $user['id'], (string) ($user['name'] ?? 'منشی'), $body);
    flash_set('success', 'پیام برای مدیر سایت ارسال شد.');
} catch (Throwable $e) {
    flash_set('error', $e->getMessage() !== '' ? $e->getMessage() : 'خطا در ارسال پیام.');
}

redirect('/secretary/profile#compose-to-admin');
