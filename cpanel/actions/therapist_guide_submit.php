<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once __DIR__ . '/../includes/therapist_guide_results.php';
try {
    csrf_verify();
    if (($_POST['version'] ?? '') !== '20261021m') {
        http_response_code(409);
        echo json_encode(['ok' => false, 'error' => 'سؤال‌ها به‌روز شده‌اند؛ صفحه را تازه کن و راهنما را دوباره شروع کن.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    // Accept the previous form during rollout; the current form records the submit action.
    if (($_POST['action'] ?? '') !== 'show_result' && ($_POST['consent'] ?? '') !== '1') {
        throw new InvalidArgumentException('پاسخ‌ها را با دکمه ثبت پاسخ‌ها و دیدن نتیجه ارسال کن.');
    }
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
        $snapshot['submission_notice'] = 'با زدن ثبت پاسخ‌ها و دیدن نتیجه، پاسخ‌ها و نتیجه برای بررسی توسط تیم مجاز کلینیک ذخیره می‌شوند.';
        $snapshot['submission_action'] = ($_POST['action'] ?? '') === 'show_result' ? 'show_result' : 'legacy_consent';
        $stmt = $pdo->prepare('INSERT INTO therapist_guide_results (id,user_id,domain,version,snapshot_json) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE id=id');
        $stmt->execute([$id, current_user()['id'] ?? null, $snapshot['domain'], '20261021m', json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
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
