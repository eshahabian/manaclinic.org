<?php
declare(strict_types=1);

$doctor = trim((string) ($_GET['doctor'] ?? ''));
redirect('/secretary/therapists' . ($doctor !== '' ? '?open=' . rawurlencode($doctor) : ''));
