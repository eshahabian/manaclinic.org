<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/doctor_panel.php';
require_once __DIR__ . '/../../includes/staff_handover_copies.php';

$ctx = require_doctor_profile($pdo);
$userId = (string) $ctx['user']['id'];
mark_notifications_read_kinds($pdo, $userId, ['handover_copy', 'handover']);
$rows = fetch_staff_message_copies($pdo, $userId, 80);
render_doctor_page('پیام‌ها', staff_handover_copies_render($rows, '/doctor/staff-messages', false));
