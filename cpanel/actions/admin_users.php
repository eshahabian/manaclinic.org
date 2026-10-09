<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/admin_panel.php';
require_once __DIR__ . '/../includes/user_cleanup.php';
require_once __DIR__ . '/../includes/outreach.php';
require_once __DIR__ . '/../includes/user_referral.php';
require_site_admin();
csrf_verify();

$action = post('action');

if ($action === 'set_referral') {
    $id = post('user_id');
    $back = post('return_to');
    if (!in_array($back, ['/admin/users', '/admin/users-disabled'], true)) {
        $back = '/admin/users';
    }
    if ($id === '' || !user_referral_save($pdo, $id, post('referral_source'))) {
        flash_set('error', 'معرف را انتخاب کنید.');
        redirect($back);
    }
    flash_set('success', 'معرف ذخیره شد.');
    redirect($back);
}

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

if ($action === 'update_profile') {
    $id = post('user_id');
    $name = trim(normalize_input((string) ($_POST['name'] ?? '')));
    $username = mb_strtolower(trim(normalize_input((string) ($_POST['username'] ?? ''))));

    if ($id === '') {
        flash_set('error', 'کاربر مشخص نیست.');
        redirect('/admin/users');
    }
    if ($name === '' || mb_strlen($name) < 2) {
        flash_set('error', 'نام معتبر وارد کنید.');
        redirect('/admin/users');
    }
    if (!preg_match('/^[a-z0-9._-]{3,32}$/', $username)) {
        flash_set('error', 'نام کاربری باید ۳ تا ۳۲ کاراکتر لاتین (حروف، عدد، نقطه، خط تیره یا زیرخط) باشد.');
        redirect('/admin/users');
    }

    $row = $pdo->prepare('SELECT id, name, username FROM users WHERE id = ? LIMIT 1');
    $row->execute([$id]);
    $target = $row->fetch();
    if (!$target) {
        flash_set('error', 'کاربر یافت نشد.');
        redirect('/admin/users');
    }

    $taken = $pdo->prepare('SELECT id FROM users WHERE username = ? AND id <> ? LIMIT 1');
    $taken->execute([$username, $id]);
    if ($taken->fetch()) {
        flash_set('error', 'این نام کاربری قبلاً گرفته شده است.');
        redirect('/admin/users');
    }

    $pdo->prepare('UPDATE users SET name=?, username=? WHERE id=?')->execute([$name, $username, $id]);
    $roleStmt = $pdo->prepare('SELECT role FROM users WHERE id=? LIMIT 1');
    $roleStmt->execute([$id]);
    $savedRole = (string) ($roleStmt->fetchColumn() ?: '');
    if ($savedRole === 'SECRETARY' && ($username === 'secretary1' || $username === 'secretary2') && function_exists('staff_slot_set_label')) {
        staff_slot_set_label($pdo, $username === 'secretary2' ? 2 : 1, $name);
    }

    $me = current_user();
    if ($me && (string) $me['id'] === $id) {
        $_SESSION['user']['name'] = $name;
        $_SESSION['user']['username'] = $username;
    }

    flash_set('success', 'پروفایل «' . $name . '» به‌روز شد.');
    redirect('/admin/users');
}

if ($action === 'toggle_disabled') {
    $actor = current_user();
    if (!user_disable_actor_allowed($actor)) {
        flash_set('error', 'غیرفعال‌کردن حساب فقط برای مدیر سایت است.');
        redirect('/admin/users');
    }
    ensure_users_disabled_schema($pdo);
    $id = post('user_id');
    if ($id === '' || ($actor && (string) ($actor['id'] ?? '') === $id)) {
        flash_set('error', 'این حساب را نمی‌توان غیرفعال کرد.');
        redirect('/admin/users');
    }
    $row = $pdo->prepare('SELECT id, name, username, role, is_disabled FROM users WHERE id=? LIMIT 1');
    $row->execute([$id]);
    $target = $row->fetch();
    if (!$target) {
        flash_set('error', 'کاربر یافت نشد.');
        redirect('/admin/users');
    }
    if (strcasecmp((string) ($target['username'] ?? ''), 'eshahabian') === 0) {
        flash_set('error', 'این حساب را نمی‌توان غیرفعال کرد.');
        redirect('/admin/users');
    }
    $next = (int) ($target['is_disabled'] ?? 0) === 1 ? 0 : 1;
    $pdo->prepare('UPDATE users SET is_disabled=? WHERE id=?')->execute([$next, $id]);
    if ((string) ($target['role'] ?? '') === 'DOCTOR') {
        try {
            $pdo->prepare('UPDATE doctor_profiles SET is_active=? WHERE user_id=?')->execute([$next ? 0 : 1, $id]);
        } catch (Throwable $ignored) {
        }
    }
    flash_set('success', $next ? 'حساب «' . $target['name'] . '» غیرفعال شد و در «کاربران غیر فعال شده» دیده می‌شود.' : 'حساب «' . $target['name'] . '» دوباره فعال شد.');
    $back = function_exists('safe_next_path') ? safe_next_path((string) ($_POST['return_to'] ?? '')) : null;
    redirect($back === '/admin/users-disabled' ? '/admin/users-disabled' : '/admin/users');
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
