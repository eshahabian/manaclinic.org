<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/video_call.php';
require_once __DIR__ . '/../includes/workshops.php';
require_once __DIR__ . '/../includes/doctor_profile_fields.php';

$user = require_login();
if (!video_call_allowed($user)) {
    flash_set('error', 'تماس مانا برای حساب شما فعال نیست.');
    redirect(panel_href_for($user) ?: '/');
}

ensure_video_call_schema($pdo);
video_call_touch($pdo, (string) ($user['id'] ?? ''));
$clinician = video_call_is_clinician($user);

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && $clinician) {
    csrf_verify();
    $action = post('action');
    if ($action === 'create_group') {
        try {
            $memberIds = $_POST['members'] ?? [];
            if (!is_array($memberIds)) {
                $memberIds = [];
            }
            $room = video_call_create_group($pdo, $user, post('title'), $memberIds, post('workshop'));
            $picked = count(array_filter($memberIds));
            flash_set('success', $picked > 0
                ? 'گروه ذخیره شد و افراد انتخاب‌شده به جلسه اضافه شدند.'
                : 'گروه ذخیره شد. هر هفته می‌توانید وارد همین جلسه شوید.');
            redirect('/video-call?room=' . rawurlencode((string) $room['room_key']));
        } catch (RuntimeException $e) {
            flash_set('error', $e->getMessage());
            redirect('/video-call');
        }
    }
    if ($action === 'delete_group') {
        try {
            video_call_delete_hosted_room($pdo, $user, post('room'));
            flash_set('success', 'گروه حذف شد.');
        } catch (RuntimeException $e) {
            flash_set('error', $e->getMessage());
        }
        redirect('/video-call');
    }
}

$joinToken = trim((string) ($_GET['join'] ?? ''));
$workshopId = trim((string) ($_GET['workshop'] ?? ''));
$peerId = trim((string) ($_GET['peer'] ?? ''));
$roomKey = trim((string) ($_GET['room'] ?? ''));
$media = trim((string) ($_GET['media'] ?? 'video')) === 'audio' ? 'audio' : 'video';
$room = null;

if ($joinToken !== '') {
    $room = video_call_join_via_token($pdo, $user, $joinToken);
    if (!$room) {
        flash_set('error', 'لینک جلسه نامعتبر است.');
        redirect('/video-call');
    }
    redirect('/video-call?room=' . rawurlencode((string) $room['room_key']));
}

if ($workshopId !== '') {
    $room = video_call_ensure_workshop_room($pdo, $workshopId, (string) $user['id']);
    if (!$room || !video_call_user_can_access_room($pdo, $user, $room)) {
        flash_set('error', 'جلسه آنلاین این کارگاه در دسترس نیست.');
        redirect('/video-call');
    }
    $roomKey = (string) $room['room_key'];
}

if ($peerId !== '') {
    if (!$clinician) {
        flash_set('error', 'فقط درمانگر می‌تواند تماس را شروع کند. منتظر تماس بمانید.');
        redirect('/video-call');
    }
    $room = video_call_ensure_direct_room($pdo, $user, $peerId);
    if (!$room) {
        flash_set('error', 'مخاطب یافت نشد.');
        redirect('/video-call');
    }
    $roomKey = (string) $room['room_key'];
}

if ($roomKey !== '') {
    $room = $room ?: video_call_room_by_key($pdo, $roomKey);
    if (!$room || !video_call_user_can_access_room($pdo, $user, $room)) {
        flash_set('error', 'این جلسه در دسترس شما نیست.');
        redirect('/video-call');
    }
}

$pageTitle = 'تماس مانا';
$GLOBALS['pageRobots'] = 'noindex,nofollow';
$q = trim((string) ($_GET['q'] ?? ''));
$contacts = video_call_contacts($pdo, $user, '', $clinician ? 'DOCTOR' : '', 40);
$patients = $clinician ? video_call_contacts($pdo, $user, $q !== '' ? $q : '', 'PATIENT', 80) : [];
$workshops = $clinician ? video_call_host_workshops($pdo, $user) : [];
$savedRooms = video_call_saved_rooms($pdo, $user);
$members = $room ? video_call_room_members_public($pdo, $room) : [];
$session = $room ? video_call_session_payload($pdo, $user, $room, $media) : null;
$shareUrl = (string) ($session['shareUrl'] ?? '');
$peerName = (string) ($session['title'] ?? 'جلسه');
$callActive = $room && !empty($session['room']);

ob_start();
$wmName = $peerName !== '' ? $peerName : 'جلسه';
?>
<div class="vc-shell<?= $clinician ? ' vc-skype' : '' ?>"<?php if ($clinician): ?> data-vc-app data-signal-url="<?= e(url('/video-signal')) ?>"<?php endif; ?>>
<div class="vc-lobby">
  <?php if ($clinician): ?>
    <?php require __DIR__ . '/../includes/video_call_skype_lobby.php'; ?>
  <?php else: ?>
  <div class="vc-lobby-side">
    <div class="vc-lobby-brand">
      <img src="<?= e(url('/assets/img/mana-call.png')) ?>?v=20260909c" width="44" height="44" alt="">
      <div>
        <strong>تماس مانا</strong>
        <p class="muted" style="margin:.15rem 0 0;font-size:.8rem">تماس را درمانگر شروع می‌کند</p>
      </div>
    </div>
      <p class="muted" style="font-size:.85rem;line-height:1.7">وقتی درمانگر تماس بگیرد، اعلان برایتان می‌آید. برای کارگاه آنلاین از دکمه ورود به جلسه استفاده کنید.</p>
    <?php if ($savedRooms): ?>
      <h2 class="vc-list-title">جلسه‌های ذخیره‌شده</h2>
      <ul class="vc-people">
        <?php foreach ($savedRooms as $sr): ?>
          <li>
            <a class="vc-person" href="<?= e(video_call_room_url($sr)) ?>" data-vc-room="<?= e((string) ($sr['room_key'] ?? '')) ?>">
              <span class="vc-avatar vc-avatar-md vc-avatar-room">گ</span>
              <span class="vc-person-meta">
                <strong><?= e((string) $sr['title']) ?></strong>
                <span class="muted"><?= (string) ($sr['kind'] ?? '') === 'workshop' ? 'کارگاه' : 'گروه' ?></span>
              </span>
            </a>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
    <h2 class="vc-list-title">افراد</h2>
    <?php if (!$contacts): ?>
      <p class="muted" style="font-size:.85rem">هنوز مخاطبی برای تماس نیست.</p>
    <?php else: ?>
      <ul class="vc-people">
        <?php foreach ($contacts as $c): ?>
          <li><?= video_call_person_row_html($c, false, false) ?></li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>
  <?php endif; ?>
  <div class="vc-lobby-main">
    <?php if ($clinician): ?>
    <div class="vc-composer" data-vc-composer<?= $callActive ? ' hidden' : '' ?>>
      <header class="vc-composer-head">
        <strong data-vc-composer-title>خانه</strong>
        <button type="button" class="btn btn-outline btn-sm" data-vc-save-open hidden>ذخیره گروه در مخاطبین</button>
      </header>
      <div class="vc-drop" data-vc-drop>
        <p data-vc-drop-hint>مخاطب را از فهرست انتخاب کنید، یا برای گروه روی آیکون گروه بزنید و + را بزنید.</p>
        <ul class="vc-picked" data-vc-picked></ul>
      </div>
      <div class="vc-composer-actions">
        <button type="button" class="vc-call-btn vc-call-video" data-vc-video disabled>تماس تصویری</button>
        <button type="button" class="vc-call-btn vc-call-audio" data-vc-audio disabled>تماس</button>
        <button type="button" class="vc-plus-btn" data-vc-plus title="افزودن افراد" aria-label="افزودن افراد">+</button>
      </div>
    </div>
    <?php endif; ?>
    <div class="vc-idle" data-vc-idle<?= ($callActive || $clinician) ? ' hidden' : '' ?>>
      <img class="vc-lobby-hero" src="<?= e(url('/assets/img/mana-call.png')) ?>?v=20260909c" width="160" height="160" alt="تماس مانا">
      <h1>تماس مانا</h1>
      <p class="muted" style="max-width:28rem;line-height:1.8">
        وقتی درمانگر تماس بگیرد، اعلان برایتان می‌آید. برای کارگاه آنلاین از دکمه ورود به جلسه استفاده کنید.
      </p>
    </div>
    <div class="stack video-call-page vc-in-tile<?= $clinician ? '' : ' is-guard' ?>" data-video-call
      data-signal-url="<?= e(url('/video-signal')) ?>"
      data-room="<?= e((string) ($session['room'] ?? '')) ?>"
      data-me="<?= e((string) ($user['id'] ?? '')) ?>"
      data-peer-name="<?= e($wmName) ?>"
      data-media="<?= e($media) ?>"
      data-can-start="<?= $clinician ? '1' : '0' ?>"
      data-can-record="<?= $clinician ? '1' : '0' ?>"
      data-guard-capture="<?= $clinician ? '0' : '1' ?>"
      data-group="<?= !empty($session['group']) ? '1' : '0' ?>"
      data-ring-url="<?= e(url('/assets/audio/incoming-call.ogg')) ?>"
      data-hang-url="<?= e(url('/assets/audio/hang-up.ogg')) ?>"
      <?= $callActive ? '' : 'hidden' ?>
    >
  <p class="video-call-status" data-video-status hidden><?= $callActive ? 'در حال اتصال…' : 'آماده تماس' ?></p>
  <div class="video-call-permit" data-video-permit hidden>
    <p>برای تماس، مرورگر باید به <?= $media === 'audio' ? 'میکروفون' : 'دوربین و میکروفون' ?> دسترسی بدهد.</p>
    <button type="button" class="btn btn-primary" data-video-permit-btn>اجازه دسترسی</button>
  </div>

  <div class="video-call-stage<?= ($room && (string) ($room['kind'] ?? '') !== 'direct') ? ' is-group' : '' ?>" data-video-stage>
    <div class="video-call-brand" data-video-brand aria-hidden="true">
      <img src="<?= e(url('/assets/img/mana-call.png')) ?>?v=20260909c" width="176" height="176" alt="">
    </div>
    <div class="vc-remotes" data-video-remotes></div>
    <div class="video-call-self">
      <video class="video-call-local" data-video-local autoplay playsinline muted disablepictureinpicture controlslist="nodownload noremoteplayback"></video>
    </div>
    <p class="video-call-incoming" data-video-incoming hidden>تماس ورودی</p>
    <div class="video-call-tools">
      <button type="button" class="video-call-icon video-call-icon-tool" data-video-enhance aria-label="واضح‌تر کردن تصویر" aria-pressed="false" title="واضح‌تر کردن تصویر">
        <svg viewBox="0 0 64 64" fill="none" aria-hidden="true">
          <circle cx="32" cy="32" r="27" stroke="currentColor" stroke-width="3.2"/>
          <path fill="currentColor" d="M32 14.5l2.1 6.3 6.4.2-5.1 4 1.8 6.4L32 27.8l-5.2 3.6 1.8-6.4-5.1-4 6.4-.2z"/>
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
      <span class="vc-stage-watermark" data-vc-watermark><?= $callActive ? e('در حال تماس با ' . $wmName) : '' ?></span>
      <?php if ($clinician): ?>
        <button type="button" class="video-call-icon video-call-icon-tool" data-video-record hidden aria-label="شروع ضبط" title="ضبط تماس">
          <svg viewBox="0 0 64 64" fill="none" aria-hidden="true">
            <circle cx="32" cy="32" r="27" stroke="currentColor" stroke-width="3.2"/>
            <circle cx="32" cy="32" r="10" fill="currentColor"/>
          </svg>
        </button>
      <?php endif; ?>
    </div>
    <div class="video-call-dock">
      <?php if ($clinician): ?>
        <button type="button" class="video-call-icon video-call-icon-call" data-video-start aria-label="شروع تماس" title="شروع تماس">
          <svg viewBox="0 0 64 64" fill="none" aria-hidden="true">
            <circle cx="32" cy="32" r="27" stroke="#22c55e" stroke-width="3.6"/>
            <g transform="translate(32 34) scale(1.55) translate(-12 -12)" fill="#22c55e">
              <path d="M6.62 10.79c1.44 2.83 3.76 5.14 6.59 6.59l2.2-2.2c.27-.27.67-.36 1.02-.24 1.12.37 2.33.57 3.57.57.55 0 1 .45 1 1V20c0 .55-.45 1-1 1C7.61 21 0 13.39 0 4c0-.55.45-1 1-1h3.5c.55 0 1 .45 1 1 0 1.25.2 2.45.57 3.57.11.35.03.74-.25 1.02l-2.2 2.2z"/>
            </g>
          </svg>
        </button>
      <?php else: ?>
        <button type="button" class="video-call-icon video-call-icon-call" data-video-start hidden aria-label="پاسخ" title="پاسخ">
          <svg viewBox="0 0 64 64" fill="none" aria-hidden="true">
            <circle cx="32" cy="32" r="27" stroke="#22c55e" stroke-width="3.6"/>
            <g transform="translate(32 34) scale(1.55) translate(-12 -12)" fill="#22c55e">
              <path d="M6.62 10.79c1.44 2.83 3.76 5.14 6.59 6.59l2.2-2.2c.27-.27.67-.36 1.02-.24 1.12.37 2.33.57 3.57.57.55 0 1 .45 1 1V20c0 .55-.45 1-1 1C7.61 21 0 13.39 0 4c0-.55.45-1 1-1h3.5c.55 0 1 .45 1 1 0 1.25.2 2.45.57 3.57.11.35.03.74-.25 1.02l-2.2 2.2z"/>
            </g>
          </svg>
        </button>
      <?php endif; ?>
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
  </div>
</div>
<?php if ($clinician): ?>
<div class="vc-overlay" data-vc-overlay hidden>
  <div class="vc-overlay-card" role="dialog" aria-modal="true" aria-labelledby="vc-add-title">
    <h2 id="vc-add-title">افزودن افراد</h2>
    <p class="muted">مخاطب را انتخاب کنید، به گروه اضافه کنید، بعد ذخیره یا تماس بگیرید.</p>
    <div class="vc-overlay-grid">
      <div>
        <h3>انتخاب مخاطب</h3>
        <ul class="vc-people" data-vc-overlay-source></ul>
        <button type="button" class="btn btn-outline btn-sm" data-vc-overlay-select>انتخاب</button>
      </div>
      <div>
        <h3>افراد این گروه</h3>
        <ul class="vc-people" data-vc-overlay-picked></ul>
        <button type="button" class="btn btn-outline btn-sm" data-vc-overlay-remove>حذف از گروه</button>
      </div>
    </div>
    <div class="vc-overlay-save">
      <label class="label" for="vc-group-title">ذخیره گروه با نام</label>
      <input class="input" id="vc-group-title" data-vc-group-title placeholder="مثلاً گروه ذهن‌آگاهی">
      <label class="label" for="vc-group-workshop">افزودن به کارگاه</label>
      <select class="input" id="vc-group-workshop" data-vc-group-workshop>
        <option value="">بدون کارگاه</option>
        <?php foreach ($workshops as $w): ?>
          <option value="<?= e((string) $w['id']) ?>"><?= e((string) $w['title']) ?> · <?= e(workshop_type_label((string) ($w['type'] ?? ''))) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="vc-overlay-foot">
      <button type="button" class="btn btn-primary" data-vc-overlay-add>افزودن</button>
      <button type="button" class="btn btn-outline" data-vc-overlay-cancel>انصراف</button>
    </div>
  </div>
</div>
<div class="vc-share-tile" data-vc-share-wrap<?= $shareUrl ? '' : ' hidden' ?>>
  <strong>لینک جلسه</strong>
  <p class="muted vc-group-hint">این لینک را بفرستید تا طرف مقابل وارد همین جلسه شود.</p>
  <div class="vc-share">
    <input class="input" id="vc-share-link" readonly dir="ltr" value="<?= e($shareUrl) ?>">
    <button type="button" class="btn btn-outline btn-sm" data-copy-share>کپی لینک جلسه</button>
  </div>
</div>
<?php endif; ?>
</div>
<script>
document.querySelector("[data-copy-share]")?.addEventListener("click", function(){
  var el = document.getElementById("vc-share-link");
  if (!el) return;
  navigator.clipboard.writeText(el.value).then(function(){ this.textContent = "کپی شد"; }.bind(this)).catch(function(){});
});
</script>
<?php
$inner = ob_get_clean();
$GLOBALS['pageScripts'] = '<script src="' . e(url('/assets/js/video-call.js')) . '?v=20260910a"></script>'
    . '<script src="' . e(url('/assets/js/video-call-lobby.js')) . '?v=20260910a"></script>';

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
if ($role === 'PATIENT') {
    require_once __DIR__ . '/../includes/patient_panel.php';
    render_patient_page($pageTitle, $inner);
    return;
}

$GLOBALS['pageTitle'] = $pageTitle;
$GLOBALS['content'] = $inner;
require __DIR__ . '/../includes/layout.php';
