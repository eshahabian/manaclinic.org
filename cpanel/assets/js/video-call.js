(function () {
  var root = document.querySelector("[data-video-call]");
  if (!root) return;
  var signalUrl = root.getAttribute("data-signal-url") || "/video-signal";
  var peerName = root.getAttribute("data-peer-name") || "طرف مقابل";
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
  var token = (document.querySelector('meta[name="csrf-token"]') || {}).content || "";

  var pc = null;
  var localStream = null;
  var remoteStream = null;
  var after = "";
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

  function beep(freq, start, dur, type, gainVal) {
    var ac = ctx();
    if (!ac) return;
    var osc = ac.createOscillator();
    var gain = ac.createGain();
    osc.type = type || "sine";
    osc.frequency.setValueAtTime(freq, ac.currentTime + start);
    gain.gain.setValueAtTime(0.0001, ac.currentTime + start);
    gain.gain.exponentialRampToValueAtTime(gainVal || 0.08, ac.currentTime + start + 0.02);
    gain.gain.exponentialRampToValueAtTime(0.0001, ac.currentTime + start + dur);
    osc.connect(gain);
    gain.connect(ac.destination);
    osc.start(ac.currentTime + start);
    osc.stop(ac.currentTime + start + dur + 0.02);
  }

  function playRingBurst() {
    beep(495, 0, 0.42, "sine", 0.07);
    beep(425, 0.12, 0.42, "sine", 0.05);
    beep(495, 0.55, 0.42, "sine", 0.07);
    beep(425, 0.67, 0.42, "sine", 0.05);
  }

  function startRing() {
    stopRing();
    ctx();
    playRingBurst();
    ringTimer = setInterval(playRingBurst, 3200);
  }

  function stopRing() {
    if (ringTimer) {
      clearInterval(ringTimer);
      ringTimer = null;
    }
  }

  function playConnected() {
    stopRing();
    beep(880, 0, 0.16, "sine", 0.09);
    beep(1175, 0.14, 0.28, "sine", 0.08);
  }

  function playDisconnected() {
    stopRing();
    beep(520, 0, 0.18, "triangle", 0.07);
    beep(360, 0.14, 0.28, "triangle", 0.06);
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
      { urls: "stun:stun1.l.google.com:19302" }
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
        return conn.setLocalDescription(offer).then(function () {
          return post({ action: "send", kind: "offer", payload: { type: offer.type, sdp: offer.sdp } });
        });
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
      if (incomingEl) incomingEl.hidden = true;
      if (startBtn) startBtn.hidden = true;
      if (hangBtn) hangBtn.hidden = false;
      setStatus("در حال پاسخ…");
      var conn = ensurePc();
      return conn.setRemoteDescription(new RTCSessionDescription(offer)).then(function () {
        flushIce();
        return conn.createAnswer();
      }).then(function (answer) {
        return conn.setLocalDescription(answer).then(function () {
          return post({ action: "send", kind: "answer", payload: { type: answer.type, sdp: answer.sdp } });
        });
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
    post({ action: "poll", after: after }).then(function (data) {
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
        if (sig.created_at && (!after || sig.created_at >= after)) after = sig.created_at;
        handle(sig);
      });
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

  requestMedia().catch(function () {});
  poll();
  setInterval(poll, 800);
})();
