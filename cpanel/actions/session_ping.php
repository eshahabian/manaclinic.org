<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$user = require_login();
$active = post('active') === '1';
if ($active) {
    $_SESSION['last_activity'] = time();
    if (function_exists('staff_tracks_presence') && staff_tracks_presence($user) && function_exists('staff_touch_activity')) {
        staff_touch_activity($pdo, (string) $user['id']);
    }
}

$last = (int) ($_SESSION['last_activity'] ?? time());
$remaining = max(0, auth_idle_seconds() - (time() - $last));

echo json_encode([
    'ok' => true,
    'expired' => false,
    'replaced' => false,
    'remaining' => $remaining,
], JSON_UNESCAPED_UNICODE);
