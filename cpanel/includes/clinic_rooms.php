<?php
declare(strict_types=1);

/** اتاق‌ها و مراجعه‌کنندگان قدیمی: ادمین‌ها، هر دو منشی، دکتر شیوا و دکتر عطیه گارسچی. */

function clinic_rooms_user_allowed(?array $user): bool
{
    if (!$user) {
        return false;
    }
    $role = (string) ($user['role'] ?? '');
    if ($role === 'ADMIN' || $role === 'SECRETARY') {
        return true;
    }
    if ($role !== 'DOCTOR') {
        return false;
    }
    if (!function_exists('doctor_has_shiva_access') && is_file(__DIR__ . '/doctor_profile_fields.php')) {
        require_once __DIR__ . '/doctor_profile_fields.php';
    }

    return function_exists('doctor_has_shiva_access') && doctor_has_shiva_access($user);
}

function clinic_desk_home(?array $user): string
{
    $role = (string) ($user['role'] ?? '');
    if ($role === 'SECRETARY') {
        return '/secretary/messages';
    }
    if ($role === 'DOCTOR') {
        return '/doctor/appointments';
    }

    return '/admin';
}

function clinic_desk_render_page(string $title, string $innerHtml): void
{
    $user = function_exists('current_user') ? current_user() : null;
    $role = (string) ($user['role'] ?? '');
    if ($role === 'SECRETARY') {
        require_once __DIR__ . '/secretary_panel.php';
        render_secretary_page($title, $innerHtml);

        return;
    }
    if ($role === 'DOCTOR' && !(function_exists('is_admin_user') && is_admin_user($user))) {
        require_once __DIR__ . '/doctor_panel.php';
        global $pdo;
        if (!isset($GLOBALS['doctor_ctx']) && $pdo instanceof PDO) {
            require_doctor_profile($pdo);
        }
        render_doctor_page($title, $innerHtml);

        return;
    }
    if (!function_exists('render_admin_page')) {
        require_once __DIR__ . '/admin_panel.php';
    }
    render_admin_page($title, $innerHtml);
}

function clinic_rooms_numbers(): array
{
    return [1, 2, 3];
}

function clinic_room_label(int $room): string
{
    return 'اتاق ' . to_fa_digits((string) $room);
}

function ensure_clinic_rooms_schema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    $pdo->exec("
      CREATE TABLE IF NOT EXISTS clinic_room_bookings (
        id VARCHAR(32) PRIMARY KEY,
        room_no TINYINT NOT NULL,
        kind ENUM('APPOINTMENT','WORKSHOP','BLOCK') NOT NULL,
        appointment_id VARCHAR(32) NULL,
        workshop_session_id VARCHAR(32) NULL,
        workshop_id VARCHAR(32) NULL,
        starts_at DATETIME NOT NULL,
        ends_at DATETIME NOT NULL,
        title VARCHAR(255) NULL,
        note VARCHAR(255) NULL,
        booked_by_user_id VARCHAR(32) NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_room_span (room_no, starts_at, ends_at),
        INDEX idx_room_appt (appointment_id),
        INDEX idx_room_wsess (workshop_session_id)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    foreach ([
        'patient_id' => 'patient_id VARCHAR(32) NULL AFTER workshop_id',
        'doctor_id' => 'doctor_id VARCHAR(32) NULL AFTER patient_id',
        'series_id' => 'series_id VARCHAR(32) NULL AFTER booked_by_user_id',
    ] as $column => $ddl) {
        try {
            $has = $pdo->query('SHOW COLUMNS FROM clinic_room_bookings LIKE ' . $pdo->quote($column))->fetch();
            if (!$has) {
                $pdo->exec('ALTER TABLE clinic_room_bookings ADD COLUMN ' . $ddl);
            }
        } catch (Throwable $ignored) {
        }
    }
    $ready = true;
}

/** «8» یا «۰۸:۳۰» یا «14:00» را به HH:MM تبدیل می‌کند. */
function clinic_rooms_parse_clock(string $raw): ?string
{
    $map = ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9', '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9'];
    $raw = strtr(trim($raw), $map);
    $raw = str_replace(['٫', '.', ' '], [':', ':', ''], $raw);
    if (preg_match('/^(\d{1,2})$/', $raw, $m)) {
        $hour = (int) $m[1];
        if ($hour >= 0 && $hour <= 23) {
            return sprintf('%02d:00', $hour);
        }
    }
    if (preg_match('/^(\d{1,2}):(\d{2})$/', $raw, $m)) {
        $hour = (int) $m[1];
        $minute = (int) $m[2];
        if ($hour >= 0 && $hour <= 23 && $minute >= 0 && $minute <= 59) {
            return sprintf('%02d:%02d', $hour, $minute);
        }
    }

    return null;
}

function clinic_rooms_require_user(): array
{
    $user = require_login(['ADMIN', 'SECRETARY', 'DOCTOR']);
    if (!clinic_rooms_user_allowed($user)) {
        flash_set('error', 'رزرو اتاق برای این حساب باز نیست.');
        redirect(clinic_desk_home($user));
    }

    return $user;
}

/**
 * تقویم یک ماه شمسی، از شنبه. روز انتخاب‌شده داخل همین ماه است.
 *
 * @param array<string, int> $countsByDay
 * @return array{title:string,weekday_names:list<string>,weeks:list<list<array<string,mixed>|null>>,prev_day:string,next_day:string}
 */
function clinic_rooms_month_board(string $selectedYmd, array $countsByDay = []): array
{
    $selectedYmd = clinic_rooms_parse_day($selectedYmd);
    $ts = strtotime($selectedYmd . ' 12:00:00') ?: time();
    [$jy, $jm, $jd] = gregorian_to_jalali((int) date('Y', $ts), (int) date('n', $ts), (int) date('j', $ts));
    $len = jalali_month_length($jy, $jm);
    $months = jalali_month_names();
    $weekNames = function_exists('doctor_weekdays_sat_first') ? array_values(doctor_weekdays_sat_first()) : ['شنبه', 'یکشنبه', 'دوشنبه', 'سه‌شنبه', 'چهارشنبه', 'پنجشنبه', 'جمعه'];
    $firstG = jalali_ymd($jy, $jm, 1);
    $phpW = (int) date('w', strtotime($firstG . ' 12:00:00') ?: time());
    $lead = ($phpW + 1) % 7;
    $today = date('Y-m-d');
    $cells = [];
    for ($i = 0; $i < $lead; $i++) {
        $cells[] = null;
    }
    for ($d = 1; $d <= $len; $d++) {
        $g = jalali_ymd($jy, $jm, $d);
        $cells[] = [
            'date' => $g,
            'jd_fa' => to_fa_digits((string) $d),
            'selected' => $g === $selectedYmd,
            'today' => $g === $today,
            'count' => (int) ($countsByDay[$g] ?? 0),
        ];
    }
    while (count($cells) % 7 !== 0) {
        $cells[] = null;
    }
    $prevJm = $jm - 1;
    $prevJy = $jy;
    if ($prevJm < 1) {
        $prevJm = 12;
        $prevJy--;
    }
    $nextJm = $jm + 1;
    $nextJy = $jy;
    if ($nextJm > 12) {
        $nextJm = 1;
        $nextJy++;
    }

    return [
        'title' => ($months[$jm] ?? '') . ' ' . to_fa_digits((string) $jy),
        'weekday_names' => $weekNames,
        'weeks' => array_chunk($cells, 7),
        'prev_day' => jalali_ymd($prevJy, $prevJm, min($jd, jalali_month_length($prevJy, $prevJm))),
        'next_day' => jalali_ymd($nextJy, $nextJm, min($jd, jalali_month_length($nextJy, $nextJm))),
        'range_start' => $firstG . ' 00:00:00',
        'range_end' => date('Y-m-d H:i:s', strtotime(jalali_ymd($jy, $jm, $len) . ' +1 day') ?: time()),
    ];
}

/** شنبه تا جمعهٔ هفته‌ای که این روز داخل آن است. */
function clinic_rooms_week_days(string $ymd): array
{
    $ts = strtotime($ymd . ' 12:00:00') ?: time();
    $phpW = (int) date('w', $ts);
    $sinceSat = ($phpW === 6) ? 0 : ($phpW + 1);
    $start = strtotime('-' . $sinceSat . ' days', $ts) ?: $ts;
    $names = function_exists('doctor_weekdays_sat_first') ? doctor_weekdays_sat_first() : [];
    $days = [];
    for ($i = 0; $i < 7; $i++) {
        $t = strtotime('+' . $i . ' days', $start) ?: $start;
        $day = date('Y-m-d', $t);
        $days[] = [
            'date' => $day,
            'weekday' => (string) ($names[$i] ?? ''),
            'label' => function_exists('jalali_day_parts') ? ((jalali_day_parts($day . ' 12:00:00')['label'] ?? $day)) : $day,
        ];
    }

    return $days;
}

function clinic_rooms_parse_day(?string $raw): string
{
    $raw = trim((string) $raw);
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
        $ts = strtotime($raw . ' 12:00:00');
        if ($ts) {
            return date('Y-m-d', $ts);
        }
    }

    return date('Y-m-d');
}

/**
 * @return list<array<string, mixed>>
 */
function clinic_rooms_ready(PDO $pdo): void
{
    ensure_clinic_rooms_schema($pdo);
    if (!function_exists('ensure_workshop_sessions_schema')) {
        require_once __DIR__ . '/workshop_sessions.php';
    }
    ensure_workshop_sessions_schema($pdo);
}

function clinic_rooms_between(PDO $pdo, string $from, string $to): array
{
    clinic_rooms_ready($pdo);
    $stmt = $pdo->prepare(clinic_rooms_select_sql() . "
      WHERE b.starts_at < ? AND b.ends_at > ?
      ORDER BY b.starts_at ASC, b.room_no ASC
    ");
    $stmt->execute([$to, $from]);

    return $stmt->fetchAll();
}

/**
 * @return list<array<string, mixed>>
 */
function clinic_rooms_past(PDO $pdo, int $limit = 400): array
{
    clinic_rooms_ready($pdo);
    $limit = max(1, min(800, $limit));
    $stmt = $pdo->query(clinic_rooms_select_sql() . "
      WHERE b.ends_at < NOW()
      ORDER BY b.starts_at DESC
      LIMIT {$limit}
    ");

    return $stmt->fetchAll();
}

function clinic_rooms_select_sql(): string
{
    return "
      SELECT b.*,
             a.patient_id AS appt_patient_id,
             a.doctor_id AS appt_doctor_id,
             COALESCE(pu.name, rpu.name) AS patient_name,
             COALESCE(du.name, rdu.name) AS doctor_name,
             a.status AS appt_status,
             a.session_mode,
             ws.title AS session_title,
             ws.session_date,
             w.title AS workshop_title,
             w.status AS workshop_status,
             wdu.name AS workshop_doctor_name,
             (SELECT COUNT(*) FROM workshop_enrollments e
               WHERE e.workshop_id = w.id AND e.status IN ('CONFIRMED','COMPLETED')) AS workshop_enrolled,
             bu.name AS booked_by_name
      FROM clinic_room_bookings b
      LEFT JOIN appointments a ON a.id = b.appointment_id
      LEFT JOIN users pu ON pu.id = a.patient_id
      LEFT JOIN doctor_profiles dp ON dp.id = a.doctor_id
      LEFT JOIN users du ON du.id = dp.user_id
      LEFT JOIN users rpu ON rpu.id = b.patient_id
      LEFT JOIN doctor_profiles rdp ON rdp.id = b.doctor_id
      LEFT JOIN users rdu ON rdu.id = rdp.user_id
      LEFT JOIN workshop_sessions ws ON ws.id = b.workshop_session_id
      LEFT JOIN workshops w ON w.id = COALESCE(b.workshop_id, ws.workshop_id)
      LEFT JOIN doctor_profiles wdp ON wdp.id = w.doctor_id
      LEFT JOIN users wdu ON wdu.id = wdp.user_id
      LEFT JOIN users bu ON bu.id = b.booked_by_user_id
    ";
}

function clinic_room_purpose(array $row): string
{
    $kind = (string) ($row['kind'] ?? '');
    if ($kind === 'APPOINTMENT') {
        $doctor = trim((string) ($row['doctor_name'] ?? ''));
        $patient = trim((string) ($row['patient_name'] ?? ''));
        $who = $doctor !== '' && $patient !== '' ? $doctor . ' با ' . $patient : ($patient !== '' ? $patient : $doctor);
        $prefix = trim((string) ($row['appointment_id'] ?? '')) === '' ? 'تراپی' : 'نوبت';

        return $prefix . ($who !== '' ? ' · ' . $who : '');
    }
    if ($kind === 'WORKSHOP') {
        $title = trim((string) ($row['workshop_title'] ?? $row['title'] ?? ''));
        $doctor = trim((string) ($row['workshop_doctor_name'] ?? ''));

        return 'کارگاه' . ($title !== '' ? ' · ' . $title : '') . ($doctor !== '' ? ' · ' . $doctor : '');
    }
    $title = trim((string) ($row['title'] ?? ''));

    return 'سایر' . ($title !== '' ? ' · ' . $title : '');
}

function clinic_room_was_held(array $row, ?int $now = null): bool
{
    $now = $now ?? time();
    $end = strtotime((string) ($row['ends_at'] ?? '')) ?: 0;
    if ($end >= $now) {
        return false;
    }
    $kind = (string) ($row['kind'] ?? '');
    if ($kind === 'APPOINTMENT') {
        $status = (string) ($row['appt_status'] ?? '');
        if ($status === '') {
            return true;
        }

        return in_array($status, ['CONFIRMED', 'COMPLETED'], true);
    }
    if ($kind === 'WORKSHOP') {
        return (string) ($row['workshop_status'] ?? '') !== 'CANCELLED';
    }

    return true;
}

/**
 * نام مراجعه‌کنندگان تأییدشدهٔ هر کارگاه.
 *
 * @param list<string> $workshopIds
 * @return array<string, list<string>>
 */
function clinic_rooms_workshop_patient_names(PDO $pdo, array $workshopIds): array
{
    $ids = [];
    foreach ($workshopIds as $id) {
        $id = trim((string) $id);
        if ($id !== '') {
            $ids[$id] = $id;
        }
    }
    $ids = array_values($ids);
    if (!$ids) {
        return [];
    }
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("
      SELECT e.workshop_id, u.name
      FROM workshop_enrollments e
      JOIN users u ON u.id = e.patient_id
      WHERE e.workshop_id IN ($marks)
        AND e.status IN ('CONFIRMED','COMPLETED')
      ORDER BY u.name ASC
    ");
    $stmt->execute($ids);
    $out = [];
    foreach ($stmt->fetchAll() as $row) {
        $wid = (string) ($row['workshop_id'] ?? '');
        $name = trim((string) ($row['name'] ?? ''));
        if ($wid === '' || $name === '') {
            continue;
        }
        $out[$wid][] = $name;
    }

    return $out;
}

function clinic_room_outcome_label(array $row): string
{
    if (strtotime((string) ($row['ends_at'] ?? '')) >= time()) {
        return 'پیش‌رو';
    }
    if (clinic_room_was_held($row)) {
        return 'برگزار شده';
    }
    $kind = (string) ($row['kind'] ?? '');
    if ($kind === 'APPOINTMENT' && (string) ($row['appt_status'] ?? '') === 'CANCELLED') {
        return 'نوبت لغو شده';
    }
    if ($kind === 'WORKSHOP' && (string) ($row['workshop_status'] ?? '') === 'CANCELLED') {
        return 'کارگاه لغو شده';
    }

    return 'برگزار نشده';
}

/**
 * @return array<string, int> appointment_id or workshop_session_id => room
 */
function clinic_rooms_active_assignments(PDO $pdo): array
{
    clinic_rooms_ready($pdo);
    $rows = $pdo->query("
      SELECT room_no, appointment_id, workshop_session_id
      FROM clinic_room_bookings
      WHERE ends_at >= NOW()
    ")->fetchAll();
    $map = [];
    foreach ($rows as $row) {
        $room = (int) ($row['room_no'] ?? 0);
        $appt = (string) ($row['appointment_id'] ?? '');
        $sess = (string) ($row['workshop_session_id'] ?? '');
        if ($appt !== '') {
            $map['a:' . $appt] = $room;
        }
        if ($sess !== '') {
            $map['w:' . $sess] = $room;
        }
    }

    return $map;
}

/**
 * @return list<array<string, mixed>>
 */
function clinic_rooms_open_appointments(PDO $pdo): array
{
    $from = date('Y-m-d 00:00:00');
    $to = date('Y-m-d 00:00:00', strtotime('+90 days') ?: time());
    $stmt = $pdo->prepare("
      SELECT a.id, a.starts_at, a.ends_at, a.status,
             pu.name AS patient_name, du.name AS doctor_name
      FROM appointments a
      JOIN users pu ON pu.id = a.patient_id
      JOIN doctor_profiles dp ON dp.id = a.doctor_id
      JOIN users du ON du.id = dp.user_id
      WHERE a.session_mode = 'IN_PERSON'
        AND a.status IN ('PENDING_APPROVAL','PENDING_PAYMENT','CONFIRMED')
        AND a.starts_at >= ? AND a.starts_at < ?
      ORDER BY a.starts_at ASC
      LIMIT 150
    ");
    $stmt->execute([$from, $to]);

    return $stmt->fetchAll();
}

/**
 * @return list<array<string, mixed>>
 */
function clinic_rooms_open_workshop_sessions(PDO $pdo): array
{
    if (!function_exists('ensure_workshop_sessions_schema')) {
        require_once __DIR__ . '/workshop_sessions.php';
    }
    ensure_workshop_sessions_schema($pdo);
    $from = date('Y-m-d');
    $to = date('Y-m-d', strtotime('+120 days') ?: time());
    $stmt = $pdo->prepare("
      SELECT ws.id, ws.session_date, ws.title AS session_title,
             w.id AS workshop_id, w.title AS workshop_title, w.starts_at, w.ends_at,
             du.name AS doctor_name
      FROM workshop_sessions ws
      JOIN workshops w ON w.id = ws.workshop_id
      JOIN doctor_profiles dp ON dp.id = w.doctor_id
      JOIN users du ON du.id = dp.user_id
      WHERE w.type = 'IN_PERSON'
        AND w.status <> 'CANCELLED'
        AND ws.session_date >= ? AND ws.session_date <= ?
      ORDER BY ws.session_date ASC, w.title ASC
      LIMIT 150
    ");
    $stmt->execute([$from, $to]);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$row) {
        [$start, $end] = clinic_rooms_workshop_span((string) $row['session_date'], (string) $row['starts_at'], (string) $row['ends_at']);
        $row['span_start'] = $start;
        $row['span_end'] = $end;
    }
    unset($row);

    return $rows;
}

/** @return array{0:string,1:string} */
function clinic_rooms_workshop_span(string $sessionDate, string $workshopStart, string $workshopEnd): array
{
    $startClock = date('H:i:s', strtotime($workshopStart) ?: time());
    $endClock = date('H:i:s', strtotime($workshopEnd) ?: time());
    $start = $sessionDate . ' ' . $startClock;
    $startTs = strtotime($start) ?: time();
    $endTs = strtotime($sessionDate . ' ' . $endClock) ?: 0;
    if ($endTs <= $startTs) {
        $endTs = $startTs + 2 * 3600;
    }
    if ($endTs - $startTs > 8 * 3600) {
        $endTs = $startTs + 2 * 3600;
    }

    return [date('Y-m-d H:i:s', $startTs), date('Y-m-d H:i:s', $endTs)];
}

function clinic_rooms_assign(
    PDO $pdo,
    array $actor,
    int $room,
    string $day,
    string $purpose,
    string $startRaw,
    string $endRaw,
    string $patientId,
    string $doctorId,
    string $workshopSessionId,
    string $blockTitle,
    string $note,
    int $repeatWeeks = 1
): array {
    clinic_rooms_ready($pdo);
    if (!in_array($room, clinic_rooms_numbers(), true)) {
        throw new RuntimeException('اتاق نامعتبر است.');
    }
    $note = mb_substr(trim($note), 0, 255);
    $actorId = (string) ($actor['id'] ?? '');
    if ($actorId === '') {
        throw new RuntimeException('حساب شما برای رزرو شناخته نشد.');
    }

    $startClock = clinic_rooms_parse_clock($startRaw);
    $endClock = clinic_rooms_parse_clock($endRaw);
    if ($startClock === null || $endClock === null) {
        throw new RuntimeException('ساعت شروع و پایان را بنویسید یا از فهرست انتخاب کنید. مثلاً ۸ یا ۱۴:۳۰.');
    }
    $day = clinic_rooms_parse_day($day);
    $startsAt = $day . ' ' . $startClock . ':00';
    $endsAt = $day . ' ' . $endClock . ':00';
    if (strtotime($endsAt) <= strtotime($startsAt)) {
        throw new RuntimeException('ساعت پایان باید بعد از شروع باشد.');
    }

    $kind = 'BLOCK';
    $appointmentId = null;
    $sessionId = null;
    $workshopId = null;
    $title = null;
    $patientId = trim($patientId);
    $doctorId = trim($doctorId);
    $purpose = trim($purpose);

    if ($purpose === 'therapy') {
        if ($patientId === '' || $doctorId === '') {
            throw new RuntimeException('برای تراپی، مراجعه‌کننده و درمانگر را انتخاب کنید.');
        }
        $patientOk = $pdo->prepare("SELECT id FROM users WHERE id=? AND role='PATIENT' LIMIT 1");
        $patientOk->execute([$patientId]);
        if (!$patientOk->fetch()) {
            throw new RuntimeException('مراجعه‌کننده یافت نشد.');
        }
        $doctorOk = $pdo->prepare('SELECT id FROM doctor_profiles WHERE id=? AND is_active=1 AND is_approved=1 LIMIT 1');
        $doctorOk->execute([$doctorId]);
        if (!$doctorOk->fetch()) {
            throw new RuntimeException('درمانگر یافت نشد.');
        }
        $kind = 'APPOINTMENT';
    } elseif ($purpose === 'workshop') {
        if (!function_exists('ensure_workshop_sessions_schema')) {
            require_once __DIR__ . '/workshop_sessions.php';
        }
        ensure_workshop_sessions_schema($pdo);
        $sessionId = trim($workshopSessionId);
        $stmt = $pdo->prepare("
          SELECT ws.id, w.id AS workshop_id, w.title, w.type, w.status
          FROM workshop_sessions ws
          JOIN workshops w ON w.id = ws.workshop_id
          WHERE ws.id = ?
          LIMIT 1
        ");
        $stmt->execute([$sessionId]);
        $sess = $stmt->fetch();
        if (!$sess) {
            throw new RuntimeException('کارگاه را انتخاب کنید.');
        }
        if ((string) ($sess['type'] ?? '') !== 'IN_PERSON' || (string) ($sess['status'] ?? '') === 'CANCELLED') {
            throw new RuntimeException('این کارگاه برای اتاق حضوری قابل رزرو نیست.');
        }
        $kind = 'WORKSHOP';
        $workshopId = (string) $sess['workshop_id'];
        $title = (string) ($sess['title'] ?? '');
        $patientId = '';
        $doctorId = '';
    } elseif ($purpose === 'block') {
        $title = mb_substr(trim($blockTitle), 0, 255);
        if ($title === '') {
            throw new RuntimeException('برای سایر، عنوان کار را بنویسید.');
        }
        $patientId = '';
        $doctorId = '';
    } else {
        throw new RuntimeException('نوع جلسه را انتخاب کنید: تراپی، کارگاه یا سایر.');
    }

    if ($repeatWeeks < 1) {
        throw new RuntimeException('برای تکرار هفتگی، تعداد هفته را بنویسید. مثلاً ۸.');
    }
    if ($repeatWeeks > 24) {
        throw new RuntimeException('تکرار هفتگی حداکثر ۲۴ هفته است.');
    }

    $dates = [$day];
    for ($i = 1; $i < $repeatWeeks; $i++) {
        $dates[] = date('Y-m-d', strtotime($day . ' +' . ($i * 7) . ' days') ?: time());
    }

    $patientStore = $patientId !== '' ? $patientId : null;
    $doctorStore = $doctorId !== '' ? $doctorId : null;
    $noteStore = $note !== '' ? $note : null;
    $existingId = ($repeatWeeks === 1 && $kind === 'WORKSHOP') ? clinic_rooms_existing_id($pdo, null, $sessionId) : null;
    $blocked = [];
    foreach ($dates as $date) {
        $slotStart = $date . ' ' . $startClock . ':00';
        $slotEnd = $date . ' ' . $endClock . ':00';
        $clash = clinic_rooms_find_clash($pdo, $room, $slotStart, $slotEnd, $existingId);
        if ($clash) {
            $blocked[] = to_jalali_label($date);
        }
    }
    if ($blocked) {
        throw new RuntimeException(clinic_room_label($room) . ' در این ساعت این روزها پر است: ' . implode('، ', $blocked));
    }

    $seriesId = $repeatWeeks > 1 ? cuid() : null;
    if ($existingId !== null) {
        $pdo->prepare("
          UPDATE clinic_room_bookings
          SET room_no=?, kind=?, starts_at=?, ends_at=?, title=?, note=?, booked_by_user_id=?, workshop_id=?, patient_id=?, doctor_id=?, series_id=NULL
          WHERE id=?
        ")->execute([$room, $kind, $startsAt, $endsAt, $title, $noteStore, $actorId, $workshopId, $patientStore, $doctorStore, $existingId]);
    } else {
        $insert = $pdo->prepare("
          INSERT INTO clinic_room_bookings
            (id, room_no, kind, appointment_id, workshop_session_id, workshop_id, patient_id, doctor_id, starts_at, ends_at, title, note, booked_by_user_id, series_id)
          VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ");
        foreach ($dates as $date) {
            $insert->execute([
                cuid(),
                $room,
                $kind,
                $appointmentId,
                $sessionId,
                $workshopId,
                $patientStore,
                $doctorStore,
                $date . ' ' . $startClock . ':00',
                $date . ' ' . $endClock . ':00',
                $title,
                $noteStore,
                $actorId,
                $seriesId,
            ]);
        }
    }

    return ['day' => $day, 'room' => $room, 'weeks' => count($dates)];
}

function clinic_rooms_update(
    PDO $pdo,
    string $bookingId,
    int $room,
    string $purpose,
    string $startRaw,
    string $endRaw,
    string $patientId,
    string $doctorId,
    string $workshopSessionId,
    string $blockTitle,
    string $note,
    bool $applySeries
): array {
    clinic_rooms_ready($pdo);
    if (!in_array($room, clinic_rooms_numbers(), true)) {
        throw new RuntimeException('اتاق نامعتبر است.');
    }
    $stmt = $pdo->prepare('SELECT * FROM clinic_room_bookings WHERE id=? LIMIT 1');
    $stmt->execute([$bookingId]);
    $current = $stmt->fetch();
    if (!$current) {
        throw new RuntimeException('رزرو یافت نشد.');
    }
    if ((strtotime((string) $current['ends_at']) ?: 0) < time()) {
        throw new RuntimeException('رزرو گذشته فقط در گزارش می‌ماند و ویرایش نمی‌شود.');
    }

    $note = mb_substr(trim($note), 0, 255);
    $startClock = clinic_rooms_parse_clock($startRaw);
    $endClock = clinic_rooms_parse_clock($endRaw);
    if ($startClock === null || $endClock === null) {
        throw new RuntimeException('ساعت شروع و پایان را بنویسید یا از فهرست انتخاب کنید. مثلاً ۸ یا ۱۴:۳۰.');
    }
    if (strtotime('2000-01-01 ' . $endClock) <= strtotime('2000-01-01 ' . $startClock)) {
        throw new RuntimeException('ساعت پایان باید بعد از شروع باشد.');
    }

    $kind = 'BLOCK';
    $sessionId = null;
    $workshopId = null;
    $title = null;
    $patientId = trim($patientId);
    $doctorId = trim($doctorId);
    $purpose = trim($purpose);

    if ($purpose === 'therapy') {
        if ($patientId === '' || $doctorId === '') {
            throw new RuntimeException('برای تراپی، مراجعه‌کننده و درمانگر را انتخاب کنید.');
        }
        $patientOk = $pdo->prepare("SELECT id FROM users WHERE id=? AND role='PATIENT' LIMIT 1");
        $patientOk->execute([$patientId]);
        if (!$patientOk->fetch()) {
            throw new RuntimeException('مراجعه‌کننده یافت نشد.');
        }
        $doctorOk = $pdo->prepare('SELECT id FROM doctor_profiles WHERE id=? AND is_active=1 AND is_approved=1 LIMIT 1');
        $doctorOk->execute([$doctorId]);
        if (!$doctorOk->fetch()) {
            throw new RuntimeException('درمانگر یافت نشد.');
        }
        $kind = 'APPOINTMENT';
    } elseif ($purpose === 'workshop') {
        if (!function_exists('ensure_workshop_sessions_schema')) {
            require_once __DIR__ . '/workshop_sessions.php';
        }
        ensure_workshop_sessions_schema($pdo);
        $sessionId = trim($workshopSessionId);
        $sessStmt = $pdo->prepare("
          SELECT ws.id, w.id AS workshop_id, w.title, w.type, w.status
          FROM workshop_sessions ws
          JOIN workshops w ON w.id = ws.workshop_id
          WHERE ws.id = ?
          LIMIT 1
        ");
        $sessStmt->execute([$sessionId]);
        $sess = $sessStmt->fetch();
        if (!$sess) {
            throw new RuntimeException('کارگاه را انتخاب کنید.');
        }
        if ((string) ($sess['type'] ?? '') !== 'IN_PERSON' || (string) ($sess['status'] ?? '') === 'CANCELLED') {
            throw new RuntimeException('این کارگاه برای اتاق حضوری قابل رزرو نیست.');
        }
        $kind = 'WORKSHOP';
        $workshopId = (string) $sess['workshop_id'];
        $title = (string) ($sess['title'] ?? '');
        $patientId = '';
        $doctorId = '';
    } elseif ($purpose === 'block') {
        $title = mb_substr(trim($blockTitle), 0, 255);
        if ($title === '') {
            throw new RuntimeException('برای سایر، عنوان کار را بنویسید.');
        }
        $patientId = '';
        $doctorId = '';
    } else {
        throw new RuntimeException('نوع جلسه را انتخاب کنید: تراپی، کارگاه یا سایر.');
    }

    $targets = [[
        'id' => (string) $current['id'],
        'date' => substr((string) $current['starts_at'], 0, 10),
    ]];
    $seriesId = trim((string) ($current['series_id'] ?? ''));
    if ($applySeries && $seriesId !== '') {
        $later = $pdo->prepare("
          SELECT id, starts_at
          FROM clinic_room_bookings
          WHERE series_id=? AND starts_at>=? AND ends_at>=NOW()
          ORDER BY starts_at ASC
        ");
        $later->execute([$seriesId, (string) $current['starts_at']]);
        $targets = [];
        foreach ($later->fetchAll() as $row) {
            $targets[] = [
                'id' => (string) $row['id'],
                'date' => substr((string) $row['starts_at'], 0, 10),
            ];
        }
        if (!$targets) {
            throw new RuntimeException('رزرو تکرارشونده‌ای برای ویرایش پیدا نشد.');
        }
    }

    $patientStore = $patientId !== '' ? $patientId : null;
    $doctorStore = $doctorId !== '' ? $doctorId : null;
    $noteStore = $note !== '' ? $note : null;
    $blocked = [];
    foreach ($targets as $target) {
        $slotStart = $target['date'] . ' ' . $startClock . ':00';
        $slotEnd = $target['date'] . ' ' . $endClock . ':00';
        $clash = clinic_rooms_find_clash($pdo, $room, $slotStart, $slotEnd, $target['id']);
        if ($clash) {
            $blocked[] = to_jalali_label($target['date']);
        }
    }
    if ($blocked) {
        throw new RuntimeException(clinic_room_label($room) . ' در این ساعت این روزها پر است: ' . implode('، ', $blocked));
    }

    $update = $pdo->prepare("
      UPDATE clinic_room_bookings
      SET room_no=?, kind=?, workshop_session_id=?, workshop_id=?, patient_id=?, doctor_id=?, starts_at=?, ends_at=?, title=?, note=?
      WHERE id=?
    ");
    foreach ($targets as $target) {
        $update->execute([
            $room,
            $kind,
            $sessionId,
            $workshopId,
            $patientStore,
            $doctorStore,
            $target['date'] . ' ' . $startClock . ':00',
            $target['date'] . ' ' . $endClock . ':00',
            $title,
            $noteStore,
            $target['id'],
        ]);
    }

    return [
        'day' => substr((string) $current['starts_at'], 0, 10),
        'room' => $room,
        'count' => count($targets),
    ];
}

function clinic_rooms_existing_id(PDO $pdo, ?string $appointmentId, ?string $sessionId): ?string
{
    if ($appointmentId) {
        $stmt = $pdo->prepare('SELECT id FROM clinic_room_bookings WHERE appointment_id=? LIMIT 1');
        $stmt->execute([$appointmentId]);
        $id = $stmt->fetchColumn();

        return $id ? (string) $id : null;
    }
    if ($sessionId) {
        $stmt = $pdo->prepare('SELECT id FROM clinic_room_bookings WHERE workshop_session_id=? LIMIT 1');
        $stmt->execute([$sessionId]);
        $id = $stmt->fetchColumn();

        return $id ? (string) $id : null;
    }

    return null;
}

function clinic_rooms_find_clash(PDO $pdo, int $room, string $startsAt, string $endsAt, ?string $exceptId): ?array
{
    $sql = clinic_rooms_select_sql() . "
      WHERE b.room_no = ? AND b.starts_at < ? AND b.ends_at > ?
    ";
    $params = [$room, $endsAt, $startsAt];
    if ($exceptId) {
        $sql .= ' AND b.id <> ?';
        $params[] = $exceptId;
    }
    $sql .= ' LIMIT 1';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();

    return $row ?: null;
}

function clinic_rooms_release(PDO $pdo, string $bookingId): string
{
    clinic_rooms_ready($pdo);
    $stmt = $pdo->prepare('SELECT id, starts_at, ends_at FROM clinic_room_bookings WHERE id=? LIMIT 1');
    $stmt->execute([$bookingId]);
    $row = $stmt->fetch();
    if (!$row) {
        throw new RuntimeException('رزرو یافت نشد.');
    }
    if ((strtotime((string) $row['ends_at']) ?: 0) < time()) {
        throw new RuntimeException('رزرو گذشته برای گزارش می‌ماند و حذف نمی‌شود.');
    }
    $pdo->prepare('DELETE FROM clinic_room_bookings WHERE id=?')->execute([$bookingId]);

    return substr((string) $row['starts_at'], 0, 10);
}

/** این رزرو و هفته‌های بعدیِ همان تکرار را حذف می‌کند. */
function clinic_rooms_release_series(PDO $pdo, string $bookingId): string
{
    clinic_rooms_ready($pdo);
    $stmt = $pdo->prepare('SELECT id, starts_at, ends_at, series_id FROM clinic_room_bookings WHERE id=? LIMIT 1');
    $stmt->execute([$bookingId]);
    $row = $stmt->fetch();
    if (!$row) {
        throw new RuntimeException('رزرو یافت نشد.');
    }
    $seriesId = trim((string) ($row['series_id'] ?? ''));
    if ($seriesId === '') {
        return clinic_rooms_release($pdo, $bookingId);
    }
    $pdo->prepare("
      DELETE FROM clinic_room_bookings
      WHERE series_id=? AND starts_at >= ? AND ends_at >= NOW()
    ")->execute([$seriesId, (string) $row['starts_at']]);

    return substr((string) $row['starts_at'], 0, 10);
}

/**
 * @param list<array<string, mixed>> $bookings
 * @return list<int>
 */
function clinic_rooms_hour_range(array $bookings): array
{
    $from = 8;
    $to = 21;
    foreach ($bookings as $row) {
        $start = strtotime((string) ($row['starts_at'] ?? '')) ?: 0;
        $end = strtotime((string) ($row['ends_at'] ?? '')) ?: 0;
        if ($start <= 0 || $end <= 0) {
            continue;
        }
        $from = min($from, (int) date('G', $start));
        $endHour = (int) date('G', $end - 1);
        $to = max($to, $endHour + 1);
    }
    $from = max(0, $from);
    $to = min(24, max($from + 1, $to));
    $hours = [];
    for ($h = $from; $h < $to; $h++) {
        $hours[] = $h;
    }

    return $hours;
}

function clinic_rooms_booking_at_hour(array $bookings, string $day, int $hour): ?array
{
    $slotStart = strtotime($day . sprintf(' %02d:00:00', $hour)) ?: 0;
    $slotEnd = $slotStart + 3600;
    foreach ($bookings as $row) {
        $start = strtotime((string) ($row['starts_at'] ?? '')) ?: 0;
        $end = strtotime((string) ($row['ends_at'] ?? '')) ?: 0;
        if ($start < $slotEnd && $end > $slotStart) {
            return $row;
        }
    }

    return null;
}
