<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/admin_panel.php';

require_login(['ADMIN']);
$fallback = '<h1>ساعت کاری منشی‌ها</h1><p class="muted">بارگذاری ساعت کاری الان ممکن نیست. یک‌بار دیگر صفحه را باز کنید.</p>';
$html = $fallback;
$pageScripts = '';
try {
    require_once __DIR__ . '/../../includes/staff_hours_ui.php';
    $slots = staff_hours_collect($pdo);
    $html = staff_hours_render(is_array($slots) ? $slots : [], [
        'can_rename' => false,
        'export_base' => '/admin/staff-hours-export',
    ]);
    if (!is_string($html) || trim($html) === '') {
        $html = $fallback;
    }
    $pageScripts = function_exists('staff_hours_scripts') ? staff_hours_scripts() : '';
} catch (Throwable $e) {
    error_log('staff-hours admin: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    $html = $fallback;
    $pageScripts = '';
}
render_admin_page('ساعت کاری منشی‌ها', $html);
