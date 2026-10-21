<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once __DIR__ . '/../includes/therapist_guide_results.php';
try {
    csrf_verify();
    if (($_POST['consent'] ?? '') !== '1') throw new InvalidArgumentException('برای ثبت پاسخ‌ها، اطلاعیه دسترسی کلینیک را تأیید کن.');
    $raw = $_POST['answers'] ?? '';
    $nonce = $_POST['submission'] ?? '';
    if (!is_string($raw) || strlen($raw) > 8000 || !is_string($nonce) || !preg_match('/^[a-f0-9]{32}$/D', $nonce)) {
        throw new InvalidArgumentException('درخواست معتبر نیست.');
    }
    $answers = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($answers)) throw new InvalidArgumentException('پاسخ‌ها معتبر نیستند.');
    $snapshot = therapist_guide_snapshot($answers);
    therapist_guide_schema($pdo);
    $id = hash_hmac('sha256', $nonce, csrf_token());
    $existing = $pdo->prepare('SELECT id FROM therapist_guide_results WHERE id=?');
    $existing->execute([$id]);
    if (!$existing->fetchColumn()) {
        if (time() - (int) ($_SESSION['guide_last_submission'] ?? 0) < 15) {
            http_response_code(429);
            echo json_encode(['ok' => false, 'error' => 'چند لحظه صبر کن و دوباره ثبت کن.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $snapshot['doctors'] = therapist_guide_recommendations($pdo, $snapshot);
        $snapshot['route_label'] = doctor_domain_options()[$snapshot['route']] ?? $snapshot['route'];
        $snapshot['consent'] = 'پاسخ‌ها و نتیجه برای عماد شهابیان، عطیه گارسچی و شیوا گرانمایه‌پور قابل مشاهده است.';
        $stmt = $pdo->prepare('INSERT INTO therapist_guide_results (id,user_id,domain,version,snapshot_json) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE id=id');
        $stmt->execute([$id, current_user()['id'] ?? null, $snapshot['domain'], '20261021i', json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
        $_SESSION['guide_last_submission'] = time();
    }
    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
} catch (InvalidArgumentException | JsonException $e) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'پاسخ‌ها ناقص یا نامعتبرند؛ دوباره بررسی کن.'], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('therapist guide save failed: ' . get_class($e));
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'ثبت انجام نشد؛ پاسخ‌ها در همین صفحه باقی مانده‌اند. دوباره تلاش کن.'], JSON_UNESCAPED_UNICODE);
}
