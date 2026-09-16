<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/admin_panel.php';
require_once __DIR__ . '/../includes/user_cleanup.php';
require_login(['ADMIN']);
csrf_verify();

$action = post('action');

if ($action === 'set_password') {
    $id = post('user_id');
    $new = normalize_input((string) ($_POST['new_password'] ?? ''));
    $confirm = normalize_input((string) ($_POST['new_password_confirm'] ?? ''));
    $forceChange = !empty($_POST['must_change_password']);

    if ($id === '') {
        flash_set('error', 'کاربر مشخص نیست.');
        redirect('/admin/users');
    }
    if (strlen($new) < 6) {
        flash_set('error', 'رمز جدید حداقل ۶ کاراکتر باشد.');
        redirect('/admin/users');
    }
    if ($confirm !== '' && $new !== $confirm) {
        flash_set('error', 'تکرار رمز جدید مطابقت ندارد.');
        redirect('/admin/users');
    }

    $row = $pdo->prepare('SELECT id, name FROM users WHERE id = ?');
    $row->execute([$id]);
    $target = $row->fetch();
    if (!$target) {
        flash_set('error', 'کاربر یافت نشد.');
        redirect('/admin/users');
    }

    $me = current_user();
    if ($me && (string) $me['id'] === $id) {
        $forceChange = false;
    }

    $hash = password_hash($new, PASSWORD_DEFAULT);
    $pdo->prepare('UPDATE users SET password_hash=?, must_change_password=? WHERE id=?')
        ->execute([$hash, $forceChange ? 1 : 0, $id]);
    user_remember_password_plain($pdo, (string) $id, $new);

    $check = $pdo->prepare('SELECT password_hash FROM users WHERE id=?');
    $check->execute([$id]);
    $saved = $check->fetchColumn();
    if (!$saved || !password_verify($new, (string) $saved)) {
        flash_set('error', 'ذخیره رمز ناموفق بود.');
        redirect('/admin/users');
    }

    if ($me && (string) $me['id'] === $id) {
        $_SESSION['user']['must_change_password'] = 0;
    }

    flash_set('success', 'رمز عبور «' . $target['name'] . '» تغییر کرد.');
    redirect('/admin/users');
}

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
        db_commit($pdo);
        flash_set('success', 'کاربر «' . $user['name'] . '» و نوبت‌های مرتبط حذف شد.');
    } catch (Throwable $e) {
        db_rollback($pdo);
        // اگر به‌خاطر DDL تراکنش زودتر بسته شده و کاربر واقعاً پاک شده، پیام موفقیت بده
        $still = $pdo->prepare('SELECT 1 FROM users WHERE id=? LIMIT 1');
        $still->execute([$id]);
        if (!$still->fetchColumn()) {
            flash_set('success', 'کاربر «' . $user['name'] . '» و نوبت‌های مرتبط حذف شد.');
        } else {
            flash_set('error', 'حذف ناموفق بود. دوباره تلاش کنید.');
        }
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
    $targetIds = [];
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
            $targetIds[] = $id;
            delete_user_cascade($pdo, $id);
            $deleted++;
        }
        db_commit($pdo);
        flash_set('success', to_fa_digits((string) $deleted) . ' کاربر انتخاب‌شده حذف شد.');
    } catch (Throwable $e) {
        db_rollback($pdo);
        $gone = 0;
        foreach ($targetIds as $tid) {
            $still = $pdo->prepare('SELECT 1 FROM users WHERE id=? LIMIT 1');
            $still->execute([$tid]);
            if (!$still->fetchColumn()) {
                $gone++;
            }
        }
        if ($gone > 0) {
            flash_set('success', to_fa_digits((string) $gone) . ' کاربر انتخاب‌شده حذف شد.');
        } else {
            flash_set('error', 'حذف ناموفق بود. دوباره تلاش کنید.');
        }
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

        db_commit($pdo);
        flash_set(
            'success',
            'پاک‌سازی انجام شد: ' . to_fa_digits((string) $deletedUsers) . ' کاربر حذف شد و ' . to_fa_digits((string) $deletedAppointments) . ' نوبت پاک شد.'
        );
    } catch (Throwable $e) {
        db_rollback($pdo);
        flash_set('error', 'پاک‌سازی ناموفق بود. دوباره تلاش کنید.');
    }
    redirect('/admin/users');
}

flash_set('error', 'درخواست نامعتبر است.');
redirect('/admin/users');
