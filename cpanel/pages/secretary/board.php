<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/secretary_panel.php';
require_once __DIR__ . '/../../includes/staff_board.php';

$user = staff_board_require_user();
$filter = trim((string) ($_GET['filter'] ?? 'open'));
$html = staff_board_render($pdo, $user, ['filter' => $filter]);
$pageScripts = staff_board_scripts();
render_secretary_page('یادداشت مشترک', $html);
