<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/admin_staff_messages.php';

$user = require_login(['ADMIN']);
csrf_verify();

$action = post('action');
$next = '/admin/secretary-messages';

try {
    if ($action === 'send') {
        $body = (string) ($_POST['body'] ?? '');
        $toIds = $_POST['to_user_ids'] ?? [];
        if (!is_array($toIds)) {
            $toIds = [];
        }
        if (!empty($_POST['to_all'])) {
            $toIds = array_map(static fn($s) => (string) ($s['id'] ?? ''), admin_staff_msg_secretaries($pdo));
        }
        $n = admin_staff_msg_send(
            $pdo,
            (string) $user['id'],
            $body,
            $toIds,
            $_FILES['image'] ?? null
        );
        flash_set('success', 'پیام برای ' . to_fa_digits((string) $n) . ' منشی ارسال شد. تا «خواندم» نزنند کار نمی‌کنند.');
    } elseif ($action === 'delete') {
        $id = post('message_id');
        if ($id === '') {
            throw new RuntimeException('پیام یافت نشد.');
        }
        admin_staff_msg_delete($pdo, $id);
        flash_set('success', 'پیام حذف شد.');
    } else {
        throw new RuntimeException('عملیات نامعتبر است.');
    }
} catch (Throwable $e) {
    flash_set('error', $e->getMessage() !== '' ? $e->getMessage() : 'خطا در ارسال پیام.');
}

redirect($next);
