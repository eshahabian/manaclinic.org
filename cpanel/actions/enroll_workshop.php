<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/workshops.php';
require_once __DIR__ . '/../includes/notifications.php';

$user = current_user();
if (!$user || $user['role'] !== 'PATIENT') {
    http_response_code(401);
    echo json_encode(['error' => 'لطفاً با حساب مراجعه‌کننده وارد شوید.'], JSON_UNESCAPED_UNICODE);
    exit;
}

ensure_workshop_schema($pdo);
$workshopId = post('workshopId');
if ($workshopId === '') {
    http_response_code(400);
    echo json_encode(['error' => 'کارگاه نامعتبر است.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$stmt = $pdo->prepare('
  SELECT w.* FROM workshops w
  ' . workshop_active_doctor_join('w') . '
  WHERE w.id = ? AND ' . workshop_patient_enrollable_sql('w')
);
$stmt->execute([$workshopId]);
$workshop = $stmt->fetch();
if (!$workshop) {
    http_response_code(404);
    echo json_encode(['error' => 'کارگاه یافت نشد یا ثبت‌نام بسته شده است.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!workshop_has_capacity($pdo, $workshop)) {
    http_response_code(409);
    echo json_encode(['error' => 'ظرفیت کارگاه تکمیل شده است.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$exists = $pdo->prepare('
  SELECT id, status FROM workshop_enrollments
  WHERE workshop_id = ? AND patient_id = ?
');
$exists->execute([$workshopId, $user['id']]);
$existing = $exists->fetch();
if ($existing && in_array($existing['status'], ['PENDING_PAYMENT', 'CONFIRMED', 'COMPLETED'], true)) {
    http_response_code(409);
    echo json_encode(['error' => 'شما قبلاً در این کارگاه ثبت‌نام کرده‌اید.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$enrollmentId = cuid();
$paymentId = cuid();
$amount = (int) $workshop['price'];
$needsPayment = $amount > 0;

try {
    $pdo->beginTransaction();

    if ($existing && in_array($existing['status'], ['CANCELLED', 'REFUNDED'], true)) {
        $enrollmentId = $existing['id'];
        $pdo->prepare("UPDATE workshop_enrollments SET status='PENDING_PAYMENT', enrolled_at=NOW() WHERE id=?")
            ->execute([$enrollmentId]);
        $pdo->prepare('DELETE FROM workshop_payments WHERE enrollment_id=?')->execute([$enrollmentId]);
    } else {
        $pdo->prepare('INSERT INTO workshop_enrollments (id, workshop_id, patient_id) VALUES (?,?,?)')
            ->execute([$enrollmentId, $workshopId, $user['id']]);
    }

    $pdo->prepare('INSERT INTO workshop_payments (id, enrollment_id, amount) VALUES (?,?,?)')
        ->execute([$paymentId, $enrollmentId, $amount]);

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!$needsPayment) {
    try {
        confirm_workshop_payment($pdo, [
            'id' => $paymentId,
            'enrollment_id' => $enrollmentId,
            'amount' => 0,
            'wallet_amount' => 0,
            'ref_id' => null,
        ]);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

echo json_encode([
    'enrollmentId' => $enrollmentId,
    'message' => $needsPayment
        ? 'ثبت‌نام انجام شد. برای تکمیل، پرداخت را از بخش «ثبت‌نام‌های من» انجام دهید.'
        : 'ثبت‌نام رایگان با موفقیت انجام شد.',
    'needsPayment' => $needsPayment,
], JSON_UNESCAPED_UNICODE);
