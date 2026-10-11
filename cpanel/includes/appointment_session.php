<?php
declare(strict_types=1);

/** نوع جلسه نوبت فردی + یادداشت مشترک خصوصی مراجع↔درمانگر */

function ensure_appointment_session_schema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    try {
        $has = $pdo->query("SHOW COLUMNS FROM appointments LIKE 'session_mode'")->fetch();
        if (!$has) {
            $pdo->exec("ALTER TABLE appointments ADD COLUMN session_mode ENUM('IN_PERSON','ONLINE') NOT NULL DEFAULT 'IN_PERSON' AFTER notes");
        }
    } catch (Throwable $ignored) {
    }

    try {
        $pdo->exec("
          CREATE TABLE IF NOT EXISTS appointment_shared_notes (
            id VARCHAR(32) PRIMARY KEY,
            appointment_id VARCHAR(32) NOT NULL,
            author_user_id VARCHAR(32) NOT NULL,
            author_role ENUM('DOCTOR','PATIENT','ADMIN') NOT NULL,
            body MEDIUMTEXT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_shared_note_app (appointment_id, created_at),
            INDEX idx_shared_note_author (author_user_id)
          ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (Throwable $ignored) {
    }

    ensure_appointment_approval_status($pdo);
    ensure_appointment_hold_columns($pdo);
    $ready = true;
}

/** زمان درخواست، تأیید پرداخت، و یادداشت منشی روی نوبت. */
function ensure_appointment_hold_columns(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    $adds = [
        'requested_at' => 'ALTER TABLE appointments ADD COLUMN requested_at DATETIME NULL',
        'reviewed_at' => 'ALTER TABLE appointments ADD COLUMN reviewed_at DATETIME NULL',
        'payment_confirmed_at' => 'ALTER TABLE appointments ADD COLUMN payment_confirmed_at DATETIME NULL',
        'staff_payment_note' => 'ALTER TABLE appointments ADD COLUMN staff_payment_note TEXT NULL',
    ];
    foreach ($adds as $name => $sql) {
        try {
            $has = $pdo->query('SHOW COLUMNS FROM appointments LIKE ' . $pdo->quote($name))->fetch();
            if (!$has) {
                $pdo->exec($sql);
            }
        } catch (Throwable $ignored) {
        }
    }
    $ready = true;
}

function appointment_hold_seconds(): int
{
    return 3600;
}

function appointment_is_open_hold(array $row): bool
{
    return in_array((string) ($row['status'] ?? ''), ['PENDING_APPROVAL', 'PENDING_PAYMENT'], true);
}

/**
 * رزرو موقت پرداخت‌نشده بعد از یک ساعت لغو می‌شود.
 * لغوهای قدیمی بدون اعلان پاک می‌شوند تا صفحه اول سیل نوتیفیکیشن نسازد.
 */
function appointment_expire_unpaid_holds(PDO $pdo): int
{
    ensure_appointment_hold_columns($pdo);
    $seconds = appointment_hold_seconds();
    try {
        $stmt = $pdo->query("
          SELECT a.id, a.patient_id, a.starts_at, a.requested_at, a.created_at
          FROM appointments a
          LEFT JOIN payments p ON p.appointment_id = a.id
          WHERE a.status IN ('PENDING_APPROVAL','PENDING_PAYMENT')
            AND (p.id IS NULL OR p.status <> 'PAID')
            AND COALESCE(a.requested_at, a.created_at) < DATE_SUB(NOW(), INTERVAL {$seconds} SECOND)
        ");
        $rows = $stmt ? $stmt->fetchAll() : [];
    } catch (Throwable $ignored) {
        return 0;
    }
    if (!$rows) {
        return 0;
    }

    $note = 'مهلت یک‌ساعته تمام شد و چون پرداخت تأیید نشده بود، این وقت آزاد شد.';
    $n = 0;
    $now = time();
    foreach ($rows as $row) {
        $id = (string) ($row['id'] ?? '');
        if ($id === '') {
            continue;
        }
        try {
            $pdo->prepare("UPDATE payments SET status='FAILED' WHERE appointment_id=? AND status='PENDING'")
                ->execute([$id]);
            $updated = $pdo->prepare("
              UPDATE appointments
              SET status='CANCELLED',
                  cancel_reason='unpaid_timeout',
                  cancellation_note=?,
                  cancelled_at=NOW()
              WHERE id=? AND status IN ('PENDING_APPROVAL','PENDING_PAYMENT')
            ");
            $updated->execute([$note, $id]);
            if ($updated->rowCount() < 1) {
                continue;
            }
            $n++;
            $requested = strtotime((string) ($row['requested_at'] ?: $row['created_at'] ?? '')) ?: 0;
            if ($requested > 0 && ($now - $requested) < 7200 && function_exists('notify_user')) {
                $when = function_exists('format_fa_datetime')
                    ? format_fa_datetime((string) ($row['starts_at'] ?? ''))
                    : (string) ($row['starts_at'] ?? '');
                notify_user(
                    $pdo,
                    (string) ($row['patient_id'] ?? ''),
                    'رزرو موقت لغو شد',
                    "وقت {$when} چون تا یک ساعت پرداختش تأیید نشد، آزاد گردید. در صورت نیاز دوباره درخواست دهید.",
                    '/dashboard/appointments',
                    'appointment'
                );
            }
        } catch (Throwable $ignored) {
        }
    }

    return $n;
}

/** وضعیت PENDING_APPROVAL را به enum نوبت اضافه می‌کند (رزرو سایت تا تأیید منشی). */
function ensure_appointment_approval_status(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    try {
        $col = $pdo->query("SHOW COLUMNS FROM appointments LIKE 'status'")->fetch(PDO::FETCH_ASSOC);
        $type = strtoupper((string) ($col['Type'] ?? ''));
        if ($type !== '' && !str_contains($type, 'PENDING_APPROVAL')) {
            $pdo->exec("
              ALTER TABLE appointments
              MODIFY COLUMN status ENUM('PENDING_PAYMENT','CONFIRMED','CANCELLED','COMPLETED','PENDING_APPROVAL')
              NOT NULL DEFAULT 'PENDING_PAYMENT'
            ");
        }
        $ready = true;
    } catch (Throwable $ignored) {
    }
}

function appointment_normalize_session_mode(?string $mode): string
{
    $mode = strtoupper(trim((string) $mode));

    return $mode === 'ONLINE' ? 'ONLINE' : 'IN_PERSON';
}

function appointment_session_mode_label(?string $mode): string
{
    return appointment_normalize_session_mode($mode) === 'ONLINE' ? 'آنلاین' : 'حضوری';
}

function appointment_session_mode_badge_html(?string $mode): string
{
    $mode = appointment_normalize_session_mode($mode);
    $cls = $mode === 'ONLINE' ? 'appt-mode-badge appt-mode-badge--online' : 'appt-mode-badge appt-mode-badge--in-person';
    $label = appointment_session_mode_label($mode);

    return '<span class="' . e($cls) . '">' . e($label) . '</span>';
}

function appointment_is_upcoming(array $row, ?int $now = null): bool
{
    $now = $now ?? time();
    $status = (string) ($row['status'] ?? '');
    if (in_array($status, ['CANCELLED', 'COMPLETED'], true)) {
        return false;
    }
    $start = strtotime((string) ($row['starts_at'] ?? '')) ?: 0;

    return $start >= $now;
}

function appointment_is_online_mode(array $row): bool
{
    return appointment_normalize_session_mode((string) ($row['session_mode'] ?? '')) === 'ONLINE';
}

/**
 * تماس مانا روی کارت نوبت آنلاین دیده شود (هنوز لغو/تمام نشده).
 */
function appointment_mana_call_should_show(array $row, ?int $now = null): bool
{
    $now = $now ?? time();
    if (!appointment_is_online_mode($row)) {
        return false;
    }
    $status = (string) ($row['status'] ?? '');
    if (in_array($status, ['CANCELLED', 'COMPLETED'], true)) {
        return false;
    }
    $start = strtotime((string) ($row['starts_at'] ?? '')) ?: 0;
    if ($start <= 0) {
        return false;
    }
    $end = strtotime((string) ($row['ends_at'] ?? '')) ?: ($start + 3600);

    // از رزرو تا پایان جلسه دکمه دیده شود؛ فعال‌شدن جداست
    return $now <= $end;
}

/** از ۱۵ دقیقه قبل از شروع تا پایان جلسه دکمه فعال است. */
function appointment_mana_call_is_active(array $row, ?int $now = null): bool
{
    $now = $now ?? time();
    if (!appointment_mana_call_should_show($row, $now)) {
        return false;
    }
    $start = strtotime((string) ($row['starts_at'] ?? '')) ?: 0;
    $end = strtotime((string) ($row['ends_at'] ?? '')) ?: ($start + 3600);

    return $now >= ($start - 15 * 60) && $now <= $end;
}

/**
 * بازگرداندن نوبت‌های آینده‌ای که به‌خاطر مهلت پرداخت خودکار لغو شده‌اند
 * (پرداخت هنوز PENDING است؛ لغو دستی پرداخت را FAILED می‌کند).
 */
function appointment_restore_auto_cancelled_unpaid(PDO $pdo): int
{
    $n = 0;
    try {
        $n += (int) $pdo->exec("
          UPDATE appointments a
          INNER JOIN payments p ON p.appointment_id = a.id
          SET a.status = 'PENDING_PAYMENT'
          WHERE a.status = 'CANCELLED'
            AND a.starts_at >= NOW()
            AND (a.cancel_reason IS NULL OR TRIM(a.cancel_reason) = '')
            AND p.status = 'PENDING'
        ");
        $n += (int) $pdo->exec("
          UPDATE appointments a
          LEFT JOIN payments p ON p.appointment_id = a.id
          SET a.status = 'PENDING_PAYMENT'
          WHERE a.status = 'CANCELLED'
            AND a.starts_at >= NOW()
            AND (a.cancel_reason IS NULL OR TRIM(a.cancel_reason) = '')
            AND p.id IS NULL
        ");
    } catch (Throwable $ignored) {
        return 0;
    }

    return $n;
}

/** انتخاب نوع جلسه در فرم رزرو */
/** نوبت قطعی پنل درمانگر و مراجع: فقط بعد از ثبت منشی یا تأیید پرداخت. */
function appointment_is_panel_fixed(array $row): bool
{
    return in_array((string) ($row['status'] ?? ''), ['CONFIRMED', 'COMPLETED'], true);
}

function appointment_room_no_sql(string $alias = 'a'): string
{
    if (!preg_match('/^[a-z_]+$/', $alias)) {
        $alias = 'a';
    }

    return '(SELECT rb.room_no FROM clinic_room_bookings rb WHERE rb.appointment_id = ' . $alias . '.id ORDER BY rb.starts_at DESC LIMIT 1) AS room_no';
}

function appointment_ensure_room_schema(PDO $pdo): void
{
    if (!function_exists('clinic_rooms_ready') && is_file(__DIR__ . '/clinic_rooms.php')) {
        require_once __DIR__ . '/clinic_rooms.php';
    }
    if (function_exists('clinic_rooms_ready')) {
        clinic_rooms_ready($pdo);
    }
}

function appointment_room_badge_html(array $row): string
{
    $room = (int) ($row['room_no'] ?? 0);
    if ($room < 1) {
        return '';
    }
    if (!function_exists('clinic_room_label') && is_file(__DIR__ . '/clinic_rooms.php')) {
        require_once __DIR__ . '/clinic_rooms.php';
    }
    $label = function_exists('clinic_room_label') ? clinic_room_label($room) : ('اتاق ' . to_fa_digits((string) $room));

    return '<span class="badge">' . e($label) . '</span>';
}

function appointment_room_pick_html(string $name = 'room_no', string $id = 'room_no', string $selected = ''): string
{
    if (!function_exists('clinic_rooms_numbers') && is_file(__DIR__ . '/clinic_rooms.php')) {
        require_once __DIR__ . '/clinic_rooms.php';
    }
    $numbers = function_exists('clinic_rooms_numbers') ? clinic_rooms_numbers() : [1, 2, 3];
    $selected = trim($selected);
    ob_start();
    ?>
    <div class="appt-room-pick" data-appt-room-pick>
      <label class="label" for="<?= e($id) ?>">اتاق جلسه</label>
      <select class="input" name="<?= e($name) ?>" id="<?= e($id) ?>">
        <option value="">— انتخاب اتاق —</option>
        <?php foreach ($numbers as $n): ?>
          <?php $n = (int) $n; ?>
          <option value="<?= $n ?>"<?= $selected === (string) $n ? ' selected' : '' ?>><?= e(function_exists('clinic_room_label') ? clinic_room_label($n) : ('اتاق ' . $n)) ?></option>
        <?php endforeach; ?>
      </select>
      <p class="muted appt-room-hint" id="<?= e($id) ?>-hint" style="margin:.35rem 0 0;font-size:.8rem">برای جلسه حضوری انتخاب اتاق لازم است. اگر جلسه آنلاین باشد، اتاق اجباری نیست.</p>
    </div>
    <?php
    return (string) ob_get_clean();
}

function appointment_parse_room_choice(string $raw): int
{
    $raw = trim($raw);

    return $raw === '' ? 0 : (int) $raw;
}

/** اتاق حضوری اجباری است؛ آنلاین می‌تواند خالی بماند. در صورت تداخل خطا می‌دهد. */
function appointment_assert_room_choice(PDO $pdo, array $appointment, int $room): int
{
    appointment_ensure_room_schema($pdo);
    if (!function_exists('clinic_rooms_numbers')) {
        require_once __DIR__ . '/clinic_rooms.php';
    }
    $online = appointment_is_online_mode($appointment);
    if ($room === 0) {
        if ($online) {
            return 0;
        }
        throw new RuntimeException('برای جلسه حضوری اتاق را انتخاب کنید.');
    }
    if (!in_array($room, clinic_rooms_numbers(), true)) {
        throw new RuntimeException('اتاق نامعتبر است.');
    }
    $starts = (string) ($appointment['starts_at'] ?? '');
    $ends = (string) ($appointment['ends_at'] ?? '');
    $apptId = trim((string) ($appointment['id'] ?? ''));
    $except = $apptId !== '' && function_exists('clinic_rooms_existing_id')
        ? clinic_rooms_existing_id($pdo, $apptId, null)
        : null;
    $clash = clinic_rooms_find_clash($pdo, $room, $starts, $ends, $except);
    if ($clash) {
        throw new RuntimeException(clinic_room_label($room) . ' در این ساعت پر است.');
    }

    return $room;
}

function appointment_save_room(PDO $pdo, array $actor, array $appointment, int $room): void
{
    if ($room < 1) {
        return;
    }
    appointment_ensure_room_schema($pdo);
    $actorId = (string) ($actor['id'] ?? '');
    if ($actorId === '') {
        throw new RuntimeException('حساب منشی برای ثبت اتاق شناخته نشد.');
    }
    $apptId = (string) ($appointment['id'] ?? '');
    if ($apptId === '') {
        throw new RuntimeException('نوبت برای ثبت اتاق مشخص نیست.');
    }
    $starts = (string) ($appointment['starts_at'] ?? '');
    $ends = (string) ($appointment['ends_at'] ?? '');
    $patientId = trim((string) ($appointment['patient_id'] ?? ''));
    $doctorId = trim((string) ($appointment['doctor_id'] ?? ''));
    $existing = clinic_rooms_existing_id($pdo, $apptId, null);
    if ($existing) {
        $pdo->prepare("
          UPDATE clinic_room_bookings
          SET room_no=?, kind='APPOINTMENT', starts_at=?, ends_at=?, patient_id=?, doctor_id=?, booked_by_user_id=?
          WHERE id=?
        ")->execute([
            $room,
            $starts,
            $ends,
            $patientId !== '' ? $patientId : null,
            $doctorId !== '' ? $doctorId : null,
            $actorId,
            $existing,
        ]);

        return;
    }
    $pdo->prepare("
      INSERT INTO clinic_room_bookings
        (id, room_no, kind, appointment_id, patient_id, doctor_id, starts_at, ends_at, booked_by_user_id)
      VALUES (?,?,?,?,?,?,?,?,?)
    ")->execute([
        cuid(),
        $room,
        'APPOINTMENT',
        $apptId,
        $patientId !== '' ? $patientId : null,
        $doctorId !== '' ? $doctorId : null,
        $starts,
        $ends,
        $actorId,
    ]);
}

/**
 * جابه‌جایی ساعت و دقیقه یک نوبت. اگر اتاق به همین نوبت وصل باشد، بازه اتاق هم جابه‌جا می‌شود.
 *
 * @return array{patient_name:string,starts_at:string,ends_at:string,unchanged:bool}
 */
function appointment_reschedule(PDO $pdo, array $actor, string $appointmentId, int $hour, int $minute): array
{
    if (!function_exists('appointment_booking_hours')) {
        require_once __DIR__ . '/availability.php';
    }
    if (!in_array($hour, appointment_booking_hours(), true) || $minute < 0 || $minute > 55 || $minute % 5 !== 0) {
        throw new RuntimeException('ساعت یا دقیقه نامعتبر است.');
    }
    $stmt = $pdo->prepare("
      SELECT a.*, u.name AS patient_name, dp.user_id AS doctor_user_id
      FROM appointments a
      JOIN users u ON u.id = a.patient_id
      JOIN doctor_profiles dp ON dp.id = a.doctor_id
      WHERE a.id = ?
      LIMIT 1
    ");
    $stmt->execute([$appointmentId]);
    $row = $stmt->fetch();
    if (!$row) {
        throw new RuntimeException('نوبت یافت نشد.');
    }
    $status = (string) ($row['status'] ?? '');
    if (in_array($status, ['CANCELLED', 'COMPLETED'], true)) {
        throw new RuntimeException('این نوبت را نمی‌توان جابه‌جا کرد.');
    }
    $oldTs = strtotime(str_replace('T', ' ', (string) ($row['starts_at'] ?? ''))) ?: 0;
    if ($oldTs <= 0) {
        throw new RuntimeException('زمان فعلی نوبت نامعتبر است.');
    }
    $date = date('Y-m-d', $oldTs);
    $startsAt = $date . ' ' . sprintf('%02d:%02d:00', $hour, $minute);
    $endsAt = date('Y-m-d H:i:s', (strtotime($startsAt) ?: 0) + (appointment_slot_minutes() * 60));
    $patientName = (string) ($row['patient_name'] ?? 'مراجعه‌کننده');
    if ($startsAt === date('Y-m-d H:i:s', $oldTs)) {
        return [
            'patient_name' => $patientName,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'unchanged' => true,
        ];
    }
    if (strtotime($startsAt) <= time()) {
        throw new RuntimeException('زمان جدید گذشته است.');
    }
    $doctorId = (string) ($row['doctor_id'] ?? '');
    if (appointment_has_overlap($pdo, $doctorId, $startsAt, $endsAt, $appointmentId)) {
        throw new RuntimeException('این ساعت برای این درمانگر پر است.');
    }

    appointment_ensure_room_schema($pdo);
    if (!function_exists('clinic_rooms_find_clash')) {
        require_once __DIR__ . '/clinic_rooms.php';
    }
    $roomStmt = $pdo->prepare('SELECT id, room_no FROM clinic_room_bookings WHERE appointment_id=?');
    $roomStmt->execute([$appointmentId]);
    $rooms = $roomStmt->fetchAll() ?: [];
    foreach ($rooms as $roomRow) {
        $roomNo = (int) ($roomRow['room_no'] ?? 0);
        $roomId = (string) ($roomRow['id'] ?? '');
        if ($roomNo < 1 || $roomId === '') {
            continue;
        }
        $clash = clinic_rooms_find_clash($pdo, $roomNo, $startsAt, $endsAt, $roomId);
        if ($clash) {
            $label = function_exists('clinic_room_label') ? clinic_room_label($roomNo) : ('اتاق ' . $roomNo);
            throw new RuntimeException($label . ' در زمان جدید پر است.');
        }
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE appointments SET starts_at=?, ends_at=? WHERE id=?')->execute([$startsAt, $endsAt, $appointmentId]);
        if ($rooms !== []) {
            $pdo->prepare('UPDATE clinic_room_bookings SET starts_at=?, ends_at=? WHERE appointment_id=?')
                ->execute([$startsAt, $endsAt, $appointmentId]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    $when = format_fa_datetime($startsAt);
    $actorId = (string) ($actor['id'] ?? '');
    if (!function_exists('notify_user')) {
        require_once __DIR__ . '/notifications.php';
    }
    $patientId = (string) ($row['patient_id'] ?? '');
    if ($patientId !== '') {
        notify_user(
            $pdo,
            $patientId,
            'ساعت نوبت تغییر کرد',
            "ساعت نوبت شما به {$when} تغییر کرد.",
            '/dashboard/appointments',
            'appointment',
            $actorId !== '' ? $actorId : null
        );
    }
    if (!function_exists('notify_doctor_profile')) {
        require_once __DIR__ . '/notifications.php';
    }
    if (function_exists('notify_doctor_profile') && $doctorId !== '') {
        notify_doctor_profile(
            $pdo,
            $doctorId,
            'ساعت نوبت تغییر کرد',
            "ساعت نوبت «{$patientName}» به {$when} تغییر کرد.",
            '/doctor/appointments',
            'appointment'
        );
    }

    return [
        'patient_name' => $patientName,
        'starts_at' => $startsAt,
        'ends_at' => $endsAt,
        'unchanged' => false,
    ];
}

function appointment_time_edit_form_html(array $row, string $next): string
{
    $status = (string) ($row['status'] ?? '');
    $appointmentId = trim((string) ($row['id'] ?? ''));
    if ($appointmentId === '' || in_array($status, ['CANCELLED', 'COMPLETED'], true)) {
        return '';
    }
    $ts = strtotime(str_replace('T', ' ', (string) ($row['starts_at'] ?? ''))) ?: 0;
    if ($ts <= 0) {
        return '';
    }
    $hourNow = (int) date('G', $ts);
    $minuteNow = (int) date('i', $ts);
    $minuteNow -= $minuteNow % 5;
    if (!function_exists('appointment_booking_hours')) {
        require_once __DIR__ . '/availability.php';
    }
    $hours = appointment_booking_hours();
    ob_start();
    ?>
    <form method="post" action="<?= e(url('/secretary/appointments')) ?>" style="display:flex;flex-wrap:wrap;gap:.4rem;align-items:end;margin:0 0 .6rem">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="set_time">
      <input type="hidden" name="appointment_id" value="<?= e($appointmentId) ?>">
      <input type="hidden" name="next" value="<?= e($next) ?>">
      <label style="margin:0">
        <span class="label" style="font-size:.75rem">تغییر ساعت</span>
        <select class="input" name="hour" style="width:auto;min-width:6.5rem;padding:.35rem .5rem">
          <?php foreach ($hours as $hour): ?>
            <?php $hour = (int) $hour; ?>
            <option value="<?= $hour ?>"<?= $hour === $hourNow ? ' selected' : '' ?>><?= e(sprintf('%02d', $hour)) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label style="margin:0">
        <span class="label" style="font-size:.75rem">دقیقه</span>
        <select class="input" name="minute" style="width:auto;min-width:4.5rem;padding:.35rem .5rem">
          <?php for ($minute = 0; $minute < 60; $minute += 5): ?>
            <option value="<?= $minute ?>"<?= $minute === $minuteNow ? ' selected' : '' ?>><?= e(sprintf('%02d', $minute)) ?></option>
          <?php endfor; ?>
        </select>
      </label>
      <button type="submit" class="btn btn-outline btn-sm">ذخیره ساعت</button>
    </form>
    <?php
    return (string) ob_get_clean();
}

function appointment_session_mode_pick_html(string $name = 'session_mode', string $selected = 'IN_PERSON', string $idPrefix = 'sm'): string
{
    $selected = appointment_normalize_session_mode($selected);
    ob_start();
    ?>
    <div class="session-mode-pick" data-session-mode-pick>
      <span class="label" style="display:block;margin-bottom:.4rem">نوع جلسه</span>
      <div class="session-mode-pick-opts" role="radiogroup" aria-label="نوع جلسه">
        <label class="session-mode-opt">
          <input type="radio" name="<?= e($name) ?>" id="<?= e($idPrefix) ?>-in-person" value="IN_PERSON"<?= $selected === 'IN_PERSON' ? ' checked' : '' ?>>
          <span>حضوری</span>
        </label>
        <label class="session-mode-opt">
          <input type="radio" name="<?= e($name) ?>" id="<?= e($idPrefix) ?>-online" value="ONLINE"<?= $selected === 'ONLINE' ? ' checked' : '' ?>>
          <span>آنلاین</span>
        </label>
      </div>
    </div>
    <?php
    return (string) ob_get_clean();
}

/**
 * @return array{ok:bool,row?:array,error?:string}
 */
function appointment_shared_note_load_context(PDO $pdo, array $user, string $appointmentId): array
{
    ensure_appointment_session_schema($pdo);
    if ($appointmentId === '') {
        return ['ok' => false, 'error' => 'نوبت مشخص نیست.'];
    }

    $stmt = $pdo->prepare("
      SELECT a.*,
             u.name AS patient_name,
             du.name AS doctor_name,
             dp.user_id AS doctor_user_id,
             dp.id AS doctor_profile_id
      FROM appointments a
      JOIN users u ON u.id = a.patient_id
      JOIN doctor_profiles dp ON dp.id = a.doctor_id
      JOIN users du ON du.id = dp.user_id
      WHERE a.id = ?
      LIMIT 1
    ");
    $stmt->execute([$appointmentId]);
    $row = $stmt->fetch();
    if (!$row) {
        return ['ok' => false, 'error' => 'نوبت یافت نشد.'];
    }

    $role = (string) ($user['role'] ?? '');
    $uid = (string) ($user['id'] ?? '');
    $allowed = false;
    if ($role === 'PATIENT' && $uid === (string) ($row['patient_id'] ?? '')) {
        $allowed = true;
    } elseif ($role === 'DOCTOR' && $uid === (string) ($row['doctor_user_id'] ?? '')) {
        $allowed = true;
    } elseif ($role === 'ADMIN') {
        $allowed = true;
    }

    if (!$allowed) {
        return ['ok' => false, 'error' => 'دسترسی به این یادداشت ندارید.'];
    }

    return ['ok' => true, 'row' => $row];
}

function appointment_shared_notes_list(PDO $pdo, string $appointmentId): array
{
    ensure_appointment_session_schema($pdo);
    $stmt = $pdo->prepare("
      SELECT n.*, u.name AS author_name
      FROM appointment_shared_notes n
      LEFT JOIN users u ON u.id = n.author_user_id
      WHERE n.appointment_id = ?
      ORDER BY n.created_at ASC, n.id ASC
    ");
    $stmt->execute([$appointmentId]);

    return $stmt->fetchAll() ?: [];
}

function appointment_shared_note_add(PDO $pdo, array $user, string $appointmentId, string $body): bool
{
    $body = trim($body);
    if ($body === '') {
        return false;
    }
    $ctx = appointment_shared_note_load_context($pdo, $user, $appointmentId);
    if (empty($ctx['ok'])) {
        return false;
    }

    $role = (string) ($user['role'] ?? '');
    $authorRole = $role === 'ADMIN' ? 'ADMIN' : ($role === 'DOCTOR' ? 'DOCTOR' : 'PATIENT');
    $id = function_exists('cuid') ? cuid() : bin2hex(random_bytes(12));
    $pdo->prepare('INSERT INTO appointment_shared_notes (id, appointment_id, author_user_id, author_role, body) VALUES (?,?,?,?,?)')
        ->execute([$id, $appointmentId, (string) $user['id'], $authorRole, $body]);

    return true;
}

function appointment_shared_notes_count_map(PDO $pdo, array $appointmentIds): array
{
    ensure_appointment_session_schema($pdo);
    $ids = array_values(array_filter(array_map('strval', $appointmentIds)));
    if ($ids === []) {
        return [];
    }
    $in = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT appointment_id, COUNT(*) AS c FROM appointment_shared_notes WHERE appointment_id IN ($in) GROUP BY appointment_id");
    $stmt->execute($ids);
    $map = [];
    foreach ($stmt->fetchAll() ?: [] as $row) {
        $map[(string) $row['appointment_id']] = (int) $row['c'];
    }

    return $map;
}

function appointment_shared_note_role_label(string $role): string
{
    return match ($role) {
        'DOCTOR', 'ADMIN' => 'درمانگر',
        'PATIENT' => 'مراجعه‌کننده',
        default => 'کاربر',
    };
}

/**
 * رندر صفحه/پنل یادداشت مشترک
 *
 * @param list<array<string,mixed>> $notes
 */
function appointment_shared_notes_panel_html(
    array $appointment,
    array $notes,
    string $postUrl,
    string $backUrl,
    string $viewerRole
): string {
    $when = function_exists('format_fa_datetime')
        ? format_fa_datetime((string) ($appointment['starts_at'] ?? ''))
        : (string) ($appointment['starts_at'] ?? '');
    $peer = $viewerRole === 'PATIENT'
        ? (string) ($appointment['doctor_name'] ?? 'درمانگر')
        : (string) ($appointment['patient_name'] ?? 'مراجعه‌کننده');
    ob_start();
    ?>
    <div class="stack shared-note-page">
      <div class="row-between" style="gap:.75rem;flex-wrap:wrap;align-items:flex-start">
        <div>
          <h1 style="margin:0">یادداشت مشترک جلسه</h1>
          <p class="muted" style="margin:.35rem 0 0;font-size:.9rem;line-height:1.7">
            خصوصی بین شما و <?= e($peer) ?> · <?= e($when) ?>
            · <?= appointment_session_mode_badge_html((string) ($appointment['session_mode'] ?? '')) ?>
          </p>
        </div>
        <a class="btn btn-outline btn-sm" href="<?= e(url($backUrl)) ?>">بازگشت</a>
      </div>

      <div class="panel stack shared-note-thread">
        <?php if (!$notes): ?>
          <p class="muted" style="margin:0">هنوز یادداشتی برای این جلسه نوشته نشده. اولین پیام را بنویسید.</p>
        <?php else: ?>
          <ul class="shared-note-feed">
            <?php foreach ($notes as $n): ?>
              <?php
                $ar = (string) ($n['author_role'] ?? '');
                $mine = ($viewerRole === 'PATIENT' && $ar === 'PATIENT')
                    || ($viewerRole !== 'PATIENT' && in_array($ar, ['DOCTOR', 'ADMIN'], true));
              ?>
              <li class="shared-note-item<?= $mine ? ' is-mine' : ' is-peer' ?>">
                <div class="shared-note-meta muted">
                  <?= e(appointment_shared_note_role_label($ar)) ?>
                  <?php if (!empty($n['author_name'])): ?>
                    · <?= e((string) $n['author_name']) ?>
                  <?php endif; ?>
                  · <?= e(format_fa_datetime((string) ($n['created_at'] ?? ''))) ?>
                </div>
                <div class="shared-note-body"><?= nl2br(e((string) ($n['body'] ?? ''))) ?></div>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>

      <form class="panel form-stack" method="post" action="<?= e(url($postUrl)) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="appointment_id" value="<?= e((string) ($appointment['id'] ?? '')) ?>">
        <input type="hidden" name="next" value="<?= e($backUrl) ?>">
        <label class="label" for="shared-note-body">یادداشت جدید برای این جلسه</label>
        <textarea class="input" id="shared-note-body" name="body" rows="4" required placeholder="فقط شما و طرف مقابل این متن را می‌بینید…"></textarea>
        <button class="btn btn-primary" type="submit">ثبت یادداشت</button>
      </form>
    </div>
    <?php
    return (string) ob_get_clean();
}
