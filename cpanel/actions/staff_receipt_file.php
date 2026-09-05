<?php
declare(strict_types=1);

$user = require_login(['SECRETARY', 'DOCTOR', 'ADMIN']);

$paymentId = trim((string) ($_GET['id'] ?? ''));
if ($paymentId === '') {
    http_response_code(404);
    echo 'فیش یافت نشد.';
    exit;
}

$stmt = $pdo->prepare('SELECT receipt_path FROM payments WHERE id=? LIMIT 1');
$stmt->execute([$paymentId]);
$row = $stmt->fetch();
$relative = trim((string) ($row['receipt_path'] ?? ''));
if ($relative === '') {
    http_response_code(404);
    echo 'فیشی برای این پرداخت ثبت نشده است.';
    exit;
}

$abs = staff_receipt_abs($relative);
if (!is_file($abs)) {
    http_response_code(404);
    echo 'فایل فیش روی سرور نیست.';
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
header('Content-Disposition: inline; filename="receipt-' . basename($relative) . '"');
header('X-Content-Type-Options: nosniff');
header('Content-Length: ' . (string) filesize($abs));
readfile($abs);
exit;
