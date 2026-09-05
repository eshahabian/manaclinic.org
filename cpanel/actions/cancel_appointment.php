<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/appointment_cancel.php';

$user = current_user();
if (!$user || $user['role'] !== 'PATIENT') {
    http_response_code(401);
    echo json_encode(['error' => 'لطفاً با حساب مراجعه‌کننده وارد شوید.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$appointmentId = post('appointmentId');
if ($appointmentId === '') {
    http_response_code(400);
    echo json_encode(['error' => 'شناسه نوبت نامعتبر است.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo->beginTransaction();
try {
    $result = cancel_patient_appointment($pdo, $appointmentId, $user['id']);
    $pdo->commit();
    echo json_encode(['success' => true, 'message' => $result['message']], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
