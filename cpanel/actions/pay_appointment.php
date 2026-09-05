<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/booking_terms.php';

$user = current_user();
if (!$user || $user['role'] !== 'PATIENT') {
    http_response_code(401);
    echo json_encode(['error' => 'لطفاً با حساب مراجعه‌کننده وارد شوید.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!online_payment_enabled($config)) {
    http_response_code(503);
    echo json_encode(['error' => online_payment_disabled_message()], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!booking_terms_accepted()) {
    booking_terms_not_accepted_error();
}

$appointmentId = post('appointmentId');
if ($appointmentId === '') {
    http_response_code(400);
    echo json_encode(['error' => 'شناسه نوبت نامعتبر است.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$stmt = $pdo->prepare("
  SELECT a.*, p.id AS payment_id, p.amount, p.status AS pay_status, p.authority
  FROM appointments a
  JOIN payments p ON p.appointment_id = a.id
  WHERE a.id = ? AND a.patient_id = ?
");
$stmt->execute([$appointmentId, $user['id']]);
$row = $stmt->fetch();
if (!$row) {
    http_response_code(404);
    echo json_encode(['error' => 'نوبت یافت نشد.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($row['status'] !== 'PENDING_PAYMENT' || $row['pay_status'] !== 'PENDING') {
    http_response_code(400);
    echo json_encode(['error' => 'این نوبت قابل پرداخت نیست.'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $callback = rtrim($config['app_url'], '/') . '/payments/verify';
    $pay = zarinpal_request(
        $config,
        (int) $row['amount'],
        'پرداخت نوبت مانا کلینیک - ' . $appointmentId,
        $callback,
        $user['email'] ?? null
    );
    $pdo->prepare('UPDATE payments SET authority=? WHERE id=?')->execute([$pay['authority'], $row['payment_id']]);

    echo json_encode(['paymentUrl' => $pay['paymentUrl']], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(502);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
