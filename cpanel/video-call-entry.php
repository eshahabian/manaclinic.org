<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/video_call_engine.php';

if (mana_video_call_engine() !== 'legacy') {
    require __DIR__ . '/livekit-v2.php';
    exit;
}

$room = trim((string) ($_GET['room'] ?? ''));
$media = trim((string) ($_GET['media'] ?? 'video')) === 'audio' ? 'audio' : 'video';
if ($room === '') {
    header('Location: /video-call');
    exit;
}
header('Location: /video-call?room=' . rawurlencode($room) . '&media=' . rawurlencode($media) . '&answer=1&legacy=1');
exit;
