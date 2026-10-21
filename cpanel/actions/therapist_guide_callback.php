<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once __DIR__ . '/../includes/consult_requests.php';
try {
    csrf_verify();
    $nonce = $_POST['submission'] ?? '';
    $name = $_POST['name'] ?? '';
    $phone = $_POST['phone'] ?? '';
    if (!is_string($nonce) || !preg_match('/^[a-f0-9]{32}$/D', $nonce)
        || !is_string($name) || !is_string($phone) || mb_strlen(trim($name)) < 3
        || mb_strlen($name) > 100 || strlen($phone) > 40) {
        throw new InvalidArgumentException('نام و شماره تماس را درست وارد کن.');
    }
    $name = trim($name);
    $phone = consult_normalize_phone($phone);
    if ($phone === '') throw new InvalidArgumentException('شماره موبایل را درست وارد کن؛ مانند ۰۹۱۲۱۲۳۴۵۶۷.');
    // A callback can only refer to a completed result from this browser session.
    $resultId = hash_hmac('sha256', $nonce, csrf_token());
    $result = $pdo->prepare('SELECT id FROM therapist_guide_results WHERE id=? LIMIT 1');
    $result->execute([$resultId]);
    if (!$result->fetchColumn()) {
        throw new InvalidArgumentException('ابتدا سؤال‌ها را کامل کن و نتیجه را ثبت کن.');
    }
    ensure_consult_requests_schema($pdo);
    // Only contact details enter the secretary queue; no answers, route or analysis.
    // Stable ID makes retries safe without creating duplicate callback requests.
    $requestId = substr(hash('sha256', 'guide-callback:' . $resultId), 0, 24);
    $request = $pdo->prepare('SELECT id FROM consult_requests WHERE id=?');
    $request->execute([$requestId]);
    if (!$request->fetchColumn()) {
        if (function_exists('throttle_too_many') && throttle_too_many('guide_callback', 5, 600)) {
            http_response_code(429);
            echo json_encode(['ok' => false, 'error' => 'چند درخواست پشت سر هم ثبت شده؛ کمی بعد دوباره تلاش کن.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $pdo->prepare('INSERT INTO consult_requests (id,name,phone,message,status) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE id=id')
            ->execute([$requestId, $name, $phone, 'درخواست تماس پس از راهنمای انتخاب درمانگر', 'new']);
        if (function_exists('throttle_hit')) throttle_hit('guide_callback', 600);
    }
    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
} catch (InvalidArgumentException $e) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('therapist guide callback failed: ' . get_class($e));
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'درخواست تماس ثبت نشد؛ دوباره تلاش کن.'], JSON_UNESCAPED_UNICODE);
}
