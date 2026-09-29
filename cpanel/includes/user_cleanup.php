<?php
declare(strict_types=1);

/**
 * حذف امن کاربر و همه وابستگی‌ها (بدون اتکا به FK CASCADE).
 * مهم: اینجا CREATE/ALTER نزن — DDL تراکنش MySQL را می‌بندد و commit بعدی خطای کاذب می‌دهد.
 */
function delete_user_cascade(PDO $pdo, string $userId): void
{
    $restoreFk = false;
    try {
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        $restoreFk = true;
    } catch (Throwable $ignored) {
    }
    try {
        delete_user_cascade_work($pdo, $userId);
    } finally {
        if ($restoreFk) {
            try {
                $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
            } catch (Throwable $ignored) {
            }
        }
    }
}

function delete_user_cascade_work(PDO $pdo, string $userId): void
{
    $identity = ['id' => $userId, 'name' => '', 'username' => '', 'phone' => '', 'email' => '', 'role' => ''];
    try {
        $stmt = $pdo->prepare('SELECT id, name, username, phone, email, role FROM users WHERE id=? LIMIT 1');
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        if (is_array($row) && $row) {
            $identity = $row;
        }
    } catch (Throwable $ignored) {
    }
    delete_user_attached_rows($pdo, $identity);

    $try = static function (callable $fn): void {
        try {
            $fn();
        } catch (Throwable $ignored) {
        }
    };

    // اعلان‌ها و منشن‌ها
    $try(static function () use ($pdo, $userId): void {
        $pdo->prepare('DELETE FROM notifications WHERE recipient_user_id = ? OR sender_user_id = ?')
            ->execute([$userId, $userId]);
    });
    $try(static function () use ($pdo, $userId): void {
        $pdo->prepare('DELETE FROM user_mentions WHERE from_user_id = ? OR to_user_id = ?')
            ->execute([$userId, $userId]);
    });

    $try(static function () use ($pdo, $userId): void {
        $pdo->prepare('DELETE FROM staff_handover_notes WHERE from_user_id = ? OR to_user_id = ?')
            ->execute([$userId, $userId]);
    });

    $try(static function () use ($pdo, $userId): void {
        $pdo->prepare('UPDATE staff_shared_notes SET updated_by = NULL WHERE updated_by = ?')->execute([$userId]);
        $pdo->prepare('UPDATE staff_shared_notes SET done_by = NULL WHERE done_by = ?')->execute([$userId]);
        $pdo->prepare('UPDATE staff_shared_notes SET created_by = NULL WHERE created_by = ?')->execute([$userId]);
    });

    $try(static function () use ($pdo, $userId): void {
        $pdo->prepare('DELETE FROM admin_staff_message_recipients WHERE to_user_id = ?')->execute([$userId]);
        $sent = $pdo->prepare('SELECT id, image_path FROM admin_staff_messages WHERE from_user_id = ?');
        $sent->execute([$userId]);
        foreach ($sent->fetchAll() as $row) {
            $mid = (string) ($row['id'] ?? '');
            if ($mid === '') {
                continue;
            }
            $pdo->prepare('DELETE FROM admin_staff_message_recipients WHERE message_id = ?')->execute([$mid]);
            $pdo->prepare('DELETE FROM admin_staff_messages WHERE id = ?')->execute([$mid]);
            $img = trim((string) ($row['image_path'] ?? ''));
            if ($img !== '' && function_exists('admin_staff_msg_abs')) {
                $abs = admin_staff_msg_abs($img);
                if (is_file($abs)) {
                    @unlink($abs);
                }
            }
        }
    });

    $try(static function () use ($pdo, $userId): void {
        $pdo->prepare('DELETE FROM secretary_to_admin_messages WHERE from_user_id = ?')->execute([$userId]);
    });

    // کیف پول
    $try(static function () use ($pdo, $userId): void {
        $pdo->prepare('DELETE FROM wallet_transactions WHERE user_id = ?')->execute([$userId]);
        $pdo->prepare('DELETE FROM wallets WHERE user_id = ?')->execute([$userId]);
    });

    // ژورنال / مراقبت
    foreach (['patient_journal_entries', 'patient_care_notes'] as $table) {
        $try(static function () use ($pdo, $userId, $table): void {
            $pdo->prepare("DELETE FROM {$table} WHERE user_id = ?")->execute([$userId]);
        });
        $try(static function () use ($pdo, $userId, $table): void {
            $pdo->prepare("DELETE FROM {$table} WHERE patient_id = ?")->execute([$userId]);
        });
    }

    // پرداخت‌های نوبت‌های این مراجعه‌کننده
    $try(static function () use ($pdo, $userId): void {
        $pdo->prepare("
          DELETE FROM payments WHERE appointment_id IN (
            SELECT id FROM appointments WHERE patient_id = ?
          )
        ")->execute([$userId]);
    });
    $try(static function () use ($pdo, $userId): void {
        $ids = $pdo->prepare('SELECT id FROM appointments WHERE patient_id = ?');
        $ids->execute([$userId]);
        foreach ($ids->fetchAll(PDO::FETCH_COLUMN) as $aid) {
            $pdo->prepare('DELETE FROM payments WHERE appointment_id = ?')->execute([$aid]);
        }
    });

    // یادداشت / هایلایت / پرونده
    foreach (['doctor_session_notes', 'doctor_highlights', 'doctor_patient_charts'] as $table) {
        $try(static function () use ($pdo, $userId, $table): void {
            $pdo->prepare("DELETE FROM {$table} WHERE patient_id = ?")->execute([$userId]);
        });
    }

    // مسیر کارگاه و ثبت‌نام
    $try(static function () use ($pdo, $userId): void {
        $enr = $pdo->prepare('SELECT id FROM workshop_enrollments WHERE patient_id = ?');
        $enr->execute([$userId]);
        foreach ($enr->fetchAll(PDO::FETCH_COLUMN) as $eid) {
            $eid = (string) $eid;
            $pdo->prepare('DELETE FROM workshop_path_notes WHERE enrollment_id = ?')->execute([$eid]);
            $pdo->prepare('DELETE FROM workshop_payments WHERE enrollment_id = ?')->execute([$eid]);
            $pdo->prepare('DELETE FROM workshop_enrollments WHERE id = ?')->execute([$eid]);
        }
    });

    $try(static function () use ($pdo, $userId): void {
        $pdo->prepare('DELETE FROM workshop_qa_likes WHERE user_id = ?')->execute([$userId]);
        $pdo->prepare('DELETE FROM workshop_qa_posts WHERE author_user_id = ? OR audience_user_id = ?')
            ->execute([$userId, $userId]);
    });

    // نوبت‌ها
    $try(static function () use ($pdo, $userId): void {
        $pdo->prepare('DELETE FROM appointments WHERE patient_id = ?')->execute([$userId]);
    });

    // شیفت / گزارش منشی
    foreach ([
        'staff_shifts' => 'user_id',
        'secretary_action_log' => 'user_id',
        'secretary_day_reports' => 'user_id',
    ] as $table => $col) {
        $try(static function () use ($pdo, $userId, $table, $col): void {
            $pdo->prepare("DELETE FROM {$table} WHERE {$col} = ?")->execute([$userId]);
        });
    }

    // تماس ویدیو
    $try(static function () use ($pdo, $userId): void {
        $pdo->prepare('DELETE FROM video_call_room_members WHERE user_id = ?')->execute([$userId]);
        $pdo->prepare('DELETE FROM video_call_presence WHERE user_id = ?')->execute([$userId]);
        $pdo->prepare('DELETE FROM video_call_contact_stats WHERE user_id = ? OR peer_user_id = ?')
            ->execute([$userId, $userId]);
    });

    // دستیار
    $try(static function () use ($pdo, $userId): void {
        $pdo->prepare('DELETE FROM assistant_sessions WHERE user_id = ?')->execute([$userId]);
    });

    // اگر درمانگر بود
    $try(static function () use ($pdo, $userId): void {
        $dp = $pdo->prepare('SELECT id FROM doctor_profiles WHERE user_id = ?');
        $dp->execute([$userId]);
        $doctorProfileId = $dp->fetchColumn();
        if ($doctorProfileId) {
            foreach (['availabilities', 'appointments', 'doctor_session_notes', 'doctor_highlights', 'doctor_patient_charts'] as $table) {
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
    });

    // ارجاع preferred_doctor از دیگران را قطع کن (اگر ستون باشد)
    $try(static function () use ($pdo, $userId): void {
        $pdo->prepare('UPDATE users SET preferred_doctor_id = NULL WHERE preferred_doctor_id IN (SELECT id FROM doctor_profiles WHERE user_id = ?)')->execute([$userId]);
    });
    $try(static function () use ($pdo, $userId): void {
        $pdo->prepare('UPDATE users SET preferred_doctor_id = NULL WHERE id = ?')->execute([$userId]);
    });

    $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);
    delete_user_name_traces($pdo, $identity);
}

function user_cleanup_run(PDO $pdo, string $sql, array $params = []): void
{
    try {
        $pdo->prepare($sql)->execute($params);
    } catch (Throwable $ignored) {
    }
}

/** @return list<string> */
function user_cleanup_ids(PDO $pdo, string $sql, array $params = []): array
{
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $ids = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $id = trim((string) $id);
            if ($id !== '') {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    } catch (Throwable $ignored) {
        return [];
    }
}

/** @param list<string> $ids */
function user_cleanup_delete_ids(PDO $pdo, string $table, string $column, array $ids): void
{
    if (!$ids || !preg_match('/^[a-z_]+$/', $table) || !preg_match('/^[a-z_]+$/', $column)) {
        return;
    }
    foreach (array_chunk($ids, 80) as $chunk) {
        $marks = implode(',', array_fill(0, count($chunk), '?'));
        user_cleanup_run($pdo, "DELETE FROM {$table} WHERE {$column} IN ($marks)", $chunk);
    }
}

function user_cleanup_unlink_public(string $publicPath): void
{
    $publicPath = trim($publicPath);
    if ($publicPath === '' || !str_starts_with($publicPath, '/uploads/') || str_contains($publicPath, '..')) {
        return;
    }
    $abs = dirname(__DIR__) . $publicPath;
    if (is_file($abs)) {
        @unlink($abs);
    }
}

/** @param list<string> $paths */
function user_cleanup_unlink_column(PDO $pdo, string $sql, array $params, string $column): void
{
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        foreach ($stmt->fetchAll() as $row) {
            user_cleanup_unlink_public((string) ($row[$column] ?? ''));
        }
    } catch (Throwable $ignored) {
    }
}

/**
 * @param array{id?:string,name?:string,username?:string,phone?:string,email?:string,role?:string} $user
 */
function delete_user_attached_rows(PDO $pdo, array $user): void
{
    $userId = trim((string) ($user['id'] ?? ''));
    if ($userId === '') {
        return;
    }

    $doctorIds = user_cleanup_ids($pdo, 'SELECT id FROM doctor_profiles WHERE user_id=?', [$userId]);
    $appointmentIds = user_cleanup_ids($pdo, 'SELECT id FROM appointments WHERE patient_id=?', [$userId]);
    if ($doctorIds) {
        $marks = implode(',', array_fill(0, count($doctorIds), '?'));
        $appointmentIds = array_values(array_unique(array_merge(
            $appointmentIds,
            user_cleanup_ids($pdo, "SELECT id FROM appointments WHERE doctor_id IN ($marks)", $doctorIds)
        )));
    }
    $enrollmentIds = user_cleanup_ids($pdo, 'SELECT id FROM workshop_enrollments WHERE patient_id=?', [$userId]);
    $workshopIds = $doctorIds
        ? user_cleanup_ids($pdo, 'SELECT id FROM workshops WHERE doctor_id IN (' . implode(',', array_fill(0, count($doctorIds), '?')) . ')', $doctorIds)
        : [];
    if ($workshopIds) {
        $marks = implode(',', array_fill(0, count($workshopIds), '?'));
        $enrollmentIds = array_values(array_unique(array_merge(
            $enrollmentIds,
            user_cleanup_ids($pdo, "SELECT id FROM workshop_enrollments WHERE workshop_id IN ($marks)", $workshopIds)
        )));
    }

    user_cleanup_unlink_column($pdo, 'SELECT avatar_url FROM doctor_profiles WHERE user_id=?', [$userId], 'avatar_url');
    user_cleanup_unlink_column($pdo, 'SELECT photo_path FROM patient_journal_entries WHERE patient_id=?', [$userId], 'photo_path');
    if ($appointmentIds) {
        $marks = implode(',', array_fill(0, count($appointmentIds), '?'));
        user_cleanup_unlink_column($pdo, "SELECT receipt_path FROM payments WHERE appointment_id IN ($marks)", $appointmentIds, 'receipt_path');
    }
    if ($enrollmentIds) {
        $marks = implode(',', array_fill(0, count($enrollmentIds), '?'));
        user_cleanup_unlink_column($pdo, "SELECT receipt_path FROM workshop_payments WHERE enrollment_id IN ($marks)", $enrollmentIds, 'receipt_path');
    }
    user_cleanup_unlink_column($pdo, 'SELECT cover_url FROM articles WHERE author_id=? OR submitted_by_user_id=?', [$userId, $userId], 'cover_url');
    if ($workshopIds) {
        $marks = implode(',', array_fill(0, count($workshopIds), '?'));
        user_cleanup_unlink_column($pdo, "SELECT banner_url FROM workshops WHERE id IN ($marks)", $workshopIds, 'banner_url');
    }

    user_cleanup_delete_ids($pdo, 'appointment_shared_notes', 'author_user_id', [$userId]);
    user_cleanup_delete_ids($pdo, 'appointment_shared_notes', 'appointment_id', $appointmentIds);
    user_cleanup_delete_ids($pdo, 'payments', 'appointment_id', $appointmentIds);
    user_cleanup_delete_ids($pdo, 'doctor_session_notes', 'appointment_id', $appointmentIds);
    user_cleanup_delete_ids($pdo, 'doctor_session_notes', 'patient_id', [$userId]);
    user_cleanup_delete_ids($pdo, 'doctor_highlights', 'patient_id', [$userId]);
    user_cleanup_delete_ids($pdo, 'doctor_patient_charts', 'patient_id', [$userId]);
    if ($doctorIds) {
        user_cleanup_delete_ids($pdo, 'doctor_session_notes', 'doctor_id', $doctorIds);
        user_cleanup_delete_ids($pdo, 'doctor_highlights', 'doctor_id', $doctorIds);
        user_cleanup_delete_ids($pdo, 'doctor_patient_charts', 'doctor_id', $doctorIds);
        user_cleanup_delete_ids($pdo, 'availabilities', 'doctor_id', $doctorIds);
        user_cleanup_delete_ids($pdo, 'doctor_weekly_hours', 'doctor_id', $doctorIds);
    }
    user_cleanup_delete_ids($pdo, 'appointments', 'id', $appointmentIds);
    user_cleanup_run($pdo, 'DELETE FROM appointments WHERE patient_id=?', [$userId]);

    user_cleanup_run($pdo, 'DELETE FROM clinic_room_bookings WHERE patient_id=?', [$userId]);
    if ($doctorIds) {
        $marks = implode(',', array_fill(0, count($doctorIds), '?'));
        user_cleanup_run($pdo, "DELETE FROM clinic_room_bookings WHERE doctor_id IN ($marks)", $doctorIds);
    }
    user_cleanup_delete_ids($pdo, 'clinic_room_bookings', 'appointment_id', $appointmentIds);

    user_cleanup_delete_ids($pdo, 'workshop_path_notes', 'enrollment_id', $enrollmentIds);
    user_cleanup_delete_ids($pdo, 'workshop_payments', 'enrollment_id', $enrollmentIds);
    user_cleanup_delete_ids($pdo, 'workshop_enrollments', 'id', $enrollmentIds);
    user_cleanup_run($pdo, 'DELETE FROM workshop_enrollments WHERE patient_id=?', [$userId]);
    user_cleanup_delete_ids($pdo, 'workshop_qa_posts', 'author_user_id', [$userId]);
    user_cleanup_run($pdo, 'DELETE FROM workshop_qa_posts WHERE audience_user_id=?', [$userId]);
    user_cleanup_run($pdo, 'DELETE FROM workshop_qa_likes WHERE user_id=?', [$userId]);
    if ($workshopIds) {
        user_cleanup_delete_ids($pdo, 'workshop_session_notes', 'workshop_id', $workshopIds);
        user_cleanup_delete_ids($pdo, 'workshop_sessions', 'workshop_id', $workshopIds);
        user_cleanup_delete_ids($pdo, 'workshop_media_items', 'workshop_id', $workshopIds);
        user_cleanup_delete_ids($pdo, 'workshop_qa_posts', 'workshop_id', $workshopIds);
        user_cleanup_delete_ids($pdo, 'workshops', 'id', $workshopIds);
    }

    user_cleanup_run($pdo, 'DELETE FROM articles WHERE author_id=? OR submitted_by_user_id=?', [$userId, $userId]);
    user_cleanup_run($pdo, 'DELETE FROM patient_journal_entries WHERE patient_id=?', [$userId]);
    user_cleanup_run($pdo, 'DELETE FROM patient_care_notes WHERE patient_id=?', [$userId]);
    user_cleanup_run($pdo, 'DELETE FROM patient_care_notes WHERE user_id=?', [$userId]);
    user_cleanup_run($pdo, 'DELETE FROM wallet_transactions WHERE user_id=?', [$userId]);
    user_cleanup_run($pdo, 'DELETE FROM wallets WHERE user_id=?', [$userId]);
    user_cleanup_run($pdo, 'DELETE FROM notifications WHERE recipient_user_id=? OR sender_user_id=?', [$userId, $userId]);
    user_cleanup_run($pdo, 'DELETE FROM user_mentions WHERE from_user_id=? OR to_user_id=?', [$userId, $userId]);
    user_cleanup_run($pdo, 'DELETE FROM staff_handover_notes WHERE from_user_id=? OR to_user_id=?', [$userId, $userId]);
    user_cleanup_run($pdo, 'DELETE FROM staff_shared_notes WHERE created_by=? OR updated_by=? OR done_by=?', [$userId, $userId, $userId]);
    user_cleanup_run($pdo, 'DELETE FROM secretary_to_admin_messages WHERE from_user_id=?', [$userId]);
    user_cleanup_run($pdo, 'DELETE FROM staff_shifts WHERE user_id=?', [$userId]);
    user_cleanup_run($pdo, 'DELETE FROM secretary_action_log WHERE user_id=?', [$userId]);
    user_cleanup_run($pdo, 'DELETE FROM secretary_day_reports WHERE user_id=?', [$userId]);
    user_cleanup_run($pdo, 'DELETE FROM assistant_sessions WHERE user_id=? OR assigned_by_user_id=?', [$userId, $userId]);
    user_cleanup_run($pdo, 'DELETE FROM mana_path_events WHERE user_id=?', [$userId]);
    user_cleanup_run($pdo, 'DELETE FROM mana_path_trees WHERE user_id=?', [$userId]);
    user_cleanup_run($pdo, 'DELETE FROM mana_path_profiles WHERE user_id=?', [$userId]);
    user_cleanup_run($pdo, 'DELETE FROM video_call_room_members WHERE user_id=?', [$userId]);
    user_cleanup_run($pdo, 'DELETE FROM video_call_presence WHERE user_id=?', [$userId]);
    user_cleanup_run($pdo, 'DELETE FROM video_call_contact_stats WHERE host_user_id=? OR peer_user_id=?', [$userId, $userId]);
    user_cleanup_run($pdo, 'DELETE FROM video_call_signals WHERE sender_id=? OR target_id=?', [$userId, $userId]);
    $roomIds = user_cleanup_ids($pdo, 'SELECT id FROM video_call_rooms WHERE host_user_id=?', [$userId]);
    user_cleanup_delete_ids($pdo, 'video_call_room_members', 'room_id', $roomIds);
    user_cleanup_delete_ids($pdo, 'video_call_rooms', 'id', $roomIds);
    user_cleanup_run($pdo, 'DELETE FROM password_reset_tokens WHERE user_id=?', [$userId]);
    $email = trim((string) ($user['email'] ?? ''));
    if ($email !== '') {
        user_cleanup_run($pdo, 'DELETE FROM password_reset_tokens WHERE email=?', [$email]);
    }
    if ($doctorIds) {
        $marks = implode(',', array_fill(0, count($doctorIds), '?'));
        user_cleanup_run($pdo, "UPDATE users SET preferred_doctor_id=NULL WHERE preferred_doctor_id IN ($marks)", $doctorIds);
    }
    user_cleanup_run($pdo, 'DELETE FROM doctor_profiles WHERE user_id=?', [$userId]);
}

/**
 * @param array{id?:string,name?:string,phone?:string,email?:string} $user
 */
function delete_user_name_traces(PDO $pdo, array $user): void
{
    $name = trim((string) ($user['name'] ?? ''));
    $phone = trim((string) ($user['phone'] ?? ''));
    if ($phone !== '') {
        user_cleanup_run($pdo, 'DELETE FROM consult_requests WHERE phone=?', [$phone]);
    }
    if (mb_strlen($name) >= 5) {
        $like = '%' . str_replace(['%', '_'], '', $name) . '%';
        user_cleanup_run($pdo, 'DELETE FROM consult_requests WHERE name=?', [$name]);
        user_cleanup_run($pdo, 'DELETE FROM notifications WHERE title LIKE ? OR body LIKE ?', [$like, $like]);
        user_cleanup_run($pdo, 'DELETE FROM clinic_room_bookings WHERE title LIKE ? OR note LIKE ?', [$like, $like]);
    }
}

/** ردیف‌های یتیم و حسابی که حذف شده ولی اسمش مانده را از دیتابیس برمی‌دارد. */
function purge_removed_user_traces(PDO $pdo): void
{
    ensure_clinic_maintenance($pdo);
    try {
        $done = $pdo->query("SELECT v FROM clinic_maintenance WHERE k='purge_user_traces_20260929'")->fetchColumn();
        if ($done) {
            return;
        }
    } catch (Throwable $ignored) {
        return;
    }

    $targets = [];
    try {
        $stmt = $pdo->query("SELECT id FROM users WHERE name LIKE '%مهراد%' AND name LIKE '%بابایی%'");
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $id = trim((string) $id);
            if ($id !== '') {
                $targets[] = $id;
            }
        }
    } catch (Throwable $ignored) {
    }
    foreach ($targets as $id) {
        try {
            delete_user_cascade($pdo, $id);
        } catch (Throwable $ignored) {
        }
    }

    user_cleanup_run($pdo, 'DELETE FROM appointments WHERE patient_id NOT IN (SELECT id FROM users)');
    user_cleanup_run($pdo, 'DELETE FROM doctor_profiles WHERE user_id NOT IN (SELECT id FROM users)');
    user_cleanup_run($pdo, 'DELETE FROM clinic_room_bookings WHERE patient_id IS NOT NULL AND patient_id<>\'\' AND patient_id NOT IN (SELECT id FROM users)');
    user_cleanup_run($pdo, 'DELETE FROM clinic_room_bookings WHERE doctor_id IS NOT NULL AND doctor_id<>\'\' AND doctor_id NOT IN (SELECT id FROM doctor_profiles)');
    user_cleanup_run($pdo, 'DELETE FROM workshop_enrollments WHERE patient_id NOT IN (SELECT id FROM users)');
    user_cleanup_run($pdo, 'DELETE FROM articles WHERE author_id NOT IN (SELECT id FROM users)');
    user_cleanup_run($pdo, "DELETE FROM consult_requests WHERE name LIKE '%مهراد%' AND name LIKE '%بابایی%' AND NOT EXISTS (SELECT 1 FROM users WHERE name LIKE '%مهراد%' AND name LIKE '%بابایی%')");
    user_cleanup_run($pdo, "DELETE FROM notifications WHERE (title LIKE '%مهراد بابایی%' OR body LIKE '%مهراد بابایی%') AND NOT EXISTS (SELECT 1 FROM users WHERE name LIKE '%مهراد%' AND name LIKE '%بابایی%')");

    $left = 1;
    try {
        $left = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE name LIKE '%مهراد%' AND name LIKE '%بابایی%'")->fetchColumn();
    } catch (Throwable $ignored) {
        return;
    }
    if ($left > 0) {
        return;
    }
    try {
        $pdo->prepare("INSERT INTO clinic_maintenance (k, v) VALUES ('purge_user_traces_20260929', '1')")->execute();
    } catch (Throwable $ignored) {
    }
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
