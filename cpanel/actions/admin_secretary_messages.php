<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/admin_staff_messages.php';
require_once __DIR__ . '/../includes/secretary_to_admin.php';

$user = require_login(['ADMIN']);
csrf_verify();

$action = post('action');
$next = '/admin/secretary-messages';

try {
    if ($action === 'ack_from_secretary') {
        $mid = trim((string) ($_POST['message_id'] ?? ''));
        if ($mid === '') {
            throw new RuntimeException('پیام مشخص نیست.');
        }
        secretary_to_admin_ack($pdo, $mid);
        flash_set('success', 'پیام منشی خوانده شد.');
        redirect($next . '#from-secretaries');
    }

    if ($action === 'ack_all_from_secretaries') {
        $n = secretary_to_admin_ack_all($pdo);
        flash_set('success', $n > 0 ? (to_fa_digits((string) $n) . ' پیام خوانده شد.') : 'پیام خوانده‌نشده‌ای نبود.');
        redirect($next . '#from-secretaries');
    }

    if ($action === 'delete_from_secretary') {
        $mid = trim((string) ($_POST['message_id'] ?? ''));
        if ($mid === '') {
            throw new RuntimeException('پیام مشخص نیست.');
        }
        secretary_to_admin_delete($pdo, $mid);
        flash_set('success', 'پیام منشی حذف شد.');
        redirect($next . '#from-secretaries');
    }

    if ($action === 'delete_to_secretary') {
        $mid = trim((string) ($_POST['message_id'] ?? ''));
        if ($mid === '') {
            throw new RuntimeException('پیام مشخص نیست.');
        }
        admin_staff_msg_delete($pdo, $mid);
        flash_set('success', 'پیام ارسال‌شده به منشی حذف شد.');
        redirect($next);
    }

    if ($action === 'edit_to_secretary') {
        $mid = trim((string) ($_POST['message_id'] ?? ''));
        if ($mid === '') {
            throw new RuntimeException('پیام مشخص نیست.');
        }
        $body = (string) ($_POST['body'] ?? '');
        admin_staff_msg_update($pdo, $mid, $body);
        flash_set('success', 'متن پیام به‌روزرسانی شد.');
        redirect($next . '#sent-' . rawurlencode($mid));
    }

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
        ? 'پیام برای ۱ منشی ارسال و در بخش پیام مدیر ثبت شد.'
        : ('پیام جداگانه برای ' . to_fa_digits((string) $sentCount) . ' منشی ارسال و ثبت شد.');
    flash_set('success', $label . ' تا «خواندم» نزنند کار نمی‌کنند.');
} catch (Throwable $e) {
    flash_set('error', $e->getMessage() !== '' ? $e->getMessage() : 'خطا در ارسال پیام.');
}

redirect($next);
