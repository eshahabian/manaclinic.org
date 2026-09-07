<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/doctor_panel.php';
require_once __DIR__ . '/../../includes/staff_hours_ui.php';

$ctx = require_doctor_profile($pdo);
$hoursUser = $ctx['user'] ?? [];
$profileUserId = (string) ($ctx['profile']['user_id'] ?? '');
if ($profileUserId !== '' && $profileUserId !== (string) ($hoursUser['id'] ?? '')) {
    $stmt = $pdo->prepare('SELECT id, name, username, role FROM users WHERE id=? LIMIT 1');
    $stmt->execute([$profileUserId]);
    $row = $stmt->fetch();
    if (is_array($row)) {
        $hoursUser = $row;
    }
}

try {
    $block = staff_hours_block_for_user($pdo, is_array($hoursUser) ? $hoursUser : []);
    $inner = staff_hours_render_self($block);
} catch (Throwable $ignored) {
    $inner = '<h1>ساعت کاری من</h1><p class="muted">بارگذاری ساعت کاری الان ممکن نیست. یک‌بار دیگر صفحه را باز کنید.</p>';
}
$pageScripts = staff_hours_scripts();
render_doctor_page('ساعت کاری من', $inner);
