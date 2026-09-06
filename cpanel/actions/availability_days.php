<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/availability.php';

header('Content-Type: application/json; charset=utf-8');

require_login(['SECRETARY', 'DOCTOR']);
ensure_availability_schema($pdo);

$doctorId = trim((string) ($_GET['doctorId'] ?? ''));
if ($doctorId === '') {
    echo json_encode(['error' => 'پارامتر ناقص', 'days' => [], 'hoursByDate' => new stdClass()], JSON_UNESCAPED_UNICODE);
    exit;
}

$stmt = $pdo->prepare("
  SELECT DATE_FORMAT(`date`, '%Y-%m-%d') AS d, available_hours
  FROM availabilities
  WHERE doctor_id = ? AND `date` >= (CURDATE() - INTERVAL 1 DAY)
  ORDER BY `date` ASC
");
$stmt->execute([$doctorId]);

$days = [];
$hoursByDate = [];
foreach ($stmt->fetchAll() as $row) {
    $d = (string) ($row['d'] ?? '');
    if ($d === '') {
        continue;
    }
    $days[] = $d;
    $hoursByDate[$d] = appointment_hours_decode($row['available_hours'] ?? null);
}

echo json_encode([
    'days' => $days,
    'hoursByDate' => $hoursByDate,
], JSON_UNESCAPED_UNICODE);
