<?php
declare(strict_types=1);

$user = require_login(['PATIENT']);
require_once __DIR__ . '/../../includes/patient_panel.php';
require_once __DIR__ . '/../../includes/workshop_media.php';

ensure_workshop_media_schema($pdo);
$enrollmentId = trim((string) ($_GET['enrollment'] ?? ''));
if ($enrollmentId === '') {
    flash_set('error', 'ثبت‌نام یافت نشد.');
    redirect('/dashboard/courses?type=offline');
}

$enrollment = workshop_media_enrollment_access($pdo, (string) $user['id'], $enrollmentId);
if (!$enrollment) {
    flash_set('error', 'دسترسی به محتوای این دوره آفلاین ندارید.');
    redirect('/dashboard/courses?type=offline');
}

$mediaItems = workshop_media_list($pdo, (string) $enrollment['workshop_id']);
$watermark = trim((string) ($user['username'] ?? ''));
if ($watermark === '') {
    $watermark = trim((string) ($user['name'] ?? 'کاربر'));
}

ob_start();
?>
<div class="stack">
  <a href="<?= e(url('/dashboard/courses?type=offline')) ?>" style="font-size:.9rem;color:var(--primary)">← بازگشت به دوره‌های آفلاین</a>
  <h1><?= e($enrollment['title']) ?></h1>
  <p class="muted">محتوای آفلاین — فقط برای حساب شما قابل مشاهده است.</p>

  <?php if (!$mediaItems): ?>
    <div class="panel">
      <p class="muted">هنوز ویدیو یا فایل صوتی برای این دوره بارگذاری نشده است.</p>
    </div>
  <?php endif; ?>

  <?php foreach ($mediaItems as $item): ?>
    <article class="panel offline-lesson">
      <div class="offline-lesson-head">
        <strong><?= e($item['title']) ?></strong>
        <span class="badge"><?= e(workshop_media_kind_label($item['kind'])) ?></span>
      </div>
      <?php if ($item['description']): ?>
        <p class="offline-lesson-desc"><?= nl2br(e($item['description'])) ?></p>
      <?php endif; ?>

      <?php if ($item['kind'] === 'VIDEO'): ?>
        <div class="wm-video-box">
          <video controls playsinline preload="metadata" src="<?= e(workshop_media_stream_url($item['id'])) ?>"></video>
          <div class="wm-overlay" aria-hidden="true">
            <?php for ($i = 0; $i < 15; $i++): ?>
              <span><?= e($watermark) ?></span>
            <?php endfor; ?>
          </div>
        </div>
      <?php else: ?>
        <div class="offline-audio-box">
          <audio controls preload="metadata" src="<?= e(workshop_media_stream_url($item['id'])) ?>" style="width:100%"></audio>
          <p class="muted offline-audio-wm">شناسه پخش: <?= e($watermark) ?></p>
        </div>
      <?php endif; ?>
    </article>
  <?php endforeach; ?>
</div>
<style>
  .offline-lesson-head { display:flex; flex-wrap:wrap; gap:.5rem; align-items:center; margin-bottom:.5rem; }
  .offline-lesson-desc { font-size:.9rem; line-height:1.7; margin:0 0 .75rem; color:var(--muted); }
  .wm-video-box { position:relative; width:100%; max-width:100%; margin-top:.5rem; border-radius:.75rem; overflow:hidden; background:#000; }
  .wm-video-box video { width:100%; display:block; max-height:70vh; }
  .wm-overlay {
    position:absolute; inset:0; pointer-events:none; z-index:2;
    display:grid; grid-template-columns:repeat(3, 1fr); gap:.75rem;
    align-content:space-around; justify-items:center; overflow:hidden;
  }
  .wm-overlay span {
    color:rgba(255,255,255,.5); font-size:clamp(.6rem, 2.8vw, .85rem);
    transform:rotate(-22deg); text-shadow:0 1px 3px rgba(0,0,0,.85);
    user-select:none; white-space:nowrap;
  }
  .offline-audio-box { margin-top:.5rem; }
  .offline-audio-wm { font-size:.75rem; margin:.35rem 0 0; }
</style>
<script>
document.querySelectorAll(".wm-video-box video").forEach(function(v){
  v.addEventListener("contextmenu", function(e){ e.preventDefault(); });
});
</script>
<?php
render_patient_page('محتوای آفلاین', ob_get_clean());
