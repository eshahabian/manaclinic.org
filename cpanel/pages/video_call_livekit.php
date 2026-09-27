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
      <div class="lk-controls" style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center">
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
<script>
(function(){
  var root=document.querySelector('[data-livekit-v2]'); if(!root)return;
  var key=root.dataset.room||'', audioOnly=root.dataset.media==='audio', auto=root.dataset.auto==='1';
  var remotesEl=root.querySelector('[data-lk-remotes]'), selfEl=root.querySelector('[data-lk-self]'), statusEl=root.querySelector('[data-lk-status]');
  var stageEl=root.querySelector('[data-lk-stage]');
  var connectBtn=root.querySelector('[data-lk-connect]'), cameraBtn=root.querySelector('[data-lk-camera]'), micBtn=root.querySelector('[data-lk-mic]'), leaveBtn=root.querySelector('[data-lk-leave]'), recordBtn=root.querySelector('[data-lk-record]');
  var fsBtn=root.querySelector('[data-lk-fs]'), bgSelect=root.querySelector('[data-lk-bg]');
  var room=null, busy=false, joined=false;
  var recorder=null, recordChunks=[], drawTimer=null, audioCtx=null, audioDest=null, audioSources=[];
  var bgMode='none', bgBusy=false, bgActive=false;
  var bgRawStream=null, bgVideo=null, bgCanvas=null, bgCtx=null, bgMask=null, bgMaskCtx=null, bgRaf=null, bgPub=null;
  var bgImages={};

  function status(s){if(statusEl)statusEl.textContent=s;}
  function cameraIsOn(){
    if(bgActive)return true;
    return !!(room&&room.localParticipant&&room.localParticipant.isCameraEnabled);
  }
  function syncCameraLabel(){
    if(!cameraBtn||cameraBtn.hidden)return;
    cameraBtn.textContent=cameraIsOn()?'خاموش کردن دوربین':'روشن کردن دوربین';
  }
  function buttons(on){
    connectBtn.disabled=on||busy;
    if(cameraBtn)cameraBtn.disabled=!on;
    micBtn.disabled=!on;
    leaveBtn.disabled=!on;
    if(recordBtn)recordBtn.disabled=!on;
    if(fsBtn)fsBtn.disabled=!on;
    if(bgSelect)bgSelect.disabled=!on||audioOnly;
    if(on)connectBtn.textContent='متصل';
    syncCameraLabel();
  }
  function mediaTrack(pub){return pub&&pub.track&&(pub.track.mediaStreamTrack||pub.track._mediaStreamTrack)||null;}
  function addAudioTrack(track){if(!audioCtx||!audioDest||!track)return;try{var src=audioCtx.createMediaStreamSource(new MediaStream([track]));src.connect(audioDest);audioSources.push(src);}catch(e){}}
  function setRecordUi(on){if(!recordBtn)return;recordBtn.classList.toggle('is-recording',!!on);recordBtn.textContent=on?'توقف ضبط':'ضبط جلسه';}
  function stopRecording(silent){
    if(drawTimer){cancelAnimationFrame(drawTimer);drawTimer=null;}
    audioSources.forEach(function(s){try{s.disconnect();}catch(e){}});
    audioSources=[];
    if(audioCtx){try{audioCtx.close();}catch(e){}audioCtx=null;}
    audioDest=null;
    if(!recorder){setRecordUi(false);return;}
    var rec=recorder; recorder=null; setRecordUi(false);
    try{if(rec.state!=='inactive')rec.stop();}catch(e){}
    if(!silent)status('ضبط پایان یافت و فایل آماده می‌شود…');
  }
  function drawCover(g,v,x,y,w,h){
    if(!v||v.readyState<2||!v.videoWidth)return;
    var scale=Math.max(w/v.videoWidth,h/v.videoHeight), dw=v.videoWidth*scale, dh=v.videoHeight*scale;
    g.save(); g.beginPath(); g.rect(x,y,w,h); g.clip();
    g.drawImage(v, x+(w-dw)/2, y+(h-dh)/2, dw, dh);
    g.restore();
  }
  function startRecording(){
    if(!recordBtn||recorder||!room)return;
    if(!window.MediaRecorder||!HTMLCanvasElement.prototype.captureStream){status('ضبط در این مرورگر پشتیبانی نمی‌شود.');return;}
    var canvas=document.createElement('canvas'); canvas.width=1280; canvas.height=720;
    var g=canvas.getContext('2d');
    var mixed=canvas.captureStream(15);
    var AC=window.AudioContext||window.webkitAudioContext;
    if(AC){try{
      audioCtx=new AC(); audioDest=audioCtx.createMediaStreamDestination();
      room.localParticipant.trackPublications.forEach(function(pub){var t=mediaTrack(pub);if(t&&t.kind==='audio')addAudioTrack(t);});
      room.remoteParticipants.forEach(function(p){p.trackPublications.forEach(function(pub){var t=mediaTrack(pub);if(t&&t.kind==='audio')addAudioTrack(t);});});
      audioDest.stream.getAudioTracks().forEach(function(t){mixed.addTrack(t);});
    }catch(e){}}
    recordChunks=[];
    var opts={};
    if(MediaRecorder.isTypeSupported&&MediaRecorder.isTypeSupported('video/webm;codecs=vp9,opus'))opts.mimeType='video/webm;codecs=vp9,opus';
    else if(MediaRecorder.isTypeSupported&&MediaRecorder.isTypeSupported('video/webm'))opts.mimeType='video/webm';
    try{recorder=new MediaRecorder(mixed,opts);}catch(e){try{recorder=new MediaRecorder(mixed);}catch(err){recorder=null;status('ضبط در این مرورگر پشتیبانی نمی‌شود.');return;}}
    var recMime=recorder.mimeType||opts.mimeType||'video/webm';
    recorder.ondataavailable=function(e){if(e.data&&e.data.size)recordChunks.push(e.data);};
    recorder.onstop=function(){
      if(!recordChunks.length)return;
      var blob=new Blob(recordChunks,{type:recMime});
      recordChunks=[];
      var a=document.createElement('a');
      a.href=URL.createObjectURL(blob);
      a.download='mana-session-'+new Date().toISOString().replace(/[:.]/g,'-')+'.webm';
      document.body.appendChild(a); a.click();
      setTimeout(function(){URL.revokeObjectURL(a.href);a.remove();},1000);
      if(joined)status('فایل ضبط روی دستگاه ذخیره شد.');
    };
    recorder.start(1000);
    setRecordUi(true);
    status('در حال ضبط جلسه… فایل بعد از توقف روی دستگاه ذخیره می‌شود.');
    (function draw(){
      if(!recorder)return;
      g.fillStyle='#0d1a16'; g.fillRect(0,0,1280,720);
      var remote=remotesEl?remotesEl.querySelector('video'):null;
      var self=selfEl?selfEl.querySelector('video'):null;
      if(remote)drawCover(g,remote,0,0,1280,720);
      else if(self)drawCover(g,self,0,0,1280,720);
      if(remote&&self)drawCover(g,self,1280-280-28,720-200-28,280,200);
      drawTimer=requestAnimationFrame(draw);
    })();
  }

  function fsActive(){
    return !!(document.fullscreenElement||document.webkitFullscreenElement)||(!!stageEl&&stageEl.classList.contains('is-fs-fallback'));
  }
  function setFsUi(on){if(fsBtn)fsBtn.textContent=on?'خروج از تمام‌صفحه':'تمام‌صفحه';}
  function exitFsFallback(){
    if(!stageEl)return;
    stageEl.classList.remove('is-fs-fallback');
    document.documentElement.classList.remove('lk-fs-active');
  }
  function enterFullscreen(){
    if(!stageEl)return;
    var req=stageEl.requestFullscreen||stageEl.webkitRequestFullscreen||stageEl.msRequestFullscreen;
    if(req){
      try{
        var p=req.call(stageEl);
        if(p&&p.catch)p.catch(function(){stageEl.classList.add('is-fs-fallback');document.documentElement.classList.add('lk-fs-active');setFsUi(true);});
        else setFsUi(true);
        return;
      }catch(e){}
    }
    stageEl.classList.add('is-fs-fallback');
    document.documentElement.classList.add('lk-fs-active');
    setFsUi(true);
  }
  function exitFullscreen(){
    var exit=document.exitFullscreen||document.webkitExitFullscreen||document.msExitFullscreen;
    if(document.fullscreenElement||document.webkitFullscreenElement){
      try{exit.call(document);}catch(e){}
    }
    exitFsFallback();
    setFsUi(false);
  }
  function toggleFullscreen(){if(fsActive())exitFullscreen();else enterFullscreen();}
  document.addEventListener('fullscreenchange',function(){setFsUi(fsActive());if(!document.fullscreenElement)exitFsFallback();});
  document.addEventListener('webkitfullscreenchange',function(){setFsUi(fsActive());if(!document.webkitFullscreenElement)exitFsFallback();});

  function loadBgImage(name,src){
    if(!src)return null;
    if(bgImages[name])return bgImages[name];
    var img=new Image();
    img.decoding='async';
    img.src=src;
    bgImages[name]=img;
    return img;
  }
  function ensureBgCanvas(w,h){
    if(!bgCanvas){
      bgCanvas=document.createElement('canvas');
      bgCanvas.width=w; bgCanvas.height=h;
      bgCanvas.style.cssText='position:fixed;left:-9999px;top:0;width:1px;height:1px;opacity:0;pointer-events:none';
      document.body.appendChild(bgCanvas);
      bgCtx=bgCanvas.getContext('2d');
      bgMask=document.createElement('canvas');
      bgMask.width=w; bgMask.height=h;
      bgMaskCtx=bgMask.getContext('2d');
    }else if(bgCanvas.width!==w||bgCanvas.height!==h){
      bgCanvas.width=w; bgCanvas.height=h;
      bgMask.width=w; bgMask.height=h;
    }
  }
  function drawVideoCover(ctx,video,w,h){
    if(!video||video.readyState<2||!video.videoWidth)return;
    var scale=Math.max(w/video.videoWidth,h/video.videoHeight);
    var dw=video.videoWidth*scale, dh=video.videoHeight*scale;
    ctx.drawImage(video,(w-dw)/2,(h-dh)/2,dw,dh);
  }
  function drawSoftPerson(ctx,video,w,h){
    if(!bgMaskCtx||!video||video.readyState<2)return;
    bgMaskCtx.clearRect(0,0,w,h);
    drawVideoCover(bgMaskCtx,video,w,h);
    bgMaskCtx.globalCompositeOperation='destination-in';
    var g=bgMaskCtx.createRadialGradient(w*0.5,h*0.42,Math.min(w,h)*0.16,w*0.5,h*0.52,Math.min(w,h)*0.48);
    g.addColorStop(0,'rgba(0,0,0,1)');
    g.addColorStop(0.55,'rgba(0,0,0,.92)');
    g.addColorStop(1,'rgba(0,0,0,0)');
    bgMaskCtx.fillStyle=g;
    bgMaskCtx.fillRect(0,0,w,h);
    bgMaskCtx.globalCompositeOperation='source-over';
    ctx.drawImage(bgMask,0,0);
  }
  function paintBackground(w,h){
    if(bgMode==='blur'){
      bgCtx.save();
      bgCtx.filter='blur(14px)';
      drawVideoCover(bgCtx,bgVideo,w,h);
      bgCtx.restore();
      bgCtx.fillStyle='rgba(13,26,22,.18)';
      bgCtx.fillRect(0,0,w,h);
      return;
    }
    if(bgMode==='green'){
      bgCtx.fillStyle='#1b5e4b';
      bgCtx.fillRect(0,0,w,h);
      bgCtx.fillStyle='rgba(255,255,255,.06)';
      for(var i=0;i<18;i++){
        bgCtx.beginPath();
        bgCtx.arc((i*73)%w,(i*97)%h,18+(i%5)*6,0,Math.PI*2);
        bgCtx.fill();
      }
      return;
    }
    if(bgMode==='mint'){
      var grd=bgCtx.createLinearGradient(0,0,w,h);
      grd.addColorStop(0,'#d8efe6');
      grd.addColorStop(0.55,'#8fd4ba');
      grd.addColorStop(1,'#134437');
      bgCtx.fillStyle=grd;
      bgCtx.fillRect(0,0,w,h);
      return;
    }
    var img=bgImages.room||bgImages.soft;
    if(img&&img.complete&&img.naturalWidth){
      var scale=Math.max(w/img.naturalWidth,h/img.naturalHeight);
      var dw=img.naturalWidth*scale, dh=img.naturalHeight*scale;
      bgCtx.drawImage(img,(w-dw)/2,(h-dh)/2,dw,dh);
      bgCtx.fillStyle='rgba(13,26,22,.28)';
      bgCtx.fillRect(0,0,w,h);
    }else{
      bgCtx.fillStyle='#0d1a16';
      bgCtx.fillRect(0,0,w,h);
    }
  }
  function bgDrawLoop(){
    if(!bgActive||!bgCtx||!bgVideo){bgRaf=null;return;}
    var w=bgCanvas.width, h=bgCanvas.height;
    paintBackground(w,h);
    drawSoftPerson(bgCtx,bgVideo,w,h);
    bgRaf=requestAnimationFrame(bgDrawLoop);
  }
  async function unpublishCameraTracks(){
    if(!room)return;
    var pubs=[];
    room.localParticipant.trackPublications.forEach(function(pub){
      if(pub&&pub.track&&pub.track.kind===LivekitClient.Track.Kind.Video)pubs.push(pub);
    });
    for(var i=0;i<pubs.length;i++){
      try{await room.localParticipant.unpublishTrack(pubs[i].track);}catch(e){}
    }
    bgPub=null;
  }
  async function stopBgPipeline(restoreCamera){
    bgActive=false;
    if(bgRaf){cancelAnimationFrame(bgRaf);bgRaf=null;}
    try{await unpublishCameraTracks();}catch(e){}
    if(bgRawStream){
      bgRawStream.getTracks().forEach(function(t){try{t.stop();}catch(e){}});
      bgRawStream=null;
    }
    if(bgVideo){try{bgVideo.srcObject=null;}catch(e){}}
    if(restoreCamera&&room&&!audioOnly){
      try{await room.localParticipant.setCameraEnabled(true);}catch(e){}
    }
    syncCameraLabel();
  }
  async function startBgPipeline(mode){
    if(!room||audioOnly||!mode||mode==='none')return;
    if(!HTMLCanvasElement.prototype.captureStream){
      status('تغییر پس‌زمینه در این مرورگر پشتیبانی نمی‌شود.');
      if(bgSelect)bgSelect.value='none';
      bgMode='none';
      return;
    }
    bgMode=mode;
    if(stageEl){
      loadBgImage('room',stageEl.getAttribute('data-bg-room')||'');
      loadBgImage('soft',stageEl.getAttribute('data-bg-soft')||'');
    }
    try{
      if(room.localParticipant.isCameraEnabled){
        await room.localParticipant.setCameraEnabled(false);
      }else{
        await unpublishCameraTracks();
      }
      if(!bgRawStream||!bgRawStream.getVideoTracks().some(function(t){return t.readyState==='live';})){
        bgRawStream=await navigator.mediaDevices.getUserMedia({video:{facingMode:'user',width:{ideal:1280},height:{ideal:720}},audio:false});
      }
      if(!bgVideo){
        bgVideo=document.createElement('video');
        bgVideo.muted=true;
        bgVideo.playsInline=true;
        bgVideo.setAttribute('playsinline','');
        bgVideo.style.cssText='position:fixed;left:-9999px;width:1px;height:1px;opacity:0';
        document.body.appendChild(bgVideo);
      }
      bgVideo.srcObject=bgRawStream;
      await bgVideo.play().catch(function(){});
      await new Promise(function(resolve){
        if(bgVideo.readyState>=2)resolve();
        else bgVideo.onloadeddata=function(){resolve();};
        setTimeout(resolve,800);
      });
      var vw=bgVideo.videoWidth||640, vh=bgVideo.videoHeight||480;
      var targetW=Math.min(960,vw||640), targetH=Math.round(targetW*((vh||480)/(vw||640)));
      ensureBgCanvas(targetW,targetH);
      if(!bgActive){
        bgActive=true;
        var out=bgCanvas.captureStream(24);
        var track=out.getVideoTracks()[0];
        bgPub=await room.localParticipant.publishTrack(track,{
          name:'camera',
          source:LivekitClient.Track.Source.Camera
        });
        bgDrawLoop();
      }
      syncCameraLabel();
    }catch(e){
      bgActive=false;
      bgMode='none';
      if(bgSelect)bgSelect.value='none';
      status((e&&e.message)?e.message:'اعمال پس‌زمینه ممکن نشد.');
      try{await room.localParticipant.setCameraEnabled(true);}catch(err){}
      syncCameraLabel();
    }
  }
  async function setBackground(mode){
    if(bgBusy||!joined||!room)return;
    bgBusy=true;
    try{
      mode=String(mode||'none');
      if(mode==='none'){
        bgMode='none';
        if(bgActive)await stopBgPipeline(true);
        else syncCameraLabel();
      }else if(bgActive){
        bgMode=mode;
      }else{
        await startBgPipeline(mode);
      }
    }finally{
      bgBusy=false;
    }
  }

  function id(s){return 'lk-'+String(s||'').replace(/[^a-zA-Z0-9_-]/g,'_');}
  function remoteCount(){return room ? room.remoteParticipants.size : 0;}
  function setCallStatus(){
    if(!joined)return;
    var n=remoteCount();
    status(n>0 ? ('تماس برقرار است — طرف مقابل متصل ('+n+')') : 'تماس برقرار است — در انتظار ورود طرف مقابل…');
  }
  function tile(identity,label,local){
    var parent=local?selfEl:remotesEl;
    var x=document.getElementById(id(identity));
    if(x){
      if(parent&&x.parentElement!==parent)parent.appendChild(x);
      return x;
    }
    x=document.createElement('div');
    x.id=id(identity);
    x.className='lk-tile'+(local?' is-self':'');
    var n=document.createElement('span');
    n.textContent=label||'شرکت‌کننده';
    n.style.cssText='position:absolute;right:.45rem;bottom:.35rem;z-index:3;background:rgba(0,0,0,.55);padding:.15rem .4rem;border-radius:.4rem;font-size:.7rem;color:#fff';
    x.appendChild(n);
    if(parent)parent.appendChild(x);
    return x;
  }
  function attach(track,p,local){
    var T=LivekitClient.Track;
    var who=local?'local':(p&&p.identity?p.identity:'remote');
    var x=tile(who, local?'شما':'طرف مقابل', !!local);
    var el=track.attach();
    el.autoplay=true;
    el.playsInline=true;
    el.setAttribute('playsinline','');
    if(track.kind===T.Kind.Video){
      el.muted=!!local;
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
  function unlock(){root.querySelectorAll('audio,video').forEach(function(el){var p=el.play();if(p&&p.catch)p.catch(function(){});});}
  async function cleanupMedia(){
    stopRecording(true);
    await stopBgPipeline(false);
    exitFullscreen();
  }

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
      room.on(LivekitClient.RoomEvent.Disconnected,function(){
        stopRecording(true);
        stopBgPipeline(false);
        busy=false;joined=false;status('تماس پایان یافت.');buttons(false);
      });

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
      if(bgSelect&&bgSelect.value&&bgSelect.value!=='none')setBackground(bgSelect.value);
    }catch(e){
      busy=false;joined=false;status(e&&e.message?e.message:'اتصال برقرار نشد.');
      if(room){try{room.disconnect();}catch(x){}room=null;}
      buttons(false);
    }
  }

  connectBtn.addEventListener('click',function(){unlock();connect();});
  if(cameraBtn)cameraBtn.addEventListener('click',async function(){
    if(!room)return;
    try{
      if(bgMode!=='none'||bgActive){
        if(bgActive){
          var keepMode=bgMode;
          await stopBgPipeline(false);
          bgMode=keepMode;
          syncCameraLabel();
        }else{
          var mode=(bgSelect&&bgSelect.value)||bgMode||'blur';
          if(mode==='none'){
            await room.localParticipant.setCameraEnabled(true);
          }else{
            await startBgPipeline(mode);
          }
          syncCameraLabel();
        }
      }else{
        var on=!room.localParticipant.isCameraEnabled;
        await room.localParticipant.setCameraEnabled(on);
        syncCameraLabel();
      }
    }catch(e){status('دوربین فعال نشد. دسترسی مرورگر را چک کنید.');}
  });
  micBtn.addEventListener('click',async function(){
    if(!room)return;
    var on=!room.localParticipant.isMicrophoneEnabled;
    await room.localParticipant.setMicrophoneEnabled(on);
    micBtn.textContent=on?'قطع میکروفون':'وصل میکروفون';
  });
  if(fsBtn)fsBtn.addEventListener('click',function(){toggleFullscreen();});
  if(bgSelect)bgSelect.addEventListener('change',function(){setBackground(bgSelect.value);});
  leaveBtn.addEventListener('click',async function(){
    await cleanupMedia();
    if(room)room.disconnect();
    room=null;
    if(remotesEl)remotesEl.innerHTML='';
    if(selfEl)selfEl.innerHTML='';
    joined=false;
    if(bgSelect)bgSelect.value='none';
    bgMode='none';
    status('از تماس خارج شدید.');
    buttons(false);
  });
  if(recordBtn)recordBtn.addEventListener('click',function(){if(recorder)stopRecording(false);else startRecording();});
  if(remotesEl)remotesEl.addEventListener('click',unlock);
  if(selfEl)selfEl.addEventListener('click',unlock);
  document.addEventListener('visibilitychange',function(){if(!document.hidden){unlock();hydrateRemotes();}});
  window.addEventListener('pagehide',function(){stopRecording(true);stopBgPipeline(false);if(room)room.disconnect();});

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
