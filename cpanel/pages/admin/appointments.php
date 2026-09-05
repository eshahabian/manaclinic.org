<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/admin_panel.php';
require_once __DIR__ . '/../../includes/user_cleanup.php';
require_login(['ADMIN']);

$appointmentsDeskAdminTools = true;
$appointmentsDeskNext = '/admin/appointments';
require __DIR__ . '/../../includes/appointments_desk_ui.php';

$pageScripts = $appointmentsDeskScripts ?? '';
render_admin_page('نوبت‌ها', $appointmentsDeskHtml ?? '');
