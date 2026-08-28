<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$doctorId = (string) ($_GET['doctorId'] ?? '');
$date = (string) ($_GET['date'] ?? '');
if ($doctorId === '' || $date === '') {
    echo json_encode(['error' => 'پارامتر ناقص', 'slots' => []]);
    exit;
}

// آزاد کردن نوبت‌های پرداخت‌نشده قدیمی
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

$all = generate_slots($availability['start_time'], $availability['end_time'], (int)$availability['slot_minutes']);

$takenStmt = $pdo->prepare("
  SELECT DATE_FORMAT(starts_at, '%H:%i') AS t
  FROM appointments
  WHERE doctor_id=? AND DATE(starts_at)=? AND status IN ('PENDING_PAYMENT','CONFIRMED','COMPLETED')
");
$takenStmt->execute([$doctorId, $date]);
$taken = array_column($takenStmt->fetchAll(), 't');

$now = time();
$free = [];
foreach ($all as $slot) {
    if (in_array($slot, $taken, true)) continue;
    $ts = strtotime($date . ' ' . $slot . ':00');
    if ($ts && $ts > $now) $free[] = $slot;
}

echo json_encode(['slots' => $free], JSON_UNESCAPED_UNICODE);
