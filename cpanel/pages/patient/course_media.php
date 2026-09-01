<?php
declare(strict_types=1);

$user = require_login(['PATIENT']);
require_once __DIR__ . '/../../includes/patient_panel.php';
require_once __DIR__ . '/../../includes/workshops.php';
require_once __DIR__ . '/../../includes/workshop_media.php';

ensure_workshop_media_schema($pdo);
$enrollmentId = trim((string) ($_GET['enrollment'] ?? ''));
if ($enrollmentId === '') {
    flash_set('error', 'ثبت‌نام یافت نشد.');
    redirect('/dashboard/courses');
}

$enrollment = workshop_media_enrollment_access($pdo, (string) $user['id'], $enrollmentId);
if (!$enrollment) {
    flash_set('error', 'دسترسی به محتوای این کارگاه ندارید.');
    redirect('/dashboard/courses');
}

$mediaItems = workshop_media_list($pdo, (string) $enrollment['workshop_id']);
$mediaCounts = workshop_media_kind_counts_from_list($mediaItems);
$watermark = workshop_media_watermark_for_user($user, $pdo);
$backTab = workshop_courses_tab_for_type((string) $enrollment['type']);
$backUrl = url('/dashboard/courses?type=' . $backTab);

$pageLabels = [
    'OFFLINE' => 'محتوای دوره آفلاین',
    'ONLINE' => 'ضبط جلسات آنلاین',
    'IN_PERSON' => 'ضبط جلسات حضوری',
];
$pageLabel = $pageLabels[$enrollment['type']] ?? 'ضبط جلسات';

$audioStreams = [];
foreach ($mediaItems as $item) {
    if ($item['kind'] === 'AUDIO') {
        $audioStreams[$item['id']] = workshop_media_stream_url((string) $item['id'], $user);
    }
}

ob_start();
?>
<div class="stack offline-course-page">
  <a href="<?= e($backUrl) ?>" style="font-size:.9rem;color:var(--primary)">← بازگشت به دوره‌های من</a>
  <h1><?= e($enrollment['title']) ?></h1>
  <div style="display:flex;flex-wrap:wrap;gap:.5rem;align-items:center;margin-top:.35rem">
    <?= workshop_media_counts_html($mediaCounts, false) ?>
  </div>
  <p class="muted" style="margin-top:.5rem"><?= e($pageLabel) ?> — فقط پخش آنلاین برای حساب شما. لینک‌ها موقت هستند و قابل اشتراک‌گذاری نیستند.</p>

  <?php if (!$mediaItems): ?>
    <div class="panel">
      <p class="muted">هنوز ویدیو یا فایل صوتی برای این کارگاه بارگذاری نشده است.</p>
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
          <video
            controls
            playsinline
            preload="metadata"
            controlsList="nodownload noplaybackrate"
            disablePictureInPicture
            oncontextmenu="return false;"
            src="<?= e(workshop_media_stream_url((string) $item['id'], $user)) ?>"
          ></video>
          <div class="wm-overlay" aria-hidden="true">
            <?php for ($i = 0; $i < 15; $i++): ?>
              <span><?= e($watermark) ?></span>
            <?php endfor; ?>
          </div>
        </div>
      <?php else: ?>
        <div class="offline-audio-box" data-audio-id="<?= e($item['id']) ?>">
          <p class="muted offline-audio-status" id="audio-status-<?= e($item['id']) ?>">برای پخش، دکمه زیر را بزنید.</p>
          <button type="button" class="btn btn-primary btn-sm audio-play-btn" data-audio-id="<?= e($item['id']) ?>">پخش صوت</button>
          <audio
            id="audio-<?= e($item['id']) ?>"
            class="protected-audio"
            controls
            controlsList="nodownload noplaybackrate"
            preload="none"
            oncontextmenu="return false;"
            style="width:100%;margin-top:.5rem;display:none"
          ></audio>
          <p class="muted offline-audio-wm">واترمارک: <?= e($watermark) ?> — دانلود مستقیم غیرفعال است.</p>
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
  .offline-audio-wm { font-size:.75rem; margin:.35rem 0 0; line-height:1.5; }
  .offline-audio-status { font-size:.85rem; margin:0 0 .5rem; }
  .offline-course-page { user-select:none; }
  .offline-course-page .offline-lesson-desc { user-select:text; }
</style>
<?php
$courseMediaContent = ob_get_clean();

$GLOBALS['pageScripts'] = '
<script>
(function(){
  var audioStreams = ' . json_encode($audioStreams, JSON_UNESCAPED_UNICODE) . ';
  var blobUrls = [];

  function revokeAllBlobs() {
    blobUrls.forEach(function(u){ try { URL.revokeObjectURL(u); } catch(e) {} });
    blobUrls = [];
  }
  window.addEventListener("pagehide", revokeAllBlobs);

  document.querySelectorAll(".wm-video-box video").forEach(function(v){
    v.addEventListener("contextmenu", function(e){ e.preventDefault(); });
    v.addEventListener("dragstart", function(e){ e.preventDefault(); });
  });

  document.querySelectorAll(".audio-play-btn").forEach(function(btn){
    btn.addEventListener("click", function(){
      var id = btn.getAttribute("data-audio-id");
      var audio = document.getElementById("audio-" + id);
      var status = document.getElementById("audio-status-" + id);
      var streamUrl = audioStreams[id];
      if (!audio || !streamUrl) return;
      btn.disabled = true;
      if (status) status.textContent = "در حال آماده‌سازی پخش...";
      fetch(streamUrl, { credentials: "same-origin", cache: "no-store" })
        .then(function(res){
          if (!res.ok) throw new Error("stream");
          return res.blob();
        })
        .then(function(blob){
          var blobUrl = URL.createObjectURL(blob);
          blobUrls.push(blobUrl);
          audio.src = blobUrl;
          audio.style.display = "block";
          btn.style.display = "none";
          if (status) status.textContent = "در حال پخش — فقط برای حساب شما.";
          return audio.play();
        })
        .catch(function(){
          btn.disabled = false;
          if (status) status.textContent = "خطا در پخش. صفحه را رفرش کنید.";
        });
    });
  });

  document.querySelectorAll(".protected-audio").forEach(function(audio){
    audio.addEventListener("contextmenu", function(e){ e.preventDefault(); });
    audio.addEventListener("dragstart", function(e){ e.preventDefault(); });
  });
})();
</script>';

render_patient_page($pageLabel, $courseMediaContent);
