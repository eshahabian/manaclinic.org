<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/video_call.php';

$user = require_login();
if (!video_call_allowed($user)) {
    video_call_json(['error' => 'اجازه تماس تصویری ندارید.'], 403);
}

ensure_video_call_schema($pdo);
$peer = video_call_peer($pdo, $user);
if (!$peer) {
    video_call_json(['error' => 'طرف مقابل در سیستم پیدا نشد.'], 404);
}

$meId = (string) ($user['id'] ?? '');
$peerId = (string) ($peer['id'] ?? '');
$room = video_call_room_id();
video_call_touch($pdo, $meId);

$raw = file_get_contents('php://input') ?: '';
$body = json_decode($raw, true);
if (!is_array($body)) {
    $body = $_POST;
}
$action = trim((string) ($body['action'] ?? $_GET['action'] ?? 'poll'));

if ($action === 'poll') {
    $after = trim((string) ($body['after'] ?? $_GET['after'] ?? ''));
    $sql = 'SELECT id, kind, payload, created_at FROM video_call_signals WHERE room_id=? AND sender_id=?';
    $params = [$room, $peerId];
    $sql .= ' AND created_at >= DATE_SUB(NOW(), INTERVAL 90 SECOND)';
    $sql .= ' ORDER BY created_at ASC, id ASC LIMIT 80';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $signals = [];
    foreach ($stmt->fetchAll() as $row) {
        $payload = json_decode((string) ($row['payload'] ?? ''), true);
        $signals[] = [
            'id' => (string) $row['id'],
            'kind' => (string) $row['kind'],
            'payload' => is_array($payload) ? $payload : null,
            'created_at' => (string) $row['created_at'],
        ];
    }
    video_call_json([
        'ok' => true,
        'online' => video_call_is_online($pdo, $peerId),
        'signals' => $signals,
        'server_time' => date('Y-m-d H:i:s'),
    ]);
}

if ($action === 'send') {
    csrf_verify();
    $kind = trim((string) ($body['kind'] ?? ''));
    if (!in_array($kind, ['offer', 'answer', 'ice', 'hangup', 'ringing'], true)) {
        video_call_json(['error' => 'نوع پیام نامعتبر است.'], 400);
    }
    $payload = $body['payload'] ?? null;
    $pdo->prepare('INSERT INTO video_call_signals (id, room_id, sender_id, kind, payload) VALUES (?,?,?,?,?)')
        ->execute([
            cuid(),
            $room,
            $meId,
            $kind,
            $payload === null ? null : json_encode($payload, JSON_UNESCAPED_UNICODE),
        ]);
    if ($kind === 'hangup') {
        $pdo->prepare("DELETE FROM video_call_signals WHERE room_id=? AND created_at < DATE_SUB(NOW(), INTERVAL 2 MINUTE)")
            ->execute([$room]);
    }
    video_call_json(['ok' => true]);
}

video_call_json(['error' => 'درخواست نامعتبر است.'], 400);
