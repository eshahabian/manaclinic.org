<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/secretary_panel.php';
require_login(['SECRETARY']);

$appointmentsDeskNext = '/secretary/appointments';
require __DIR__ . '/../../includes/appointments_desk_ui.php';

$pageScripts = $appointmentsDeskScripts ?? '';
render_secretary_page('نوبت‌ها', $appointmentsDeskHtml ?? '');
