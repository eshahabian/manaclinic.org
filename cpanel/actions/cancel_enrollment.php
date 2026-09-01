<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/workshops.php';

$user = current_user();
if (!$user) {
    http_response_code(401);
    echo json_encode(['error' => 'لطفاً وارد شوید.'], JSON_UNESCAPED_UNICODE);
    exit;
}

ensure_workshop_schema($pdo);
$enrollmentId = post('enrollmentId');
if ($enrollmentId === '') {
    http_response_code(400);
    echo json_encode(['error' => 'شناسه نامعتبر است.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$stmt = $pdo->prepare('SELECT e.*, w.doctor_id FROM workshop_enrollments e JOIN workshops w ON w.id = e.workshop_id WHERE e.id = ?');
$stmt->execute([$enrollmentId]);
$row = $stmt->fetch();
if (!$row) {
    http_response_code(404);
    echo json_encode(['error' => 'ثبت‌نام یافت نشد.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$allowed = false;
if ($user['role'] === 'PATIENT' && $row['patient_id'] === $user['id']) {
    $allowed = true;
} elseif ($user['role'] === 'DOCTOR') {
    $doc = $pdo->prepare('SELECT id FROM doctor_profiles WHERE user_id=? AND id=?');
    $doc->execute([$user['id'], $row['doctor_id']]);
    $allowed = (bool) $doc->fetch();
} elseif ($user['role'] === 'SECRETARY') {
    $allowed = true;
}

if (!$allowed) {
    http_response_code(403);
    echo json_encode(['error' => 'دسترسی مجاز نیست.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo->beginTransaction();
try {
    $result = cancel_workshop_enrollment($pdo, $enrollmentId);
    $pdo->commit();
    $msg = $result['refunded']
        ? 'ثبت‌نام لغو شد و مبلغ به کیف پول شما بازگشت.'
        : ($result['status'] === 'CANCELLED'
            ? 'ثبت‌نام لغو شد. (بازگشت وجه فقط تا ۲۴ ساعت قبل از شروع امکان‌پذیر است.)'
            : 'عملیات انجام شد.');
    echo json_encode(['success' => true, 'message' => $msg, 'refunded' => $result['refunded']], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    $pdo->rollBack();
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
