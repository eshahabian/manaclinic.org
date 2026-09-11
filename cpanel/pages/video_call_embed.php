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
$canRecord = (string) ($user['role'] ?? '') === 'DOCTOR';
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<style>
*{box-sizing:border-box}html,body{margin:0;width:100%;height:100%;background:#07130f;font-family:Arial,sans-serif;overflow:hidden}button{font:inherit}
#call{position:relative;width:100%;height:100%;min-height:100%;background:#07130f;overflow:hidden;color:#fff}
#remoteGrid{position:absolute;inset:0;display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));grid-auto-rows:minmax(0,1fr);gap:3px;background:#07130f}
.remoteTile{position:relative;min-width:0;min-height:0;background:#0d1a16;overflow:hidden}.remoteTile video{width:100%;height:100%;object-fit:cover;display:block}
#remoteGrid.direct{display:block}.remoteTile:only-child{width:100%;height:100%}
#localBox{position:absolute;right:12px;bottom:82px;width:min(25%,150px);height:min(29%,118px);z-index:7;border-radius:10px;overflow:hidden;background:#12241f;box-shadow:0 2px 14px rgba(0,0,0,.35);display:none}#localBox.has-video{display:block}#localBox video{width:100%;height:100%;object-fit:cover;display:block;transform:scaleX(-1)}
.label{position:absolute;right:6px;bottom:6px;background:rgba(0,0,0,.45);padding:3px 6px;border-radius:6px;font-size:10px;z-index:2}
#status{position:absolute;top:8px;right:10px;left:10px;z-index:10;margin:0;text-align:center;font-size:12px;color:#eef5f2;text-shadow:0 1px 3px #000;pointer-events:none}
#controls{position:absolute;left:50%;bottom:12px;transform:translateX(-50%);z-index:12;display:flex;align-items:center;gap:16px;direction:ltr}
.iconBtn{width:54px;height:54px;border:0;background:transparent!important;padding:0;display:grid;place-items:center;cursor:pointer;filter:drop-shadow(0 2px 3px rgba(0,0,0,.5))}.iconBtn[hidden]{display:none!important}.iconBtn:disabled{opacity:.35;cursor:default}.iconBtn img{width:54px;height:54px;display:block;object-fit:contain}
.smallBtn{width:36px;height:36px;border:0;background:rgba(0,0,0,.26);color:#fff;border-radius:50%;font-size:20px;display:grid;place-items:center;padding:0;cursor:pointer;backdrop-filter:blur(3px)}.smallBtn[hidden]{display:none!important}
#topTools{position:absolute;left:9px;top:8px;z-index:13;display:flex;gap:7px;direction:ltr}
#recordBtn{font-size:18px;color:#ff4f4f}#recordBtn.recording{animation:pulse 1s infinite}.recDot{width:12px;height:12px;border-radius:50%;background:#ff4141;display:block}
@keyframes pulse{50%{opacity:.45}}
#recordHint{position:absolute;top:52px;left:50%;transform:translateX(-50%);z-index:14;max-width:88%;background:rgba(0,0,0,.76);color:#fff;padding:9px 12px;border-radius:10px;font-size:12px;line-height:1.7;text-align:center;display:none}#recordHint.show{display:block}
#empty{position:absolute;inset:0;display:grid;place-items:center;color:#aebdb7;font-size:13px;z-index:1;pointer-events:none}
#camFix{position:absolute;left:50%;top:50%;transform:translate(-50%,-50%);z-index:15;display:none;flex-direction:column;gap:8px;align-items:center;background:rgba(0,0,0,.72);padding:14px 16px;border-radius:12px;max-width:90%}
#camFix.show{display:flex}#camFix button{border:0;background:#1b5e4b;color:#fff;border-radius:10px;padding:10px 14px;cursor:pointer;font-size:13px}
@media(max-width:640px){#localBox{width:29%;height:23%;right:8px;bottom:74px}.iconBtn,.iconBtn img{width:50px;height:50px}#controls{bottom:9px;gap:14px}.smallBtn{width:34px;height:34px}}
</style>
</head>
<body>
<div id="call" data-room="<?= e($roomKey) ?>" data-media="<?= e($media) ?>" data-can-record="<?= $canRecord ? '1' : '0' ?>">
  <div id="remoteGrid"></div>
  <div id="empty">در انتظار ورود طرف مقابل…</div>
  <div id="localBox"><span class="label">شما</span></div>
  <p id="status">در حال آماده‌سازی تماس…</p>
  <div id="camFix"><button type="button" id="enableCam">فعال‌سازی دوربین و میکروفون</button></div>
  <div id="topTools">
    <button type="button" class="smallBtn" id="fullscreen" title="تمام صفحه" aria-label="تمام صفحه">⛶</button>
    <?php if ($canRecord): ?><button type="button" class="smallBtn" id="recordBtn" title="ضبط جلسه" aria-label="ضبط جلسه"><span class="recDot"></span></button><?php endif; ?>
    <button type="button" class="smallBtn" id="close" title="بستن تماس" aria-label="بستن تماس">×</button>
  </div>
  <?php if ($canRecord): ?><div id="recordHint">اگر می‌خواهید این جلسه را ضبط کنید، دکمه قرمز ضبط را بزنید. فایل ضبط روی دستگاه شما ذخیره می‌شود.</div><?php endif; ?>
  <div id="controls">
    <button type="button" class="iconBtn" id="connect" aria-label="تماس مجدد" title="تماس مجدد"><img alt="" src="data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAGAAAABgCAYAAADimHc4AAABiUlEQVR4nO3Z0U3DQBAEUIOohDboheLohTZoBb4soSgotu5uZ43e+46SuZ3YTu62DQAAAAAAAADgP3pKBzjj9fP9++hrv94+LrG21iHPDPyRroW0CzVz6H/pVEabIBWDv9WhiHiAxOBvJYt4Tn3wtvUY/rZlc0Sa7zL4e6qvhvIroPPwt60+X2kB3Ye/q8xZVsBVhr+ryltSwNWGv6vIvbyAqw5/tzr/0gKuPvzdynVE/wew8H/Aqm/Nkd/pyc8+a0kBswcwsvBOWe55mflms81Y7P4eXZ9HbW9BKy73kUyrtiiW73ucXXTFXkynk7Wyjbeacji67cCHuUpypL2TOg+714V70bGjuIuC0icSjyO0OH0zECprXe8RfGEencU7YiRu/rqedCh9zDBcwaXnUJXXIPFTB7aFUldMptNzRMAWEKCFNAmALCFBCmgDAFhCkgTAFhCghTQJgCwhQQNlTA7JOsqpOxTrmHr4BZi6k+luySe8otaDRE6kz4qrkBAAAAAAAAAAAAAPr5AXM6q36UdGgMAAAAAElFTkSuQmCC"></button>
    <button type="button" class="iconBtn" id="leave" aria-label="قطع تماس" title="قطع تماس" hidden><img alt="" src="data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAGAAAABgCAYAAADimHc4AAABhUlEQVR4nO3ZUW7CMBAE0LTqBXonjs2deoT2K1KFqEhke2dTvfeNYLxDErC3DQAAAAAAAADgP3pLBzjj63b7Pvraz/v9EmtrHfLMwF/pWki7UDOH/pdOZbQJUjH4Rx2KiAdIDP5Rsoj31AdvW4/hb1s2R6T5LoN/pvpqKL8COg9/2+rzlRbQffi7ypxlBVxl+LuqvCUFXG34u4rcywu46vB3q/MvLeDqw9+tXEf0fwAL/wes+tYc+Z2e/OyzlhQwewAjC++U5ZmPmW8224zF7u/R9XnU9ha04nIfybRqi2L5vsfZRVfsxXQ6WSvbeDqy6MqNsFd5qrKUPQO634t31buhsYOIxyIShyK/M3Q4HSNgWusdf2Eckc49ZSti9L6eei50yD1cwKzhVZfQJfdQAbOHVlVCp9x2Q8MUEKaAMAWEKSBMAWEKCFNAmALCFBCmgDAFhCkgTAFhQwXMPsmqOhnrlHv4Cpi1mOpjyS65p9yCRkOkzoSvmhsAAAAAAAAAAAAAoJ8fxgit1iqqH/8AAAAASUVORK5CYII="></button>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/livekit-client@2.22.3/dist/livekit-client.umd.min.js"></script>
<script src="/assets/js/mana-livekit-call.js?v=20260911l"></script>
<script>
(function(){
var root=document.getElementById('call'),key=root.dataset.room||'',audioOnly=root.dataset.media==='audio',canRecord=root.dataset.canRecord==='1';
var remoteGrid=document.getElementById('remoteGrid'),localBox=document.getElementById('localBox'),empty=document.getElementById('empty'),statusEl=document.getElementById('status');
var connectBtn=document.getElementById('connect'),leaveBtn=document.getElementById('leave'),fullBtn=document.getElementById('fullscreen'),closeBtn=document.getElementById('close'),recordBtn=document.getElementById('recordBtn'),recordHint=document.getElementById('recordHint');
var camFix=document.getElementById('camFix'),enableCamBtn=document.getElementById('enableCam');
var room=null,busy=false,csrf=(document.querySelector('meta[name="csrf-token"]')||{}).content||'',everConnected=false;
var recorder=null,recordChunks=[],drawTimer=null,recCanvas=null,recCtx=null,audioCtx=null,audioDest=null,audioSources=[];
function status(s){statusEl.textContent=s||'';statusEl.style.display=s?'block':'none'}
function controls(on){connectBtn.hidden=on;leaveBtn.hidden=!on;connectBtn.disabled=busy;leaveBtn.disabled=busy}
function showCamFix(on){if(camFix)camFix.classList.toggle('show',!!on)}
function tid(s){return 'lk-'+String(s||'').replace(/[^a-zA-Z0-9_-]/g,'_')}
function updateLayout(){var n=remoteGrid.querySelectorAll('.remoteTile').length;remoteGrid.classList.toggle('direct',n<=1);empty.style.display=n?'none':'grid'}
function remoteTile(id){var x=document.getElementById(tid(id));if(x)return x;x=document.createElement('div');x.id=tid(id);x.className='remoteTile';var n=document.createElement('span');n.className='label';n.textContent='شرکت‌کننده';x.appendChild(n);remoteGrid.appendChild(x);updateLayout();return x}
function getContainer(p,local){return local?localBox:remoteTile(p&&p.identity?p.identity:'remote')}
function attach(track,p,local){
  if(!window.ManaLiveKit)return;
  ManaLiveKit.attachTrack(track,getContainer(p,local),{
    muted:!!local&&ManaLiveKit.isVideoTrack(track),
    mirrored:!!local&&ManaLiveKit.isVideoTrack(track),
    prepend:true,
    onVideo:function(){if(local)localBox.classList.add('has-video');updateLayout();}
  });
}
function localPub(pub){if(pub&&pub.track&&room)attach(pub.track,room.localParticipant,true)}
function unlock(){document.querySelectorAll('audio,video').forEach(function(el){if(window.ManaLiveKit)ManaLiveKit.safePlay(el); else {var p=el.play();if(p&&p.catch)p.catch(function(){})}})}
function showRecordHint(){if(!canRecord||!recordHint)return;recordHint.classList.add('show');setTimeout(function(){recordHint.classList.remove('show')},9000)}
function mediaTrack(pub){return pub&&pub.track&&(pub.track.mediaStreamTrack||pub.track._mediaStreamTrack)||null}
function addAudioTrack(track){if(!audioCtx||!audioDest||!track)return;try{var src=audioCtx.createMediaStreamSource(new MediaStream([track]));src.connect(audioDest);audioSources.push(src)}catch(e){}}
function stopRecording(silent){if(!recorder)return;try{recorder.stop()}catch(e){}if(drawTimer){cancelAnimationFrame(drawTimer);drawTimer=null}if(audioSources.length)audioSources.forEach(function(s){try{s.disconnect()}catch(e){}});audioSources=[];if(audioCtx){try{audioCtx.close()}catch(e){}audioCtx=null}audioDest=null;if(recordBtn){recordBtn.classList.remove('recording');recordBtn.title='ضبط جلسه'}if(!silent)status('ضبط پایان یافت و فایل آماده می‌شود…')}
function startRecording(){if(!canRecord||recorder||!room)return;if(!window.MediaRecorder||!HTMLCanvasElement.prototype.captureStream){alert('ضبط در این مرورگر پشتیبانی نمی‌شود.');return}recCanvas=document.createElement('canvas');recCanvas.width=1280;recCanvas.height=720;recCtx=recCanvas.getContext('2d');var mixed=recCanvas.captureStream(15);var AC=window.AudioContext||window.webkitAudioContext;if(AC){try{audioCtx=new AC();audioDest=audioCtx.createMediaStreamDestination();room.localParticipant.trackPublications.forEach(function(pub){var t=mediaTrack(pub);if(t&&t.kind==='audio')addAudioTrack(t)});room.remoteParticipants.forEach(function(p){p.trackPublications.forEach(function(pub){var t=mediaTrack(pub);if(t&&t.kind==='audio')addAudioTrack(t)})});audioDest.stream.getAudioTracks().forEach(function(t){mixed.addTrack(t)})}catch(e){}}var opts={};if(MediaRecorder.isTypeSupported&&MediaRecorder.isTypeSupported('video/webm;codecs=vp9,opus'))opts.mimeType='video/webm;codecs=vp9,opus';else if(MediaRecorder.isTypeSupported&&MediaRecorder.isTypeSupported('video/webm'))opts.mimeType='video/webm';try{recorder=new MediaRecorder(mixed,opts)}catch(e){recorder=new MediaRecorder(mixed)}recordChunks=[];recorder.ondataavailable=function(e){if(e.data&&e.data.size)recordChunks.push(e.data)};recorder.onstop=function(){var blob=new Blob(recordChunks,{type:recorder.mimeType||'video/webm'}),a=document.createElement('a'),stamp=new Date().toISOString().replace(/[:.]/g,'-');a.href=URL.createObjectURL(blob);a.download='mana-session-'+stamp+'.webm';document.body.appendChild(a);a.click();setTimeout(function(){URL.revokeObjectURL(a.href);a.remove()},1000);recorder=null;status('فایل ضبط ذخیره شد.');setTimeout(function(){status('تماس برقرار است.')},2500)};recorder.start(1000);if(recordBtn){recordBtn.classList.add('recording');recordBtn.title='توقف ضبط'}function draw(){if(!recorder)return;recCtx.fillStyle='#07130f';recCtx.fillRect(0,0,1280,720);var v=remoteGrid.querySelector('video')||localBox.querySelector('video');if(v&&v.readyState>=2){try{recCtx.drawImage(v,0,0,1280,720)}catch(e){}}drawTimer=requestAnimationFrame(draw)}draw();status('در حال ضبط جلسه…')}
async function connect(){
  if(busy||room||!window.ManaLiveKit||!window.LivekitClient)return;
  busy=true;controls(false);showCamFix(false);status('در حال اتصال…');
  try{
    var res=await ManaLiveKit.connectRoom({
      roomKey:key,csrf:csrf,audioOnly:audioOnly,onStatus:status,
      hooks:{
        getContainer:getContainer,
        onTrack:function(t,pub,p){attach(t,p,false)},
        onLocalPub:localPub,
        onParticipantLeft:function(p){var x=document.getElementById(tid(p.identity));if(x)x.remove();updateLayout();if(everConnected&&room&&room.remoteParticipants.size===0)status('طرف مقابل از تماس خارج شد.')},
        onDisconnected:function(){if(recorder)stopRecording(true);busy=false;room=null;localBox.classList.remove('has-video');controls(false);showCamFix(false);status('تماس پایان یافت. برای تماس مجدد دکمه سبز را بزنید.')},
        onStatus:status
      }
    });
    room=res.room;everConnected=true;busy=false;controls(true);unlock();
    if(!audioOnly&&!(res.media&&res.media.cam)){showCamFix(true);status('صدا وصل شد؛ برای تصویر روی فعال‌سازی دوربین بزنید.');}
    else status('تماس برقرار است.');
    showRecordHint();
  }catch(e){
    busy=false;if(room){try{room.disconnect()}catch(x){}room=null}
    controls(false);showCamFix(true);status(e&&e.message?e.message:'اتصال برقرار نشد. دوباره تلاش کنید.');
  }
}
async function enableCam(){
  unlock();
  if(!room){await connect();return;}
  try{
    if(!room.localParticipant.isMicrophoneEnabled)await room.localParticipant.setMicrophoneEnabled(true);
    if(!audioOnly)await room.localParticipant.setCameraEnabled(true);
    room.localParticipant.trackPublications.forEach(localPub);
    showCamFix(false);status('تماس برقرار است.');
  }catch(e){status(e&&e.message?e.message:'دوربین فعال نشد.');showCamFix(true)}
}
function leave(){if(busy)return;if(recorder)stopRecording(true);if(room){try{room.disconnect()}catch(e){}room=null}remoteGrid.innerHTML='';localBox.querySelectorAll('video').forEach(function(v){v.remove()});localBox.classList.remove('has-video');updateLayout();controls(false);showCamFix(false);status('تماس قطع شد. برای تماس مجدد دکمه سبز را بزنید.')}
connectBtn.addEventListener('click',function(){unlock();connect()});
if(enableCamBtn)enableCamBtn.addEventListener('click',function(){enableCam()});
leaveBtn.addEventListener('click',leave);
fullBtn.addEventListener('click',function(){var el=document.documentElement;if(document.fullscreenElement){document.exitFullscreen().catch(function(){})}else if(el.requestFullscreen){el.requestFullscreen().catch(function(){})}});
closeBtn.addEventListener('click',function(){leave();parent.postMessage({type:'mana-livekit-leave'},location.origin)});
if(recordBtn)recordBtn.addEventListener('click',function(){if(recorder)stopRecording(false);else startRecording()});
document.addEventListener('visibilitychange',function(){if(!document.hidden)unlock()});
window.addEventListener('pagehide',function(){if(recorder)stopRecording(true);if(room)room.disconnect()});
window.addEventListener('message',function(ev){if(ev.origin!==location.origin)return;if(ev.data&&ev.data.type==='mana-livekit-connect'){unlock();if(!room)connect()}});
setTimeout(connect,120);
})();
</script>
</body>
</html>
