<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/livekit.php';
require_once __DIR__ . '/../includes/video_call.php';

$user = require_login();
if (!video_call_allowed($user)) {
    video_call_json(['ok' => false, 'error' => 'دسترسی به تماس برای این حساب فعال نیست.'], 403);
}

$raw = file_get_contents('php://input');
$body = json_decode((string) $raw, true);
if (!is_array($body)) {
    $body = $_POST;
}

$roomKey = trim((string) ($body['room'] ?? ''));
if ($roomKey === '') {
    video_call_json(['ok' => false, 'error' => 'اتاق مشخص نشده است.'], 422);
}

ensure_video_call_schema($pdo);
$room = video_call_room_by_key($pdo, $roomKey);
if (!$room || !video_call_user_can_access_room($pdo, $user, $room)) {
    video_call_json(['ok' => false, 'error' => 'این جلسه در دسترس شما نیست.'], 403);
}

if (!mana_livekit_ready()) {
    video_call_json([
        'ok' => false,
        'error' => 'LiveKit هنوز روی هاست تنظیم نشده است.',
        'code' => 'LIVEKIT_NOT_CONFIGURED',
    ], 503);
}

try {
    // LiveKit uses the immutable database room ID, not the visible room key.
    // The room key/number may therefore change later without splitting a call.
    $stableRoomId = trim((string) ($room['id'] ?? ''));
    if ($stableRoomId === '') {
        throw new RuntimeException('شناسه پایدار جلسه موجود نیست.');
    }
    $token = mana_livekit_token($user, $stableRoomId);
    video_call_json([
        'ok' => true,
        'serverUrl' => $token['serverUrl'],
        'participantToken' => $token['participantToken'],
        'roomName' => $token['roomName'],
        'identity' => $token['identity'],
        'expiresAt' => $token['expiresAt'],
    ]);
} catch (Throwable $e) {
    video_call_json(['ok' => false, 'error' => 'ساخت دسترسی تماس ممکن نشد.'], 500);
}
