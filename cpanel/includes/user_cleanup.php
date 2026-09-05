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
