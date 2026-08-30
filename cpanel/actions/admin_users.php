<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/admin_panel.php';
require_once __DIR__ . '/../includes/user_cleanup.php';
require_login(['ADMIN']);

$action = post('action');

if ($action === 'delete_user') {
    $id = post('user_id');
    if ($id === '') {
        flash_set('error', 'کاربر مشخص نیست.');
        redirect('/admin/users');
    }
    $row = $pdo->prepare('SELECT id, role, name FROM users WHERE id = ?');
    $row->execute([$id]);
    $user = $row->fetch();
    if (!$user) {
        flash_set('error', 'کاربر یافت نشد.');
        redirect('/admin/users');
    }
    if ($user['role'] === 'ADMIN') {
        flash_set('error', 'حذف ادمین مجاز نیست.');
        redirect('/admin/users');
    }
    try {
        $pdo->beginTransaction();
        delete_user_cascade($pdo, $id);
        $pdo->commit();
        flash_set('success', 'کاربر «' . $user['name'] . '» و نوبت‌های مرتبط حذف شد.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        flash_set('error', 'حذف ناموفق: ' . $e->getMessage());
    }
    redirect('/admin/users');
}

if ($action === 'delete_selected') {
    $ids = $_POST['user_ids'] ?? [];
    if (!is_array($ids) || !$ids) {
        flash_set('error', 'هیچ کاربری انتخاب نشده است.');
        redirect('/admin/users');
    }
    $deleted = 0;
    try {
        $pdo->beginTransaction();
        foreach ($ids as $id) {
            $id = (string) $id;
            if ($id === '') {
                continue;
            }
            $row = $pdo->prepare('SELECT id, role FROM users WHERE id = ?');
            $row->execute([$id]);
            $user = $row->fetch();
            if (!$user || $user['role'] === 'ADMIN') {
                continue;
            }
            delete_user_cascade($pdo, $id);
            $deleted++;
        }
        $pdo->commit();
        flash_set('success', "{$deleted} کاربر انتخاب‌شده حذف شد.");
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        flash_set('error', 'حذف ناموفق: ' . $e->getMessage());
    }
    redirect('/admin/users');
}

if ($action === 'cleanup_named_and_appointments') {
    $targets = find_cleanup_test_users($pdo);
    $deletedUsers = 0;
    $deletedAppointments = 0;
    try {
        $pdo->beginTransaction();

        foreach ($targets as $u) {
            delete_user_cascade($pdo, (string) $u['id']);
            $deletedUsers++;
        }

        $deletedAppointments = delete_all_appointments($pdo);

        $pdo->commit();
        flash_set(
            'success',
            "پاک‌سازی انجام شد: {$deletedUsers} کاربر حذف شد و {$deletedAppointments} نوبت پاک شد."
        );
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        flash_set('error', 'پاک‌سازی ناموفق: ' . $e->getMessage());
    }
    redirect('/admin/users');
}

flash_set('error', 'درخواست نامعتبر است.');
redirect('/admin/users');
