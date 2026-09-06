<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/availability.php';

header('Content-Type: application/json; charset=utf-8');

ensure_availability_schema($pdo);

$doctorId = (string) ($_GET['doctorId'] ?? '');
$date = (string) ($_GET['date'] ?? '');
if ($doctorId === '' || $date === '') {
    echo json_encode(['error' => 'پارامتر ناقص', 'slots' => []]);
    exit;
}

$pdo->prepare("
  UPDATE appointments SET status='CANCELLED'
  WHERE doctor_id=? AND status='PENDING_PAYMENT' AND created_at < (NOW() - INTERVAL 20 MINUTE)
")->execute([$doctorId]);

$stmt = $pdo->prepare('SELECT * FROM availabilities WHERE doctor_id=? AND date=? LIMIT 1');
$stmt->execute([$doctorId, $date]);
$availability = $stmt->fetch();
if (!$availability) {
    echo json_encode(['slots' => []]);
    exit;
}

$hours = appointment_availability_hours($availability);
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

$now = time();
$free = [];
foreach ($hours as $hour) {
    $startsAt = appointment_slot_starts_at($date, $hour);
    if (isset($taken[$startsAt])) {
        continue;
    }
    $ts = strtotime($startsAt);
    if ($ts && $ts > $now) {
        $free[] = [
            'value' => appointment_hour_to_time($hour),
            'label' => appointment_hour_chip_label($hour),
        ];
    }
}

echo json_encode(['slots' => $free], JSON_UNESCAPED_UNICODE);
