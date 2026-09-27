<?php
declare(strict_types=1);

$user = require_login(['PATIENT']);
require_once __DIR__ . '/../../includes/patient_panel.php';
require_once __DIR__ . '/../../includes/workshops.php';
require_once __DIR__ . '/../../includes/workshop_media.php';
require_once __DIR__ . '/../../includes/workshop_path.php';

ensure_workshop_media_schema($pdo);
$enrollmentId = trim((string) ($_GET['enrollment'] ?? ''));
if ($enrollmentId === '') {
    flash_set('error', 'ثبت‌نام یافت نشد.');
    redirect('/dashboard/workshops/mine');
}

$enrollment = workshop_media_enrollment_access($pdo, (string) $user['id'], $enrollmentId);
if (!$enrollment) {
    flash_set('error', 'دسترسی به محتوای این کارگاه ندارید.');
    redirect('/dashboard/workshops/mine');
}
if (workshop_is_offline((string) ($enrollment['type'] ?? ''))) {
    redirect('/dashboard/workshops/path?enrollment=' . rawurlencode($enrollmentId));
}

require_once __DIR__ . '/../../includes/workshop_sessions.php';
workshop_sessions_sync(
    $pdo,
    (string) $enrollment['workshop_id'],
    (string) ($enrollment['type'] ?? 'OFFLINE'),
    (string) ($enrollment['starts_at'] ?? date('Y-m-d H:i:s')),
    (string) ($enrollment['ends_at'] ?? date('Y-m-d H:i:s')),
    [],
    workshop_session_interval_normalize((string) ($enrollment['session_interval'] ?? 'DAILY'))
);
$sessions = workshop_sessions_with_media($pdo, (string) $enrollment['workshop_id']);
$mediaItems = workshop_media_list($pdo, (string) $enrollment['workshop_id']);
$mediaCounts = workshop_media_kind_counts_from_list($mediaItems);
$watermark = workshop_media_watermark_for_user($user, $pdo);
$backTab = workshop_is_archived([
    'status' => (string) ($enrollment['workshop_status'] ?? ''),
    'type' => (string) ($enrollment['type'] ?? ''),
    'ends_at' => (string) ($enrollment['ends_at'] ?? ''),
]) ? 'archive' : workshop_courses_tab_for_type((string) $enrollment['type']);
$backUrl = url('/dashboard/workshops/mine?type=' . $backTab);

$pageLabels = [
    'OFFLINE' => 'محتوای دوره آفلاین',
    'ONLINE' => 'ضبط جلسات آنلاین',
    'IN_PERSON' => 'ضبط جلسات حضوری',
];
$pageLabel = $pageLabels[$enrollment['type']] ?? 'ضبط جلسات';

$audioStreams = [];
foreach ($mediaItems as $item) {
    if (($item['kind'] ?? '') === 'AUDIO' && !empty($item['id'])) {
        $audioStreams[(string) $item['id']] = workshop_media_audio_client_pack(
            (string) $item['id'],
            $user,
            (string) ($item['mime_type'] ?? '')
        );
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
      <p class="muted">هنوز فایلی برای جلسات این کارگاه بارگذاری نشده است.</p>
    </div>
  <?php endif; ?>

  <?php foreach ($sessions as $session): ?>
    <article class="panel offline-lesson">
      <div class="offline-lesson-head">
        <strong><?= e((string) ($session['title'] ?? 'جلسه')) ?></strong>
        <?php if (!empty($session['session_date'])): ?>
          <span class="badge"><?= e(to_jalali_label((string) $session['session_date'])) ?></span>
        <?php endif; ?>
      </div>
      <?php
        $sessionFiles = is_array($session['files'] ?? null) ? $session['files'] : [];
        $hasAny = workshop_media_kind_files($sessionFiles['VIDEO'] ?? null) !== []
            || workshop_media_kind_files($sessionFiles['AUDIO'] ?? null) !== []
            || workshop_media_kind_files($sessionFiles['PDF'] ?? null) !== [];
      ?>
      <?php if (!$hasAny): ?>
        <p class="muted">برای این روز هنوز فایلی بارگذاری نشده است.</p>
      <?php else: ?>
        <?= workshop_path_media_html($sessionFiles, ['user' => $user, 'watermark' => $watermark]) ?>
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
  .wm-pdf-box { margin-top:.75rem; }
  .wm-pdf-frame { position:relative; min-height:28rem; border-radius:.75rem; overflow:hidden; background:#f3f3f3; }
  .wm-pdf-frame iframe { width:100%; height:28rem; border:0; }
  .offline-course-page { user-select:none; }
  .offline-course-page .offline-lesson-desc { user-select:text; }
</style>
<?php
$courseMediaContent = ob_get_clean();

$GLOBALS['pageScripts'] = workshop_offline_protect_script($audioStreams, $watermark);

render_patient_page($pageLabel, $courseMediaContent);
