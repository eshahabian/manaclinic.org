<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/video_call.php';

$user = require_login();
if (!video_call_allowed($user)) {
    flash_set('error', 'این بخش آزمایشی فقط برای دو کاربر مشخص فعال است.');
    redirect(panel_href_for($user) ?: '/');
}

ensure_video_call_schema($pdo);
$peer = video_call_peer($pdo, $user);
if (!$peer) {
    flash_set('error', 'طرف مقابل هنوز در سیستم ثبت نشده است.');
    redirect(panel_href_for($user) ?: '/');
}

$peerOnline = video_call_is_online($pdo, (string) $peer['id']);
$pageTitle = 'تماس تصویری آزمایشی';
$pageDescription = 'تماس تصویری آزمایشی مانا کلینیک.';
$GLOBALS['pageRobots'] = 'noindex,nofollow';
$GLOBALS['pageDescription'] = $pageDescription;

ob_start();
?>
<div class="stack video-call-page<?= video_call_is_clinician($user) ? '' : ' is-guard' ?>" data-video-call
  data-signal-url="<?= e(url('/video-signal')) ?>"
  data-peer-name="<?= e((string) $peer['name']) ?>"
  data-can-record="<?= video_call_is_clinician($user) ? '1' : '0' ?>"
  data-guard-capture="<?= video_call_is_clinician($user) ? '0' : '1' ?>"
>
  <h1>تماس تصویری آزمایشی</h1>
  <p class="muted" style="margin:0;line-height:1.8">فقط بین شما و <?= e((string) $peer['name']) ?> فعال است.</p>
  <p class="video-call-status" data-video-status>
    <?= $peerOnline ? 'طرف مقابل آنلاین است.' : 'طرف مقابل فعلاً در این صفحه نیست — هر دو باید این صفحه را باز کنید.' ?>
  </p>
  <div class="video-call-permit" data-video-permit>
    <p>برای تماس، مرورگر باید به دوربین و میکروفون دسترسی بدهد.</p>
    <button type="button" class="btn btn-primary" data-video-permit-btn>اجازه دسترسی به دوربین و میکروفون</button>
  </div>

  <div class="video-call-stage" data-video-stage>
    <video class="video-call-remote" data-video-remote autoplay playsinline disablepictureinpicture controlslist="nodownload noremoteplayback"></video>
    <div class="video-call-incoming" data-video-incoming hidden>
      <p>تماس ورودی از <?= e((string) $peer['name']) ?></p>
      <button type="button" class="btn btn-primary" data-video-accept>پاسخ</button>
      <button type="button" class="btn btn-outline" data-video-decline>رد</button>
    </div>
    <button type="button" class="video-call-fs" data-video-fs aria-label="تمام‌صفحه">تمام‌صفحه</button>
    <div class="video-call-blackout" data-video-blackout hidden>نمایش تصویر در این حالت ممکن نیست</div>
  </div>
  <div class="video-call-self">
    <video class="video-call-local" data-video-local autoplay playsinline muted disablepictureinpicture controlslist="nodownload noremoteplayback"></video>
    <span>تصویر شما</span>
  </div>

  <div class="video-call-actions">
    <button type="button" class="btn btn-primary" data-video-start>شروع تماس</button>
    <button type="button" class="btn btn-outline" data-video-hangup hidden>قطع تماس</button>
    <?php if (video_call_is_clinician($user)): ?>
      <button type="button" class="btn btn-outline" data-video-record hidden>شروع ضبط</button>
    <?php endif; ?>
    <button type="button" class="btn btn-outline" data-video-fs-btn>تمام‌صفحه</button>
  </div>
</div>
<?php
$inner = ob_get_clean();
$GLOBALS['pageScripts'] = '<script src="' . e(url('/assets/js/video-call.js')) . '?v=20260908f"></script>';

$role = (string) ($user['role'] ?? '');
if ($role === 'DOCTOR') {
    require_once __DIR__ . '/../includes/doctor_panel.php';
    render_doctor_page($pageTitle, $inner);
    return;
}
if ($role === 'ADMIN') {
    require_once __DIR__ . '/../includes/admin_panel.php';
    render_admin_page($pageTitle, $inner);
    return;
}
if ($role === 'SECRETARY') {
    require_once __DIR__ . '/../includes/secretary_panel.php';
    render_secretary_page($pageTitle, $inner);
    return;
}
if ($role === 'PATIENT') {
    require_once __DIR__ . '/../includes/patient_panel.php';
    render_patient_page($pageTitle, $inner);
    return;
}

$GLOBALS['pageTitle'] = $pageTitle;
$GLOBALS['content'] = $inner;
require __DIR__ . '/../includes/layout.php';
