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
  var after = "";
  var calling = false;
  var ready = false;
  var seen = {};
  var pendingOffer = null;
  var lastPeerStatus = "";

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

  function ensurePc() {
    if (pc) return pc;
    pc = new RTCPeerConnection({ iceServers: iceServers() });
    pc.onicecandidate = function (ev) {
      if (ev.candidate) {
        post({ action: "send", kind: "ice", payload: ev.candidate.toJSON ? ev.candidate.toJSON() : ev.candidate });
      }
    };
    pc.ontrack = function (ev) {
      if (remoteEl) remoteEl.srcObject = ev.streams[0] || new MediaStream([ev.track]);
    };
    pc.onconnectionstatechange = function () {
      if (!pc) return;
      if (pc.connectionState === "connected") setStatus("تماس برقرار شد.");
      if (pc.connectionState === "disconnected" || pc.connectionState === "failed") setStatus("ارتباط قطع شد.");
    };
    if (localStream) {
      localStream.getTracks().forEach(function (t) { pc.addTrack(t, localStream); });
    }
    return pc;
  }

  function endPeer() {
    if (remoteEl) remoteEl.srcObject = null;
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
    media().then(function () {
      calling = true;
      if (startBtn) startBtn.hidden = true;
      if (hangBtn) hangBtn.hidden = false;
      setStatus("در حال تماس…");
      var conn = ensurePc();
      return conn.createOffer({ offerToReceiveAudio: true, offerToReceiveVideo: true }).then(function (offer) {
        return conn.setLocalDescription(offer).then(function () {
          return post({ action: "send", kind: "offer", payload: { type: offer.type, sdp: offer.sdp } });
        });
      });
    }).catch(function (err) {
      if (!ready) return;
      setStatus("شروع تماس ناموفق بود. دوباره تلاش کنید.");
      endPeer();
      console.error(err);
    });
  }

  function acceptCall(offer) {
    media().then(function () {
      calling = true;
      if (incomingEl) incomingEl.hidden = true;
      if (startBtn) startBtn.hidden = true;
      if (hangBtn) hangBtn.hidden = false;
      setStatus("در حال پاسخ…");
      var conn = ensurePc();
      return conn.setRemoteDescription(new RTCSessionDescription(offer)).then(function () {
        return conn.createAnswer();
      }).then(function (answer) {
        return conn.setLocalDescription(answer).then(function () {
          return post({ action: "send", kind: "answer", payload: { type: answer.type, sdp: answer.sdp } });
        });
      });
    }).catch(function (err) {
      if (!ready) return;
      setStatus("پاسخ به تماس ناموفق بود.");
      endPeer();
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
      if (calling) {
        acceptCall(payload);
      } else if (incomingEl) {
        incomingEl.hidden = false;
        setStatus("تماس ورودی از " + peerName);
      }
    } else if (kind === "answer" && payload && pc) {
      pc.setRemoteDescription(new RTCSessionDescription(payload)).catch(function () {});
    } else if (kind === "ice" && payload && pc) {
      pc.addIceCandidate(new RTCIceCandidate(payload)).catch(function () {});
    } else if (kind === "hangup") {
      pendingOffer = null;
      endPeer();
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
        if (ready && next !== lastPeerStatus) {
          lastPeerStatus = next;
          setStatus(next);
        } else if (!ready) {
          lastPeerStatus = next;
        }
      }
      (data.signals || []).forEach(function (sig) {
        if (sig.created_at && sig.created_at > after) after = sig.created_at;
        handle(sig);
      });
    }).catch(function () {});
  }

  if (permitBtn) permitBtn.addEventListener("click", function () {
    requestMedia().catch(function () {});
  });
  if (startBtn) startBtn.addEventListener("click", startCall);
  if (hangBtn) hangBtn.addEventListener("click", function () {
    post({ action: "send", kind: "hangup", payload: null });
    endPeer();
    setStatus("تماس قطع شد.");
  });
  if (acceptBtn) acceptBtn.addEventListener("click", function () {
    if (pendingOffer) acceptCall(pendingOffer);
  });
  if (declineBtn) declineBtn.addEventListener("click", function () {
    pendingOffer = null;
    if (incomingEl) incomingEl.hidden = true;
    post({ action: "send", kind: "hangup", payload: null });
    setStatus("تماس رد شد.");
  });

  requestMedia().catch(function () {});
  poll();
  setInterval(poll, 1200);
})();
