<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/doctor_panel.php';
require_once __DIR__ . '/../../includes/staff_hours_ui.php';

require_doctor_profile($pdo);
$byUser = staff_hours_collect($pdo);
$pageScripts = staff_hours_scripts();
render_doctor_page('ساعت کاری منشی‌ها', staff_hours_render($byUser));
