<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/doctor_panel.php';
require_once __DIR__ . '/../includes/staff_hours_ui.php';

require_doctor_profile($pdo);
$slot = (int) ($_GET['slot'] ?? 0);
staff_hours_send_export($pdo, ($slot === 1 || $slot === 2) ? $slot : null);
