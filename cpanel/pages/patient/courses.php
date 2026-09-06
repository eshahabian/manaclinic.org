<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/helpers.php';

$view = trim((string) ($_GET['view'] ?? ''));
if ($view === 'requested') {
    redirect('/dashboard/workshops/requested');
}
if ($view === 'mine') {
    redirect('/dashboard/workshops/mine');
}
$type = trim((string) ($_GET['type'] ?? $_GET['tab'] ?? ''));
$suffix = in_array($type, ['in-person', 'online', 'offline', 'archive'], true) ? ('?type=' . rawurlencode($type)) : '';
redirect('/dashboard/workshops' . $suffix);
