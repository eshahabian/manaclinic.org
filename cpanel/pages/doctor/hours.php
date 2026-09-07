<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/doctor_panel.php';

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

$fallback = '<h1>ساعت کاری من</h1><p class="muted">بارگذاری ساعت کاری الان ممکن نیست. یک‌بار دیگر صفحه را باز کنید.</p>';
$inner = $fallback;
$pageScripts = '';
try {
    require_once __DIR__ . '/../../includes/staff_hours_ui.php';
    $block = staff_hours_block_for_user($pdo, is_array($hoursUser) ? $hoursUser : []);
    $inner = staff_hours_render_self(is_array($block) ? $block : []);
    if (!is_string($inner) || trim($inner) === '') {
        $inner = $fallback;
    }
    $pageScripts = function_exists('staff_hours_scripts') ? staff_hours_scripts() : '';
} catch (Throwable $e) {
    error_log('staff-hours self: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    $inner = $fallback;
    $pageScripts = '';
}
render_doctor_page('ساعت کاری من', $inner);
