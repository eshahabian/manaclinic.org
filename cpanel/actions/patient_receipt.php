<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/appointment_payment.php';

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

try {
    $result = patient_upload_appointment_receipt($pdo, $appointmentId, (string) $user['id'], $_FILES['receipt'] ?? []);
    echo json_encode([
        'ok' => true,
        'message' => 'فیش ارسال شد. پس از تأیید منشی، نوبت ثبت می‌شود.',
        'paymentId' => $result['payment_id'],
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
