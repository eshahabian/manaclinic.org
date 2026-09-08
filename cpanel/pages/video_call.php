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
  data-polite="<?= video_call_is_clinician($user) ? '0' : '1' ?>"
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
          <g transform="translate(32 34) scale(1.55) translate(-12 -12)" fill="#22c55e">
            <path d="M6.62 10.79c1.44 2.83 3.76 5.14 6.59 6.59l2.2-2.2c.27-.27.67-.36 1.02-.24 1.12.37 2.33.57 3.57.57.55 0 1 .45 1 1V20c0 .55-.45 1-1 1C7.61 21 0 13.39 0 4c0-.55.45-1 1-1h3.5c.55 0 1 .45 1 1 0 1.25.2 2.45.57 3.57.11.35.03.74-.25 1.02l-2.2 2.2z"/>
          </g>
        </svg>
      </button>
      <button type="button" class="video-call-icon video-call-icon-hang" data-video-hangup hidden aria-label="قطع تماس" title="قطع تماس">
        <svg viewBox="0 0 64 64" fill="none" aria-hidden="true">
          <circle cx="32" cy="32" r="27" stroke="#ef4444" stroke-width="3.6"/>
          <g transform="translate(32 33) scale(1.7) translate(-12 -12)" fill="#ef4444">
            <path d="M12 9c-1.6 0-3.15.25-4.6.72v3.1c0 .39-.23.74-.56.9-.98.49-1.87 1.12-2.66 1.85-.18.18-.43.28-.7.28-.28 0-.53-.11-.71-.29L.29 13.08c-.18-.17-.29-.42-.29-.7 0-.28.11-.53.29-.71C3.34 8.78 7.46 7 12 7s8.66 1.78 11.71 4.67c.18.18.29.43.29.71 0 .28-.11.53-.29.7l-2.48 2.48c-.18.18-.43.29-.71.29-.27 0-.52-.11-.7-.28-.79-.74-1.69-1.36-2.67-1.85-.33-.16-.56-.5-.56-.9v-3.1C15.15 9.25 13.6 9 12 9z"/>
          </g>
        </svg>
      </button>
    </div>
    <div class="video-call-blackout" data-video-blackout hidden>نمایش تصویر در این حالت ممکن نیست</div>
  </div>
</div>
<?php
$inner = ob_get_clean();
$GLOBALS['pageScripts'] = '<script src="' . e(url('/assets/js/video-call.js')) . '?v=20260908h"></script>';

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
