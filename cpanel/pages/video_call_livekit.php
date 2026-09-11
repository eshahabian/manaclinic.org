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
if ($title === '') {
    $title = 'جلسه';
}

if ($room && $roomKey !== '' && $ready) {
    $me = (string) ($user['id'] ?? '');
    if ($me !== '') {
        $pdo->prepare("DELETE FROM video_call_signals WHERE room_id=? AND target_id=? AND kind='ringing'")
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
    }
}

$pageTitle = 'تماس مانا';
$GLOBALS['pageRobots'] = 'noindex,nofollow';
$auto = $roomKey !== '' && ($start || $answer);
$audioOnly = $media === 'audio';

ob_start();
?>
<div class="container-page section" style="max-width:76rem" data-livekit-v2 data-room="<?= e($roomKey) ?>" data-media="<?= e($media) ?>" data-auto="<?= $auto ? '1' : '0' ?>">
  <div class="panel stack" style="gap:.75rem">
    <div style="display:flex;justify-content:space-between;gap:1rem;align-items:center;flex-wrap:wrap">
      <div>
        <h1 style="margin:0">تماس مانا</h1>
        <p class="muted" style="margin:.3rem 0 0"><?= e($title) ?></p>
      </div>
      <?php if ($roomKey && $ready): ?><span class="badge">ارتباط امن</span><?php endif; ?>
    </div>
    <?php if (!$roomKey): ?>
      <p>برای شروع تماس، یک مخاطب یا جلسه را انتخاب کنید.</p>
      <div><a class="btn btn-primary" href="<?= e(url('/video-call')) ?>">بازگشت</a></div>
    <?php elseif (!$ready): ?>
      <div class="flash flash-error">سرویس تماس امن موقتاً آماده نیست. کلیدهای LiveKit را در config.php تنظیم کنید.</div>
    <?php else: ?>
      <div style="display:flex;gap:.5rem;flex-wrap:wrap">
        <button type="button" class="btn btn-primary" data-lk-connect><?= $answer ? 'پاسخ و ورود' : 'ورود به تماس' ?></button>
        <button type="button" class="btn btn-outline" data-lk-camera<?= $audioOnly ? ' hidden' : '' ?> disabled>دوربین</button>
        <button type="button" class="btn btn-outline" data-lk-mic disabled>میکروفون</button>
        <button type="button" class="btn btn-outline" data-lk-leave disabled>خروج</button>
      </div>
      <p class="muted" data-lk-status style="margin:0">آماده اتصال — برای تصویر روی «ورود به تماس» بزنید</p>
      <div data-lk-grid style="display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:.75rem;min-height:18rem;background:#0d1a16;border-radius:1rem;padding:.75rem"></div>
    <?php endif; ?>
  </div>
</div>
<?php if ($roomKey && $ready): ?>
<script src="https://cdn.jsdelivr.net/npm/livekit-client@2.22.3/dist/livekit-client.umd.min.js"></script>
<script src="<?= e(url('/assets/js/mana-livekit-call.js')) ?>?v=20260911l"></script>
<script>
(function () {
  var root = document.querySelector("[data-livekit-v2]");
  if (!root || !window.ManaLiveKit) return;
  var key = root.dataset.room || "";
  var audioOnly = root.dataset.media === "audio";
  var auto = root.dataset.auto === "1";
  var grid = root.querySelector("[data-lk-grid]");
  var statusEl = root.querySelector("[data-lk-status]");
  var connectBtn = root.querySelector("[data-lk-connect]");
  var cameraBtn = root.querySelector("[data-lk-camera]");
  var micBtn = root.querySelector("[data-lk-mic]");
  var leaveBtn = root.querySelector("[data-lk-leave]");
  var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || "";
  var room = null;
  var busy = false;

  function status(s) { if (statusEl) statusEl.textContent = s; }
  function buttons(on) {
    connectBtn.disabled = on || busy;
    cameraBtn.disabled = !on;
    micBtn.disabled = !on;
    leaveBtn.disabled = !on;
  }
  function id(s) { return "lk-" + String(s || "").replace(/[^a-zA-Z0-9_-]/g, "_"); }
  function tile(identity, label) {
    var x = document.getElementById(id(identity));
    if (x) return x;
    x = document.createElement("div");
    x.id = id(identity);
    x.style.cssText = "position:relative;min-height:15rem;background:#12241f;border-radius:.85rem;overflow:hidden;display:grid;place-items:center;color:#fff";
    var n = document.createElement("span");
    n.textContent = label || "شرکت‌کننده";
    n.style.cssText = "position:absolute;right:.6rem;bottom:.45rem;z-index:3;background:rgba(0,0,0,.55);padding:.2rem .45rem;border-radius:.4rem;font-size:.75rem";
    x.appendChild(n);
    grid.appendChild(x);
    return x;
  }
  function getContainer(p, local) {
    return tile(p && p.identity ? p.identity : (local ? "local" : "remote"), local ? "شما" : "شرکت‌کننده");
  }
  function attach(track, p, local) {
    ManaLiveKit.attachTrack(track, getContainer(p, local), {
      muted: !!local && ManaLiveKit.isVideoTrack(track),
      mirrored: !!local && ManaLiveKit.isVideoTrack(track),
      prepend: true
    });
  }
  function localPub(pub) {
    if (pub && pub.track && room) attach(pub.track, room.localParticipant, true);
  }
  function unlock() {
    grid.querySelectorAll("audio,video").forEach(function (el) { ManaLiveKit.safePlay(el); });
  }

  async function connect() {
    if (busy || room || !window.LivekitClient) return;
    busy = true;
    buttons(false);
    status("در حال اتصال امن…");
    try {
      var res = await ManaLiveKit.connectRoom({
        roomKey: key,
        csrf: csrf,
        audioOnly: audioOnly,
        onStatus: status,
        hooks: {
          getContainer: getContainer,
          onTrack: function (t, pub, p) { attach(t, p, false); },
          onLocalPub: localPub,
          onParticipantLeft: function (p) {
            var x = document.getElementById(id(p.identity));
            if (x) x.remove();
          },
          onDisconnected: function () {
            busy = false;
            room = null;
            status("تماس پایان یافت.");
            buttons(false);
          },
          onStatus: status
        }
      });
      room = res.room;
      busy = false;
      buttons(true);
      unlock();
      if (!audioOnly && !(res.media && res.media.cam)) {
        status("صدا وصل شد؛ برای تصویر دوباره روی ورود بزنید یا دکمه دوربین را لمس کنید.");
      } else {
        status("تماس برقرار است.");
      }
      cameraBtn.textContent = room.localParticipant.isCameraEnabled ? "خاموش کردن دوربین" : "روشن کردن دوربین";
      micBtn.textContent = room.localParticipant.isMicrophoneEnabled ? "قطع میکروفون" : "وصل میکروفون";
    } catch (e) {
      busy = false;
      status(e && e.message ? e.message : "اتصال برقرار نشد.");
      if (room) {
        try { room.disconnect(); } catch (x) {}
        room = null;
      }
      buttons(false);
    }
  }

  connectBtn.addEventListener("click", function () { unlock(); connect(); });
  cameraBtn.addEventListener("click", async function () {
    if (!room) return;
    try {
      var on = !room.localParticipant.isCameraEnabled;
      await room.localParticipant.setCameraEnabled(on);
      room.localParticipant.trackPublications.forEach(localPub);
      cameraBtn.textContent = on ? "خاموش کردن دوربین" : "روشن کردن دوربین";
    } catch (e) {
      status(e && e.message ? e.message : "دوربین فعال نشد.");
    }
  });
  micBtn.addEventListener("click", async function () {
    if (!room) return;
    try {
      var on = !room.localParticipant.isMicrophoneEnabled;
      await room.localParticipant.setMicrophoneEnabled(on);
      micBtn.textContent = on ? "قطع میکروفون" : "وصل میکروفون";
    } catch (e) {
      status(e && e.message ? e.message : "میکروفون فعال نشد.");
    }
  });
  leaveBtn.addEventListener("click", function () {
    if (room) room.disconnect();
    room = null;
    grid.innerHTML = "";
    status("از تماس خارج شدید.");
    buttons(false);
  });
  grid.addEventListener("click", unlock);
  document.addEventListener("visibilitychange", function () { if (!document.hidden) unlock(); });
  window.addEventListener("pagehide", function () { if (room) room.disconnect(); });

  // Auto only after a short delay; if camera is blocked, user can tap the main button.
  if (auto) setTimeout(connect, 80);
})();
</script>
<?php endif; ?>
<?php
$content = ob_get_clean();
require __DIR__ . '/../includes/layout.php';
