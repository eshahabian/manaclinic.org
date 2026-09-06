<?php
declare(strict_types=1);

/**
 * حذف امن کاربر و همه وابستگی‌ها (بدون اتکا به FK CASCADE).
 */
function delete_user_cascade(PDO $pdo, string $userId): void
{
    // اعلان‌ها
    try {
        if (function_exists('ensure_notifications_table')) {
            ensure_notifications_table($pdo);
        }
        $pdo->prepare('DELETE FROM notifications WHERE recipient_user_id = ?')->execute([$userId]);
    } catch (Throwable $ignored) {
    }

    try {
        $pdo->prepare('DELETE FROM staff_handover_notes WHERE from_user_id = ? OR to_user_id = ?')
            ->execute([$userId, $userId]);
    } catch (Throwable $ignored) {
    }

    // پرداخت‌های نوبت‌های این مراجعه‌کننده
    try {
        $pdo->prepare("
          DELETE FROM payments WHERE appointment_id IN (
            SELECT id FROM appointments WHERE patient_id = ?
          )
        ")->execute([$userId]);
    } catch (Throwable $ignored) {
        // بعضی MySQLها subquery delete را محدود می‌کنند
        try {
            $ids = $pdo->prepare('SELECT id FROM appointments WHERE patient_id = ?');
            $ids->execute([$userId]);
            foreach ($ids->fetchAll(PDO::FETCH_COLUMN) as $aid) {
                $pdo->prepare('DELETE FROM payments WHERE appointment_id = ?')->execute([$aid]);
            }
        } catch (Throwable $ignored2) {
        }
    }

    // یادداشت / هایلایت / پرونده
    foreach (['doctor_session_notes', 'doctor_highlights', 'doctor_patient_charts'] as $table) {
        try {
            $pdo->prepare("DELETE FROM {$table} WHERE patient_id = ?")->execute([$userId]);
        } catch (Throwable $ignored) {
        }
    }

    // نوبت‌ها
    try {
        $pdo->prepare('DELETE FROM appointments WHERE patient_id = ?')->execute([$userId]);
    } catch (Throwable $ignored) {
    }

    // اگر درمانگر بود
    try {
        $dp = $pdo->prepare('SELECT id FROM doctor_profiles WHERE user_id = ?');
        $dp->execute([$userId]);
        $doctorProfileId = $dp->fetchColumn();
        if ($doctorProfileId) {
            foreach (['availabilities', 'appointments', 'doctor_session_notes', 'doctor_highlights', 'doctor_patient_charts'] as $table) {
                $col = $table === 'appointments' || $table === 'availabilities' ? 'doctor_id' : 'doctor_id';
                try {
                    if ($table === 'appointments') {
                        $aids = $pdo->prepare('SELECT id FROM appointments WHERE doctor_id = ?');
                        $aids->execute([$doctorProfileId]);
                        foreach ($aids->fetchAll(PDO::FETCH_COLUMN) as $aid) {
                            $pdo->prepare('DELETE FROM payments WHERE appointment_id = ?')->execute([$aid]);
                        }
                    }
                    $pdo->prepare("DELETE FROM {$table} WHERE doctor_id = ?")->execute([$doctorProfileId]);
                } catch (Throwable $ignored) {
                }
            }
            $pdo->prepare('DELETE FROM doctor_profiles WHERE id = ?')->execute([$doctorProfileId]);
        }
    } catch (Throwable $ignored) {
    }

    // ارجاع preferred_doctor از دیگران را قطع کن (اگر ستون باشد)
    try {
        $pdo->prepare('UPDATE users SET preferred_doctor_id = NULL WHERE preferred_doctor_id IN (SELECT id FROM doctor_profiles WHERE user_id = ?)')->execute([$userId]);
    } catch (Throwable $ignored) {
        try {
            $pdo->prepare('UPDATE users SET preferred_doctor_id = NULL WHERE id = ?')->execute([$userId]);
        } catch (Throwable $ignored2) {
        }
    }

    $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);
}

function normalize_fa_name(string $name): string
{
    $name = trim($name);
    $name = str_replace(['ي', 'ك', '‌', '‎', '‏'], ['ی', 'ک', '', '', ''], $name);
    $name = preg_replace('/\s+/u', ' ', $name) ?? $name;
    return mb_strtolower($name, 'UTF-8');
}

/**
 * پیدا کردن کاربران تستی مشخص‌شده برای پاک‌سازی
 * @return list<array{id:string,name:string,username:?string,role:string}>
 */
function find_cleanup_test_users(PDO $pdo): array
{
    $stmt = $pdo->query("SELECT id, name, username, role FROM users WHERE role <> 'ADMIN'");
    $rows = $stmt->fetchAll();
    $out = [];
    foreach ($rows as $u) {
        $norm = normalize_fa_name((string) ($u['name'] ?? ''));
        $user = normalize_fa_name((string) ($u['username'] ?? ''));

        // حساب شخصی را نگه دار
        if (str_contains($norm, 'شهابیان') || str_contains($user, 'shahabian') || str_contains($user, 'eshahabian')) {
            continue;
        }

        $match =
            str_contains($norm, 'برهان') ||
            str_contains($norm, 'شاوردی') ||
            str_contains($norm, 'رضایی') ||
            $norm === 'عماد' ||
            str_starts_with($norm, 'عماد ') ||
            in_array($user, ['emad', 'ali', 'alirezaei', 'arezai', 'borhan', 'shaverdi', 'bshaverdi'], true) ||
            str_contains($user, 'borhan') ||
            str_contains($user, 'shaverdi') ||
            str_contains($user, 'rezaei') ||
            str_contains($user, 'rezaee');

        if ($match) {
            $out[] = $u;
        }
    }
    return $out;
}

function admin_appointment_delete_form(string $appointmentId, string $next = '/admin/appointments'): string
{
    if ($appointmentId === '') {
        return '';
    }
    $user = function_exists('current_user') ? current_user() : null;
    $role = strtoupper((string) ($user['role'] ?? ''));
    if (!in_array($role, ['ADMIN', 'DOCTOR'], true)) {
        return '';
    }
    $action = $role === 'DOCTOR' ? '/doctor/appointments' : '/admin/appointments';
    ob_start();
    ?>
    <form method="post" action="<?= e(url($action)) ?>" style="margin:0" onsubmit="return confirm('این نوبت برای همیشه حذف شود؟');">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="delete">
      <input type="hidden" name="appointment_id" value="<?= e($appointmentId) ?>">
      <input type="hidden" name="next" value="<?= e($next) ?>">
      <button type="submit" class="btn btn-danger btn-sm">حذف</button>
    </form>
    <?php
    return (string) ob_get_clean();
}

function appointment_cancel_form(string $appointmentId, string $status, string $action, string $next): string
{
    if ($appointmentId === '' || $status === 'CANCELLED') {
        return '';
    }
    ob_start();
    ?>
    <form method="post" action="<?= e(url($action)) ?>" style="margin:0" onsubmit="return confirm('این نوبت لغو شود؟');">
      <?= csrf_field() ?>
      <input type="hidden" name="id" value="<?= e($appointmentId) ?>">
      <input type="hidden" name="status" value="CANCELLED">
      <input type="hidden" name="next" value="<?= e($next) ?>">
      <button type="submit" class="btn btn-outline btn-sm">لغو</button>
    </form>
    <?php
    return (string) ob_get_clean();
}

function ensure_clinic_maintenance(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    try {
        $pdo->exec("
          CREATE TABLE IF NOT EXISTS clinic_maintenance (
            k VARCHAR(64) PRIMARY KEY,
            v VARCHAR(255) NOT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
          ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (Throwable $ignored) {
    }
    $ready = true;
}

/** پاک‌کردن نوبت‌های تستی انجام‌شده و جلسات شهریور/مهر عماد شهابیان — کارگاه/کاربر/مقاله دست نخورده می‌ماند */
function purge_dummy_clinic_bookings(PDO $pdo): void
{
    ensure_clinic_maintenance($pdo);
    try {
        $done = $pdo->query("SELECT v FROM clinic_maintenance WHERE k='purge_dummy_bookings_20260906'")->fetchColumn();
        if ($done) {
            return;
        }
    } catch (Throwable $ignored) {
        return;
    }

    $ids = [];
    try {
        $rows = $pdo->query("
          SELECT id FROM appointments
          WHERE status IN ('COMPLETED', 'CANCELLED')
             OR starts_at < NOW()
        ")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($rows as $id) {
            $ids[(string) $id] = true;
        }
    } catch (Throwable $ignored) {
    }

    try {
        $users = $pdo->query("
          SELECT id FROM users
          WHERE name LIKE '%شهابیان%'
             OR username LIKE '%shahabian%'
             OR username LIKE '%eshahabian%'
        ")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($users as $uid) {
            foreach ([6, 7] as $jm) {
                $start = jalali_ymd(1405, $jm, 1);
                $end = jalali_ymd(1405, $jm, jalali_month_length(1405, $jm));
                $stmt = $pdo->prepare("
                  SELECT id FROM appointments
                  WHERE patient_id = ?
                    AND DATE(starts_at) BETWEEN ? AND ?
                ");
                $stmt->execute([(string) $uid, $start, $end]);
                foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
                    $ids[(string) $id] = true;
                }
                try {
                    $notes = $pdo->prepare("
                      SELECT id FROM doctor_session_notes
                      WHERE patient_id = ?
                        AND DATE(COALESCE(updated_at, created_at)) BETWEEN ? AND ?
                    ");
                    $notes->execute([(string) $uid, $start, $end]);
                    foreach ($notes->fetchAll(PDO::FETCH_COLUMN) as $nid) {
                        $pdo->prepare('DELETE FROM doctor_session_notes WHERE id=?')->execute([(string) $nid]);
                    }
                } catch (Throwable $ignored) {
                }
            }
        }
    } catch (Throwable $ignored) {
    }

    foreach (array_keys($ids) as $appointmentId) {
        try {
            delete_appointment_by_id($pdo, $appointmentId);
        } catch (Throwable $ignored) {
        }
    }

    try {
        $pdo->prepare("INSERT INTO clinic_maintenance (k, v) VALUES ('purge_dummy_bookings_20260906', ?)")
            ->execute([(string) count($ids)]);
    } catch (Throwable $ignored) {
    }
}

/** حذف یک نوبت و پرداخت مرتبط */
function delete_appointment_by_id(PDO $pdo, string $appointmentId): void
{
    if ($appointmentId === '') {
        throw new RuntimeException('نوبت مشخص نیست.');
    }
    foreach (['doctor_session_notes', 'doctor_highlights'] as $table) {
        try {
            $pdo->prepare("DELETE FROM {$table} WHERE appointment_id=?")->execute([$appointmentId]);
        } catch (Throwable $ignored) {
        }
    }
    $pdo->prepare('DELETE FROM payments WHERE appointment_id=?')->execute([$appointmentId]);
    $pdo->prepare('DELETE FROM appointments WHERE id=?')->execute([$appointmentId]);
}

/** حذف همه نوبت‌ها و پرداخت‌ها */
function delete_all_appointments(PDO $pdo): int
{
    try {
        $pdo->exec('DELETE FROM payments');
    } catch (Throwable $ignored) {
    }
    foreach (['doctor_session_notes', 'doctor_highlights'] as $table) {
        try {
            $pdo->exec("DELETE FROM {$table}");
        } catch (Throwable $ignored) {
        }
    }
    return (int) $pdo->exec('DELETE FROM appointments');
}
