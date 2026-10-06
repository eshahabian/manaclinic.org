<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/mentions.php';

$user = require_login(['ADMIN', 'DOCTOR', 'SECRETARY', 'PATIENT']);
$userId = (string) ($user['id'] ?? '');
$role = strtoupper((string) ($user['role'] ?? ''));
$tabPath = mentions_panel_path($role);

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
    csrf_verify();
    $action = post('action');
    if ($action === 'ack') {
        $mid = trim((string) ($_POST['mention_id'] ?? ''));
        mentions_mark_read($pdo, $userId, $mid !== '' ? $mid : null);
        flash_set('success', 'منشن خوانده شد.');
    } elseif ($action === 'ack_all') {
        $n = mentions_mark_read($pdo, $userId, null);
        flash_set('success', $n > 0 ? (to_fa_digits((string) $n) . ' منشن خوانده شد.') : 'منشن خوانده‌نشده‌ای نبود.');
    }
}

redirect($tabPath);
