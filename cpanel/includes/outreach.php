<?php
declare(strict_types=1);

/**
 * فهرست مراجعه‌کنندگان قدیمی و صف پیامک.
 * ارسال واقعی خاموش است تا پنل پیامک وصل شود؛ فقط sms_panel_enabled را true کنید
 * و در sms_dispatch_pending درگاه را صدا بزنید.
 */

function sms_panel_enabled(): bool
{
    return false;
}

/** همین دو حساب ادمین منو و ابزار پیامک را مثل هم می‌بینند. */
function sms_operator_usernames(): array
{
    return ['admin', 'eshahabian'];
}

function sms_operator_allowed(?array $user): bool
{
    if (!$user) {
        return false;
    }
    $name = strtolower(trim((string) ($user['username'] ?? '')));

    return in_array($name, sms_operator_usernames(), true);
}

function ensure_outreach_schema(PDO $pdo): void
{
    static $ready = false;
    if ($ready || $pdo->inTransaction()) {
        return;
    }
    $pdo->exec("
      CREATE TABLE IF NOT EXISTS outreach_contacts (
        id VARCHAR(32) PRIMARY KEY,
        name VARCHAR(191) NOT NULL,
        phone VARCHAR(32) NOT NULL,
        note VARCHAR(255) NULL,
        linked_user_id VARCHAR(32) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_outreach_phone (phone)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $pdo->exec("
      CREATE TABLE IF NOT EXISTS sms_outbox (
        id VARCHAR(32) PRIMARY KEY,
        phone VARCHAR(32) NOT NULL,
        body VARCHAR(500) NOT NULL,
        kind VARCHAR(32) NOT NULL DEFAULT 'manual',
        status VARCHAR(16) NOT NULL DEFAULT 'PENDING',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_sms_status (status, created_at)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $pdo->exec("
      CREATE TABLE IF NOT EXISTS patient_quiet_notices (
        patient_id VARCHAR(32) PRIMARY KEY,
        notified_at DATETIME NOT NULL
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    try {
        $has = $pdo->query("SHOW COLUMNS FROM users LIKE 'last_login_at'")->fetch();
        if (!$has) {
            try {
                $pdo->exec('ALTER TABLE users ADD COLUMN last_login_at DATETIME NULL');
            } catch (Throwable $ignored) {
            }
        }
    } catch (Throwable $ignored) {
    }
    $ready = true;
}

function user_touch_last_login(PDO $pdo, string $userId): void
{
    if ($userId === '') {
        return;
    }
    try {
        ensure_outreach_schema($pdo);
        $pdo->prepare('UPDATE users SET last_login_at=NOW() WHERE id=?')->execute([$userId]);
    } catch (Throwable $ignored) {
    }
}

function outreach_link_user_by_phone(PDO $pdo, string $phone, string $userId): void
{
    $phone = normalize_phone($phone);
    if ($phone === '' || $userId === '') {
        return;
    }
    try {
        ensure_outreach_schema($pdo);
        $pdo->prepare('UPDATE outreach_contacts SET linked_user_id=? WHERE phone=?')->execute([$userId, $phone]);
    } catch (Throwable $ignored) {
    }
}

function outreach_save_contact(PDO $pdo, string $name, string $phone, string $note = ''): bool
{
    ensure_outreach_schema($pdo);
    $name = trim($name);
    $phone = normalize_phone($phone);
    $note = mb_substr(trim($note), 0, 255);
    if ($name === '' || !is_valid_phone($phone)) {
        return false;
    }
    $linked = null;
    try {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE phone=? AND role='PATIENT' ORDER BY created_at ASC LIMIT 1");
        $stmt->execute([$phone]);
        $found = $stmt->fetchColumn();
        if ($found) {
            $linked = (string) $found;
        }
    } catch (Throwable $ignored) {
    }
    $pdo->prepare('
      INSERT INTO outreach_contacts (id, name, phone, note, linked_user_id)
      VALUES (?,?,?,?,?)
      ON DUPLICATE KEY UPDATE name=VALUES(name), note=IF(VALUES(note)="", note, VALUES(note)), linked_user_id=COALESCE(VALUES(linked_user_id), linked_user_id)
    ')->execute([cuid(), $name, $phone, $note !== '' ? $note : null, $linked]);

    return true;
}

/** @return array{saved:int,skipped:int} */
function outreach_import_lines(PDO $pdo, string $raw): array
{
    $saved = 0;
    $skipped = 0;
    $lines = preg_split('/\R/u', $raw) ?: [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        $line = str_replace(['،', '؛', '|', "\t"], ',', $line);
        $phone = '';
        $name = '';
        if (preg_match('/^(.*?)[, ]+(\+?\d[\d\s\-]{7,20})$/u', $line, $m)) {
            $name = trim($m[1], " \t,");
            $phone = $m[2];
        }
        if (outreach_save_contact($pdo, $name, $phone)) {
            $saved++;
        } else {
            $skipped++;
        }
    }

    return ['saved' => $saved, 'skipped' => $skipped];
}

/** @return list<array<string,mixed>> */
function outreach_contacts(PDO $pdo): array
{
    ensure_outreach_schema($pdo);

    return $pdo->query('
      SELECT c.*, u.name AS linked_name
      FROM outreach_contacts c
      LEFT JOIN users u ON u.id = c.linked_user_id
      ORDER BY c.created_at DESC
    ')->fetchAll();
}

function sms_queue(PDO $pdo, string $phone, string $body, string $kind = 'manual'): void
{
    ensure_outreach_schema($pdo);
    $phone = normalize_phone($phone);
    $body = trim($body);
    if ($phone === '' || $body === '' || !is_valid_phone($phone)) {
        return;
    }
    if (mb_strlen($body) > 500) {
        $body = mb_substr($body, 0, 497) . '…';
    }
    $pdo->prepare('INSERT INTO sms_outbox (id, phone, body, kind, status) VALUES (?,?,?,?,\'PENDING\')')
        ->execute([cuid(), $phone, $body, $kind]);
}

function outreach_queue_message(PDO $pdo, string $body, string $kind = 'manual'): int
{
    $count = 0;
    foreach (outreach_contacts($pdo) as $row) {
        $phone = (string) ($row['phone'] ?? '');
        if ($phone === '') {
            continue;
        }
        sms_queue($pdo, $phone, $body, $kind);
        $count++;
    }

    return $count;
}

function outreach_queue_workshop(PDO $pdo, string $title, string $startsAt): int
{
    $when = function_exists('format_fa_datetime') ? format_fa_datetime($startsAt) : $startsAt;
    $body = 'مانا کلینیک: کارگاه «' . trim($title) . '» از ' . $when . ' برگزار می‌شود. برای ثبت‌نام به سایت کلینیک سر بزنید.';

    return outreach_queue_message($pdo, $body, 'workshop');
}

/** @return array<string,string> patient_id => last starts_at */
function patient_last_therapy_map(PDO $pdo): array
{
    $map = [];
    try {
        $rows = $pdo->query("
          SELECT patient_id, MAX(starts_at) AS last_at
          FROM appointments
          WHERE status IN ('CONFIRMED','COMPLETED')
          GROUP BY patient_id
        ")->fetchAll();
        foreach ($rows as $row) {
            $id = (string) ($row['patient_id'] ?? '');
            if ($id !== '') {
                $map[$id] = (string) ($row['last_at'] ?? '');
            }
        }
    } catch (Throwable $ignored) {
    }

    return $map;
}

function outreach_scan_quiet_patients(PDO $pdo): void
{
    if ($pdo->inTransaction()) {
        return;
    }
    ensure_outreach_schema($pdo);
    if (function_exists('ensure_clinic_maintenance')) {
        ensure_clinic_maintenance($pdo);
    }
    $today = date('Y-m-d');
    try {
        $done = $pdo->query("SELECT v FROM clinic_maintenance WHERE k='quiet_patient_scan'")->fetchColumn();
        if ((string) $done === $today) {
            return;
        }
    } catch (Throwable $ignored) {
        return;
    }

    $cutoff = time() - (30 * 86400);
    $notices = [];
    try {
        foreach ($pdo->query('SELECT patient_id, notified_at FROM patient_quiet_notices') as $row) {
            $notices[(string) $row['patient_id']] = (string) $row['notified_at'];
        }
    } catch (Throwable $ignored) {
    }

    $patients = [];
    try {
        $patients = $pdo->query("
          SELECT u.id, u.name, u.created_at, u.last_login_at, u.preferred_doctor_id,
            (SELECT MAX(a.starts_at) FROM appointments a
              WHERE a.patient_id = u.id AND a.status IN ('CONFIRMED','COMPLETED')) AS last_therapy,
            (SELECT dp.user_id FROM doctor_profiles dp WHERE dp.id = u.preferred_doctor_id LIMIT 1) AS doctor_user_id
          FROM users u
          WHERE u.role = 'PATIENT' AND u.is_disabled = 0
        ")->fetchAll();
    } catch (Throwable $ignored) {
        return;
    }

    $quiet = [];
    foreach ($patients as $row) {
        $stamps = [];
        foreach (['created_at', 'last_login_at', 'last_therapy'] as $key) {
            $ts = strtotime((string) ($row[$key] ?? ''));
            if ($ts) {
                $stamps[] = $ts;
            }
        }
        if (!$stamps) {
            continue;
        }
        $last = max($stamps);
        if ($last > $cutoff) {
            continue;
        }
        $id = (string) $row['id'];
        $told = strtotime($notices[$id] ?? '');
        if ($told && $told >= $last) {
            continue;
        }
        $doctorUserId = (string) ($row['doctor_user_id'] ?? '');
        if ($doctorUserId === '') {
            try {
                $stmt = $pdo->prepare("
                  SELECT dp.user_id
                  FROM appointments a
                  JOIN doctor_profiles dp ON dp.id = a.doctor_id
                  WHERE a.patient_id = ? AND a.status IN ('CONFIRMED','COMPLETED')
                  ORDER BY a.starts_at DESC
                  LIMIT 1
                ");
                $stmt->execute([$id]);
                $doctorUserId = (string) ($stmt->fetchColumn() ?: '');
            } catch (Throwable $ignored) {
            }
        }
        $quiet[] = [
            'id' => $id,
            'name' => (string) ($row['name'] ?? ''),
            'doctor_user_id' => $doctorUserId,
        ];
    }

    if ($quiet) {
        $staff = [];
        try {
            $staff = $pdo->query("SELECT id, name, phone, role FROM users WHERE role IN ('ADMIN','SECRETARY') AND is_disabled = 0")->fetchAll();
        } catch (Throwable $ignored) {
        }
        $byRecipient = [];
        foreach ($staff as $person) {
            $byRecipient[(string) $person['id']] = $person;
            $byRecipient[(string) $person['id']]['names'] = [];
        }
        foreach ($quiet as $row) {
            foreach ($staff as $person) {
                $byRecipient[(string) $person['id']]['names'][] = $row['name'];
            }
            $docId = $row['doctor_user_id'];
            if ($docId !== '' && !isset($byRecipient[$docId])) {
                try {
                    $stmt = $pdo->prepare('SELECT id, name, phone, role FROM users WHERE id=? LIMIT 1');
                    $stmt->execute([$docId]);
                    $doc = $stmt->fetch();
                    if ($doc) {
                        $byRecipient[$docId] = $doc;
                        $byRecipient[$docId]['names'] = [];
                    }
                } catch (Throwable $ignored) {
                }
            }
            if ($docId !== '' && isset($byRecipient[$docId]) && (string) ($byRecipient[$docId]['role'] ?? '') === 'DOCTOR') {
                $byRecipient[$docId]['names'][] = $row['name'];
            }
        }

        foreach ($byRecipient as $person) {
            $names = array_values(array_filter(array_unique($person['names'] ?? [])));
            if (!$names) {
                continue;
            }
            $shown = array_slice($names, 0, 12);
            $text = 'بیش از یک ماه از این مراجعه‌کنندگان خبری نیست: ' . implode('، ', $shown);
            if (count($names) > count($shown)) {
                $text .= ' و ' . (count($names) - count($shown)) . ' نفر دیگر';
            }
            $role = (string) ($person['role'] ?? '');
            $link = $role === 'DOCTOR' ? '/doctor/patients' : ($role === 'SECRETARY' ? '/secretary/appointments' : '/admin/users');
            if (function_exists('notify_user')) {
                notify_user($pdo, (string) $person['id'], 'مراجعه‌کننده بی‌خبر', $text, $link, 'other', null, 'personal');
            }
            sms_queue($pdo, (string) ($person['phone'] ?? ''), 'مانا کلینیک: ' . $text, 'quiet');
        }

        $mark = $pdo->prepare('
          INSERT INTO patient_quiet_notices (patient_id, notified_at) VALUES (?, NOW())
          ON DUPLICATE KEY UPDATE notified_at=NOW()
        ');
        foreach ($quiet as $row) {
            try {
                $mark->execute([$row['id']]);
            } catch (Throwable $ignored) {
            }
        }
    }

    try {
        $pdo->prepare("INSERT INTO clinic_maintenance (k, v) VALUES ('quiet_patient_scan', ?) ON DUPLICATE KEY UPDATE v=VALUES(v)")
            ->execute([$today]);
    } catch (Throwable $ignored) {
    }
}
