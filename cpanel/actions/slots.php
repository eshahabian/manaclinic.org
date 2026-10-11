<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/availability.php';

header('Content-Type: application/json; charset=utf-8');

ensure_availability_schema($pdo);

$doctorId = (string) ($_GET['doctorId'] ?? '');
$date = (string) ($_GET['date'] ?? '');
if ($doctorId === '' || $date === '') {
    echo json_encode([
        'error' => 'پارامتر ناقص',
        'slots' => [],
        'message' => 'پارامتر ناقص',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
if (!appointment_date_within_horizon($date)) {
    echo json_encode([
        'slots' => [],
        'reason' => 'horizon',
        'message' => 'نوبت فقط تا دو هفته آینده باز است',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
if (!function_exists('therapist_date_is_closed')) {
    require_once __DIR__ . '/../includes/therapist_presence.php';
}
if (therapist_date_is_closed($pdo, $doctorId, $date)) {
    echo json_encode([
        'slots' => [],
        'reason' => 'closed',
        'message' => 'در این تاریخ درمانگر حضور ندارد',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

appointment_expire_stale_pending_payments($pdo, $doctorId);
if (function_exists('appointment_expire_unpaid_holds') || is_file(__DIR__ . '/../includes/appointment_session.php')) {
    require_once __DIR__ . '/../includes/appointment_session.php';
    appointment_expire_unpaid_holds($pdo);
}

$stmt = $pdo->prepare('SELECT * FROM availabilities WHERE doctor_id=? AND date=? LIMIT 1');
$stmt->execute([$doctorId, $date]);
$availability = $stmt->fetch();
if (!$availability) {
    echo json_encode([
        'slots' => [],
        'reason' => 'no_availability',
        'message' => 'در این تاریخ درمانگر وقت خالی ندارد',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$hours = appointment_availability_hours($availability);
if ($hours === []) {
    echo json_encode([
        'slots' => [],
        'reason' => 'no_hours',
        'message' => 'در این تاریخ درمانگر وقت خالی ندارد',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$nextDate = date('Y-m-d', strtotime($date . ' +1 day') ?: time());

$takenStmt = $pdo->prepare("
  SELECT a.starts_at, a.ends_at, u.name AS patient_name
  FROM appointments a
  JOIN users u ON u.id = a.patient_id
  WHERE a.doctor_id=? AND a.starts_at < ? AND a.ends_at > ?
    AND a.status IN ('PENDING_APPROVAL','PENDING_PAYMENT','CONFIRMED','COMPLETED')
");
$rangeStart = $date . ' 00:00:00';
$rangeEnd = $nextDate . ' 23:59:59';
$takenStmt->execute([$doctorId, $rangeEnd, $rangeStart]);
$taken = $takenStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$staff = current_user();
$isStaff = $staff && in_array((string) ($staff['role'] ?? ''), ['SECRETARY', 'ADMIN', 'DOCTOR'], true);
$staffCanSeePast = $isStaff && (string) ($_GET['include_past'] ?? '') === '1';

$now = time();
$free = [];
$booked = [];
foreach ($hours as $hour) {
    $anchor = appointment_slot_starts_at($date, $hour);
    $hourEnd = date('Y-m-d H:i:s', (strtotime($anchor) ?: 0) + (appointment_slot_minutes() * 60));
    $cover = appointment_booking_covering_window($taken, $anchor, $hourEnd);
    $freeMinutes = [];
    for ($minute = 0; $minute < 60; $minute += 5) {
        $startsAt = appointment_slot_starts_at($date, sprintf('%02d:%02d', $hour, $minute));
        $endsAt = date('Y-m-d H:i:s', (strtotime($startsAt) ?: 0) + (appointment_slot_minutes() * 60));
        $blocked = appointment_booking_covering_window($taken, $startsAt, $endsAt) !== null;
        $ts = strtotime($startsAt);
        if (!$blocked && $ts && ($staffCanSeePast || $ts > $now)) {
            $freeMinutes[] = sprintf('%02d', $minute);
        }
    }
    if (!$isStaff) {
        $freeMinutes = in_array('00', $freeMinutes, true) ? ['00'] : [];
    }
    if ($freeMinutes === []) {
        if ($isStaff && is_array($cover)) {
            $clock = appointment_clock_from_row($cover);
            $booked[] = [
                'value' => appointment_hour_to_time($hour),
                'clock' => $clock,
                'patient' => trim((string) ($cover['patient_name'] ?? '')),
                'label' => $clock !== '' ? appointment_clock_label($clock) : appointment_hour_chip_label($hour),
            ];
        }
        continue;
    }
    $free[] = [
        'value' => appointment_hour_to_time($hour),
        'label' => appointment_clock_label(appointment_hour_to_time($hour)),
        'date_label' => appointment_short_jalali_date(substr($anchor, 0, 10)),
        'minutes' => $freeMinutes,
    ];
}

if ($free === [] && $booked === []) {
    echo json_encode([
        'slots' => [],
        'booked' => [],
        'reason' => 'all_taken',
        'message' => 'ساعت خالی در این تاریخ باقی نمانده است',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode([
    'slots' => $free,
    'booked' => $isStaff ? $booked : [],
], JSON_UNESCAPED_UNICODE);
