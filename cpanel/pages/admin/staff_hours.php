<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/admin_panel.php';
require_once __DIR__ . '/../../includes/staff_hours_ui.php';

require_login(['ADMIN']);
try {
    $slots = staff_hours_collect($pdo);
    $html = staff_hours_render($slots, [
        'can_rename' => false,
        'export_base' => '/admin/staff-hours-export',
    ]);
} catch (Throwable $ignored) {
    $html = '<h1>ساعت کاری منشی‌ها</h1><p class="muted">بارگذاری ساعت کاری الان ممکن نیست. یک‌بار دیگر صفحه را باز کنید.</p>';
}
$pageScripts = staff_hours_scripts();
render_admin_page('ساعت کاری منشی‌ها', $html);
