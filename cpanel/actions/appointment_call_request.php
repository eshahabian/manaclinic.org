<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/appointment_session.php';
require_once __DIR__ . '/../includes/notifications.php';
require_once __DIR__ . '/../includes/video_call.php';

header('Content-Type: application/json; charset=utf-8');

$user = current_user();
if (!$user || ($user['role'] ?? '') !== 'PATIENT') {
    http_response_code(401);
    echo json_encode(['error' => 'لطفاً با حساب مراجعه‌کننده وارد شوید.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$appointmentId = trim((string) (post('appointmentId') ?: post('appointment_id') ?: ''));
if ($appointmentId === '') {
    http_response_code(400);
    echo json_encode(['error' => 'نوبت مشخص نیست.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$stmt = $pdo->prepare("
  SELECT a.*, u.name AS patient_name, du.id AS doctor_user_id, du.name AS doctor_name
  FROM appointments a
  JOIN users u ON u.id = a.patient_id
  JOIN doctor_profiles dp ON dp.id = a.doctor_id
  JOIN users du ON du.id = dp.user_id
  WHERE a.id = ? AND a.patient_id = ?
  LIMIT 1
");
$stmt->execute([$appointmentId, (string) $user['id']]);
$row = $stmt->fetch();
if (!$row) {
    http_response_code(404);
    echo json_encode(['error' => 'نوبت یافت نشد.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!appointment_is_online_mode($row)) {
    http_response_code(400);
    echo json_encode(['error' => 'تماس مانا فقط برای جلسه آنلاین است.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!appointment_mana_call_is_active($row)) {
    http_response_code(400);
    echo json_encode(['error' => 'تماس مانا از ۱۵ دقیقه قبل از شروع جلسه فعال می‌شود.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$doctorUserId = (string) ($row['doctor_user_id'] ?? '');
$patientId = (string) ($user['id'] ?? '');
$patientName = (string) ($user['name'] ?? ($row['patient_name'] ?? 'مراجعه‌کننده'));
$when = format_fa_datetime((string) ($row['starts_at'] ?? ''));
$acceptLink = '/video-call?peer=' . rawurlencode($patientId) . '&media=video&start=1';

notify_user(
    $pdo,
    $doctorUserId,
    'درخواست تماس مانا',
    "مراجعه‌کننده «{$patientName}» برای نوبت {$when} آماده است. برای پذیرش و شروع جلسه روی مشاهده بزنید.",
    $acceptLink,
    'appointment',
    $patientId,
    'personal'
);

echo json_encode([
    'ok' => true,
    'message' => 'درخواست تماس برای درمانگر ارسال شد. منتظر پذیرش بمانید.',
], JSON_UNESCAPED_UNICODE);
