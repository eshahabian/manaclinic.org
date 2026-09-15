<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/admin_staff_messages.php';

$user = require_login(['ADMIN']);
csrf_verify();

$action = post('action');
$next = '/admin/secretary-messages';

try {
    if ($action !== 'send') {
        throw new RuntimeException('عملیات نامعتبر است.');
    }

    $body = (string) ($_POST['body'] ?? '');
    $mode = trim((string) ($_POST['send_mode'] ?? 'one'));
    $toIds = [];

    if ($mode === 'all') {
        $toIds = array_map(static fn($s) => (string) ($s['id'] ?? ''), admin_staff_msg_secretaries($pdo));
    } elseif ($mode === 'multi') {
        $raw = $_POST['to_user_ids'] ?? [];
        if (!is_array($raw)) {
            $raw = [];
        }
        foreach ($raw as $id) {
            $id = trim((string) $id);
            if ($id !== '') {
                $toIds[] = $id;
            }
        }
    } else {
        $one = trim((string) ($_POST['to_user_id'] ?? ''));
        if ($one !== '') {
            $toIds[] = $one;
        }
    }

    $toIds = array_values(array_unique($toIds));
    if (!$toIds) {
        throw new RuntimeException('حداقل یک منشی را انتخاب کنید.');
    }

    $imageFile = $_FILES['image'] ?? null;
    $sentCount = 0;
    $imagePath = null;

    foreach ($toIds as $i => $tid) {
        if ($i === 0) {
            $res = admin_staff_msg_send($pdo, (string) $user['id'], $body, [$tid], $imageFile);
            $imagePath = $res['image_path'] ?? null;
            $sentCount += (int) ($res['count'] ?? 0);
        } else {
            $res = admin_staff_msg_send_copy(
                $pdo,
                (string) $user['id'],
                $body,
                [$tid],
                is_string($imagePath) ? $imagePath : null
            );
            $sentCount += (int) ($res['count'] ?? 0);
        }
    }

    $label = $sentCount === 1
        ? 'پیام برای ۱ منشی ارسال و در پروفایلش ثبت شد.'
        : ('پیام جداگانه برای ' . to_fa_digits((string) $sentCount) . ' منشی ارسال و در پروفایل‌شان ثبت شد.');
    flash_set('success', $label . ' تا «خواندم» نزنند کار نمی‌کنند.');
} catch (Throwable $e) {
    flash_set('error', $e->getMessage() !== '' ? $e->getMessage() : 'خطا در ارسال پیام.');
}

redirect($next);
