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
  var acceptBtn = root.querySelector("[data-video-accept]");
  var declineBtn = root.querySelector("[data-video-decline]");
  var fsBtns = root.querySelectorAll("[data-video-fs], [data-video-fs-btn]");
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
    playRingBurst();
    ringTimer = setInterval(playRingBurst, 1800);
  }

  function stopRing() {
    if (ringTimer) {
      clearInterval(ringTimer);
      ringTimer = null;
    }
  }

  function playConnected() {
    stopRing();
    tone(760, 0, 0.09, 0.05);
  }

  function playDisconnected() {
    stopRing();
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
    }).then(function (r) { return r.json(); });
  }

  function iceServers() {
    return [
      { urls: "stun:stun.l.google.com:19302" },
      { urls: "stun:stun1.l.google.com:19302" },
      { urls: "turn:openrelay.metered.ca:80", username: "openrelayproject", credential: "openrelayproject" },
      { urls: "turn:openrelay.metered.ca:443", username: "openrelayproject", credential: "openrelayproject" },
      { urls: "turn:openrelay.metered.ca:443?transport=tcp", username: "openrelayproject", credential: "openrelayproject" }
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
    });
    if (remoteEl) {
      remoteEl.srcObject = remoteStream;
      remoteEl.autoplay = true;
      remoteEl.playsInline = true;
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

  function waitGathering(conn) {
    return new Promise(function (resolve) {
      if (!conn || conn.iceGatheringState === "complete") {
        resolve();
        return;
      }
      var finished = false;
      function finish() {
        if (finished) return;
        finished = true;
        conn.removeEventListener("icegatheringstatechange", onChange);
        resolve();
      }
      function onChange() {
        if (conn.iceGatheringState === "complete") finish();
      }
      conn.addEventListener("icegatheringstatechange", onChange);
      setTimeout(finish, 3500);
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
    pc = new RTCPeerConnection({
      iceServers: iceServers(),
      iceCandidatePoolSize: 4
    });
    pc.onicecandidate = function (ev) {
      if (ev.candidate) {
        post({ action: "send", kind: "ice", payload: ev.candidate.toJSON ? ev.candidate.toJSON() : ev.candidate });
      }
    };
    pc.ontrack = attachRemoteTrack;
    pc.onconnectionstatechange = function () {
      if (!pc) return;
      if (pc.connectionState === "connected") {
        if (!connectedOnce) {
          connectedOnce = true;
          playConnected();
        }
        setStatus("تماس برقرار شد.");
      }
      if (pc.connectionState === "disconnected" || pc.connectionState === "failed") {
        setStatus("ارتباط قطع شد.");
      }
    };
    addLocalTracks(pc);
    return pc;
  }

  function endPeer(playHang) {
    stopRing();
    if (playHang) playDisconnected();
    connectedOnce = false;
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
    if (startBtn) startBtn.hidden = false;
    if (hangBtn) hangBtn.hidden = true;
  }

  function startCall() {
    ctx();
    media().then(function () {
      calling = true;
      connectedOnce = false;
      if (startBtn) startBtn.hidden = true;
      if (hangBtn) hangBtn.hidden = false;
      setStatus("در حال تماس… منتظر پاسخ طرف مقابل.");
      startRing();
      var conn = ensurePc();
      return conn.createOffer({ offerToReceiveAudio: true, offerToReceiveVideo: true }).then(function (offer) {
        return conn.setLocalDescription(offer);
      }).then(function () {
        return waitGathering(conn);
      }).then(function () {
        return sendLocal("offer", conn);
      });
    }).catch(function (err) {
      if (!ready) return;
      setStatus("شروع تماس ناموفق بود. دوباره تلاش کنید.");
      endPeer(false);
      console.error(err);
    });
  }

  function acceptCall(offer) {
    ctx();
    media().then(function () {
      calling = true;
      connectedOnce = false;
      stopRing();
      if (incomingEl) incomingEl.hidden = true;
      if (startBtn) startBtn.hidden = true;
      if (hangBtn) hangBtn.hidden = false;
      setStatus("در حال پاسخ…");
      var conn = ensurePc();
      return conn.setRemoteDescription(new RTCSessionDescription(offer)).then(function () {
        flushIce();
        return conn.createAnswer();
      }).then(function (answer) {
        return conn.setLocalDescription(answer);
      }).then(function () {
        return waitGathering(conn);
      }).then(function () {
        return sendLocal("answer", conn);
      });
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
        acceptCall(payload);
      } else if (incomingEl) {
        incomingEl.hidden = false;
        setStatus("تماس ورودی از " + peerName);
        startRing();
      }
    } else if (kind === "answer" && payload && pc) {
      pc.setRemoteDescription(new RTCSessionDescription(payload)).then(function () {
        flushIce();
      }).catch(function () {});
    } else if (kind === "ice" && payload) {
      pendingIce.push(payload);
      flushIce();
    } else if (kind === "hangup") {
      endPeer(true);
      setStatus("تماس قطع شد.");
    }
  }

  function poll() {
    post({ action: "poll" }).then(function (data) {
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
      (data.signals || []).forEach(handle);
    }).catch(function () {});
  }

  if (permitBtn) permitBtn.addEventListener("click", function () {
    ctx();
    requestMedia().catch(function () {});
  });
  if (startBtn) startBtn.addEventListener("click", startCall);
  if (hangBtn) hangBtn.addEventListener("click", function () {
    post({ action: "send", kind: "hangup", payload: null });
    endPeer(true);
    setStatus("تماس قطع شد.");
  });
  if (acceptBtn) acceptBtn.addEventListener("click", function () {
    if (pendingOffer) acceptCall(pendingOffer);
  });
  if (declineBtn) declineBtn.addEventListener("click", function () {
    pendingOffer = null;
    if (incomingEl) incomingEl.hidden = true;
    post({ action: "send", kind: "hangup", payload: null });
    endPeer(true);
    setStatus("تماس رد شد.");
  });
  fsBtns.forEach(function (btn) {
    btn.addEventListener("click", toggleFullscreen);
  });

  requestMedia().catch(function () {});
  poll();
  setInterval(poll, 700);
})();
