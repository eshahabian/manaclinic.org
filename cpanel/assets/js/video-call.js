(function () {
  var root = document.querySelector("[data-video-call]");
  if (!root) return;
  var signalUrl = root.getAttribute("data-signal-url") || "/video-signal";
  var room = root.getAttribute("data-room") || "";
  var meId = root.getAttribute("data-me") || "";
  var peerName = root.getAttribute("data-peer-name") || "طرف مقابل";
  var audioOnly = root.getAttribute("data-media") === "audio";
  var canStart = root.getAttribute("data-can-start") === "1";
  var isGroup = root.getAttribute("data-group") === "1";
  var stageEl = root.querySelector("[data-video-stage]");
  var remotesEl = root.querySelector("[data-video-remotes]");
  var localEl = root.querySelector("[data-video-local]");
  var statusEl = root.querySelector("[data-video-status]");
  var incomingEl = root.querySelector("[data-video-incoming]");
  var permitEl = root.querySelector("[data-video-permit]");
  var permitBtn = root.querySelector("[data-video-permit-btn]");
  var startBtn = root.querySelector("[data-video-start]");
  var hangBtn = root.querySelector("[data-video-hangup]");
  var enhanceBtn = root.querySelector("[data-video-enhance]");
  var fsBtns = root.querySelectorAll("[data-video-fs]");
  var token = (document.querySelector('meta[name="csrf-token"]') || {}).content || "";
  var ringUrl = root.getAttribute("data-ring-url") || "";
  var hangUrl = root.getAttribute("data-hang-url") || "";
  var canRecord = root.getAttribute("data-can-record") === "1";
  var guardCapture = root.getAttribute("data-guard-capture") === "1";
  var recordBtn = root.querySelector("[data-video-record]");
  var blackoutEl = root.querySelector("[data-video-blackout]");

  var localStream = null;
  var ready = false;
  var calling = false;
  var seen = {};
  var peers = {};
  var pendingOffers = {};
  var lastStatus = "";
  var audioCtx = null;
  var ringTimer = null;
  var ringAudio = null;
  var hangAudio = null;
  var recorder = null;
  var recChunks = [];
  var recTimer = null;
  var guarded = false;
  var joined = false;
  var wantAutoAnswer = false;
  try {
    wantAutoAnswer = sessionStorage.getItem("mana-video-auto-answer") === "1"
      || /(?:^|[?&])answer=1(?:&|$)/.test(location.search || "");
    sessionStorage.removeItem("mana-video-auto-answer");
  } catch (e) {}

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
  function iceServers() {
    return [
      { urls: "stun:stun.l.google.com:19302" },
      { urls: "stun:stun1.l.google.com:19302" },
      { urls: "stun:stun.cloudflare.com:3478" }
    ];
  }
  function post(body) {
    body.room = room;
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
  function sendTo(kind, to, payload) {
    return post({ action: "send", kind: kind, target_id: to || "", payload: payload || null });
  }
  function mediaErrorText(err) {
    var name = err && err.name;
    if (name === "NotAllowedError" || name === "PermissionDeniedError") {
      return "دسترسی به " + (audioOnly ? "میکروفون" : "دوربین و میکروفون") + " رد شد.";
    }
    if (location.protocol !== "https:" && location.hostname !== "localhost" && location.hostname !== "127.0.0.1") {
      return "تماس تصویری فقط روی آدرس امن (https) کار می‌کند.";
    }
    return "مرورگر باید به " + (audioOnly ? "میکروفون" : "دوربین و میکروفون") + " دسترسی بدهد.";
  }
  function constraintSets() {
    if (audioOnly) return [{ audio: true, video: false }];
    return [
      { audio: true, video: { facingMode: "user", width: { ideal: 1280 }, height: { ideal: 720 } } },
      { audio: true, video: true },
      { audio: true, video: { facingMode: "user" } },
      { audio: true, video: false }
    ];
  }
  function getUserMediaFallback(index, sets) {
    sets = sets || constraintSets();
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
      return Promise.reject(new Error("این مرورگر تماس را پشتیبانی نمی‌کند."));
    }
    if (index >= sets.length) return Promise.reject(new Error("دوربین یا میکروفون در دسترس نیست."));
    return navigator.mediaDevices.getUserMedia(sets[index]).catch(function (err) {
      if (err && (err.name === "NotAllowedError" || err.name === "PermissionDeniedError" || err.name === "SecurityError")) {
        return Promise.reject(err);
      }
      return getUserMediaFallback(index + 1, sets);
    });
  }
  function attachLocal(stream) {
    localStream = stream;
    ready = true;
    if (localEl) {
      localEl.srcObject = stream;
      localEl.muted = true;
      var play = localEl.play();
      if (play && play.catch) play.catch(function () {});
    }
    setPermit(false);
  }
  function requestMedia() {
    setStatus("منتظر اجازه دسترسی…");
    return getUserMediaFallback(0).then(function (stream) {
      attachLocal(stream);
      setStatus(canStart ? "آماده تماس." : "آماده پذیرش تماس.");
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
  function remoteTile(id) {
    if (!remotesEl) return null;
    var el = remotesEl.querySelector('[data-peer="' + id + '"]');
    if (el) return el;
    var wrap = document.createElement("div");
    wrap.className = "vc-remote-tile";
    wrap.setAttribute("data-peer", id);
    var vid = document.createElement("video");
    vid.autoplay = true;
    vid.playsInline = true;
    vid.setAttribute("data-video-remote", "1");
    wrap.appendChild(vid);
    remotesEl.appendChild(wrap);
    return wrap;
  }
  function addLocalTracks(conn) {
    if (!localStream || !conn) return;
    var senders = conn.getSenders ? conn.getSenders() : [];
    localStream.getTracks().forEach(function (track) {
      var already = senders.some(function (s) { return s.track && s.track.id === track.id; });
      if (!already) conn.addTrack(track, localStream);
    });
  }
  function ensurePeer(userId) {
    if (!userId || userId === meId) return null;
    if (peers[userId]) {
      addLocalTracks(peers[userId].pc);
      return peers[userId];
    }
    var tile = remoteTile(userId);
    var vid = tile ? tile.querySelector("video") : null;
    var remoteStream = new MediaStream();
    var pc = new RTCPeerConnection({ iceServers: iceServers() });
    var rec = { pc: pc, pendingIce: [], makingOffer: false, remoteStream: remoteStream, vid: vid };
    pc.onicecandidate = function (ev) {
      if (ev.candidate) {
        sendTo("ice", userId, ev.candidate.toJSON ? ev.candidate.toJSON() : ev.candidate);
      }
    };
    pc.ontrack = function (ev) {
      var tracks = ev.streams && ev.streams[0] ? ev.streams[0].getTracks() : (ev.track ? [ev.track] : []);
      tracks.forEach(function (track) {
        if (!remoteStream.getTracks().some(function (t) { return t.id === track.id; })) {
          remoteStream.addTrack(track);
        }
      });
      if (vid) {
        vid.srcObject = remoteStream;
        vid.play().catch(function () {});
      }
      if (stageEl) stageEl.classList.add("is-live");
    };
    pc.onconnectionstatechange = function () {
      if (pc.connectionState === "connected") {
        calling = true;
        syncButtons();
        setStatus("تماس برقرار شد.");
        stopRing();
      }
    };
    addLocalTracks(pc);
    peers[userId] = rec;
    return rec;
  }
  function flushIce(rec) {
    if (!rec || !rec.pc.remoteDescription) return;
    rec.pendingIce.splice(0).forEach(function (c) {
      rec.pc.addIceCandidate(new RTCIceCandidate(c)).catch(function () {});
    });
  }
  function offerTo(userId) {
    var rec = ensurePeer(userId);
    if (!rec) return Promise.resolve();
    rec.makingOffer = true;
    return rec.pc.createOffer().then(function (offer) {
      return rec.pc.setLocalDescription(offer);
    }).then(function () {
      rec.makingOffer = false;
      return sendTo("offer", userId, rec.pc.localDescription);
    }).catch(function (err) {
      rec.makingOffer = false;
      console.error(err);
    });
  }
  function answerFrom(userId, offer) {
    var rec = ensurePeer(userId);
    if (!rec) return Promise.resolve();
    addLocalTracks(rec.pc);
    return rec.pc.setRemoteDescription(new RTCSessionDescription(offer)).then(function () {
      flushIce(rec);
      return rec.pc.createAnswer();
    }).then(function (answer) {
      return rec.pc.setLocalDescription(answer);
    }).then(function () {
      return sendTo("answer", userId, rec.pc.localDescription);
    });
  }
  function closePeer(userId) {
    var rec = peers[userId];
    if (!rec) return;
    try { rec.pc.close(); } catch (e) {}
    delete peers[userId];
    var tile = remotesEl && remotesEl.querySelector('[data-peer="' + userId + '"]');
    if (tile) tile.remove();
  }
  function endAll(playHang) {
    stopRing();
    stopRecording(true);
    Object.keys(peers).forEach(closePeer);
    calling = false;
    joined = false;
    pendingOffers = {};
    if (stageEl) stageEl.classList.remove("is-live");
    if (incomingEl) incomingEl.hidden = true;
    if (playHang) {
      if (!hangAudio) hangAudio = new Audio(hangUrl);
      if (hangAudio) hangAudio.play().catch(function () {});
    }
    syncButtons();
  }
  function startRing() {
    stopRing();
    if (ringUrl) {
      if (!ringAudio) {
        ringAudio = new Audio(ringUrl);
        ringAudio.loop = true;
      }
      ringAudio.currentTime = 0;
      ringAudio.play().catch(function () {});
    }
  }
  function stopRing() {
    if (ringTimer) { clearInterval(ringTimer); ringTimer = null; }
    if (ringAudio) { ringAudio.pause(); try { ringAudio.currentTime = 0; } catch (e) {} }
  }
  function syncButtons() {
    var incoming = incomingEl && !incomingEl.hidden && !calling;
    if (startBtn) {
      var showStart = canStart || incoming;
      startBtn.hidden = !showStart;
      startBtn.disabled = false;
      startBtn.setAttribute("title", incoming ? "پاسخ" : "شروع تماس");
    }
    if (hangBtn) hangBtn.hidden = !(calling || incoming || Object.keys(peers).length);
    if (recordBtn) recordBtn.hidden = !canRecord || !calling;
  }
  function ringMembers(members) {
    (members || []).forEach(function (m) {
      if (!m || m.id === meId) return;
      sendTo("ringing", m.id, { name: peerName });
    });
  }
  function joinRoom(members) {
    joined = true;
    calling = true;
    syncButtons();
    sendTo("join", "", { name: peerName });
    (members || []).forEach(function (m) {
      if (!m || m.id === meId) return;
      if (meId < m.id) offerTo(m.id);
    });
    Object.keys(pendingOffers).forEach(function (id) {
      var off = pendingOffers[id];
      delete pendingOffers[id];
      media().then(function () { return answerFrom(id, off); }).catch(function () {});
    });
  }
  function startCall(members) {
    media().then(function () {
      function go(list) {
        setStatus("در حال تماس…");
        if (canStart) ringMembers(list || []);
        joinRoom(list || []);
      }
      if (members && members.length) {
        go(members);
        return;
      }
      post({ action: "poll" }).then(function (data) {
        go((data && data.members) || []);
      }).catch(function () { go([]); });
    }).catch(function () {});
  }
  function acceptIncoming() {
    media().then(function () {
      if (incomingEl) incomingEl.hidden = true;
      stopRing();
      joinRoom([]);
      Object.keys(pendingOffers).forEach(function (id) {
        var off = pendingOffers[id];
        delete pendingOffers[id];
        answerFrom(id, off);
      });
    }).catch(function () {});
  }
  function handle(sig) {
    if (!sig || seen[sig.id]) return;
    seen[sig.id] = true;
    var from = sig.from;
    var kind = sig.kind;
    var payload = sig.payload;
    if (kind === "ringing") {
      if (!calling && incomingEl) {
        incomingEl.hidden = false;
        incomingEl.textContent = "تماس ورودی از " + (payload && payload.name ? payload.name : peerName);
        startRing();
        syncButtons();
        setStatus("تماس ورودی");
        if (wantAutoAnswer) acceptIncoming();
      }
    } else if (kind === "join") {
      if (calling && from && from !== meId && meId < from) {
        media().then(function () { return offerTo(from); }).catch(function () {});
      }
    } else if (kind === "offer" && payload) {
      pendingOffers[from] = payload;
      if (calling) {
        media().then(function () { return answerFrom(from, payload); }).catch(function () {});
      } else if (incomingEl) {
        incomingEl.hidden = false;
        startRing();
        syncButtons();
        if (wantAutoAnswer) acceptIncoming();
      }
    } else if (kind === "answer" && payload && peers[from]) {
      var rec = peers[from];
      if (rec.pc.signalingState === "have-local-offer") {
        rec.pc.setRemoteDescription(new RTCSessionDescription(payload)).then(function () { flushIce(rec); }).catch(function () {});
      }
    } else if (kind === "ice" && payload) {
      var recIce = ensurePeer(from);
      if (recIce) {
        recIce.pendingIce.push(payload);
        flushIce(recIce);
      }
    } else if (kind === "hangup" || kind === "leave") {
      if (from) closePeer(from);
      if (!isGroup || Object.keys(peers).length === 0) {
        endAll(true);
        setStatus("تماس قطع شد.");
      }
    }
  }
  function poll() {
    post({ action: "poll" }).then(function (data) {
      if (!data || !data.ok) return;
      (data.signals || []).forEach(handle);
      if (!calling && canStart) {
        var names = (data.members || []).filter(function (m) { return m.id !== meId && m.online; }).map(function (m) { return m.name; });
        var next = names.length ? ("آنلاین: " + names.join("، ")) : "مخاطب فعلاً آفلاین است.";
        if (next !== lastStatus) { lastStatus = next; setStatus(next); }
      }
      if (calling && isGroup) {
        (data.members || []).forEach(function (m) {
          if (m && m.id !== meId && meId < m.id && !peers[m.id]) offerTo(m.id);
        });
      }
    }).catch(function () {});
  }
  function setRecordingUi(on) {
    if (!recordBtn) return;
    recordBtn.classList.toggle("is-recording", !!on);
    recordBtn.setAttribute("title", on ? "توقف ضبط" : "ضبط تماس");
  }
  function downloadRecording() {
    if (!recChunks.length) return;
    var blob = new Blob(recChunks, { type: recChunks[0].type || "video/webm" });
    recChunks = [];
    var a = document.createElement("a");
    a.href = URL.createObjectURL(blob);
    a.download = "mana-call.webm";
    document.body.appendChild(a);
    a.click();
    setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 800);
  }
  function stopRecording() {
    if (recTimer) { cancelAnimationFrame(recTimer); recTimer = null; }
    if (!recorder) { setRecordingUi(false); return; }
    var rec = recorder;
    recorder = null;
    if (rec.state !== "inactive") rec.stop();
    else downloadRecording();
    setRecordingUi(false);
  }
  function startRecording() {
    if (!canRecord || recorder) return;
    recChunks = [];
    var canvas = document.createElement("canvas");
    canvas.width = 1280; canvas.height = 720;
    var g = canvas.getContext("2d");
    function draw() {
      if (!recorder) return;
      g.fillStyle = "#111";
      g.fillRect(0, 0, canvas.width, canvas.height);
      var vids = remotesEl ? remotesEl.querySelectorAll("video") : [];
      if (vids[0] && vids[0].readyState >= 2) g.drawImage(vids[0], 0, 0, canvas.width, canvas.height);
      recTimer = requestAnimationFrame(draw);
    }
    var mixed = canvas.captureStream(12);
    if (localStream) localStream.getAudioTracks().forEach(function (t) { mixed.addTrack(t); });
    try { recorder = new MediaRecorder(mixed, { mimeType: "video/webm" }); } catch (e) { recorder = new MediaRecorder(mixed); }
    recorder.ondataavailable = function (e) { if (e.data && e.data.size) recChunks.push(e.data); };
    recorder.onstop = downloadRecording;
    recorder.start(1000);
    draw();
    setRecordingUi(true);
  }

  if (permitBtn) permitBtn.addEventListener("click", function () {
    ctx();
    requestMedia().then(function () {
      if (isGroup && !canStart) startCall([]);
    }).catch(function () {});
  });
  if (startBtn) startBtn.addEventListener("click", function () {
    ctx();
    if (incomingEl && !incomingEl.hidden && !calling) acceptIncoming();
    else startCall([]);
  });
  if (hangBtn) hangBtn.addEventListener("click", function () {
    sendTo("hangup", "", null);
    endAll(true);
    setStatus("تماس قطع شد.");
  });
  fsBtns.forEach(function (btn) {
    btn.addEventListener("click", function () {
      var el = stageEl || root;
      if (document.fullscreenElement) document.exitFullscreen();
      else if (el.requestFullscreen) el.requestFullscreen();
    });
  });
  if (enhanceBtn) enhanceBtn.addEventListener("click", function () {
    var on = !stageEl.classList.contains("is-enhanced");
    stageEl.classList.toggle("is-enhanced", on);
    enhanceBtn.setAttribute("aria-pressed", on ? "true" : "false");
  });
  if (recordBtn) recordBtn.addEventListener("click", function () {
    if (recorder) stopRecording();
    else startRecording();
  });
  if (guardCapture) {
    document.addEventListener("visibilitychange", function () {
      var on = document.hidden;
      root.classList.toggle("is-black", on);
      if (blackoutEl) blackoutEl.hidden = !on;
    });
  }
  window.addEventListener("pagehide", function () {
    if (calling) sendTo("leave", "", null);
  });
  syncButtons();
  requestMedia().then(function () {
    if (isGroup) startCall([]);
    else if (wantAutoAnswer) acceptIncoming();
  }).catch(function () {});
  poll();
  setInterval(poll, 800);
})();
