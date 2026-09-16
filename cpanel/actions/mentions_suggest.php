<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/mentions.php';

$user = require_login(['ADMIN', 'DOCTOR', 'SECRETARY', 'PATIENT']);
$q = trim((string) ($_GET['q'] ?? ''));
$items = mentions_suggest($pdo, $user, $q, 12);

echo json_encode([
    'ok' => true,
    'items' => $items,
    'color' => MENTION_TEXT_COLOR,
], JSON_UNESCAPED_UNICODE);
