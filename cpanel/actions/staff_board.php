<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/staff_board.php';

$user = require_login(['SECRETARY', 'ADMIN', 'DOCTOR']);
if (!staff_board_can_access($user)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'دسترسی ندارید.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$userId = (string) ($user['id'] ?? '');

try {
    if ($method === 'GET') {
        $filter = trim((string) ($_GET['filter'] ?? 'open'));
        $items = staff_board_list($pdo, $filter);
        echo json_encode([
            'ok' => true,
            'items' => $items,
            'counts' => staff_board_counts($pdo),
            'filter' => in_array($filter, ['open', 'done', 'all'], true) ? $filter : 'open',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($method !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'متد مجاز نیست.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (!staff_board_can_edit($user)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'ویرایش مجاز نیست.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $action = post('action');
    if ($action === 'create') {
        $item = staff_board_create(
            $pdo,
            $userId,
            post('body'),
            post('is_bold') === '1',
            post('is_highlight') === '1'
        );
        echo json_encode([
            'ok' => true,
            'item' => $item,
            'counts' => staff_board_counts($pdo),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'update') {
        $item = staff_board_update_body($pdo, post('id'), $userId, post('body'));
        echo json_encode([
            'ok' => true,
            'item' => $item,
            'counts' => staff_board_counts($pdo),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'toggle') {
        $force = post('is_done');
        $forceBool = $force === '' ? null : ($force === '1');
        $item = staff_board_toggle_done($pdo, post('id'), $userId, $forceBool);
        echo json_encode([
            'ok' => true,
            'item' => $item,
            'counts' => staff_board_counts($pdo),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'format') {
        $bold = post('is_bold');
        $hl = post('is_highlight');
        $item = staff_board_set_format(
            $pdo,
            post('id'),
            $userId,
            $bold === '' ? null : ($bold === '1'),
            $hl === '' ? null : ($hl === '1')
        );
        echo json_encode([
            'ok' => true,
            'item' => $item,
            'counts' => staff_board_counts($pdo),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'delete') {
        staff_board_delete($pdo, post('id'));
        echo json_encode([
            'ok' => true,
            'counts' => staff_board_counts($pdo),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'عملیات نامعتبر است.'], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage() !== '' ? $e->getMessage() : 'خطا در ذخیره.',
    ], JSON_UNESCAPED_UNICODE);
}
