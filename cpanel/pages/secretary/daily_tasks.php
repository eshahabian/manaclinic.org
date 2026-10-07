<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/secretary_panel.php';
require_once __DIR__ . '/../../includes/secretary_daily_tasks.php';

require_login(['SECRETARY']);
$requested = trim((string) ($_GET['date'] ?? $_GET['task_date'] ?? date('Y-m-d')));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $requested) || $requested > date('Y-m-d')) {
    $requested = date('Y-m-d');
}
redirect('/secretary/messages?open_tasks=1&task_date=' . rawurlencode($requested));
