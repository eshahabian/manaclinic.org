<?php
declare(strict_types=1);

const STAFF_IDLE_SECONDS = 600;
const DOCTOR_SHIFT_IDLE_SECONDS = 43200;
const STAFF_RECEIPT_MAX_BYTES = 5242880;
const STAFF_REGULAR_START = '09:00:00';
const STAFF_REGULAR_END = '20:00:00';

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
    $addColumn($pdo, 'appointments', 'cancel_reason', 'cancel_reason VARCHAR(32) NULL AFTER created_by_user_id');
    $addColumn($pdo, 'appointments', 'cancellation_note', 'cancellation_note TEXT NULL AFTER cancel_reason');
    $addColumn($pdo, 'appointments', 'cancelled_by_user_id', 'cancelled_by_user_id VARCHAR(32) NULL AFTER cancellation_note');
    $addColumn($pdo, 'appointments', 'cancelled_at', 'cancelled_at DATETIME NULL AFTER cancelled_by_user_id');
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
    ensure_secretary_day_reports($pdo);
    ensure_staff_slot_labels($pdo);
}

function ensure_staff_slot_labels(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    try {
        $pdo->exec("
          CREATE TABLE IF NOT EXISTS staff_slot_labels (
            slot TINYINT NOT NULL PRIMARY KEY,
            label VARCHAR(80) NOT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
          ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (Throwable $ignored) {
    }
    $ready = true;
}

function staff_slot_default_label(int $slot): string
{
    return $slot === 2 ? 'منشی ۲' : 'منشی ۱';
}

function staff_slot_labels(PDO $pdo): array
{
    ensure_staff_slot_labels($pdo);
    $out = [1 => staff_slot_default_label(1), 2 => staff_slot_default_label(2)];
    try {
        foreach ($pdo->query('SELECT slot, label FROM staff_slot_labels') as $row) {
            $slot = (int) ($row['slot'] ?? 0);
            $label = trim((string) ($row['label'] ?? ''));
            if (($slot === 1 || $slot === 2) && $label !== '') {
                $out[$slot] = $label;
            }
        }
    } catch (Throwable $ignored) {
    }
    return $out;
}

function staff_slot_set_label(PDO $pdo, int $slot, string $label): void
{
    ensure_staff_slot_labels($pdo);
    $slot = $slot === 2 ? 2 : 1;
    $label = trim($label);
    if ($label === '') {
        $label = staff_slot_default_label($slot);
    }
    if (function_exists('mb_substr')) {
        $label = mb_substr($label, 0, 80);
    } else {
        $label = substr($label, 0, 80);
    }
    $pdo->prepare('
      INSERT INTO staff_slot_labels (slot, label) VALUES (?,?)
      ON DUPLICATE KEY UPDATE label = VALUES(label)
    ')->execute([$slot, $label]);
}

function staff_slot_user(PDO $pdo, int $slot): ?array
{
    $slot = $slot === 2 ? 2 : 1;
    $username = $slot === 2 ? 'secretary2' : 'secretary1';
    try {
        $stmt = $pdo->prepare("SELECT id, name, username FROM users WHERE username=? AND role='SECRETARY' LIMIT 1");
        $stmt->execute([$username]);
        $row = $stmt->fetch();
        if (is_array($row) && $row) {
            return $row;
        }
        $all = $pdo->query("SELECT id, name, username FROM users WHERE role='SECRETARY' ORDER BY username ASC")->fetchAll();
        $pick = is_array($all) ? ($all[$slot - 1] ?? null) : null;
        return is_array($pick) ? $pick : null;
    } catch (Throwable $ignored) {
        return null;
    }
}

function ensure_secretary_day_reports(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    try {
        $pdo->exec("
          CREATE TABLE IF NOT EXISTS secretary_day_reports (
            id VARCHAR(32) PRIMARY KEY,
            user_id VARCHAR(32) NOT NULL,
            report_date DATE NOT NULL,
            body TEXT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_sec_day_report (user_id, report_date),
            INDEX idx_sec_report_date (report_date)
          ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (Throwable $ignored) {
    }
    $ready = true;
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

function staff_tracks_presence(?array $user): bool
{
    $role = (string) ($user['role'] ?? '');
    return $role === 'SECRETARY' || $role === 'DOCTOR';
}

function staff_shift_idle_seconds_for_role(string $role): int
{
    return $role === 'DOCTOR' ? DOCTOR_SHIFT_IDLE_SECONDS : staff_idle_seconds();
}

function staff_close_stale_shifts(PDO $pdo, ?string $userId = null): void
{
    try {
        if ($userId) {
            $roleStmt = $pdo->prepare('SELECT role FROM users WHERE id=? LIMIT 1');
            $roleStmt->execute([$userId]);
            $role = (string) ($roleStmt->fetchColumn() ?: '');
            $idle = staff_shift_idle_seconds_for_role($role);
            $pdo->prepare("
              UPDATE staff_shifts
              SET ended_at = last_seen_at, end_reason = 'idle'
              WHERE ended_at IS NULL
                AND last_seen_at < DATE_SUB(NOW(), INTERVAL ? SECOND)
                AND user_id = ?
            ")->execute([$idle, $userId]);
            return;
        }
        $pdo->prepare("
          UPDATE staff_shifts s
          JOIN users u ON u.id = s.user_id
          SET s.ended_at = s.last_seen_at, s.end_reason = 'idle'
          WHERE s.ended_at IS NULL
            AND (
              (u.role = 'DOCTOR' AND s.last_seen_at < DATE_SUB(NOW(), INTERVAL ? SECOND))
              OR (IFNULL(u.role, '') <> 'DOCTOR' AND s.last_seen_at < DATE_SUB(NOW(), INTERVAL ? SECOND))
            )
        ")->execute([DOCTOR_SHIFT_IDLE_SECONDS, staff_idle_seconds()]);
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
    $role = (string) ($user['role'] ?? '');
    $userId = (string) ($user['id'] ?? '');
    if ($userId === '' || !staff_tracks_presence($user)) {
        return;
    }
    staff_close_stale_shifts($pdo, $userId);
    if ($role === 'SECRETARY') {
        $last = (int) ($_SESSION['last_activity'] ?? 0);
        if ($last > 0 && (time() - $last) >= staff_idle_seconds()) {
            staff_idle_logout($pdo, $user);
        }
    }
    if ($touch) {
        staff_touch_activity($pdo, $userId);
    }
    if ($role === 'DOCTOR' && !staff_current_shift($pdo, $userId)) {
        staff_shift_start($pdo, $userId);
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
    return (int) (staff_shift_seconds_split($shift)['total'] ?? 0);
}

function staff_regular_window(): array
{
    return [
        'start' => STAFF_REGULAR_START,
        'end' => STAFF_REGULAR_END,
    ];
}

/** تفکیک ساعت عادی (۹ تا ۲۰) و اضافه‌کار */
function staff_interval_seconds_split(int $start, int $end): array
{
    if ($start <= 0 || $end <= $start) {
        return ['total' => 0, 'regular' => 0, 'overtime' => 0];
    }

    $regular = 0;
    $cursor = $start;
    while ($cursor < $end) {
        $day = date('Y-m-d', $cursor);
        $nextMidnight = strtotime($day . ' +1 day') ?: ($cursor + 86400);
        $segEnd = min($end, $nextMidnight);
        $rStart = strtotime($day . ' ' . STAFF_REGULAR_START) ?: $cursor;
        $rEnd = strtotime($day . ' ' . STAFF_REGULAR_END) ?: $cursor;
        $overlap = min($segEnd, $rEnd) - max($cursor, $rStart);
        if ($overlap > 0) {
            $regular += $overlap;
        }
        $cursor = $segEnd;
    }

    $total = $end - $start;
    $regular = min($total, $regular);

    return [
        'total' => $total,
        'regular' => $regular,
        'overtime' => max(0, $total - $regular),
    ];
}

function staff_shift_seconds_split(array $shift): array
{
    $start = strtotime((string) ($shift['started_at'] ?? ''));
    if (!$start) {
        return ['total' => 0, 'regular' => 0, 'overtime' => 0];
    }
    $end = !empty($shift['ended_at']) ? strtotime((string) $shift['ended_at']) : time();

    return staff_interval_seconds_split($start, (int) $end);
}

/** فقط ردیف‌های شیفت (لیست یا یک ردیف انجمنی) */
function staff_hours_shift_rows(mixed $rows): array
{
    if (!is_array($rows) || $rows === []) {
        return [];
    }
    if (array_key_exists('started_at', $rows) || array_key_exists('ended_at', $rows) || array_key_exists('user_id', $rows)) {
        return [$rows];
    }
    $out = [];
    foreach ($rows as $row) {
        if (is_array($row)) {
            $out[] = $row;
        }
    }
    return $out;
}

function staff_rows_seconds_split(array $rows): array
{
    $out = ['total' => 0, 'regular' => 0, 'overtime' => 0];
    foreach (staff_hours_shift_rows($rows) as $row) {
        $part = staff_shift_seconds_split($row);
        $out['total'] += $part['total'];
        $out['regular'] += $part['regular'];
        $out['overtime'] += $part['overtime'];
    }

    return $out;
}

/** اولین ورود، آخرین خروج و باز بودن شیفت در یک روز */
function staff_day_presence_meta(array $rows): array
{
    $firstIn = null;
    $lastOut = null;
    $open = false;
    $rows = staff_hours_shift_rows($rows);
    foreach ($rows as $row) {
        $started = trim((string) ($row['started_at'] ?? ''));
        if ($started !== '' && ($firstIn === null || strcmp($started, $firstIn) < 0)) {
            $firstIn = $started;
        }
        $ended = trim((string) ($row['ended_at'] ?? ''));
        if ($ended === '') {
            $open = true;
        } elseif ($lastOut === null || strcmp($ended, $lastOut) > 0) {
            $lastOut = $ended;
        }
    }

    return [
        'first_in' => $firstIn,
        'last_out' => $lastOut,
        'open' => $open,
        'count' => count($rows),
    ];
}

function staff_format_split_line(array $split, bool $alwaysOvertime = false): string
{
    $line = 'عادی: ' . staff_format_duration((int) ($split['regular'] ?? 0));
    $ot = (int) ($split['overtime'] ?? 0);
    if ($alwaysOvertime || $ot > 0) {
        $line .= ' · اضافه‌کار: ' . staff_format_duration($ot);
    }

    return $line;
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

function staff_action_label(string $action): string
{
    return match ($action) {
        'book_appointment' => 'ثبت نوبت',
        'receipt_upload' => 'بارگذاری فیش نوبت',
        'workshop_enroll' => 'ثبت ورودی کارگاه',
        'workshop_mark_paid' => 'ثبت پرداخت کارگاه با فیش',
        'workshop_create' => 'ایجاد کارگاه',
        'workshop_update' => 'ویرایش کارگاه',
        'workshop_publish' => 'انتشار کارگاه',
        'workshop_unpublish' => 'لغو انتشار کارگاه',
        'workshop_enroll_open' => 'باز کردن ثبت‌نام کارگاه',
        'workshop_enroll_close' => 'بستن ثبت‌نام کارگاه',
        'workshop_delete' => 'حذف کارگاه',
        'workshop_media_upload' => 'بارگذاری فایل کارگاه',
        'workshop_media_delete' => 'حذف فایل کارگاه',
        'article_submit' => 'ارسال مقاله برای تأیید دکتر',
        'article_delete' => 'حذف پیش‌نویس مقاله',
        'delete_patient' => 'حذف مراجعه‌کننده',
        'create_patient' => 'ثبت مراجعه‌کننده',
        'cancel_patient' => 'ثبت کنسلی مراجع',
        'day_report' => 'ثبت گزارش پایان روز',
        default => $action,
    };
}

/** داده ساعت کاری منشی‌ها و درمانگرها برای پنل دکتر و ادمین */
function staff_hours_list_doctors(PDO $pdo): array
{
    try {
        $rows = $pdo->query("
          SELECT u.id, u.name, u.username, u.role
          FROM users u
          INNER JOIN doctor_profiles dp ON dp.user_id = u.id
          WHERE u.role = 'DOCTOR' AND dp.is_approved = 1
          ORDER BY dp.is_active DESC, u.name ASC
        ")->fetchAll();
        return is_array($rows) ? $rows : [];
    } catch (Throwable $ignored) {
        return [];
    }
}

function staff_hours_build_block(PDO $pdo, ?array $user, array $meta): array
{
    $user = is_array($user) ? $user : null;
    $uid = $user ? (string) ($user['id'] ?? '') : '';
    $open = $uid !== '' ? staff_current_shift($pdo, $uid) : null;
    $todayRows = [];
    $histRows = [];
    $reportRows = [];
    $withReports = !empty($meta['with_reports']);
    if ($uid !== '') {
        try {
            ensure_staff_desk_schema($pdo);
            $today = $pdo->prepare("
              SELECT * FROM staff_shifts
              WHERE user_id=? AND DATE(started_at)=CURDATE()
              ORDER BY started_at ASC
            ");
            $today->execute([$uid]);
            $fetchedToday = $today->fetchAll();
            $todayRows = staff_hours_shift_rows(is_array($fetchedToday) ? $fetchedToday : []);
            $hist = $pdo->prepare("
              SELECT * FROM staff_shifts
              WHERE user_id=?
              ORDER BY started_at DESC
              LIMIT 2500
            ");
            $hist->execute([$uid]);
            $fetchedHist = $hist->fetchAll();
            $histRows = staff_hours_shift_rows(is_array($fetchedHist) ? $fetchedHist : []);
            if ($withReports) {
                ensure_secretary_day_reports($pdo);
                $reports = $pdo->prepare("
                  SELECT report_date, body, updated_at
                  FROM secretary_day_reports
                  WHERE user_id=?
                  ORDER BY report_date DESC
                  LIMIT 400
                ");
                $reports->execute([$uid]);
                $fetchedReports = $reports->fetchAll();
                $reportRows = is_array($fetchedReports) ? $fetchedReports : [];
            }
        } catch (Throwable $ignored) {
            $todayRows = [];
            $histRows = [];
            $reportRows = [];
        }
    }
    $todaySeconds = 0;
    foreach ($todayRows as $row) {
        $todaySeconds += staff_shift_seconds($row);
    }
    $reportsByDate = [];
    foreach ($reportRows as $rep) {
        if (!is_array($rep)) {
            continue;
        }
        $reportsByDate[(string) ($rep['report_date'] ?? '')] = $rep;
    }

    return [
        'kind' => (string) ($meta['kind'] ?? 'secretary'),
        'slot' => $meta['slot'] ?? null,
        'key' => (string) ($meta['key'] ?? '1'),
        'tab_id' => (string) ($meta['tab_id'] ?? 'sec-1'),
        'tab_class' => (string) ($meta['tab_class'] ?? 'binder-tab-in-person'),
        'tab_tone' => (string) ($meta['tab_tone'] ?? 'in-person'),
        'label' => (string) ($meta['label'] ?? staff_actor_label($user)),
        'user' => $user,
        'open' => $open,
        'today_seconds' => $todaySeconds,
        'today_rows' => $todayRows,
        'days' => staff_shifts_grouped_by_day($histRows),
        'reports' => $reportRows,
        'reports_by_date' => $reportsByDate,
    ];
}

function staff_hours_collect(PDO $pdo): array
{
    try {
        ensure_staff_desk_schema($pdo);
    } catch (Throwable $ignored) {
    }
    staff_close_stale_shifts($pdo);
    ensure_secretary_accounts($pdo);
    ensure_secretary_day_reports($pdo);
    $labels = staff_slot_labels($pdo);
    $people = [];
    foreach ([1, 2] as $slot) {
        try {
            $sec = staff_slot_user($pdo, $slot);
            $people[] = staff_hours_build_block($pdo, $sec, [
                'kind' => 'secretary',
                'slot' => $slot,
                'key' => (string) $slot,
                'tab_id' => 'sec-' . $slot,
                'tab_class' => $slot === 1 ? 'binder-tab-appts' : 'binder-tab-workshops',
                'tab_tone' => $slot === 1 ? 'appts' : 'workshops',
                'label' => (string) ($labels[$slot] ?? staff_slot_default_label($slot)),
                'with_reports' => true,
            ]);
        } catch (Throwable $ignored) {
            $people[] = staff_hours_build_block($pdo, null, [
                'kind' => 'secretary',
                'slot' => $slot,
                'key' => (string) $slot,
                'tab_id' => 'sec-' . $slot,
                'tab_class' => $slot === 1 ? 'binder-tab-appts' : 'binder-tab-workshops',
                'tab_tone' => $slot === 1 ? 'appts' : 'workshops',
                'label' => (string) ($labels[$slot] ?? staff_slot_default_label($slot)),
                'with_reports' => false,
            ]);
        }
    }
    $tones = [
        ['binder-tab-in-person', 'in-person'],
        ['binder-tab-online', 'online'],
        ['binder-tab-offline', 'offline'],
        ['binder-tab-new', 'new'],
        ['binder-tab-archive', 'archive'],
    ];
    foreach (staff_hours_list_doctors($pdo) as $idx => $doc) {
        if (!is_array($doc)) {
            continue;
        }
        $uid = (string) ($doc['id'] ?? '');
        if ($uid === '') {
            continue;
        }
        try {
            $tone = $tones[$idx % count($tones)];
            $name = trim((string) ($doc['name'] ?? ''));
            $people[] = staff_hours_build_block($pdo, $doc, [
                'kind' => 'doctor',
                'slot' => null,
                'key' => 'd' . substr(md5($uid), 0, 10),
                'tab_id' => 'doc-' . $uid,
                'tab_class' => $tone[0],
                'tab_tone' => $tone[1],
                'label' => $name !== '' ? $name : staff_actor_label($doc),
                'with_reports' => false,
            ]);
        } catch (Throwable $ignored) {
            continue;
        }
    }

    return $people;
}

/** بلوک ساعت کاری یک نفر (صفحه «ساعت کاری من») */
function staff_hours_block_for_user(PDO $pdo, array $user): array
{
    try {
        staff_close_stale_shifts($pdo);
    } catch (Throwable $ignored) {
    }
    $uid = (string) ($user['id'] ?? '');
    $role = (string) ($user['role'] ?? '');
    $name = trim((string) ($user['name'] ?? ''));
    $isDoctor = $role === 'DOCTOR';

    return staff_hours_build_block($pdo, $user, [
        'kind' => $isDoctor ? 'doctor' : 'secretary',
        'slot' => null,
        'key' => 'self',
        'tab_id' => $isDoctor ? ('doc-' . $uid) : 'self',
        'tab_class' => $isDoctor ? 'binder-tab-in-person' : 'binder-tab-appts',
        'tab_tone' => $isDoctor ? 'in-person' : 'appts',
        'label' => $name !== '' ? $name : staff_actor_label($user),
        'with_reports' => !$isDoctor,
    ]);
}

/** تقویم شمسی: نیم‌سال، ماه، روزهای حضور */
function staff_hours_calendar(array $block): array
{
    $emptyCal = [
        'halves' => [],
        'default_half_id' => '',
        'default_month_id' => '',
        'default_day_id' => '',
        'today' => date('Y-m-d'),
    ];
    try {
    $today = date('Y-m-d');
    $current = jalali_current_month_meta();
    $currentJy = (int) ($current['year'] ?? 0);
    $currentJm = (int) ($current['month'] ?? 1);
    $present = [];
    $dayList = is_array($block['days'] ?? null) ? $block['days'] : [];
    foreach ($dayList as $day) {
        if (!is_array($day)) {
            continue;
        }
        $d = (string) ($day['date'] ?? '');
        if ($d !== '' && $d !== 'other') {
            $present[$d] = true;
        }
    }
    $reportList = is_array($block['reports'] ?? null) ? $block['reports'] : [];
    foreach ($reportList as $rep) {
        if (!is_array($rep)) {
            continue;
        }
        $d = (string) ($rep['report_date'] ?? '');
        if ($d !== '') {
            $present[$d] = true;
        }
    }

    $years = [$currentJy => true];
    foreach (array_keys($present) as $gdate) {
        $meta = jalali_month_meta_from_datetime($gdate . ' 12:00:00');
        if ($meta) {
            $years[(int) $meta['year']] = true;
        }
    }
    krsort($years);

    $rawKey = $block['key'] ?? $block['tab_id'] ?? $block['slot'] ?? '1';
    if (is_array($rawKey) || is_object($rawKey)) {
        $rawKey = '1';
    }
    $slot = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) $rawKey) ?: '1';
    $yearCount = count($years);
    $halves = [];
    foreach (array_keys($years) as $jy) {
        $jy = (int) $jy;
        if ($jy < 1) {
            continue;
        }
        foreach ([1, 2] as $half) {
            $from = $half === 1 ? 1 : 7;
            $to = $half === 1 ? 6 : 12;
            $months = [];
            $presentCount = 0;
            for ($jm = $from; $jm <= $to; $jm++) {
                $meta = jalali_month_meta_from_parts($jy, $jm);
                $len = jalali_month_length($jy, $jm);
                $days = [];
                for ($jd = 1; $jd <= $len; $jd++) {
                    $gdate = jalali_ymd($jy, $jm, $jd);
                    if (empty($present[$gdate])) {
                        continue;
                    }
                    $parts = jalali_day_parts($gdate . ' 12:00:00') ?? [];
                    $days[$gdate] = [
                        'id' => 's' . $slot . '-d-' . $gdate,
                        'date' => $gdate,
                        'day' => $jd,
                        'label' => (string) ($parts['label'] ?? to_fa_digits((string) $jd)),
                        'tab_label' => $gdate === $today ? 'امروز' : (string) ($parts['day_fa'] ?? to_fa_digits((string) $jd)),
                        'is_today' => $gdate === $today,
                    ];
                    $presentCount++;
                }
                $monthId = 's' . $slot . '-m-' . (string) ($meta['key'] ?? sprintf('%04d-%02d', $jy, $jm));
                if (!is_array($meta)) {
                    $meta = [];
                }
                $months[$monthId] = array_merge($meta, [
                    'id' => $monthId,
                    'length' => $len,
                    'days' => $days,
                    'present_count' => count($days),
                    'range_tab_label' => 'کل ' . (string) ($meta['tab_label'] ?? $meta['short'] ?? 'ماه'),
                ]);
            }
            $isCurrentYear = $jy === $currentJy;
            if (!$isCurrentYear && $presentCount === 0) {
                continue;
            }
            $halfId = 's' . $slot . '-y' . $jy . '-h' . $half;
            $base = $half === 1 ? 'نیم‌سال اول' : 'نیم‌سال دوم';
            $halves[$halfId] = [
                'id' => $halfId,
                'year' => $jy,
                'half' => $half,
                'label' => $yearCount > 1 ? ($base . ' ' . to_fa_digits((string) $jy)) : $base,
                'class' => $half === 1 ? 'binder-tab-online' : 'binder-tab-offline',
                'tone' => $half === 1 ? 'online' : 'offline',
                'months' => $months,
                'present_count' => $presentCount,
            ];
        }
    }

    $defaultHalf = $currentJm <= 6 ? 1 : 2;
    $defaultHalfId = 's' . $slot . '-y' . $currentJy . '-h' . $defaultHalf;
    if (!isset($halves[$defaultHalfId])) {
        $defaultHalfId = (string) (array_key_first($halves) ?? '');
    }
    $defaultMonthId = 's' . $slot . '-m-' . sprintf('%04d-%02d', $currentJy, $currentJm);
    $defaultDayId = isset($present[$today]) ? ('s' . $slot . '-d-' . $today) : '';

    return [
        'halves' => $halves,
        'default_half_id' => $defaultHalfId,
        'default_month_id' => $defaultMonthId,
        'default_day_id' => $defaultDayId,
        'today' => $today,
    ];
    } catch (Throwable $ignored) {
        return $emptyCal;
    }
}

function staff_hours_export_rows(PDO $pdo, ?string $who = null): array
{
    $people = staff_hours_collect($pdo);
    $out = [];
    foreach ($people as $block) {
        if (!is_array($block)) {
            continue;
        }
        $tabId = (string) ($block['tab_id'] ?? '');
        $slot = $block['slot'] ?? null;
        $user = is_array($block['user'] ?? null) ? $block['user'] : [];
        $userId = (string) ($user['id'] ?? '');
        if ($who !== null && $who !== '') {
            $match = $tabId === $who
                || $userId === $who
                || (($who === '1' || $who === '2') && (string) $slot === $who)
                || ($who === 'sec-1' && (int) $slot === 1)
                || ($who === 'sec-2' && (int) $slot === 2);
            if (!$match) {
                continue;
            }
        }
        $label = (string) ($block['label'] ?? staff_actor_label($user ?: null));
        $username = (string) ($user['username'] ?? '');
        $roleLabel = (($block['kind'] ?? '') === 'doctor') ? 'درمانگر' : 'منشی';
        $days = is_array($block['days'] ?? null) ? $block['days'] : [];
        ksort($days);
        foreach ($days as $day) {
            if (!is_array($day)) {
                continue;
            }
            $gdate = (string) ($day['date'] ?? '');
            $jalali = $gdate !== '' ? to_jalali_label($gdate) : '';
            $daySeconds = 0;
            $dayRegular = 0;
            $dayOvertime = 0;
            foreach (($day['items'] ?? []) as $i => $row) {
                if (!is_array($row)) {
                    continue;
                }
                $part = staff_shift_seconds_split($row);
                $sec = (int) $part['total'];
                $daySeconds += $sec;
                $dayRegular += (int) $part['regular'];
                $dayOvertime += (int) $part['overtime'];
                $out[] = [
                    'tab_id' => $tabId,
                    'kind' => (string) ($block['kind'] ?? 'secretary'),
                    'role' => $roleLabel,
                    'label' => $label,
                    'username' => $username,
                    'date' => $jalali,
                    'gregorian' => $gdate,
                    'entry' => $i + 1,
                    'started_at' => (string) ($row['started_at'] ?? ''),
                    'ended_at' => (string) ($row['ended_at'] ?? ''),
                    'duration' => staff_format_duration($sec),
                    'regular' => staff_format_duration((int) $part['regular']),
                    'overtime' => staff_format_duration((int) $part['overtime']),
                    'seconds' => $sec,
                    'reason' => staff_shift_reason_label($row['end_reason'] ?? null),
                    'day_total' => '',
                ];
            }
            if (($day['items'] ?? []) !== []) {
                $out[] = [
                    'tab_id' => $tabId,
                    'kind' => (string) ($block['kind'] ?? 'secretary'),
                    'role' => $roleLabel,
                    'label' => $label,
                    'username' => $username,
                    'date' => $jalali,
                    'gregorian' => $gdate,
                    'entry' => '',
                    'started_at' => '',
                    'ended_at' => '',
                    'duration' => staff_format_duration($daySeconds),
                    'regular' => staff_format_duration($dayRegular),
                    'overtime' => staff_format_duration($dayOvertime),
                    'seconds' => $daySeconds,
                    'reason' => 'جمع روز',
                    'day_total' => staff_format_duration($daySeconds),
                ];
            }
        }
    }
    return $out;
}

/** هر ورود جداگانه زیر همان روز شمسی */
function staff_shifts_grouped_by_day(array $rows): array
{
    $days = [];
    foreach (staff_hours_shift_rows($rows) as $row) {
        $dt = (string) ($row['started_at'] ?? '');
        $ts = strtotime($dt) ?: 0;
        $key = $ts ? date('Y-m-d', $ts) : 'other';
        $parts = $dt !== '' ? jalali_day_parts($dt) : null;
        if (!isset($days[$key])) {
            $days[$key] = [
                'date' => $key,
                'label' => (is_array($parts) && $parts)
                    ? ((string) ($parts['label'] ?? '') . ' ' . to_fa_digits((string) ($parts['year'] ?? '')))
                    : format_fa_datetime($dt),
                'items' => [],
            ];
        }
        $days[$key]['items'][] = $row;
    }
    foreach ($days as $k => $day) {
        $items = staff_hours_shift_rows($day['items'] ?? []);
        usort($items, static fn(array $a, array $b): int => strcmp((string) ($a['started_at'] ?? ''), (string) ($b['started_at'] ?? '')));
        $days[$k]['items'] = $items;
    }
    krsort($days);
    return $days;
}

function staff_today_action_draft(PDO $pdo, string $userId): string
{
    try {
        $stmt = $pdo->prepare("
          SELECT action, note, created_at
          FROM secretary_action_log
          WHERE user_id=? AND DATE(created_at)=CURDATE()
          ORDER BY created_at ASC
        ");
        $stmt->execute([$userId]);
        $rows = $stmt->fetchAll();
    } catch (Throwable $e) {
        return '';
    }
    if (!$rows) {
        return '';
    }
    $lines = [];
    foreach ($rows as $row) {
        $when = format_fa_datetime((string) $row['created_at']);
        $label = staff_action_label((string) $row['action']);
        $note = trim((string) ($row['note'] ?? ''));
        $lines[] = '• ' . $when . ' — ' . $label . ($note !== '' ? ' (' . $note . ')' : '');
    }
    return implode("\n", $lines);
}

function staff_get_day_report(PDO $pdo, string $userId, string $date): ?array
{
    ensure_secretary_day_reports($pdo);
    try {
        $stmt = $pdo->prepare('SELECT * FROM secretary_day_reports WHERE user_id=? AND report_date=? LIMIT 1');
        $stmt->execute([$userId, $date]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    } catch (Throwable $ignored) {
        return null;
    }
}

function staff_save_day_report(PDO $pdo, string $userId, string $date, string $body): void
{
    ensure_secretary_day_reports($pdo);
    $existing = staff_get_day_report($pdo, $userId, $date);
    if ($existing) {
        $pdo->prepare('UPDATE secretary_day_reports SET body=? WHERE id=?')
            ->execute([$body, $existing['id']]);
        return;
    }
    $pdo->prepare('INSERT INTO secretary_day_reports (id, user_id, report_date, body) VALUES (?,?,?,?)')
        ->execute([cuid(), $userId, $date, $body]);
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
    $root = realpath(staff_receipt_root()) ?: staff_receipt_root();
    $abs = $root . DIRECTORY_SEPARATOR . basename($relative);
    $real = realpath($abs);
    if ($real === false || !str_starts_with($real, $root)) {
        return $root . DIRECTORY_SEPARATOR . 'missing';
    }
    return $real;
}

function staff_receipt_user_can_view(PDO $pdo, array $user, string $paymentId): bool
{
    if ($paymentId === '') {
        return false;
    }
    $role = (string) ($user['role'] ?? '');
    if ($role === 'ADMIN' || $role === 'SECRETARY') {
        return true;
    }
    if ($role !== 'DOCTOR') {
        return false;
    }
    $dp = $pdo->prepare('SELECT id FROM doctor_profiles WHERE user_id=? LIMIT 1');
    $dp->execute([(string) $user['id']]);
    $doctorId = (string) ($dp->fetchColumn() ?: '');
    if ($doctorId === '') {
        return false;
    }
    $appt = $pdo->prepare("
      SELECT p.id
      FROM payments p
      JOIN appointments a ON a.id = p.appointment_id
      WHERE p.id=? AND a.doctor_id=?
      LIMIT 1
    ");
    $appt->execute([$paymentId, $doctorId]);
    if ($appt->fetch()) {
        return true;
    }
    $ws = $pdo->prepare("
      SELECT wp.id
      FROM workshop_payments wp
      JOIN workshop_enrollments e ON e.id = wp.enrollment_id
      JOIN workshops w ON w.id = e.workshop_id
      WHERE wp.id=? AND w.doctor_id=?
      LIMIT 1
    ");
    $ws->execute([$paymentId, $doctorId]);
    return (bool) $ws->fetch();
}

function staff_receipt_view_html(?string $paymentId, ?string $receiptPath, bool $canUpload = false, ?string $next = null): string
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
          <?= csrf_field() ?>
          <input type="hidden" name="payment_id" value="<?= e($paymentId) ?>">
          <?php if ($next): ?>
            <input type="hidden" name="next" value="<?= e($next) ?>">
          <?php endif; ?>
          <label class="btn btn-outline btn-sm staff-receipt-pick">
            <?= $receiptPath ? 'تعویض فیش' : 'بارگذاری فیش' ?>
            <input type="file" name="receipt" accept="image/jpeg,image/png,image/webp,application/pdf" required onchange="this.form.submit()">
          </label>
        </form>
        <?php
    }
    return trim((string) ob_get_clean());
}
