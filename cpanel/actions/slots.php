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
  SELECT starts_at, ends_at
  FROM appointments
  WHERE doctor_id=? AND DATE(starts_at) IN (?, ?) AND status IN ('PENDING_APPROVAL','PENDING_PAYMENT','CONFIRMED','COMPLETED')
");
$takenStmt->execute([$doctorId, $date, $nextDate]);
$taken = $takenStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$staff = current_user();
$staffCanSeePast = $staff && in_array((string) ($staff['role'] ?? ''), ['SECRETARY', 'ADMIN', 'DOCTOR'], true)
    && (string) ($_GET['include_past'] ?? '') === '1';

$now = time();
$free = [];
foreach ($hours as $hour) {
    $freeMinutes = [];
    $anchor = appointment_slot_starts_at($date, $hour);
    for ($minute = 0; $minute < 60; $minute += 5) {
        $startsAt = appointment_slot_starts_at($date, sprintf('%02d:%02d', $hour, $minute));
        $endsAt = date('Y-m-d H:i:s', strtotime($startsAt) + (appointment_slot_minutes() * 60));
        $blocked = false;
        foreach ($taken as $row) {
            $busyStart = (string) ($row['starts_at'] ?? '');
            $busyEnd = (string) ($row['ends_at'] ?? '');
            if ($busyStart !== '' && $busyEnd !== '' && $busyStart < $endsAt && $busyEnd > $startsAt) {
                $blocked = true;
                break;
            }
        }
        $ts = strtotime($startsAt);
        if (!$blocked && $ts && ($staffCanSeePast || $ts > $now)) {
            $freeMinutes[] = sprintf('%02d', $minute);
        }
    }
    if ($freeMinutes === []) {
        continue;
    }
    $free[] = [
        'value' => appointment_hour_to_time($hour),
        'label' => appointment_clock_label(appointment_hour_to_time($hour)),
        'date_label' => appointment_short_jalali_date(substr($anchor, 0, 10)),
        'minutes' => $freeMinutes,
    ];
}

if ($free === []) {
    echo json_encode([
        'slots' => [],
        'reason' => 'all_taken',
        'message' => 'ساعت خالی در این تاریخ باقی نمانده است',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['slots' => $free], JSON_UNESCAPED_UNICODE);
