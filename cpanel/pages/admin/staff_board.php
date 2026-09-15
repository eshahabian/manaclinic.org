<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/admin_panel.php';
require_once __DIR__ . '/../../includes/staff_board.php';

$user = require_login(['ADMIN']);
$filter = trim((string) ($_GET['filter'] ?? 'open'));
$html = staff_board_render($pdo, $user, ['filter' => $filter]);
$pageScripts = staff_board_scripts();
render_admin_page('یادداشت مشترک منشی‌ها', $html);
