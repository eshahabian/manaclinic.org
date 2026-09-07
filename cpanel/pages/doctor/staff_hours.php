<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/doctor_panel.php';
require_once __DIR__ . '/../../includes/staff_hours_ui.php';

$ctx = require_doctor_profile($pdo);
try {
    $slots = staff_hours_collect($pdo);
    $html = staff_hours_render($slots, [
        'can_rename' => (($ctx['user']['role'] ?? '') === 'DOCTOR'),
        'rename_action' => '/doctor/staff-hours',
        'export_base' => '/doctor/staff-hours-export',
    ]);
} catch (Throwable $ignored) {
    $html = '<h1>ساعت کاری منشی‌ها</h1><p class="muted">بارگذاری ساعت کاری الان ممکن نیست. یک‌بار دیگر صفحه را باز کنید.</p>';
}
$pageScripts = staff_hours_scripts();
render_doctor_page('ساعت کاری منشی‌ها', $html);
