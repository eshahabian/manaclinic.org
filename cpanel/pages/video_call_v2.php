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

// Public call links use a share token (?join=...). Resolve that token to the
// current canonical room key at request time so V2 does not depend on the
// room-key/room-number format and remains compatible if that format changes.
$joinToken = trim((string) ($_GET['join'] ?? ''));
$requestedRoomKey = trim((string) ($_GET['room'] ?? ''));
$room = null;
$roomKey = '';

if ($joinToken !== '') {
    $room = video_call_join_via_token($pdo, $user, $joinToken);
    if (!$room) {
        flash_set('error', 'این لینک تماس معتبر نیست یا این جلسه در دسترس شما نیست.');
        redirect('/video-call-v2');
    }
    $roomKey = (string) ($room['room_key'] ?? '');
} elseif ($requestedRoomKey !== '') {
    // Keep ?room=... for internal/backward-compatible testing only.
    $room = video_call_room_by_key($pdo, $requestedRoomKey);
    if (!$room || !video_call_user_can_access_room($pdo, $user, $room)) {
        flash_set('error', 'این جلسه در دسترس شما نیست.');
        redirect('/video-call-v2');
    }
    $roomKey = (string) ($room['room_key'] ?? $requestedRoomKey);
}

$pageTitle = 'تماس مانا V2';
$GLOBALS['pageRobots'] = 'noindex,nofollow';
$ready = mana_livekit_ready();
$title = $room ? (string) ($room['title'] ?? 'جلسه') : 'نسخه آزمایشی LiveKit';

ob_start();
?>
<div class="container-page section" style="max-width:72rem" data-livekit-v2 data-room="<?= e($roomKey) ?>">
  <div class="panel stack" style="gap:.75rem">
    <div style="display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap">
      <div>
        <h1 style="margin:0">تماس مانا V2</h1>
        <p class="muted" style="margin:.3rem 0 0"><?= e($title) ?></p>
      </div>
      <span class="badge"><?= $ready ? 'LiveKit آماده' : 'نیازمند تنظیم LiveKit' ?></span>
    </div>

    <?php if (!$roomKey): ?>
      <p style="margin:0;line-height:1.9">
        این صفحه نسخه آزمایشی موتور جدید تماس است و به تماس فعلی سایت دست نمی‌زند.
        برای تست، لینک دعوت فعلی را با مسیر <code>/video-call-v2?join=...</code> باز کنید.
      </p>
    <?php elseif (!$ready): ?>
      <div class="flash flash-error" style="margin:0">
        LiveKit هنوز روی هاست تنظیم نشده است. متغیرهای LIVEKIT_URL، LIVEKIT_API_KEY و LIVEKIT_API_SECRET باید در محیط PHP قرار بگیرند.
      </div>
    <?php else: ?>
      <div class="lkv2-toolbar" style="display:flex;gap:.5rem;flex-wrap:wrap">
        <button type="button" class="btn btn-primary" data-lk-connect>ورود به تماس</button>
        <button type="button" class="btn btn-outline" data-lk-camera disabled>دوربین</button>
        <button type="button" class="btn btn-outline" data-lk-mic disabled>میکروفون</button>
        <button type="button" class="btn btn-outline" data-lk-leave disabled>خروج</button>
      </div>
      <p class="muted" data-lk-status style="margin:0">آماده اتصال</p>
      <div data-lk-grid style="display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:.75rem;min-height:18rem;background:#0d1a16;border-radius:1rem;padding:.75rem"></div>
    <?php endif; ?>
  </div>
</div>

<?php if ($roomKey && $ready): ?>
<script src="https://cdn.jsdelivr.net/npm/livekit-client/dist/livekit-client.umd.min.js"></script>
<script>
(function () {
  var root = document.querySelector('[data-livekit-v2]');
  if (!root || !window.LivekitClient) return;
  var roomKey = root.getAttribute('data-room') || '';
  var grid = root.querySelector('[data-lk-grid]');
  var statusEl = root.querySelector('[data-lk-status]');
  var connectBtn = root.querySelector('[data-lk-connect]');
  var cameraBtn = root.querySelector('[data-lk-camera]');
  var micBtn = root.querySelector('[data-lk-mic]');
  var leaveBtn = root.querySelector('[data-lk-leave]');
  var Room = LivekitClient.Room;
  var RoomEvent = LivekitClient.RoomEvent;
  var Track = LivekitClient.Track;
  var room = null;

  function status(text) { if (statusEl) statusEl.textContent = text; }
  function tileId(identity) { return 'lkv2-' + String(identity || '').replace(/[^a-zA-Z0-9_-]/g, '_'); }
  function getTile(identity, label) {
    var id = tileId(identity);
    var el = document.getElementById(id);
    if (el) return el;
    el = document.createElement('div');
    el.id = id;
    el.style.cssText = 'position:relative;min-height:15rem;background:#12241f;border-radius:.85rem;overflow:hidden;display:grid;place-items:center;color:#fff';
    var name = document.createElement('span');
    name.textContent = label || 'شرکت‌کننده';
    name.style.cssText = 'position:absolute;right:.6rem;bottom:.45rem;z-index:3;background:rgba(0,0,0,.55);padding:.2rem .45rem;border-radius:.4rem;font-size:.75rem';
    el.appendChild(name);
    grid.appendChild(el);
    return el;
  }
  function attachTrack(track, participant, local) {
    var identity = participant && participant.identity ? participant.identity : (local ? 'local' : 'remote');
    var tile = getTile(identity, local ? 'شما' : 'شرکت‌کننده');
    var element = track.attach();
    if (track.kind === Track.Kind.Video) {
      element.autoplay = true;
      element.playsInline = true;
      element.setAttribute('playsinline', '');
      element.style.cssText = 'width:100%;height:100%;min-height:15rem;object-fit:cover;display:block;background:#0d1a16';
      var old = tile.querySelector('video');
      if (old && old !== element) old.remove();
      tile.insertBefore(element, tile.firstChild);
    } else if (track.kind === Track.Kind.Audio) {
      element.autoplay = true;
      element.style.display = 'none';
      tile.appendChild(element);
    }
  }
  function attachLocalPublished(publication) {
    if (publication && publication.track) attachTrack(publication.track, room.localParticipant, true);
  }
  function setButtons(connected) {
    connectBtn.disabled = connected;
    cameraBtn.disabled = !connected;
    micBtn.disabled = !connected;
    leaveBtn.disabled = !connected;
  }

  connectBtn.addEventListener('click', function () {
    connectBtn.disabled = true;
    status('در حال دریافت دسترسی امن…');
    fetch('/livekit-token', {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'X-CSRF-Token': (document.querySelector('meta[name="csrf-token"]') || {}).content || ''
      },
      body: JSON.stringify({ room: roomKey })
    }).then(function (r) {
      return r.json().then(function (data) {
        if (!r.ok || !data.ok) throw new Error((data && data.error) || 'خطای اتصال');
        return data;
      });
    }).then(async function (data) {
      status('در حال اتصال به سرور تماس…');
      room = new Room({ adaptiveStream: true, dynacast: true });
      room.on(RoomEvent.TrackSubscribed, function (track, publication, participant) {
        attachTrack(track, participant, false);
      });
      room.on(RoomEvent.TrackUnsubscribed, function (track) {
        try { track.detach().forEach(function (el) { el.remove(); }); } catch (e) {}
      });
      room.on(RoomEvent.LocalTrackPublished, function (publication) {
        attachLocalPublished(publication);
      });
      room.on(RoomEvent.ParticipantDisconnected, function (participant) {
        var tile = document.getElementById(tileId(participant.identity));
        if (tile) tile.remove();
      });
      room.on(RoomEvent.Reconnecting, function () { status('در حال اتصال مجدد…'); });
      room.on(RoomEvent.Reconnected, function () { status('اتصال دوباره برقرار شد.'); });
      room.on(RoomEvent.Disconnected, function () {
        status('تماس پایان یافت.');
        setButtons(false);
      });
      await room.connect(data.serverUrl, data.participantToken, { autoSubscribe: true });
      status('متصل شد؛ در انتظار اجازه دوربین و میکروفون…');
      await room.localParticipant.enableCameraAndMicrophone();
      room.localParticipant.trackPublications.forEach(function (pub) { attachLocalPublished(pub); });
      status('تماس برقرار است.');
      setButtons(true);
    }).catch(function (err) {
      status(err && err.message ? err.message : 'اتصال برقرار نشد.');
      connectBtn.disabled = false;
      if (room) { try { room.disconnect(); } catch (e) {} room = null; }
    });
  });

  cameraBtn.addEventListener('click', async function () {
    if (!room) return;
    var enabled = !room.localParticipant.isCameraEnabled;
    await room.localParticipant.setCameraEnabled(enabled);
    cameraBtn.textContent = enabled ? 'خاموش کردن دوربین' : 'روشن کردن دوربین';
  });
  micBtn.addEventListener('click', async function () {
    if (!room) return;
    var enabled = !room.localParticipant.isMicrophoneEnabled;
    await room.localParticipant.setMicrophoneEnabled(enabled);
    micBtn.textContent = enabled ? 'قطع میکروفون' : 'وصل میکروفون';
  });
  leaveBtn.addEventListener('click', function () {
    if (room) room.disconnect();
    room = null;
    grid.innerHTML = '';
    status('از تماس خارج شدید.');
    setButtons(false);
  });
  window.addEventListener('pagehide', function () { if (room) room.disconnect(); });
})();
</script>
<?php endif; ?>
<?php
$content = ob_get_clean();
require __DIR__ . '/../includes/layout.php';
