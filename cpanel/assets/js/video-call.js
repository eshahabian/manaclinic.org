(function () {
  var root = document.querySelector("[data-video-call]");
  if (!root) return;
  var signalUrl = root.getAttribute("data-signal-url") || "/video-signal";
  var peerName = root.getAttribute("data-peer-name") || "طرف مقابل";
  var localEl = root.querySelector("[data-video-local]");
  var remoteEl = root.querySelector("[data-video-remote]");
  var statusEl = root.querySelector("[data-video-status]");
  var incomingEl = root.querySelector("[data-video-incoming]");
  var startBtn = root.querySelector("[data-video-start]");
  var hangBtn = root.querySelector("[data-video-hangup]");
  var acceptBtn = root.querySelector("[data-video-accept]");
  var declineBtn = root.querySelector("[data-video-decline]");
  var token = (document.querySelector('meta[name="csrf-token"]') || {}).content || "";

  var pc = null;
  var localStream = null;
  var after = "";
  var calling = false;
  var seen = {};

  function setStatus(text) {
    if (statusEl) statusEl.textContent = text;
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

  function media() {
    if (localStream) return Promise.resolve(localStream);
    return navigator.mediaDevices.getUserMedia({ video: true, audio: true }).then(function (stream) {
      localStream = stream;
      if (localEl) localEl.srcObject = stream;
      return stream;
    });
  }

  function stopMedia() {
    if (localStream) {
      localStream.getTracks().forEach(function (t) { t.stop(); });
      localStream = null;
    }
    if (localEl) localEl.srcObject = null;
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
    calling = true;
    if (startBtn) startBtn.hidden = true;
    if (hangBtn) hangBtn.hidden = false;
    setStatus("در حال تماس…");
    media().then(function () {
      var conn = ensurePc();
      return conn.createOffer().then(function (offer) {
        return conn.setLocalDescription(offer).then(function () {
          return post({ action: "send", kind: "offer", payload: { type: offer.type, sdp: offer.sdp } });
        });
      });
    }).catch(function (err) {
      setStatus("دوربین یا میکروفون در دسترس نیست.");
      stopMedia();
      console.error(err);
    });
  }

  function acceptCall(offer) {
    calling = true;
    if (incomingEl) incomingEl.hidden = true;
    if (startBtn) startBtn.hidden = true;
    if (hangBtn) hangBtn.hidden = false;
    setStatus("در حال پاسخ…");
    media().then(function () {
      var conn = ensurePc();
      return conn.setRemoteDescription(new RTCSessionDescription(offer)).then(function () {
        return conn.createAnswer();
      }).then(function (answer) {
        return conn.setLocalDescription(answer).then(function () {
          return post({ action: "send", kind: "answer", payload: { type: answer.type, sdp: answer.sdp } });
        });
      });
    }).catch(function (err) {
      setStatus("پاسخ به تماس ناموفق بود.");
      stopMedia();
      console.error(err);
    });
  }

  var pendingOffer = null;

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
      stopMedia();
      setStatus("تماس قطع شد.");
    }
  }

  function poll() {
    post({ action: "poll", after: after }).then(function (data) {
      if (!data || !data.ok) return;
      if (!calling) {
        setStatus(data.online
          ? peerName + " آنلاین است."
          : peerName + " فعلاً در این صفحه نیست — هر دو باید این صفحه را باز کنید.");
      }
      (data.signals || []).forEach(function (sig) {
        if (sig.created_at && sig.created_at > after) after = sig.created_at;
        handle(sig);
      });
    }).catch(function () {});
  }

  if (startBtn) startBtn.addEventListener("click", startCall);
  if (hangBtn) hangBtn.addEventListener("click", function () {
    post({ action: "send", kind: "hangup", payload: null });
    stopMedia();
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

  poll();
  setInterval(poll, 1200);
})();
