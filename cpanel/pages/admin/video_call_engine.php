<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/admin_panel.php';
require_once __DIR__ . '/../../includes/video_call_engine.php';
require_login(['ADMIN']);
$mode = mana_video_call_engine();
ob_start();
?>
<h1>مسیر تماس تصویری</h1>
<p class="muted">از این بخش می‌توانید بدون حذف یا تغییر موتور فعلی، مسیر تماس را بین LiveKit و روش قبلی WebRTC جابه‌جا کنید.</p>
<div class="panel" style="margin-top:1.25rem;max-width:760px">
  <div style="display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap">
    <div>
      <strong>مسیر فعال فعلی</strong>
      <p class="muted" style="margin:.35rem 0 0"><?= $mode === 'legacy' ? 'روش قبلی WebRTC (P2P)' : 'LiveKit' ?></p>
    </div>
    <span class="badge" style="font-size:.9rem"><?= $mode === 'legacy' ? 'WebRTC قدیمی' : 'LiveKit' ?></span>
  </div>
</div>
<div class="grid-2" style="margin-top:1rem;max-width:900px">
  <form class="panel" method="post" action="<?= e(url('/admin/video-call-engine')) ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="engine" value="livekit">
    <h2 style="margin-top:0">LiveKit</h2>
    <p class="muted" style="line-height:1.8">همان مسیر فعلی و سالم تماس که الان روی سایت استفاده می‌شود.</p>
    <button class="btn btn-primary" type="submit"<?= $mode === 'livekit' ? ' disabled' : '' ?>>فعال کردن LiveKit</button>
  </form>
  <form class="panel" method="post" action="<?= e(url('/admin/video-call-engine')) ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="engine" value="legacy">
    <h2 style="margin-top:0">روش قبلی WebRTC</h2>
    <p class="muted" style="line-height:1.8">برای زمانی که بخواهید موقتاً LiveKit را کنار بگذارید و تماس از موتور قبلی P2P اجرا شود.</p>
    <button class="btn btn-outline" type="submit"<?= $mode === 'legacy' ? ' disabled' : '' ?>>فعال کردن روش قبلی</button>
  </form>
</div>
<p class="muted" style="margin-top:1rem">تغییر فقط روی تماس‌های جدید اثر می‌گذارد. تماس در حال اجرا قطع یا جابه‌جا نمی‌شود.</p>
<?php
render_admin_page('مسیر تماس تصویری', ob_get_clean());
