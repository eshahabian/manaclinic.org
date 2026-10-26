<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/video_call.php';
require_once __DIR__.'/../includes/mana_local_call.php';
$user=require_login();
if (!video_call_allowed($user)) { http_response_code(403); exit('اجازه تماس ندارید.'); }
ensure_video_call_schema($pdo);
mana_local_call_schema($pdo);
$room=mana_call_resolve_room($pdo,$user);
if (!$room || !video_call_user_can_access_room($pdo,$user,$room)) { http_response_code(403); exit('این جلسه در دسترس شما نیست.'); }
if (!mana_local_call_start_allowed($pdo,$user,$room)) { http_response_code(403); exit('این مراجع در فهرست فعلی شما نیست.'); }
try { $ice=mana_local_call_ice($user); }
catch (RuntimeException $e) { http_response_code(503); echo e($e->getMessage()); exit; }
if (video_call_is_clinician($user) && empty($_GET['answer'])) {
    // Explicit local start preserves the caller's selected engine even if the
    // global default changed while this page was opening.
    mana_call_bind_room($pdo,$room,'local');
} elseif (mana_call_room_mode($pdo,$room)!=='local') {
    http_response_code(409);exit('روش این جلسه تغییر کرده است. از پیوند جدید وارد شوید.');
}
video_call_touch($pdo,(string)$user['id']);
$pdo->prepare("DELETE FROM mana_local_signals WHERE room_id=? AND target_id=? AND kind='ringing'")
    ->execute([(string)$room['room_key'],(string)$user['id']]);
$media=($_GET['media']??'')==='audio'?'audio':'video';
$clinician=video_call_is_clinician($user);
$csrf=csrf_token();
$nonce=base64_encode(random_bytes(18));
header('Cache-Control: no-store');
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-$nonce'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; media-src 'self' blob:; connect-src 'self'; frame-ancestors 'self'; base-uri 'none'; form-action 'self'");
if (session_status()===PHP_SESSION_ACTIVE) session_write_close();
?><!doctype html>
<html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="csrf-token" content="<?=e($csrf)?>"><meta name="robots" content="noindex,nofollow"><title>تماس مانا — سرور داخلی</title>
<link rel="stylesheet" href="<?=e(url('/assets/css/video-call-local.css'))?>?v=20261025">
</head><body>
<main data-video-call data-signal-url="<?=e(url('/video-signal-local'))?>" data-room="<?=e((string)$room['room_key'])?>"
 data-me="<?=e((string)$user['id'])?>" data-peer-name="<?=e((string)$room['title'])?>" data-media="<?=e($media)?>"
 data-can-start="<?=$clinician?'1':'0'?>" data-group="<?=$room['kind']==='direct'?'0':'1'?>" data-can-record="0"
 data-ring-url="<?=e(url('/assets/audio/incoming-call.ogg'))?>" data-hang-url="<?=e(url('/assets/audio/hang-up.ogg'))?>">
 <header><h1>تماس مانا</h1><p>سرور داخلی · <?=e((string)$room['title'])?></p><a href="<?=e(url('/video-call'))?>">بازگشت</a></header>
 <p data-video-status role="status">آمادهٔ اتصال</p>
 <section data-video-permit hidden><p>برای تماس اجازهٔ دوربین و میکروفون را بدهید.</p><button data-video-permit-btn>اجازهٔ دسترسی و ورود</button></section>
 <section data-video-stage class="stage"><div data-video-remotes class="remotes"></div>
 <video data-video-local class="self" autoplay playsinline muted></video><p data-video-incoming hidden>تماس ورودی</p></section>
 <nav><button data-video-start>شروع / پاسخ تماس</button><button data-video-hangup hidden>قطع تماس</button>
 <button data-video-fs>تمام‌صفحه</button></nav>
 <p>این روش از سرویس خارجی استفاده نمی‌کند. اگر صدا فعال نشد، یک بار روی تصویر بزنید.</p>
</main>
<script nonce="<?=e($nonce)?>">window.__MANA_LOCAL_AUTO_ANSWER__=<?=$clinician?'false':'true'?>;window.__VIDEO_ICE__=<?=json_encode($ice,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>;</script>
<script src="<?=e(url('/assets/js/video-call-local.js'))?>?v=20261025"></script>
</body></html>
