<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/assistant.php';

header('Content-Type: application/json; charset=utf-8');

if (!assistant_enabled()) {
    http_response_code(403);
    echo json_encode(['error' => 'دستیار فعلاً غیرفعال است.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$user = current_user();
if (!$user || ($user['role'] ?? '') !== 'PATIENT') {
    http_response_code(401);
    echo json_encode([
        'error' => 'برای ارسال شرح‌حال به درمانگر، ابتدا وارد شوید.',
        'loginUrl' => url('/login') . '?next=' . rawurlencode(url('/assistant')),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

ensure_assistant_schema($pdo);
$sessionId = trim(post('sessionId'));
$doctorId = trim(post('doctorId'));

try {
    $session = assistant_session_get($pdo, $sessionId);
    if (!$session) {
        throw new RuntimeException('جلسه گفتگو یافت نشد.');
    }
    if ($session['status'] === 'SENT') {
        echo json_encode([
            'ok' => true,
            'message' => 'شرح‌حال قبلاً ارسال شده است.',
            'reportUrl' => url('/assistant/report?session=' . rawurlencode($sessionId)),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($session['status'] !== 'COMPLETED') {
        throw new RuntimeException('ابتدا گفتگو را کامل کنید.');
    }
    if ($doctorId === '') {
        throw new RuntimeException('یک درمانگر را انتخاب کنید.');
    }

    assistant_send_to_doctor($pdo, $session, (string) $user['id'], $doctorId);

    echo json_encode([
        'ok' => true,
        'message' => 'شرح‌حال به پرونده درمانگر ارسال شد.',
        'reportUrl' => url('/assistant/report?session=' . rawurlencode($sessionId)),
        'doctorPatientsUrl' => url('/dashboard'),
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
