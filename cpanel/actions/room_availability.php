<?php
declare(strict_types=1);

require_login(['SECRETARY']);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once __DIR__ . '/../includes/booking_room_availability.php';
$date = is_string($_GET['date'] ?? null) ? $_GET['date'] : '';
$room = (int) ($_GET['room'] ?? 0);
$day = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('Asia/Tehran'));
if (!$day || $day->format('Y-m-d') !== $date || !in_array($room, [1, 2, 3], true)) {
    http_response_code(400);
    echo json_encode(['error' => 'تاریخ یا اتاق نامعتبر است.'], JSON_UNESCAPED_UNICODE);
    exit;
}
try {
    require_once __DIR__ . '/../includes/clinic_rooms.php';
    $rows = clinic_rooms_on_day($pdo, $date);
    echo json_encode(['date' => $date, 'room' => $room, 'segments' => booking_room_segments($rows, $room, $date)], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('booking room availability: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'دریافت وضعیت اتاق انجام نشد.'], JSON_UNESCAPED_UNICODE);
}
