<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/doctor_panel.php';
require_once __DIR__ . '/../../includes/staff_hours_ui.php';

$ctx = require_doctor_profile($pdo);
$slots = staff_hours_collect($pdo);
$pageScripts = staff_hours_scripts();
render_doctor_page('ساعت کاری', staff_hours_render($slots, [
    'can_rename' => (($ctx['user']['role'] ?? '') === 'DOCTOR'),
    'rename_action' => '/doctor/staff-hours',
    'export_base' => '/doctor/staff-hours-export',
]));
