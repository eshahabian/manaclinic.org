<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/admin_panel.php';
require_once __DIR__ . '/../../includes/staff_hours_ui.php';

require_login(['ADMIN']);
$slots = staff_hours_collect($pdo);
$pageScripts = staff_hours_scripts();
render_admin_page('ساعت کاری', staff_hours_render($slots, [
    'can_rename' => false,
    'export_base' => '/admin/staff-hours-export',
]));
