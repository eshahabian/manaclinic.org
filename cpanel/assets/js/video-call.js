(function () {
  var liveDestroy = null;
  function attach(root) {
  if (!root) return function () {};
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
  var dialing = false;
  var wantAutoAnswer = false;
  var autoDial = false;
  var ac = typeof AbortController !== "undefined" ? new AbortController() : null;
  var bindOpts = ac ? { signal: ac.signal } : false;
  try {
    wantAutoAnswer = sessionStorage.getItem("mana-video-auto-answer") === "1"
      || /(?:^|[?&])answer=1(?:&|$)/.test(location.search || "");
    sessionStorage.removeItem("mana-video-auto-answer");
  } catch (e) {}
  autoDial = root.getAttribute("data-auto-dial") === "1";

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
  function descPayload(desc) {
    if (!desc) return null;
    return { type: desc.type, sdp: desc.sdp };
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
      var stream = ev.streams && ev.streams[0] ? ev.streams[0] : null;
      if (stream) {
        rec.remoteStream = stream;
        if (vid) {
          vid.srcObject = stream;
          vid.play().catch(function () {});
        }
      } else if (ev.track) {
        if (!remoteStream.getTracks().some(function (t) { return t.id === ev.track.id; })) {
          remoteStream.addTrack(ev.track);
        }
        if (vid) {
          vid.srcObject = remoteStream;
          vid.play().catch(function () {});
        }
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
    if (!rec || rec.makingOffer) return Promise.resolve();
    if (rec.pc.signalingState !== "stable") return Promise.resolve();
    rec.makingOffer = true;
    addLocalTracks(rec.pc);
    return rec.pc.createOffer({ offerToReceiveAudio: true, offerToReceiveVideo: !audioOnly }).then(function (offer) {
      return rec.pc.setLocalDescription(offer);
    }).then(function () {
      rec.makingOffer = false;
      return sendTo("offer", userId, descPayload(rec.pc.localDescription));
    }).catch(function (err) {
      rec.makingOffer = false;
      console.error(err);
    });
  }
  function answerFrom(userId, offer) {
    var rec = ensurePeer(userId);
    if (!rec || !offer) return Promise.resolve();
    addLocalTracks(rec.pc);
    return rec.pc.setRemoteDescription(new RTCSessionDescription(offer)).then(function () {
      flushIce(rec);
      return rec.pc.createAnswer();
    }).then(function (answer) {
      return rec.pc.setLocalDescription(answer);
    }).then(function () {
      return sendTo("answer", userId, descPayload(rec.pc.localDescription));
    }).catch(function (err) {
      console.error(err);
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
    dialing = false;
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
      startBtn.hidden = calling || !(canStart || incoming);
      startBtn.disabled = dialing;
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
  function flushPendingAnswers() {
    Object.keys(pendingOffers).forEach(function (id) {
      var off = pendingOffers[id];
      delete pendingOffers[id];
      answerFrom(id, off);
    });
  }
  function startCall() {
    if (dialing || calling) return;
    dialing = true;
    syncButtons();
    media().then(function () {
      return post({ action: "poll" });
    }).then(function (data) {
      var list = (data && data.members) || [];
      setStatus("در حال تماس…");
      if (canStart) ringMembers(list);
      calling = true;
      joined = true;
      dialing = false;
      syncButtons();
      list.forEach(function (m) {
        if (m && m.id && m.id !== meId) offerTo(m.id);
      });
    }).catch(function () {
      dialing = false;
      syncButtons();
    });
  }
  function acceptIncoming() {
    if (dialing || calling) {
      flushPendingAnswers();
      return;
    }
    dialing = true;
    syncButtons();
    media().then(function () {
      if (incomingEl) incomingEl.hidden = true;
      stopRing();
      calling = true;
      joined = true;
      dialing = false;
      syncButtons();
      sendTo("join", "", { name: peerName });
      flushPendingAnswers();
    }).catch(function () {
      dialing = false;
      syncButtons();
    });
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
      if (calling && canStart && from && from !== meId) {
        offerTo(from);
      }
    } else if (kind === "offer" && payload) {
      pendingOffers[from] = payload;
      if (calling) {
        delete pendingOffers[from];
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
      if (calling && isGroup && canStart) {
        (data.members || []).forEach(function (m) {
          if (m && m.id !== meId && !peers[m.id]) offerTo(m.id);
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

  function on(el, ev, fn) {
    if (!el) return;
    if (bindOpts) el.addEventListener(ev, fn, bindOpts);
    else el.addEventListener(ev, fn);
  }
  on(permitBtn, "click", function () {
    ctx();
    requestMedia().then(function () {
      if (wantAutoAnswer || (!canStart && isGroup)) acceptIncoming();
      else if (canStart) startCall();
    }).catch(function () {});
  });
  on(startBtn, "click", function () {
    ctx();
    if (incomingEl && !incomingEl.hidden && !calling) acceptIncoming();
    else if (canStart) startCall();
  });
  on(hangBtn, "click", function () {
    sendTo("hangup", "", null);
    if (window.ManaVideoCall) window.ManaVideoCall.stop();
  });
  fsBtns.forEach(function (btn) {
    on(btn, "click", function () {
      var el = stageEl || root;
      if (document.fullscreenElement) document.exitFullscreen();
      else if (el.requestFullscreen) el.requestFullscreen();
    });
  });
  on(enhanceBtn, "click", function () {
    var enabled = !stageEl.classList.contains("is-enhanced");
    stageEl.classList.toggle("is-enhanced", enabled);
    enhanceBtn.setAttribute("aria-pressed", enabled ? "true" : "false");
  });
  on(recordBtn, "click", function () {
    if (recorder) stopRecording();
    else startRecording();
  });
  if (guardCapture) {
    document.addEventListener("visibilitychange", function () {
      var hidden = document.hidden;
      root.classList.toggle("is-black", hidden);
      if (blackoutEl) blackoutEl.hidden = !hidden;
    }, bindOpts || false);
  }
  window.addEventListener("pagehide", function () {
    if (calling) sendTo("leave", "", null);
  }, bindOpts || false);
  syncButtons();
  requestMedia().then(function () {
    if (wantAutoAnswer) acceptIncoming();
    else if (autoDial && canStart) startCall();
    else if (isGroup && canStart) startCall();
    else if (isGroup && !canStart) acceptIncoming();
  }).catch(function () {});
  poll();
  var pollTimer = setInterval(poll, 800);
  return function () {
    if (ac) ac.abort();
    clearInterval(pollTimer);
    try { sendTo("leave", "", null); } catch (e) {}
    endAll(false);
    if (localStream) {
      localStream.getTracks().forEach(function (t) { t.stop(); });
      localStream = null;
    }
  };
  }

  window.ManaVideoCall = {
    start: function (info) {
      var root = document.querySelector("[data-video-call]");
      var idle = document.querySelector("[data-vc-idle]");
      if (!root || !info || !info.room) return;
      if (liveDestroy) {
        liveDestroy();
        liveDestroy = null;
      }
      root.setAttribute("data-room", info.room);
      root.setAttribute("data-peer-name", info.title || "تماس مانا");
      root.setAttribute("data-media", info.media === "audio" ? "audio" : "video");
      root.setAttribute("data-group", info.group ? "1" : "0");
      root.setAttribute("data-can-start", info.canStart ? "1" : "0");
      if (info.answer) {
        try { sessionStorage.setItem("mana-video-auto-answer", "1"); } catch (e) {}
        root.setAttribute("data-auto-dial", "0");
      } else {
        root.setAttribute("data-auto-dial", info.autoStart === false ? "0" : "1");
      }
      var mark = root.querySelector("[data-vc-watermark]");
      if (mark) mark.textContent = info.title ? ("در حال تماس با " + info.title) : "";
      var shareWrap = document.querySelector("[data-vc-share-wrap]");
      var shareInput = document.getElementById("vc-share-link");
      if (shareInput) shareInput.value = info.shareUrl || "";
      if (shareWrap) shareWrap.hidden = !info.shareUrl;
      root.hidden = false;
      if (idle) idle.hidden = true;
      var composer = document.querySelector("[data-vc-composer]");
      if (composer) composer.hidden = true;
      var stage = root.querySelector("[data-video-stage]");
      if (stage) stage.classList.toggle("is-group", !!info.group);
      liveDestroy = attach(root);
    },
    stop: function (skipDestroy) {
      if (!skipDestroy && liveDestroy) liveDestroy();
      liveDestroy = null;
      var root = document.querySelector("[data-video-call]");
      var idle = document.querySelector("[data-vc-idle]");
      if (root) {
        root.hidden = true;
        root.setAttribute("data-room", "");
      }
      var composer = document.querySelector("[data-vc-composer]");
      if (composer) composer.hidden = false;
      if (idle) idle.hidden = !!composer;
      var shareWrap = document.querySelector("[data-vc-share-wrap]");
      if (shareWrap) shareWrap.hidden = true;
      var mark = document.querySelector("[data-vc-watermark]");
      if (mark) mark.textContent = "";
    }
  };

  var bootRoot = document.querySelector("[data-video-call]");
  var bootRoom = bootRoot && bootRoot.getAttribute("data-room");
  if (bootRoom) {
    window.ManaVideoCall.start({
      room: bootRoom,
      title: bootRoot.getAttribute("data-peer-name"),
      media: bootRoot.getAttribute("data-media"),
      group: bootRoot.getAttribute("data-group") === "1",
      canStart: bootRoot.getAttribute("data-can-start") === "1",
      shareUrl: (document.getElementById("vc-share-link") || {}).value || "",
      answer: /(?:^|[?&])answer=1(?:&|$)/.test(location.search || "")
    });
  }
})();
