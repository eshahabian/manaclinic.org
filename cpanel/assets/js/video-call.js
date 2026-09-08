(function () {
  var root = document.querySelector("[data-video-call]");
  if (!root) return;
  var signalUrl = root.getAttribute("data-signal-url") || "/video-signal";
  var peerName = root.getAttribute("data-peer-name") || "طرف مقابل";
  var stageEl = root.querySelector("[data-video-stage]");
  var localEl = root.querySelector("[data-video-local]");
  var remoteEl = root.querySelector("[data-video-remote]");
  var statusEl = root.querySelector("[data-video-status]");
  var incomingEl = root.querySelector("[data-video-incoming]");
  var permitEl = root.querySelector("[data-video-permit]");
  var permitBtn = root.querySelector("[data-video-permit-btn]");
  var startBtn = root.querySelector("[data-video-start]");
  var hangBtn = root.querySelector("[data-video-hangup]");
  var enhanceBtn = root.querySelector("[data-video-enhance]");
  var fsBtns = root.querySelectorAll("[data-video-fs]");
  var token = (document.querySelector('meta[name="csrf-token"]') || {}).content || "";

  var pc = null;
  var localStream = null;
  var remoteStream = null;
  var calling = false;
  var ready = false;
  var connectedOnce = false;
  var seen = {};
  var pendingOffer = null;
  var pendingIce = [];
  var lastPeerStatus = "";
  var audioCtx = null;
  var ringTimer = null;
  var ringUrl = root.getAttribute("data-ring-url") || "";
  var hangUrl = root.getAttribute("data-hang-url") || "";
  var ringAudio = null;
  var hangAudio = null;
  var canRecord = root.getAttribute("data-can-record") === "1";
  var guardCapture = root.getAttribute("data-guard-capture") === "1";
  var polite = root.getAttribute("data-polite") === "1";
  var makingOffer = false;
  var lastAfter = "";
  var recorder = null;
  var recChunks = [];
  var recTimer = null;
  var recordBtn = root.querySelector("[data-video-record]");
  var blackoutEl = root.querySelector("[data-video-blackout]");
  var guarded = false;

  function setStatus(text) {
    if (statusEl) statusEl.textContent = text;
  }

  function setPermit(open, message) {
    if (!permitEl) return;
    permitEl.hidden = !open;
    if (open && message) {
      var p = permitEl.querySelector("p");
      if (p) p.textContent = message;
    }
  }

  function ctx() {
    if (!audioCtx) {
      var Ctx = window.AudioContext || window.webkitAudioContext;
      if (Ctx) audioCtx = new Ctx();
    }
    if (audioCtx && audioCtx.state === "suspended") audioCtx.resume();
    return audioCtx;
  }

  function makeSound(url, loop) {
    if (!url) return null;
    var a = new Audio(url);
    a.preload = "auto";
    a.loop = !!loop;
    a.playsInline = true;
    return a;
  }

  function unlockSounds() {
    ctx();
    [ringAudio, hangAudio].forEach(function (a) {
      if (!a) return;
      var prevVol = a.volume;
      a.volume = 0;
      var play = a.play();
      if (play && play.then) {
        play.then(function () {
          a.pause();
          a.currentTime = 0;
          a.volume = prevVol || 1;
        }).catch(function () {
          a.volume = prevVol || 1;
        });
      } else {
        a.volume = prevVol || 1;
      }
    });
  }

  function playFile(audio) {
    if (!audio) return false;
    try {
      audio.pause();
      audio.currentTime = 0;
      var play = audio.play();
      if (play && play.catch) play.catch(function () {});
      return true;
    } catch (e) {
      return false;
    }
  }

  function tone(freq, start, dur, vol) {
    var ac = ctx();
    if (!ac) return;
    var osc = ac.createOscillator();
    var gain = ac.createGain();
    var filter = ac.createBiquadFilter();
    osc.type = "sine";
    osc.frequency.setValueAtTime(freq, ac.currentTime + start);
    filter.type = "lowpass";
    filter.frequency.value = 1800;
    gain.gain.setValueAtTime(0.0001, ac.currentTime + start);
    gain.gain.exponentialRampToValueAtTime(vol || 0.045, ac.currentTime + start + 0.015);
    gain.gain.exponentialRampToValueAtTime(0.0001, ac.currentTime + start + dur);
    osc.connect(filter);
    filter.connect(gain);
    gain.connect(ac.destination);
    osc.start(ac.currentTime + start);
    osc.stop(ac.currentTime + start + dur + 0.03);
  }

  function playRingBurst() {
    tone(340, 0, 0.22, 0.04);
  }

  function startRing() {
    stopRing();
    ctx();
    if (!ringAudio) ringAudio = makeSound(ringUrl, true);
    if (playFile(ringAudio)) return;
    playRingBurst();
    ringTimer = setInterval(playRingBurst, 1800);
  }

  function stopRing() {
    if (ringTimer) {
      clearInterval(ringTimer);
      ringTimer = null;
    }
    if (ringAudio) {
      ringAudio.pause();
      try { ringAudio.currentTime = 0; } catch (e) {}
    }
  }

  function playConnected() {
    stopRing();
  }

  function playDisconnected() {
    stopRing();
    if (!hangAudio) hangAudio = makeSound(hangUrl, false);
    if (playFile(hangAudio)) return;
    tone(210, 0, 0.16, 0.035);
  }

  function toggleFullscreen() {
    var el = stageEl || root;
    var req = el.requestFullscreen || el.webkitRequestFullscreen || el.msRequestFullscreen;
    var exit = document.exitFullscreen || document.webkitExitFullscreen || document.msExitFullscreen;
    if (document.fullscreenElement || document.webkitFullscreenElement) {
      if (exit) exit.call(document);
      return;
    }
    if (req) req.call(el);
  }

  function syncFullscreenIcon() {
    var on = !!(document.fullscreenElement || document.webkitFullscreenElement);
    if (stageEl) stageEl.classList.toggle("is-full", on);
    fsBtns.forEach(function (btn) {
      btn.setAttribute("aria-label", on ? "خروج از تمام‌صفحه" : "تمام‌صفحه");
      btn.setAttribute("title", on ? "خروج از تمام‌صفحه" : "تمام‌صفحه");
    });
  }

  function syncCallButtons() {
    var incoming = incomingEl && !incomingEl.hidden;
    if (startBtn) {
      startBtn.hidden = !!(calling && !incoming);
      startBtn.setAttribute("aria-label", incoming ? "پاسخ" : "شروع تماس");
      startBtn.setAttribute("title", incoming ? "پاسخ" : "شروع تماس");
    }
    if (hangBtn) {
      hangBtn.hidden = !(calling || incoming);
      hangBtn.setAttribute("aria-label", incoming && !calling ? "رد تماس" : "قطع تماس");
      hangBtn.setAttribute("title", incoming && !calling ? "رد تماس" : "قطع تماس");
    }
  }

  function setRecordingUi(on) {
    if (!recordBtn) return;
    recordBtn.classList.toggle("is-recording", !!on);
    recordBtn.setAttribute("aria-label", on ? "توقف ضبط" : "شروع ضبط");
    recordBtn.setAttribute("title", on ? "توقف ضبط" : "ضبط تماس");
  }

  function mediaErrorText(err) {
    var name = (err && err.name) || "";
    if (!window.isSecureContext) {
      return "تماس تصویری فقط روی آدرس امن (https) کار می‌کند.";
    }
    if (name === "NotAllowedError" || name === "PermissionDeniedError") {
      return "دسترسی دوربین یا میکروفون مسدود است. در قفل کنار نوار آدرس، دوربین و میکروفون را روی «اجازه» بگذارید و دوباره بزنید.";
    }
    if (name === "NotFoundError" || name === "DevicesNotFoundError") {
      return "دوربین یا میکروفونی روی این دستگاه پیدا نشد.";
    }
    if (name === "NotReadableError" || name === "TrackStartError") {
      return "دوربین در برنامه دیگری باز است. آن را ببندید و دوباره اجازه بدهید.";
    }
    if (name === "SecurityError") {
      return "مرورگر به‌خاطر تنظیمات امنیتی صفحه، دوربین را مسدود کرده است.";
    }
    return "مرورگر باید به دوربین و میکروفون دسترسی بدهد. دکمه اجازه را بزنید.";
  }

  function post(body) {
    return fetch(signalUrl, {
      method: "POST",
      credentials: "same-origin",
      headers: {
        "Content-Type": "application/json",
        Accept: "application/json",
        "X-CSRF-Token": token,
        "X-Requested-With": "XMLHttpRequest"
      },
      body: JSON.stringify(body)
    }).then(function (r) {
      return r.json().then(function (data) {
        if (!r.ok) throw new Error((data && data.error) || "خطای ارتباط");
        return data;
      });
    });
  }

  function iceServers() {
    return [
      { urls: "stun:stun.l.google.com:19302" },
      { urls: "stun:stun1.l.google.com:19302" },
      { urls: "stun:stun.cloudflare.com:3478" }
    ];
  }

  var constraintSets = [
    { audio: true, video: { facingMode: "user", width: { ideal: 1280 }, height: { ideal: 720 } } },
    { audio: true, video: true },
    { audio: true, video: { facingMode: "user" } },
    { audio: false, video: true },
    { audio: true, video: false }
  ];

  function getUserMediaFallback(index) {
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
      return Promise.reject(new Error("این مرورگر تماس تصویری را پشتیبانی نمی‌کند."));
    }
    if (index >= constraintSets.length) {
      return Promise.reject(new Error("دوربین یا میکروفون در دسترس نیست."));
    }
    return navigator.mediaDevices.getUserMedia(constraintSets[index]).catch(function (err) {
      if (err && (err.name === "NotAllowedError" || err.name === "PermissionDeniedError" || err.name === "SecurityError")) {
        return Promise.reject(err);
      }
      return getUserMediaFallback(index + 1);
    });
  }

  function attachLocal(stream) {
    localStream = stream;
    ready = true;
    if (localEl) {
      localEl.srcObject = stream;
      localEl.muted = true;
      localEl.playsInline = true;
      localEl.disablePictureInPicture = true;
      try { localEl.disableRemotePlayback = true; } catch (e) {}
      var play = localEl.play();
      if (play && play.catch) play.catch(function () {});
    }
    setPermit(false);
    if (startBtn) startBtn.disabled = false;
  }

  function requestMedia() {
    setStatus("منتظر اجازه دوربین و میکروفون…");
    return getUserMediaFallback(0).then(function (stream) {
      attachLocal(stream);
      setStatus("دوربین آماده است. می‌توانید تماس را شروع کنید.");
      return stream;
    }).catch(function (err) {
      ready = false;
      setPermit(true, mediaErrorText(err));
      setStatus(mediaErrorText(err));
      throw err;
    });
  }

  function media() {
    if (localStream && localStream.getTracks().some(function (t) { return t.readyState === "live"; })) {
      return Promise.resolve(localStream);
    }
    return requestMedia();
  }

  function addLocalTracks(conn) {
    if (!localStream || !conn) return;
    var senders = conn.getSenders ? conn.getSenders() : [];
    localStream.getTracks().forEach(function (track) {
      var already = senders.some(function (s) { return s.track && s.track.id === track.id; });
      if (!already) conn.addTrack(track, localStream);
    });
  }

  function attachRemoteTrack(ev) {
    if (!remoteStream) remoteStream = new MediaStream();
    var tracks = [];
    if (ev.streams && ev.streams[0]) {
      tracks = ev.streams[0].getTracks();
    } else if (ev.track) {
      tracks = [ev.track];
    }
    tracks.forEach(function (track) {
      var exists = remoteStream.getTracks().some(function (t) { return t.id === track.id; });
      if (!exists) remoteStream.addTrack(track);
      track.onunmute = function () {
        if (remoteEl) {
          remoteEl.srcObject = remoteStream;
          remoteEl.play().catch(function () {});
        }
      };
    });
    if (remoteEl) {
      remoteEl.srcObject = remoteStream;
      remoteEl.autoplay = true;
      remoteEl.playsInline = true;
      remoteEl.disablePictureInPicture = true;
      try { remoteEl.disableRemotePlayback = true; } catch (e) {}
      var play = remoteEl.play();
      if (play && play.catch) play.catch(function () {});
    }
  }

  function flushIce() {
    if (!pc || !pc.remoteDescription) return;
    var batch = pendingIce.splice(0, pendingIce.length);
    batch.forEach(function (c) {
      pc.addIceCandidate(new RTCIceCandidate(c)).catch(function () {});
    });
  }

  function sendLocal(kind, conn) {
    var desc = conn.localDescription;
    if (!desc) return Promise.resolve();
    return post({ action: "send", kind: kind, payload: { type: desc.type, sdp: desc.sdp } });
  }

  function ensurePc() {
    if (pc) {
      addLocalTracks(pc);
      return pc;
    }
    remoteStream = new MediaStream();
    pc = new RTCPeerConnection({ iceServers: iceServers() });
    pc.onicecandidate = function (ev) {
      if (ev.candidate) {
        post({ action: "send", kind: "ice", payload: ev.candidate.toJSON ? ev.candidate.toJSON() : ev.candidate });
      }
    };
    pc.ontrack = attachRemoteTrack;
    function markConnected() {
      if (!pc || connectedOnce) return;
      connectedOnce = true;
      playConnected();
      if (recordBtn) recordBtn.hidden = !canRecord;
      setStatus("تماس برقرار شد.");
    }
    pc.onconnectionstatechange = function () {
      if (!pc) return;
      if (pc.connectionState === "connected") markConnected();
      if (pc.connectionState === "disconnected" || pc.connectionState === "failed") {
        setStatus("ارتباط قطع شد. دوباره تماس بگیرید.");
      }
    };
    pc.oniceconnectionstatechange = function () {
      if (!pc) return;
      if (pc.iceConnectionState === "connected" || pc.iceConnectionState === "completed") markConnected();
    };
    addLocalTracks(pc);
    return pc;
  }

  function endPeer(playHang) {
    stopRing();
    stopRecording(true);
    if (playHang) playDisconnected();
    connectedOnce = false;
    makingOffer = false;
    pendingIce = [];
    pendingOffer = null;
    if (remoteEl) remoteEl.srcObject = null;
    remoteStream = null;
    if (pc) {
      try { pc.close(); } catch (e) {}
      pc = null;
    }
    calling = false;
    if (incomingEl) incomingEl.hidden = true;
    syncCallButtons();
    if (recordBtn) recordBtn.hidden = true;
    setRecordingUi(false);
  }

  function startCall() {
    ctx();
    media().then(function () {
      calling = true;
      connectedOnce = false;
      syncCallButtons();
      setStatus("در حال تماس… منتظر پاسخ طرف مقابل.");
      var conn = ensurePc();
      makingOffer = true;
      return conn.createOffer().then(function (offer) {
        return conn.setLocalDescription(offer);
      }).then(function () {
        makingOffer = false;
        return sendLocal("offer", conn);
      });
    }).catch(function (err) {
      makingOffer = false;
      if (!ready) return;
      setStatus("شروع تماس ناموفق بود. دوباره تلاش کنید.");
      endPeer(false);
      console.error(err);
    });
  }

  function answerOffer(offer) {
    var conn = ensurePc();
    addLocalTracks(conn);
    var collision = makingOffer || (conn.signalingState && conn.signalingState !== "stable");
    var prep = Promise.resolve();
    if (collision) {
      if (!polite) return Promise.resolve();
      prep = conn.setLocalDescription({ type: "rollback" }).catch(function () {});
    }
    return prep.then(function () {
      return conn.setRemoteDescription(new RTCSessionDescription(offer));
    }).then(function () {
      flushIce();
      addLocalTracks(conn);
      return conn.createAnswer();
    }).then(function (answer) {
      return conn.setLocalDescription(answer);
    }).then(function () {
      return sendLocal("answer", conn);
    });
  }

  function acceptCall(offer) {
    ctx();
    media().then(function () {
      calling = true;
      connectedOnce = false;
      stopRing();
      if (incomingEl) incomingEl.hidden = true;
      syncCallButtons();
      setStatus("در حال پاسخ…");
      return answerOffer(offer);
    }).catch(function (err) {
      if (!ready) return;
      setStatus("پاسخ به تماس ناموفق بود.");
      endPeer(false);
      console.error(err);
    });
  }

  function handle(sig) {
    if (!sig || seen[sig.id]) return;
    seen[sig.id] = true;
    var kind = sig.kind;
    var payload = sig.payload;
    if (kind === "offer" && payload) {
      pendingOffer = payload;
      if (calling && pc) {
        answerOffer(payload).catch(function (err) { console.error(err); });
      } else if (incomingEl) {
        incomingEl.hidden = false;
        syncCallButtons();
        setStatus("تماس ورودی از " + peerName);
        startRing();
      }
    } else if (kind === "answer" && payload && pc) {
      if (pc.signalingState !== "have-local-offer") return;
      pc.setRemoteDescription(new RTCSessionDescription(payload)).then(function () {
        flushIce();
      }).catch(function (err) { console.error(err); });
    } else if (kind === "ice" && payload) {
      pendingIce.push(payload);
      flushIce();
    } else if (kind === "hangup") {
      if (!calling && incomingEl && incomingEl.hidden) return;
      endPeer(true);
      setStatus("تماس قطع شد.");
    }
  }

  function poll() {
    post({ action: "poll", after: lastAfter }).then(function (data) {
      if (!data || !data.ok) return;
      if (!calling) {
        var next = data.online
          ? peerName + " آنلاین است."
          : peerName + " فعلاً در این صفحه نیست — هر دو باید این صفحه را باز کنید.";
        if (ready && next !== lastPeerStatus && !ringTimer) {
          lastPeerStatus = next;
          setStatus(next);
        } else if (!ready) {
          lastPeerStatus = next;
        }
      }
      (data.signals || []).forEach(function (sig) {
        handle(sig);
        if (sig.created_at && sig.created_at > lastAfter) lastAfter = sig.created_at;
      });
    }).catch(function () {});
  }

  function setBlackout(on) {
    guarded = !!on;
    root.classList.toggle("is-black", guarded);
    if (blackoutEl) blackoutEl.hidden = !guarded;
  }

  function captureRisk() {
    if (!guardCapture) return false;
    if (document.hidden || document.visibilityState === "hidden") return true;
    if (document.pictureInPictureElement) return true;
    return false;
  }

  function tickGuard() {
    if (!guardCapture) return;
    setBlackout(captureRisk());
  }

  function captureHotkey(e) {
    if (!guardCapture) return;
    var k = e.key || "";
    var kl = k.toLowerCase();
    if (k === "PrintScreen" || k === "Snapshot") {
      setBlackout(true);
      setTimeout(function () { if (!document.hidden) setBlackout(false); }, 2000);
      return;
    }
    if ((e.metaKey || e.ctrlKey) && e.shiftKey && (kl === "3" || kl === "4" || kl === "5")) {
      setBlackout(true);
      setTimeout(function () { if (!document.hidden) setBlackout(false); }, 2000);
    }
  }

  function downloadRecording() {
    if (!recChunks.length) return;
    var blob = new Blob(recChunks, { type: recChunks[0].type || "video/webm" });
    recChunks = [];
    var a = document.createElement("a");
    a.href = URL.createObjectURL(blob);
    a.download = "video-call-" + new Date().toISOString().slice(0, 19).replace(/[:T]/g, "-") + ".webm";
    document.body.appendChild(a);
    a.click();
    setTimeout(function () {
      URL.revokeObjectURL(a.href);
      a.remove();
    }, 1500);
  }

  function stopRecording(save) {
    if (recTimer) {
      cancelAnimationFrame(recTimer);
      recTimer = null;
    }
    if (!recorder) {
      setRecordingUi(false);
      return;
    }
    var rec = recorder;
    recorder = null;
    if (save === false) {
      rec.ondataavailable = null;
      rec.onstop = null;
      recChunks = [];
      try { if (rec.state !== "inactive") rec.stop(); } catch (e) {}
    } else if (rec.state !== "inactive") {
      rec.stop();
    } else {
      downloadRecording();
    }
    setRecordingUi(false);
  }

  function startRecording() {
    if (!canRecord || recorder) return;
    recChunks = [];
    var canvas = document.createElement("canvas");
    canvas.width = 1280;
    canvas.height = 720;
    var g = canvas.getContext("2d");
    function draw() {
      if (!recorder) return;
      g.fillStyle = "#111";
      g.fillRect(0, 0, canvas.width, canvas.height);
      try {
        if (remoteEl && remoteEl.readyState >= 2) {
          g.drawImage(remoteEl, 0, 0, canvas.width, canvas.height);
        }
        if (localEl && localEl.readyState >= 2) {
          var w = Math.round(canvas.width * 0.26);
          var h = Math.round(canvas.height * 0.26);
          g.drawImage(localEl, canvas.width - w - 20, canvas.height - h - 20, w, h);
        }
      } catch (err) {}
      recTimer = requestAnimationFrame(draw);
    }
    var mixed = canvas.captureStream(12);
    function addAudio(stream) {
      if (!stream) return;
      stream.getAudioTracks().forEach(function (t) {
        if (t.readyState === "live") mixed.addTrack(t);
      });
    }
    addAudio(localStream);
    addAudio(remoteStream);
    var mime = "video/webm";
    if (window.MediaRecorder && MediaRecorder.isTypeSupported("video/webm;codecs=vp9,opus")) {
      mime = "video/webm;codecs=vp9,opus";
    } else if (window.MediaRecorder && MediaRecorder.isTypeSupported("video/webm;codecs=vp8,opus")) {
      mime = "video/webm;codecs=vp8,opus";
    }
    try {
      recorder = new MediaRecorder(mixed, { mimeType: mime });
    } catch (err) {
      recorder = new MediaRecorder(mixed);
    }
    recorder.ondataavailable = function (e) {
      if (e.data && e.data.size) recChunks.push(e.data);
    };
    recorder.onstop = downloadRecording;
    recorder.start(1000);
    draw();
    setRecordingUi(true);
  }

  if (permitBtn) permitBtn.addEventListener("click", function () {
    unlockSounds();
    ctx();
    requestMedia().catch(function () {});
  });
  if (startBtn) startBtn.addEventListener("click", function () {
    unlockSounds();
    if (pendingOffer && !(calling && pc)) acceptCall(pendingOffer);
    else startCall();
  });
  if (hangBtn) hangBtn.addEventListener("click", function () {
    var incoming = incomingEl && !incomingEl.hidden && !calling;
    post({ action: "send", kind: "hangup", payload: null });
    endPeer(true);
    setStatus(incoming ? "تماس رد شد." : "تماس قطع شد.");
  });
  fsBtns.forEach(function (btn) {
    btn.addEventListener("click", toggleFullscreen);
  });
  document.addEventListener("fullscreenchange", syncFullscreenIcon);
  document.addEventListener("webkitfullscreenchange", syncFullscreenIcon);
  if (enhanceBtn) {
    enhanceBtn.addEventListener("click", function () {
      if (!stageEl) return;
      var on = !stageEl.classList.contains("is-enhanced");
      stageEl.classList.toggle("is-enhanced", on);
      enhanceBtn.setAttribute("aria-pressed", on ? "true" : "false");
    });
  }
  if (recordBtn) {
    recordBtn.addEventListener("click", function () {
      if (recorder) stopRecording(true);
      else startRecording();
    });
  }

  if (guardCapture) {
    document.addEventListener("visibilitychange", tickGuard);
    document.addEventListener("keydown", captureHotkey, true);
    root.addEventListener("contextmenu", function (e) { e.preventDefault(); });
    [localEl, remoteEl].forEach(function (el) {
      if (!el) return;
      el.disablePictureInPicture = true;
      el.addEventListener("enterpictureinpicture", function (e) {
        e.preventDefault();
        setBlackout(true);
      });
    });
    if (navigator.mediaDevices && navigator.mediaDevices.getDisplayMedia) {
      navigator.mediaDevices.getDisplayMedia = function () {
        setBlackout(true);
        return Promise.reject(new DOMException("Screen capture is not allowed.", "NotAllowedError"));
      };
    }
  }

  ringAudio = makeSound(ringUrl, true);
  hangAudio = makeSound(hangUrl, false);
  document.addEventListener("click", unlockSounds, { once: true });
  requestMedia().catch(function () {});
  poll();
  setInterval(poll, 700);
})();
