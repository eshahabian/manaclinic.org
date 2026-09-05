<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$user = require_login(['SECRETARY']);
$active = post('active') === '1';
if ($active) {
    staff_touch_activity($pdo, (string) $user['id']);
}

$shift = staff_current_shift($pdo, (string) $user['id']);
$last = (int) ($_SESSION['last_activity'] ?? time());
$remaining = max(0, staff_idle_seconds() - (time() - $last));

echo json_encode([
    'ok' => true,
    'expired' => false,
    'remaining' => $remaining,
    'elapsed' => $shift ? staff_shift_seconds($shift) : 0,
    'started_at' => $shift['started_at'] ?? null,
], JSON_UNESCAPED_UNICODE);
