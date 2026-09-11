<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/video_call_engine.php';
require_login(['ADMIN']);
$mode = post('engine');
if (!in_array($mode, ['livekit', 'legacy'], true)) {
    flash_set('error', 'مسیر تماس نامعتبر است.');
    redirect('/admin/video-call-engine');
}
if (!mana_video_call_engine_set($mode)) {
    flash_set('error', 'ذخیره مسیر تماس ممکن نشد. سطح دسترسی پوشه storage را بررسی کنید.');
    redirect('/admin/video-call-engine');
}
flash_set('success', $mode === 'legacy' ? 'مسیر تماس روی WebRTC قبلی قرار گرفت.' : 'مسیر تماس روی LiveKit قرار گرفت.');
redirect('/admin/video-call-engine');
