<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/secretary_daily_tasks.php';

$user = require_login();
$role = (string) ($user['role'] ?? '');
$fallback = match ($role) {
    'ADMIN' => '/admin/secretary-tasks',
    'SECRETARY' => '/secretary/secretary-tasks',
    default => '/doctor/secretary-tasks',
};
secretary_assigned_tasks_handle_create($pdo, $user, $fallback);
