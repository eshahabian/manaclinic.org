<?php
declare(strict_types=1);
require_login(['DOCTOR']);
mark_notifications_read($pdo, (string) current_user()['id']);
flash_set('success', 'پیام‌ها خوانده شدند.');
$next = trim((string) ($_POST['next'] ?? '/doctor'));
if ($next === '' || !str_starts_with($next, '/doctor')) {
    $next = '/doctor';
}
redirect($next);
