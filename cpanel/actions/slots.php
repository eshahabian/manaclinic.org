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

appointment_expire_stale_pending_payments($pdo, $doctorId);

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
  SELECT starts_at
  FROM appointments
  WHERE doctor_id=? AND DATE(starts_at) IN (?, ?) AND status IN ('PENDING_PAYMENT','CONFIRMED','COMPLETED')
");
$takenStmt->execute([$doctorId, $date, $nextDate]);
$taken = [];
foreach ($takenStmt->fetchAll(PDO::FETCH_COLUMN) as $startsAt) {
    $taken[(string) $startsAt] = true;
}

$staff = current_user();
$staffCanSeePast = $staff && in_array((string) ($staff['role'] ?? ''), ['SECRETARY', 'ADMIN', 'DOCTOR'], true)
    && (string) ($_GET['include_past'] ?? '') === '1';

$now = time();
$free = [];
foreach ($hours as $hour) {
    $startsAt = appointment_slot_starts_at($date, $hour);
    if (isset($taken[$startsAt])) {
        continue;
    }
    $ts = strtotime($startsAt);
    if ($ts && ($staffCanSeePast || $ts > $now)) {
        $free[] = [
            'value' => appointment_hour_to_time($hour),
            'label' => appointment_hour_chip_label($hour),
            'date_label' => appointment_short_jalali_date(substr($startsAt, 0, 10)),
        ];
    }
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
