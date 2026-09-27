<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/video_call.php';
require_once __DIR__ . '/../includes/livekit.php';

$user = require_login();
if (!video_call_allowed($user)) {
    flash_set('error', 'تماس مانا برای حساب شما فعال نیست.');
    redirect(panel_href_for($user) ?: '/');
}

ensure_video_call_schema($pdo);
video_call_touch($pdo, (string) ($user['id'] ?? ''));

$join = trim((string) ($_GET['join'] ?? ''));
$peer = trim((string) ($_GET['peer'] ?? ''));
$workshop = trim((string) ($_GET['workshop'] ?? ''));
$requestedRoom = trim((string) ($_GET['room'] ?? ''));
$media = trim((string) ($_GET['media'] ?? 'video')) === 'audio' ? 'audio' : 'video';
$start = (string) ($_GET['start'] ?? '') === '1';
$answer = (string) ($_GET['answer'] ?? '') === '1';
$clinician = video_call_is_clinician($user);
$room = null;

if ($join !== '') {
    $room = video_call_join_via_token($pdo, $user, $join);
} elseif ($workshop !== '') {
    $room = video_call_ensure_workshop_room($pdo, $workshop, (string) ($user['id'] ?? ''));
    $start = $start || $clinician;
} elseif ($peer !== '') {
    if (!$clinician) {
        flash_set('error', 'فقط درمانگر می‌تواند تماس را شروع کند.');
        redirect('/video-call');
    }
    $room = video_call_ensure_direct_room($pdo, $user, $peer);
    $start = true;
} elseif ($requestedRoom !== '') {
    $room = video_call_room_by_key($pdo, $requestedRoom);
}

if (($join !== '' || $workshop !== '' || $peer !== '' || $requestedRoom !== '')
    && (!$room || !video_call_user_can_access_room($pdo, $user, $room))) {
    flash_set('error', 'این جلسه در دسترس شما نیست.');
    redirect('/video-call');
}

$roomKey = $room ? (string) ($room['room_key'] ?? '') : '';
$ready = mana_livekit_ready();
$title = $room ? trim((string) ($room['title'] ?? 'جلسه')) : 'تماس مانا';
if ($title === '') $title = 'جلسه';

if ($room && $roomKey !== '' && $ready) {
    $me = (string) ($user['id'] ?? '');
    if ($me !== '') {
        // Clear inbox for this user so watch stops re-alerting on the call page.
        $pdo->prepare("DELETE FROM video_call_signals WHERE room_id=? AND target_id=? AND kind IN ('ringing','offer')")
            ->execute([$roomKey, $me]);
    }
    if ($start && $clinician) {
        $pdo->prepare("DELETE FROM video_call_signals WHERE room_id=? AND kind IN ('offer','answer','ice','join','leave','hangup','ringing')")
            ->execute([$roomKey]);
        $ins = $pdo->prepare('INSERT INTO video_call_signals (id,room_id,sender_id,target_id,kind,payload) VALUES (?,?,?,?,?,?)');
        $payload = json_encode([
            'name' => $title,
            'media' => $media,
            'group' => (string) ($room['kind'] ?? '') !== 'direct',
            'engine' => 'livekit',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        foreach (video_call_room_members_public($pdo, $room) as $member) {
            $target = (string) ($member['id'] ?? '');
            if ($target !== '' && $target !== $me) {
                $ins->execute([cuid(), $roomKey, $me, $target, 'ringing', $payload]);
            }
        }
        video_call_bump_room_contacts($pdo, $user, $room);
        // Strip start=1 so refresh does not insert another wave of ringing rows.
        redirect('/video-call-live?room=' . rawurlencode($roomKey) . '&media=' . rawurlencode($media) . '&joincall=1');
    }
    if ($answer) {
        // Strip answer=1 after clearing inbox so refresh is stable.
        redirect('/video-call-live?room=' . rawurlencode($roomKey) . '&media=' . rawurlencode($media) . '&joincall=1');
    }
}

$pageTitle = 'تماس مانا';
$GLOBALS['pageRobots'] = 'noindex,nofollow';
$joinCall = (string) ($_GET['joincall'] ?? '') === '1';
$auto = $roomKey !== '' && $joinCall;
$audioOnly = $media === 'audio';

ob_start();
?>
<div class="container-page section" style="max-width:76rem" data-livekit-v2 data-room="<?= e($roomKey) ?>" data-media="<?= e($media) ?>" data-auto="<?= $auto ? '1' : '0' ?>">
  <div class="panel stack" style="gap:.75rem">
    <div style="display:flex;justify-content:space-between;gap:1rem;align-items:center;flex-wrap:wrap">
      <div><h1 style="margin:0">تماس مانا</h1><p class="muted" style="margin:.3rem 0 0"><?= e($title) ?></p></div>
      <?php if ($roomKey && $ready): ?><span class="badge">ارتباط امن</span><?php endif; ?>
    </div>
    <?php if (!$roomKey): ?>
      <p>برای شروع تماس، یک مخاطب یا جلسه را انتخاب کنید.</p>
      <div><a class="btn btn-primary" href="<?= e(url('/video-call')) ?>">بازگشت</a></div>
    <?php elseif (!$ready): ?>
      <div class="flash flash-error">سرویس تماس امن موقتاً آماده نیست.</div>
    <?php else: ?>
      <div class="lk-controls">
        <button type="button" class="btn btn-primary" data-lk-connect><?= $answer ? 'ورود به تماس' : 'ورود به تماس' ?></button>
        <button type="button" class="btn btn-outline" data-lk-camera<?= $audioOnly ? ' hidden' : '' ?> disabled>دوربین</button>
        <button type="button" class="btn btn-outline" data-lk-mic disabled>میکروفون</button>
        <?php if (!$audioOnly): ?>
          <label class="lk-bg-wrap">
            <span class="lk-bg-label">پس‌زمینه</span>
            <select class="lk-bg-select" data-lk-bg disabled>
              <option value="none">بدون پس‌زمینه</option>
              <option value="blur">مات (تار)</option>
              <option value="green">سبز کلینیک</option>
              <option value="mint">گرادیان ملایم</option>
              <option value="room">فضای آرام</option>
            </select>
          </label>
        <?php endif; ?>
        <button type="button" class="btn btn-outline" data-lk-fs disabled>تمام‌صفحه</button>
        <?php if ($clinician): ?>
          <button type="button" class="btn btn-outline lk-record" data-lk-record disabled>ضبط جلسه</button>
        <?php endif; ?>
        <button type="button" class="btn btn-outline" data-lk-leave disabled>خروج</button>
      </div>
      <p class="muted" data-lk-status style="margin:0">برای شروع، دکمه «ورود به تماس» را بزنید تا دوربین اجازه بگیرد.</p>
      <div class="lk-stage" data-lk-stage
           data-bg-room="<?= e(url('/assets/img/mind-room/room-bg.png')) ?>"
           data-bg-soft="<?= e(url('/assets/img/well/clinic.png')) ?>">
        <div class="lk-remotes" data-lk-remotes></div>
        <div class="lk-self" data-lk-self></div>
      </div>
    <?php endif; ?>
  </div>
</div>
<?php if ($roomKey && $ready): ?>
<script>
window.__MANA_LIVEKIT_CALL_ACTIVE__ = true;
try {
  var u = new URL(location.href);
  if (u.searchParams.has('joincall')) {
    u.searchParams.delete('joincall');
    history.replaceState({}, '', u.pathname + u.search + u.hash);
  }
} catch (e) {}
</script>
<script src="https://cdn.jsdelivr.net/npm/livekit-client@2.22.3/dist/livekit-client.umd.min.js"></script>
<script src="<?= e(url('/assets/js/video-call-livekit-ui.js')) ?>?v=20260928home"></script>

<?php endif; ?>
<?php
$content = ob_get_clean();
require __DIR__ . '/../includes/layout.php';
