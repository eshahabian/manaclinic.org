<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/petty_cash.php';
require_once __DIR__ . '/../includes/doctor_profile_fields.php';
require_once __DIR__ . '/../includes/staff_desk.php';

$user = require_login();
if (!petty_cash_user_allowed($user)) {
    http_response_code(403);
    echo 'دسترسی مجاز نیست.';
    exit;
}

$id = trim((string) ($_GET['id'] ?? ''));
ensure_petty_cash_schema($pdo);
$stmt = $pdo->prepare('SELECT receipt_path FROM petty_cash_entries WHERE id=? LIMIT 1');
$stmt->execute([$id]);
$relative = trim((string) ($stmt->fetchColumn() ?: ''));
if ($relative === '') {
    http_response_code(404);
    echo 'رسید یافت نشد.';
    exit;
}
$abs = staff_receipt_abs($relative);
if (!is_file($abs)) {
    http_response_code(404);
    echo 'فایل رسید روی سرور نیست.';
    exit;
}
$ext = strtolower(pathinfo($abs, PATHINFO_EXTENSION));
$mime = match ($ext) {
    'jpg', 'jpeg' => 'image/jpeg',
    'png' => 'image/png',
    'webp' => 'image/webp',
    'pdf' => 'application/pdf',
    default => 'application/octet-stream',
};
header('Content-Type: ' . $mime);
header('Content-Disposition: inline; filename="petty-' . basename($relative) . '"');
header('X-Content-Type-Options: nosniff');
header('Content-Length: ' . (string) filesize($abs));
readfile($abs);
exit;
