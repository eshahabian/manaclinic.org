<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/admin_staff_messages.php';

$user = require_login(['SECRETARY', 'ADMIN']);
$messageId = trim((string) ($_GET['id'] ?? ''));
if ($messageId === '' || !admin_staff_msg_user_can_view_image($pdo, $user, $messageId)) {
    http_response_code(404);
    echo 'یافت نشد';
    exit;
}

$msg = admin_staff_msg_get($pdo, $messageId);
$path = trim((string) ($msg['image_path'] ?? ''));
if ($path === '') {
    http_response_code(404);
    echo 'یافت نشد';
    exit;
}

$abs = admin_staff_msg_abs($path);
if (!is_file($abs)) {
    http_response_code(404);
    echo 'یافت نشد';
    exit;
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = (string) ($finfo->file($abs) ?: 'application/octet-stream');
header('Content-Type: ' . $mime);
header('Content-Length: ' . (string) filesize($abs));
header('Cache-Control: private, max-age=3600');
header('X-Content-Type-Options: nosniff');
readfile($abs);
exit;
