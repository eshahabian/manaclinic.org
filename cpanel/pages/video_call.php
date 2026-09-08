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
    <div class="video-call-self">
      <video class="video-call-local" data-video-local autoplay playsinline muted disablepictureinpicture controlslist="nodownload noremoteplayback"></video>
    </div>
    <p class="video-call-incoming" data-video-incoming hidden>تماس ورودی از <?= e((string) $peer['name']) ?></p>
    <div class="video-call-tools">
      <button type="button" class="video-call-icon video-call-icon-tool" data-video-enhance aria-label="واضح‌تر کردن تصویر" aria-pressed="false" title="واضح‌تر کردن تصویر">
        <svg viewBox="0 0 64 64" fill="none" aria-hidden="true">
          <circle cx="32" cy="32" r="27" stroke="currentColor" stroke-width="3.2"/>
          <path fill="currentColor" d="M32 14.5l2.1 6.3 6.4.2-5.1 4 1.8 6.4L32 27.8l-5.2 3.6 1.8-6.4-5.1-4 6.4-.2z"/>
          <path fill="currentColor" d="M46.5 33.5l1.2 3.4 3.5.1-2.8 2.1 1 3.4-2.9-2-2.8 2 1-3.4-2.8-2.1 3.5-.1z"/>
          <path fill="currentColor" d="M17.8 36.2l1.1 2.8 2.9.1-2.3 1.8.8 2.8-2.4-1.6-2.4 1.6.8-2.8-2.3-1.8 2.9-.1z"/>
        </svg>
      </button>
      <button type="button" class="video-call-icon video-call-icon-tool" data-video-fs aria-label="تمام‌صفحه" title="تمام‌صفحه">
        <svg class="video-call-fs-enter" viewBox="0 0 64 64" fill="none" aria-hidden="true">
          <circle cx="32" cy="32" r="27" stroke="currentColor" stroke-width="3.2"/>
          <path stroke="currentColor" stroke-width="3.2" stroke-linecap="round" stroke-linejoin="round" d="M23 27V21h6M41 27V21h-6M23 37v6h6M41 37v6h-6"/>
        </svg>
        <svg class="video-call-fs-exit" viewBox="0 0 64 64" fill="none" aria-hidden="true">
          <circle cx="32" cy="32" r="27" stroke="currentColor" stroke-width="3.2"/>
          <path stroke="currentColor" stroke-width="3.2" stroke-linecap="round" stroke-linejoin="round" d="M27 21v6h-6M37 21v6h6M27 43v-6h-6M37 43v-6h6"/>
        </svg>
      </button>
      <?php if (video_call_is_clinician($user)): ?>
        <button type="button" class="video-call-icon video-call-icon-tool" data-video-record hidden aria-label="شروع ضبط" title="ضبط تماس">
          <svg viewBox="0 0 64 64" fill="none" aria-hidden="true">
            <circle cx="32" cy="32" r="27" stroke="currentColor" stroke-width="3.2"/>
            <circle cx="32" cy="32" r="10" fill="currentColor"/>
          </svg>
        </button>
      <?php endif; ?>
    </div>
    <div class="video-call-dock">
      <button type="button" class="video-call-icon video-call-icon-call" data-video-start aria-label="شروع تماس" title="شروع تماس">
        <svg viewBox="0 0 64 64" fill="none" aria-hidden="true">
          <circle cx="32" cy="32" r="27" stroke="#22c55e" stroke-width="3.6"/>
          <g transform="rotate(-42 33 33)">
            <path fill="#22c55e" d="M27.2 21.6c1.4-1.5 3.8-1.5 5.2 0l1.8 2c.9 1 .9 2.5.1 3.6l-1.4 2c-.3.5-.3 1 0 1.4 1.5 2.4 3.7 4.6 6.1 6.1.4.3 1 .3 1.4 0l2-1.4c1.1-.8 2.6-.8 3.6.1l2 1.8c1.5 1.4 1.5 3.8 0 5.2l-1.3 1.3c-1.4 1.4-3.5 1.8-5.4 1-4.7-1.9-8.9-5.2-12.3-9.8-3.3-4.4-5.4-9.5-6-14.6-.3-2 .6-4 2.1-5.4l1.3-1.3z"/>
          </g>
          <path stroke="#22c55e" stroke-width="2.3" stroke-linecap="round" d="M41.2 15.8c2.3 1.1 4.2 2.8 5.4 5M43.4 13.4c3.1 1.5 5.6 3.8 7.2 6.8M45.6 11c3.9 1.9 7.1 4.9 9.1 8.6"/>
        </svg>
      </button>
      <button type="button" class="video-call-icon video-call-icon-hang" data-video-hangup hidden aria-label="قطع تماس" title="قطع تماس">
        <svg viewBox="0 0 64 64" fill="none" aria-hidden="true">
          <circle cx="32" cy="32" r="27" stroke="#ef4444" stroke-width="3.6"/>
          <path fill="#ef4444" d="M18.5 34.2c1.1-1.8 3.4-2.6 5.4-1.9l3.2 1.1c.9.3 1.5 1.1 1.6 2.1l.2 2.8c.1.7.6 1.2 1.3 1.3 2.6.5 5.3.5 7.9 0 .7-.1 1.2-.6 1.3-1.3l.2-2.8c.1-1 .7-1.8 1.6-2.1l3.2-1.1c2-.7 4.3.1 5.4 1.9l1.2 1.8c1.1 1.7.7 4-1 5.2-3.8 2.7-8.8 4.2-16.2 4.2s-12.4-1.5-16.2-4.2c-1.7-1.2-2.1-3.5-1-5.2l1.2-1.8z"/>
        </svg>
      </button>
    </div>
    <div class="video-call-blackout" data-video-blackout hidden>نمایش تصویر در این حالت ممکن نیست</div>
  </div>
</div>
<?php
$inner = ob_get_clean();
$GLOBALS['pageScripts'] = '<script src="' . e(url('/assets/js/video-call.js')) . '?v=20260908g"></script>';

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
