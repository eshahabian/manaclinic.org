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
      <div style="display:flex;gap:.5rem;flex-wrap:wrap">
        <button type="button" class="btn btn-primary" data-lk-connect><?= $answer ? 'ورود به تماس' : 'ورود به تماس' ?></button>
        <button type="button" class="btn btn-outline" data-lk-camera<?= $audioOnly ? ' hidden' : '' ?> disabled>دوربین</button>
        <button type="button" class="btn btn-outline" data-lk-mic disabled>میکروفون</button>
        <button type="button" class="btn btn-outline" data-lk-leave disabled>خروج</button>
      </div>
      <p class="muted" data-lk-status style="margin:0">برای شروع، دکمه «ورود به تماس» را بزنید تا دوربین اجازه بگیرد.</p>
      <div data-lk-grid style="display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:.75rem;min-height:18rem;background:#0d1a16;border-radius:1rem;padding:.75rem"></div>
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
<script>
(function(){
  var root=document.querySelector('[data-livekit-v2]'); if(!root)return;
  var key=root.dataset.room||'', audioOnly=root.dataset.media==='audio', auto=root.dataset.auto==='1';
  var grid=root.querySelector('[data-lk-grid]'), statusEl=root.querySelector('[data-lk-status]');
  var connectBtn=root.querySelector('[data-lk-connect]'), cameraBtn=root.querySelector('[data-lk-camera]'), micBtn=root.querySelector('[data-lk-mic]'), leaveBtn=root.querySelector('[data-lk-leave]');
  var room=null, busy=false, joined=false;

  function status(s){if(statusEl)statusEl.textContent=s;}
  function buttons(on){connectBtn.disabled=on||busy;cameraBtn.disabled=!on;micBtn.disabled=!on;leaveBtn.disabled=!on;if(on)connectBtn.textContent='متصل';}
  function id(s){return 'lk-'+String(s||'').replace(/[^a-zA-Z0-9_-]/g,'_');}
  function remoteCount(){return room ? room.remoteParticipants.size : 0;}
  function setCallStatus(){
    if(!joined)return;
    var n=remoteCount();
    status(n>0 ? ('تماس برقرار است — طرف مقابل متصل ('+n+')') : 'تماس برقرار است — در انتظار ورود طرف مقابل…');
  }
  function tile(identity,label){
    var x=document.getElementById(id(identity));
    if(x)return x;
    x=document.createElement('div');
    x.id=id(identity);
    x.style.cssText='position:relative;min-height:15rem;background:#12241f;border-radius:.85rem;overflow:hidden;display:grid;place-items:center;color:#fff';
    var n=document.createElement('span');
    n.textContent=label||'شرکت‌کننده';
    n.style.cssText='position:absolute;right:.6rem;bottom:.45rem;z-index:3;background:rgba(0,0,0,.55);padding:.2rem .45rem;border-radius:.4rem;font-size:.75rem';
    x.appendChild(n);
    grid.appendChild(x);
    return x;
  }
  function attach(track,p,local){
    var T=LivekitClient.Track;
    var who=p&&p.identity?p.identity:(local?'local':'remote');
    var x=tile(who, local?'شما':'طرف مقابل');
    var el=track.attach();
    el.autoplay=true;
    el.playsInline=true;
    el.setAttribute('playsinline','');
    if(track.kind===T.Kind.Video){
      el.muted=!!local;
      el.style.cssText='width:100%;height:100%;min-height:15rem;object-fit:cover;display:block;background:#0d1a16';
      var old=x.querySelector('video');
      if(old&&old!==el)old.remove();
      x.insertBefore(el,x.firstChild);
    }else{
      el.muted=false;
      el.style.display='none';
      x.appendChild(el);
    }
    var p1=el.play();
    if(p1&&p1.catch)p1.catch(function(){});
  }
  function localPub(pub){if(pub&&pub.track&&room)attach(pub.track,room.localParticipant,true);}
  function hydrateRemotes(){
    if(!room)return;
    room.remoteParticipants.forEach(function(p){
      p.trackPublications.forEach(function(pub){
        if(pub&&pub.track)attach(pub.track,p,false);
        else if(pub&&pub.setSubscribed){try{pub.setSubscribed(true);}catch(e){}}
      });
    });
    setCallStatus();
  }
  function unlock(){grid.querySelectorAll('audio,video').forEach(function(el){var p=el.play();if(p&&p.catch)p.catch(function(){});});}

  async function connect(){
    if(busy||!window.LivekitClient)return;
    busy=true;buttons(false);status('در حال دریافت دسترسی امن…');
    try{
      var r=await fetch('/livekit-token',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify({room:key})});
      var d=await r.json();
      if(!r.ok||!d.ok)throw new Error(d.error||'خطای اتصال');
      var url=String(d.serverUrl||'');
      if(url.indexOf('https://')===0)url='wss://'+url.slice(8);
      else if(url.indexOf('http://')===0)url='ws://'+url.slice(7);

      room=new LivekitClient.Room({adaptiveStream:true,dynacast:true});
      room.on(LivekitClient.RoomEvent.TrackSubscribed,function(t,pub,p){attach(t,p,false);setCallStatus();});
      room.on(LivekitClient.RoomEvent.TrackUnsubscribed,function(t){try{t.detach().forEach(function(el){el.remove();});}catch(e){}});
      room.on(LivekitClient.RoomEvent.LocalTrackPublished,localPub);
      room.on(LivekitClient.RoomEvent.ParticipantConnected,function(){hydrateRemotes();});
      room.on(LivekitClient.RoomEvent.ParticipantDisconnected,function(p){var x=document.getElementById(id(p.identity));if(x)x.remove();setCallStatus();});
      room.on(LivekitClient.RoomEvent.Reconnecting,function(){status('در حال اتصال مجدد…');});
      room.on(LivekitClient.RoomEvent.Reconnected,function(){hydrateRemotes();status('اتصال دوباره برقرار شد.');});
      room.on(LivekitClient.RoomEvent.Disconnected,function(){busy=false;joined=false;status('تماس پایان یافت.');buttons(false);});

      status('در حال اتصال…');
      await room.connect(url,d.participantToken,{autoSubscribe:true});
      hydrateRemotes();
      status(audioOnly?'در انتظار اجازه میکروفون…':'در انتظار اجازه دوربین و میکروفون…');
      try{
        if(audioOnly){
          await room.localParticipant.setMicrophoneEnabled(true);
        }else{
          await room.localParticipant.setMicrophoneEnabled(true);
          try{await room.localParticipant.setCameraEnabled(true);}catch(camErr){
            status('میکروفون وصل شد؛ دوربین اجازه نگرفت. دکمه دوربین را بزنید.');
          }
        }
      }catch(mediaErr){
        status((mediaErr&&mediaErr.message)?mediaErr.message:'اجازه میکروفون/دوربین داده نشد. دوباره تلاش کنید.');
      }
      room.localParticipant.trackPublications.forEach(localPub);
      hydrateRemotes();
      joined=true;busy=false;buttons(true);unlock();setCallStatus();
    }catch(e){
      busy=false;joined=false;status(e&&e.message?e.message:'اتصال برقرار نشد.');
      if(room){try{room.disconnect();}catch(x){}room=null;}
      buttons(false);
    }
  }

  connectBtn.addEventListener('click',function(){unlock();connect();});
  cameraBtn.addEventListener('click',async function(){
    if(!room)return;
    var on=!room.localParticipant.isCameraEnabled;
    try{await room.localParticipant.setCameraEnabled(on);cameraBtn.textContent=on?'خاموش کردن دوربین':'روشن کردن دوربین';}catch(e){status('دوربین فعال نشد. دسترسی مرورگر را چک کنید.');}
  });
  micBtn.addEventListener('click',async function(){
    if(!room)return;
    var on=!room.localParticipant.isMicrophoneEnabled;
    await room.localParticipant.setMicrophoneEnabled(on);
    micBtn.textContent=on?'قطع میکروفون':'وصل میکروفون';
  });
  leaveBtn.addEventListener('click',function(){if(room)room.disconnect();room=null;grid.innerHTML='';joined=false;status('از تماس خارج شدید.');buttons(false);});
  grid.addEventListener('click',unlock);
  document.addEventListener('visibilitychange',function(){if(!document.hidden){unlock();hydrateRemotes();}});
  window.addEventListener('pagehide',function(){if(room)room.disconnect();});

  // Auto-connect only after answer/start deep-link; still requires browser permission prompt.
  if(auto){
    status('برای دیدن تصویر دو طرف، یک‌بار «ورود به تماس» را بزنید.');
    // Do not auto-call getUserMedia without a gesture on mobile — wait for tap.
    // Desktop can still try once; if it fails, user taps the button.
    var isMobile=/Android|iPhone|iPad|iPod|Mobile/i.test(navigator.userAgent||'');
    if(!isMobile)setTimeout(connect,120);
  }
})();
</script>
<?php endif; ?>
<?php
$content = ob_get_clean();
require __DIR__ . '/../includes/layout.php';
