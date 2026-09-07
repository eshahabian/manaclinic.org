<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/admin_panel.php';

require_login(['ADMIN']);
$who = trim((string) ($_GET['who'] ?? ''));
if ($who === '') {
    $slot = (int) ($_GET['slot'] ?? 0);
    $who = ($slot === 1 || $slot === 2) ? ('sec-' . $slot) : '';
}
try {
    require_once __DIR__ . '/../includes/staff_hours_ui.php';
    staff_hours_send_export($pdo, $who !== '' ? $who : null);
} catch (Throwable $e) {
    error_log('staff-hours export admin: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'خروجی الان ممکن نیست.';
    exit;
}
