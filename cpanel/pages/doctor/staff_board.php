<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/doctor_panel.php';
require_once __DIR__ . '/../../includes/staff_board.php';

$ctx = require_doctor_profile($pdo);
$user = $ctx['user'] ?? current_user();
if (!staff_board_can_access($user)) {
    flash_set('error', 'مشاهده یادداشت مشترک منشی‌ها فقط برای دکتر شیوا گرانمایه‌پور و مدیر مجاز است.');
    redirect('/doctor/notifications');
}
$filter = trim((string) ($_GET['filter'] ?? 'open'));
$html = staff_board_render($pdo, $user, ['filter' => $filter]);
$pageScripts = staff_board_scripts();
render_doctor_page('یادداشت مشترک منشی‌ها', $html);
