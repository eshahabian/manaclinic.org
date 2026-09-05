<?php
declare(strict_types=1);

const STAFF_IDLE_SECONDS = 600;
const STAFF_RECEIPT_MAX_BYTES = 5242880;

function staff_idle_seconds(): int
{
    return STAFF_IDLE_SECONDS;
}

function ensure_staff_desk_schema(PDO $pdo): void
{
    static $passes = 0;
    if ($passes >= 2) {
        return;
    }
    $passes++;

    $addColumn = static function (PDO $pdo, string $table, string $column, string $ddl): void {
        try {
            $has = $pdo->query("SHOW COLUMNS FROM {$table} LIKE " . $pdo->quote($column))->fetch();
            if (!$has) {
                $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$ddl}");
            }
        } catch (Throwable $ignored) {
        }
    };

    $addColumn($pdo, 'appointments', 'created_by_user_id', 'created_by_user_id VARCHAR(32) NULL AFTER notes');
    $addColumn($pdo, 'payments', 'recorded_by_user_id', 'recorded_by_user_id VARCHAR(32) NULL AFTER status');
    $addColumn($pdo, 'payments', 'receipt_path', 'receipt_path VARCHAR(255) NULL AFTER recorded_by_user_id');
    $addColumn($pdo, 'users', 'created_by_user_id', 'created_by_user_id VARCHAR(32) NULL AFTER preferred_doctor_id');
    $addColumn($pdo, 'workshops', 'created_by_user_id', 'created_by_user_id VARCHAR(32) NULL AFTER status');
    $addColumn($pdo, 'workshops', 'updated_by_user_id', 'updated_by_user_id VARCHAR(32) NULL AFTER created_by_user_id');
    $addColumn($pdo, 'assistant_sessions', 'assigned_by_user_id', 'assigned_by_user_id VARCHAR(32) NULL AFTER assigned_at');

    try {
        $pdo->exec("
          CREATE TABLE IF NOT EXISTS staff_shifts (
            id VARCHAR(32) PRIMARY KEY,
            user_id VARCHAR(32) NOT NULL,
            started_at DATETIME NOT NULL,
            ended_at DATETIME NULL,
            last_seen_at DATETIME NOT NULL,
            end_reason ENUM('logout','idle','login_replace') NULL,
            INDEX idx_staff_shift_user (user_id, started_at),
            INDEX idx_staff_shift_open (user_id, ended_at)
          ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (Throwable $ignored) {
    }

    try {
        $pdo->exec("
          CREATE TABLE IF NOT EXISTS secretary_action_log (
            id VARCHAR(32) PRIMARY KEY,
            user_id VARCHAR(32) NOT NULL,
            action VARCHAR(64) NOT NULL,
            target_type VARCHAR(32) NULL,
            target_id VARCHAR(32) NULL,
            note VARCHAR(255) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_sec_log_user (user_id, created_at)
          ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (Throwable $ignored) {
    }

    ensure_secretary_accounts($pdo);
}

function ensure_secretary_accounts(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    try {
        $old = $pdo->query("SELECT id FROM users WHERE username='secretary' AND role='SECRETARY' LIMIT 1")->fetch();
        $has1 = $pdo->query("SELECT id FROM users WHERE username='secretary1' LIMIT 1")->fetch();
        if ($old && !$has1) {
            $pdo->prepare("UPDATE users SET username='secretary1', name='منشی ۱' WHERE id=?")
                ->execute([(string) $old['id']]);
        } elseif ($has1) {
            $pdo->prepare("UPDATE users SET name='منشی ۱' WHERE username='secretary1' AND role='SECRETARY'")
                ->execute();
        } else {
            $pdo->prepare('INSERT INTO users (id,username,name,email,phone,password_hash,role,must_change_password) VALUES (?,?,?,?,?,?,?,1)')
                ->execute([
                    'secretary001mana',
                    'secretary1',
                    'منشی ۱',
                    'secretary1@manaclinic.local',
                    '09124444444',
                    password_hash('123', PASSWORD_DEFAULT),
                    'SECRETARY',
                ]);
        }

        $has2 = $pdo->query("SELECT id FROM users WHERE username='secretary2' LIMIT 1")->fetch();
        if (!$has2) {
            $pdo->prepare('INSERT INTO users (id,username,name,email,phone,password_hash,role,must_change_password) VALUES (?,?,?,?,?,?,?,1)')
                ->execute([
                    'secretary002mana',
                    'secretary2',
                    'منشی ۲',
                    'secretary2@manaclinic.local',
                    '09124444445',
                    password_hash('123', PASSWORD_DEFAULT),
                    'SECRETARY',
                ]);
        } else {
            $pdo->prepare("UPDATE users SET name='منشی ۲' WHERE username='secretary2' AND role='SECRETARY'")
                ->execute();
        }
    } catch (Throwable $ignored) {
    }
}

function staff_actor_label(?array $row): string
{
    if (!$row) {
        return 'منشی';
    }
    $name = trim((string) ($row['name'] ?? ''));
    $username = trim((string) ($row['username'] ?? ''));
    if ($name !== '' && $username !== '') {
        return $name . ' · ' . $username;
    }
    return $name !== '' ? $name : ($username !== '' ? $username : 'منشی');
}

function staff_user_by_id(PDO $pdo, string $userId): ?array
{
    if ($userId === '') {
        return null;
    }
    $stmt = $pdo->prepare('SELECT id, name, username, role FROM users WHERE id=? LIMIT 1');
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function staff_sign_html(?array $row, string $prefix = 'امضا'): string
{
    if (!$row || (trim((string) ($row['name'] ?? '')) === '' && trim((string) ($row['username'] ?? '')) === '')) {
        return '';
    }
    return '<span class="staff-sign">' . e($prefix . ': ' . staff_actor_label($row)) . '</span>';
}

function staff_sign_for_id(PDO $pdo, ?string $userId, string $prefix = 'امضا'): string
{
    if (!$userId) {
        return '';
    }
    return staff_sign_html(staff_user_by_id($pdo, $userId), $prefix);
}

function staff_log_action(PDO $pdo, string $userId, string $action, ?string $targetType = null, ?string $targetId = null, ?string $note = null): void
{
    try {
        $pdo->prepare('INSERT INTO secretary_action_log (id,user_id,action,target_type,target_id,note) VALUES (?,?,?,?,?,?)')
            ->execute([cuid(), $userId, $action, $targetType, $targetId, $note]);
    } catch (Throwable $ignored) {
    }
}

function staff_close_stale_shifts(PDO $pdo, ?string $userId = null): void
{
    $sql = "
      UPDATE staff_shifts
      SET ended_at = last_seen_at, end_reason = 'idle'
      WHERE ended_at IS NULL
        AND last_seen_at < DATE_SUB(NOW(), INTERVAL ? SECOND)
    ";
    $params = [staff_idle_seconds()];
    if ($userId) {
        $sql .= ' AND user_id = ?';
        $params[] = $userId;
    }
    try {
        $pdo->prepare($sql)->execute($params);
    } catch (Throwable $ignored) {
    }
}

function staff_shift_start(PDO $pdo, string $userId): void
{
    staff_close_stale_shifts($pdo, $userId);
    try {
        $pdo->prepare("UPDATE staff_shifts SET ended_at=NOW(), end_reason='login_replace' WHERE user_id=? AND ended_at IS NULL")
            ->execute([$userId]);
        $id = cuid();
        $pdo->prepare('INSERT INTO staff_shifts (id,user_id,started_at,last_seen_at) VALUES (?,?,NOW(),NOW())')
            ->execute([$id, $userId]);
        $_SESSION['staff_shift_id'] = $id;
        $_SESSION['last_activity'] = time();
    } catch (Throwable $ignored) {
    }
}

function staff_shift_end(PDO $pdo, string $userId, string $reason = 'logout'): void
{
    if (!in_array($reason, ['logout', 'idle', 'login_replace'], true)) {
        $reason = 'logout';
    }
    try {
        $sid = (string) ($_SESSION['staff_shift_id'] ?? '');
        if ($sid !== '') {
            $pdo->prepare('UPDATE staff_shifts SET ended_at=IFNULL(ended_at, NOW()), last_seen_at=NOW(), end_reason=? WHERE id=? AND ended_at IS NULL')
                ->execute([$reason, $sid]);
        } else {
            $pdo->prepare('UPDATE staff_shifts SET ended_at=NOW(), last_seen_at=NOW(), end_reason=? WHERE user_id=? AND ended_at IS NULL')
                ->execute([$reason, $userId]);
        }
    } catch (Throwable $ignored) {
    }
    unset($_SESSION['staff_shift_id']);
}

function staff_touch_activity(PDO $pdo, string $userId): void
{
    $_SESSION['last_activity'] = time();
    try {
        $sid = (string) ($_SESSION['staff_shift_id'] ?? '');
        if ($sid !== '') {
            $pdo->prepare('UPDATE staff_shifts SET last_seen_at=NOW() WHERE id=? AND ended_at IS NULL')
                ->execute([$sid]);
            return;
        }
        $pdo->prepare('UPDATE staff_shifts SET last_seen_at=NOW() WHERE user_id=? AND ended_at IS NULL')
            ->execute([$userId]);
    } catch (Throwable $ignored) {
    }
}

function staff_current_shift(PDO $pdo, string $userId): ?array
{
    try {
        $stmt = $pdo->prepare('SELECT * FROM staff_shifts WHERE user_id=? AND ended_at IS NULL ORDER BY started_at DESC LIMIT 1');
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        return $row ?: null;
    } catch (Throwable $ignored) {
        return null;
    }
}

function staff_idle_logout(PDO $pdo, array $user): never
{
    staff_shift_end($pdo, (string) $user['id'], 'idle');
    unset($_SESSION['user'], $_SESSION['last_activity'], $_SESSION['staff_shift_id']);
    flash_set('info', 'به‌خاطر ۱۰ دقیقه بی‌فعالیتی از حساب خارج شدید و ساعت کاری متوقف شد.');
    redirect('/login');
}

function staff_guard_session(PDO $pdo, array $user, bool $touch = true): void
{
    if (($user['role'] ?? '') !== 'SECRETARY') {
        return;
    }
    staff_close_stale_shifts($pdo, (string) $user['id']);
    $last = (int) ($_SESSION['last_activity'] ?? 0);
    if ($last > 0 && (time() - $last) >= staff_idle_seconds()) {
        staff_idle_logout($pdo, $user);
    }
    if ($touch) {
        staff_touch_activity($pdo, (string) $user['id']);
    }
}

function staff_format_duration(int $seconds): string
{
    $seconds = max(0, $seconds);
    $h = intdiv($seconds, 3600);
    $m = intdiv($seconds % 3600, 60);
    return to_fa_digits((string) $h) . ' ساعت و ' . to_fa_digits((string) $m) . ' دقیقه';
}

function staff_shift_seconds(array $shift): int
{
    $start = strtotime((string) ($shift['started_at'] ?? ''));
    if (!$start) {
        return 0;
    }
    $end = !empty($shift['ended_at']) ? strtotime((string) $shift['ended_at']) : time();
    return max(0, (int) $end - $start);
}

function staff_shift_reason_label(?string $reason): string
{
    return match ($reason) {
        'logout' => 'خروج',
        'idle' => 'قطع به‌خاطر بی‌فعالیتی',
        'login_replace' => 'ورود دوباره',
        default => 'در حال کار',
    };
}

function staff_receipt_root(): string
{
    return dirname(__DIR__) . '/uploads/receipts';
}

function staff_receipt_allowed_specs(): array
{
    return [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'application/pdf' => 'pdf',
    ];
}

function staff_save_receipt(array $file, string $paymentId): string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        throw new RuntimeException('فایلی انتخاب نشده است.');
    }
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('آپلود فیش ناموفق بود.');
    }
    if (($file['size'] ?? 0) > STAFF_RECEIPT_MAX_BYTES) {
        throw new RuntimeException('حجم فیش حداکثر ۵ مگابایت باشد.');
    }
    $tmp = (string) ($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        throw new RuntimeException('فایل فیش معتبر نیست.');
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string) $finfo->file($tmp);
    $allowed = staff_receipt_allowed_specs();
    if (!isset($allowed[$mime])) {
        throw new RuntimeException('فیش باید تصویر (JPG/PNG/WEBP) یا PDF باشد.');
    }
    $dir = staff_receipt_root();
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('ساخت پوشه فیش‌ها ناموفق بود.');
    }
    $safeId = preg_replace('/[^a-zA-Z0-9_-]/', '', $paymentId) ?: cuid();
    $relative = $safeId . '-' . bin2hex(random_bytes(4)) . '.' . $allowed[$mime];
    $dest = $dir . '/' . $relative;
    if (!move_uploaded_file($tmp, $dest)) {
        throw new RuntimeException('ذخیره فیش ناموفق بود.');
    }
    return $relative;
}

function staff_receipt_abs(string $relative): string
{
    return staff_receipt_root() . '/' . basename($relative);
}

function staff_receipt_view_html(?string $paymentId, ?string $receiptPath, bool $canUpload = false): string
{
    if (!$paymentId) {
        return '';
    }
    ob_start();
    if ($receiptPath) {
        ?>
        <a class="btn btn-outline btn-sm" href="<?= e(url('/staff/receipt?id=' . $paymentId)) ?>" target="_blank" rel="noopener">مشاهده فیش</a>
        <?php
    } else {
        ?>
        <span class="muted" style="font-size:.8rem">فیش ثبت نشده</span>
        <?php
    }
    if ($canUpload) {
        ?>
        <form class="staff-receipt-form" method="post" action="<?= e(url('/secretary/receipt')) ?>" enctype="multipart/form-data">
          <input type="hidden" name="payment_id" value="<?= e($paymentId) ?>">
          <label class="btn btn-outline btn-sm staff-receipt-pick">
            <?= $receiptPath ? 'تعویض فیش' : 'آپلود فیش' ?>
            <input type="file" name="receipt" accept="image/jpeg,image/png,image/webp,application/pdf" required onchange="this.form.submit()">
          </label>
        </form>
        <?php
    }
    return trim((string) ob_get_clean());
}
