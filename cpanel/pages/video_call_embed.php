<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/video_call.php';
require_once __DIR__ . '/../includes/livekit.php';

$user = require_login();
if (!video_call_allowed($user)) {
    http_response_code(403);
    exit('forbidden');
}

ensure_video_call_schema($pdo);
$roomKey = trim((string) ($_GET['room'] ?? ''));
$media = trim((string) ($_GET['media'] ?? 'video')) === 'audio' ? 'audio' : 'video';
$room = $roomKey !== '' ? video_call_room_by_key($pdo, $roomKey) : null;
if (!$room || !video_call_user_can_access_room($pdo, $user, $room) || !mana_livekit_ready()) {
    http_response_code(403);
    exit('call unavailable');
}
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<style>
html,body{margin:0;width:100%;height:100%;background:#0d1a16;font-family:Arial,sans-serif;overflow:hidden}
#call{position:relative;width:100%;height:100%;display:flex;flex-direction:column;background:#0d1a16}
#bar{display:flex;gap:6px;padding:8px;z-index:5;background:rgba(13,26,22,.94);flex-wrap:wrap}
button{border:1px solid #d6dfdb;background:#fff;color:#17352b;border-radius:9px;padding:8px 11px;font-size:13px}button.primary{background:#16844c;color:#fff;border-color:#16844c}
#status{color:#e8f0ed;font-size:12px;padding:0 10px 7px;margin:0}
#grid{flex:1;min-height:0;display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:7px;padding:7px;box-sizing:border-box}
.tile{position:relative;min-height:0;background:#12241f;border-radius:12px;overflow:hidden;display:grid;place-items:center;color:#fff}.tile video{width:100%;height:100%;object-fit:cover;display:block}.label{position:absolute;right:7px;bottom:7px;background:rgba(0,0,0,.55);padding:3px 6px;border-radius:6px;font-size:11px;z-index:2}
@media(max-width:640px){#grid{grid-template-columns:1fr;grid-auto-rows:minmax(0,1fr)}#bar{padding:6px}button{padding:7px 9px;font-size:12px}}
</style>
</head>
<body>
<div id="call" data-room="<?= e($roomKey) ?>" data-media="<?= e($media) ?>">
  <div id="bar">
    <button class="primary" id="connect">ورود به تماس</button>
    <button id="camera"<?= $media === 'audio' ? ' hidden' : '' ?> disabled>دوربین</button>
    <button id="mic" disabled>میکروفون</button>
    <button id="leave" disabled>خروج</button>
  </div>
  <p id="status">آماده اتصال</p>
  <div id="grid"></div>
</div>
<script src="https://cdn.jsdelivr.net/npm/livekit-client@2.22.3/dist/livekit-client.umd.min.js"></script>
<script>
(function(){
var root=document.getElementById('call'),key=root.dataset.room||'',audioOnly=root.dataset.media==='audio';
var grid=document.getElementById('grid'),statusEl=document.getElementById('status'),connectBtn=document.getElementById('connect'),cameraBtn=document.getElementById('camera'),micBtn=document.getElementById('mic'),leaveBtn=document.getElementById('leave');
var room=null,busy=false,csrf=(document.querySelector('meta[name="csrf-token"]')||{}).content||'';
function status(s){statusEl.textContent=s}function buttons(on){connectBtn.disabled=on||busy;cameraBtn.disabled=!on;micBtn.disabled=!on;leaveBtn.disabled=!on}
function tid(s){return 'lk-'+String(s||'').replace(/[^a-zA-Z0-9_-]/g,'_')}
function tile(id,label){var x=document.getElementById(tid(id));if(x)return x;x=document.createElement('div');x.id=tid(id);x.className='tile';var n=document.createElement('span');n.className='label';n.textContent=label;x.appendChild(n);grid.appendChild(x);return x}
function attach(track,p,local){var who=p&&p.identity?p.identity:(local?'local':'remote'),x=tile(who,local?'شما':'شرکت‌کننده'),el=track.attach();el.autoplay=true;el.playsInline=true;el.setAttribute('playsinline','');if(track.kind===LivekitClient.Track.Kind.Video){var old=x.querySelector('video');if(old&&old!==el)old.remove();x.insertBefore(el,x.firstChild)}else{el.style.display='none';x.appendChild(el)}var pr=el.play();if(pr&&pr.catch)pr.catch(function(){})}
function localPub(pub){if(pub&&pub.track&&room)attach(pub.track,room.localParticipant,true)}
function unlock(){grid.querySelectorAll('audio,video').forEach(function(el){var p=el.play();if(p&&p.catch)p.catch(function(){})})}
async function connect(){if(busy||!window.LivekitClient)return;busy=true;buttons(false);status('در حال دریافت دسترسی امن…');try{var r=await fetch('/livekit-token',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-Token':csrf},body:JSON.stringify({room:key})});var d=await r.json();if(!r.ok||!d.ok)throw new Error(d.error||'خطای اتصال');room=new LivekitClient.Room({adaptiveStream:true,dynacast:true});room.on(LivekitClient.RoomEvent.TrackSubscribed,function(t,pub,p){attach(t,p,false)});room.on(LivekitClient.RoomEvent.TrackUnsubscribed,function(t){try{t.detach().forEach(function(el){el.remove()})}catch(e){}});room.on(LivekitClient.RoomEvent.LocalTrackPublished,localPub);room.on(LivekitClient.RoomEvent.ParticipantDisconnected,function(p){var x=document.getElementById(tid(p.identity));if(x)x.remove()});room.on(LivekitClient.RoomEvent.Reconnecting,function(){status('در حال اتصال مجدد…')});room.on(LivekitClient.RoomEvent.Reconnected,function(){status('تماس برقرار است.')});room.on(LivekitClient.RoomEvent.Disconnected,function(){busy=false;status('تماس پایان یافت.');buttons(false)});status('در حال اتصال…');await room.connect(d.serverUrl,d.participantToken,{autoSubscribe:true});status(audioOnly?'در انتظار اجازه میکروفون…':'در انتظار اجازه دوربین و میکروفون…');if(audioOnly)await room.localParticipant.setMicrophoneEnabled(true);else await room.localParticipant.enableCameraAndMicrophone();room.localParticipant.trackPublications.forEach(localPub);busy=false;status('تماس برقرار است.');buttons(true);unlock()}catch(e){busy=false;status(e&&e.message?e.message:'اتصال برقرار نشد.');if(room){try{room.disconnect()}catch(x){}room=null}buttons(false)}}
connectBtn.addEventListener('click',function(){unlock();connect()});cameraBtn.addEventListener('click',async function(){if(!room)return;var on=!room.localParticipant.isCameraEnabled;await room.localParticipant.setCameraEnabled(on);cameraBtn.textContent=on?'خاموش کردن دوربین':'روشن کردن دوربین'});micBtn.addEventListener('click',async function(){if(!room)return;var on=!room.localParticipant.isMicrophoneEnabled;await room.localParticipant.setMicrophoneEnabled(on);micBtn.textContent=on?'قطع میکروفون':'وصل میکروفون'});leaveBtn.addEventListener('click',function(){if(room)room.disconnect();room=null;grid.innerHTML='';status('از تماس خارج شدید.');buttons(false);parent.postMessage({type:'mana-livekit-leave'},location.origin)});grid.addEventListener('click',unlock);document.addEventListener('visibilitychange',function(){if(!document.hidden)unlock()});window.addEventListener('pagehide',function(){if(room)room.disconnect()});setTimeout(connect,80);
})();
</script>
</body>
</html>
