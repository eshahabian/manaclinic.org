<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/doctor_panel.php';
$ctx = require_doctor_profile($pdo);
mark_notifications_read($pdo, doctor_ctx_user_id($ctx));
flash_set('success', 'پیام‌ها خوانده شدند.');
$next = trim((string) ($_POST['next'] ?? '/doctor'));
if ($next === '' || !str_starts_with($next, '/doctor')) {
    $next = '/doctor';
}
redirect($next);
