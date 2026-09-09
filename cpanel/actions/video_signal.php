<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/video_call.php';

$user = require_login();
if (!video_call_allowed($user)) {
    video_call_json(['error' => 'اجازه تماس تصویری ندارید.'], 403);
}

ensure_video_call_schema($pdo);
$meId = (string) ($user['id'] ?? '');
video_call_touch($pdo, $meId);

$raw = file_get_contents('php://input') ?: '';
$body = json_decode($raw, true);
if (!is_array($body)) {
    $body = $_POST;
}
$action = trim((string) ($body['action'] ?? $_GET['action'] ?? 'poll'));

if ($action === 'ping' || $action === 'inbox' || $action === 'contacts') {
    if ($action === 'contacts') {
        if (!video_call_is_clinician($user)) {
            video_call_json(['error' => 'فقط درمانگر می‌تواند جستجو کند.'], 403);
        }
        $q = trim((string) ($body['q'] ?? $_GET['q'] ?? ''));
        $rows = video_call_contacts($pdo, $user, $q, 'PATIENT', 5);
        $items = [];
        foreach ($rows as $c) {
            $items[] = video_call_contact_payload($c);
        }
        video_call_json(['ok' => true, 'items' => $items]);
    }
    $stmt = $pdo->prepare("
      SELECT s.id, s.room_id, s.sender_id, s.kind, s.payload, s.created_at,
             u.name AS sender_name, r.title AS room_title, r.kind AS room_kind
      FROM video_call_signals s
      JOIN users u ON u.id = s.sender_id
      LEFT JOIN video_call_rooms r ON r.room_key = s.room_id
      WHERE s.target_id = ?
        AND s.sender_id <> ?
        AND s.kind IN ('ringing','offer')
        AND s.created_at >= DATE_SUB(NOW(), INTERVAL 45 SECOND)
      ORDER BY s.created_at DESC
      LIMIT 20
    ");
    $stmt->execute([$meId, $meId]);
    $items = [];
    foreach ($stmt->fetchAll() as $row) {
        $items[] = [
            'id' => (string) $row['id'],
            'room' => (string) $row['room_id'],
            'sender_id' => (string) $row['sender_id'],
            'sender_name' => (string) $row['sender_name'],
            'kind' => (string) $row['kind'],
            'title' => (string) ($row['room_title'] ?: $row['sender_name']),
            'room_kind' => (string) ($row['room_kind'] ?? ''),
        ];
    }
    video_call_json(['ok' => true, 'items' => $items]);
}

$roomKey = trim((string) ($body['room'] ?? $_GET['room'] ?? ''));
$joinToken = trim((string) ($body['join'] ?? ''));
$room = null;
if ($joinToken !== '') {
    $room = video_call_join_via_token($pdo, $user, $joinToken);
} elseif ($roomKey !== '') {
    $room = video_call_room_by_key($pdo, $roomKey);
}
if (!$room || !video_call_user_can_access_room($pdo, $user, $room)) {
    video_call_json(['error' => 'اتاق تماس در دسترس نیست.'], 403);
}
$roomKey = (string) $room['room_key'];

if ($action === 'poll') {
    $sql = '
      SELECT id, sender_id, target_id, kind, payload, created_at
      FROM video_call_signals
      WHERE room_id=? AND sender_id<>? AND created_at >= DATE_SUB(NOW(), INTERVAL 45 SECOND)
        AND (target_id IS NULL OR target_id=? OR target_id=\'\')
      ORDER BY created_at ASC, id ASC
      LIMIT 120
    ';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$roomKey, $meId, $meId]);
    $signals = [];
    foreach ($stmt->fetchAll() as $row) {
        $payload = json_decode((string) ($row['payload'] ?? ''), true);
        $signals[] = [
            'id' => (string) $row['id'],
            'from' => (string) $row['sender_id'],
            'to' => (string) ($row['target_id'] ?? ''),
            'kind' => (string) $row['kind'],
            'payload' => is_array($payload) ? $payload : null,
            'created_at' => (string) $row['created_at'],
        ];
    }
    $members = video_call_room_members_public($pdo, $room);
    video_call_json([
        'ok' => true,
        'room' => $roomKey,
        'members' => $members,
        'signals' => $signals,
        'can_start' => video_call_is_clinician($user),
        'server_time' => date('Y-m-d H:i:s'),
    ]);
}

if ($action === 'send') {
    if (is_array($body) && empty(csrf_request_token()) && !empty($body['_csrf'])) {
        $_POST['_csrf'] = (string) $body['_csrf'];
    }
    csrf_verify();
    $kind = trim((string) ($body['kind'] ?? ''));
    if (!in_array($kind, ['offer', 'answer', 'ice', 'hangup', 'ringing', 'join', 'leave'], true)) {
        video_call_json(['error' => 'نوع پیام نامعتبر است.'], 400);
    }
    if ($kind === 'ringing' && !video_call_is_clinician($user)) {
        video_call_json(['error' => 'فقط درمانگر می‌تواند تماس را شروع کند.'], 403);
    }
    $targetId = trim((string) ($body['target_id'] ?? $body['to'] ?? ''));
    if ($targetId === '') {
        $targetId = null;
    }
    $payload = $body['payload'] ?? null;
    if ($kind === 'hangup' && $targetId === null) {
        $pdo->prepare('DELETE FROM video_call_signals WHERE room_id=?')->execute([$roomKey]);
    } elseif ($kind === 'offer' && $targetId) {
        $pdo->prepare("DELETE FROM video_call_signals WHERE room_id=? AND sender_id=? AND target_id=? AND kind IN ('offer','answer','ice')")
            ->execute([$roomKey, $meId, $targetId]);
    }
    $pdo->prepare('
      INSERT INTO video_call_signals (id, room_id, sender_id, target_id, kind, payload)
      VALUES (?,?,?,?,?,?)
    ')->execute([
        cuid(),
        $roomKey,
        $meId,
        $targetId,
        $kind,
        $payload === null ? null : json_encode($payload, JSON_UNESCAPED_UNICODE),
    ]);
    video_call_json(['ok' => true]);
}

video_call_json(['error' => 'درخواست نامعتبر است.'], 400);
