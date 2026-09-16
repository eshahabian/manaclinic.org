<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/mentions.php';
require_once __DIR__ . '/../includes/workshop_path.php';

$user = require_login(['ADMIN', 'DOCTOR', 'SECRETARY', 'PATIENT']);
$q = trim((string) ($_GET['q'] ?? ''));
$scope = trim((string) ($_GET['scope'] ?? ''));
$scopeId = trim((string) ($_GET['scope_id'] ?? ''));
$items = mentions_suggest($pdo, $user, $q, 12, $scope, $scopeId);

echo json_encode([
    'ok' => true,
    'items' => $items,
    'color' => MENTION_TEXT_COLOR,
    'scope' => $scope,
    'scope_id' => $scopeId,
], JSON_UNESCAPED_UNICODE);
