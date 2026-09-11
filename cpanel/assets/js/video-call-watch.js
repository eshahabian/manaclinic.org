(function () {
  var cfg = window.__VIDEO_CALL_WATCH__;
  if (!cfg || !cfg.signalUrl) return;

  var token = (document.querySelector('meta[name="csrf-token"]') || {}).content || "";
  var seen = {};
  var ringing = false;
  var ringAudio = null;
  var titleTimer = null;
  var origTitle = document.title;
  var banner = null;
  var current = null;
  var activeRoom = "";

  function post(body) {
    return fetch(cfg.signalUrl, {
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

  function onLiveKitCallPage() {
    var path = (location.pathname || "").replace(/\/+$/, "");
    if (path.indexOf("/video-call-live") >= 0 || path.indexOf("/video-call-embed") >= 0) return true;
    if (document.querySelector("[data-livekit-v2]")) return true;
    if (window.__MANA_LIVEKIT_CALL_ACTIVE__) return true;
    return false;
  }

  function ackRoom(room) {
    var body = { action: "ack_ring" };
    if (room) body.room = String(room);
    return post(body).catch(function () {});
  }

  function mediaOf(item) {
    return item && item.media === "audio" ? "audio" : "video";
  }

  function usingLiveKitEmbed() {
    return !!window.__MANA_LIVEKIT_EMBED__ || !!document.querySelector('[data-livekit-frame-wrap="1"]');
  }

  function primeMedia(audioOnly) {
    if (usingLiveKitEmbed() || window.__MANA_LIVEKIT_REDIRECT__) return Promise.resolve(null);
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) return Promise.resolve(null);
    var pre = null;
    try { pre = window.__VC_PRESTREAM__; } catch (e) {}
    if (pre && pre.getTracks) {
      var hasAudio = pre.getTracks().some(function (t) { return t.kind === "audio" && t.readyState === "live"; });
      var hasVideo = pre.getTracks().some(function (t) { return t.kind === "video" && t.readyState === "live"; });
      if (hasAudio && (audioOnly || hasVideo)) return Promise.resolve(pre);
    }
    if (window.__VC_PRIMING__) return window.__VC_PRIMING__;
    var req = audioOnly ? { audio: true, video: false } : { audio: true, video: { facingMode: "user" } };
    window.__VC_PRIMING__ = navigator.mediaDevices.getUserMedia(req).then(function (stream) {
      window.__VC_PRESTREAM__ = stream;
      window.__VC_PRIMING__ = null;
      return stream;
    }).catch(function () {
      window.__VC_PRIMING__ = null;
      return null;
    });
    return window.__VC_PRIMING__;
  }

  function markAnswered(room) {
    if (!room) return;
    try { sessionStorage.setItem("mana-ack-room-" + room, String(Date.now())); } catch (e) {}
  }

  function recentlyAnswered(room) {
    if (!room) return false;
    try {
      var t = parseInt(sessionStorage.getItem("mana-ack-room-" + room) || "0", 10);
      return t && (Date.now() - t) < 120000;
    } catch (e) { return false; }
  }

  function goToCall() {
    var media = mediaOf(current);
    var room = current && current.room ? String(current.room) : "";
    markAnswered(room);
    ackRoom(room);
    stopAlert(true);
    // Always land both parties on the same standalone LiveKit page.
    var url = "/video-call-live";
    var q = "answer=1&media=" + encodeURIComponent(media);
    if (room) q = "room=" + encodeURIComponent(room) + "&" + q;
    window.location.href = url + "?" + q;
  }

  function stopAlert(keepSeen) {
    ringing = false;
    var room = current && current.room ? String(current.room) : activeRoom;
    current = null;
    activeRoom = "";
    if (ringAudio) { ringAudio.pause(); try { ringAudio.currentTime = 0; } catch (e) {} }
    if (titleTimer) { clearInterval(titleTimer); titleTimer = null; }
    document.title = origTitle;
    if (banner) banner.hidden = true;
    if (!keepSeen && room) ackRoom(room);
  }

  function ensureBanner() {
    if (banner) return banner;
    banner = document.createElement("div");
    banner.className = "video-call-alert";
    banner.hidden = true;
    banner.innerHTML = '<p class="video-call-alert-text"></p><div class="video-call-alert-actions"><button type="button" class="btn btn-primary btn-sm" data-vc-alert-open>پاسخ</button><button type="button" class="btn btn-outline btn-sm" data-vc-alert-dismiss>رد تماس</button></div>';
    document.body.appendChild(banner);
    banner.querySelector("[data-vc-alert-open]").addEventListener("click", goToCall);
    banner.querySelector("[data-vc-alert-dismiss]").addEventListener("click", function () {
      stopAlert(false);
    });
    return banner;
  }

  function startAlert(item) {
    if (ringing) return;
    if (onLiveKitCallPage()) return;
    ringing = true;
    current = item;
    activeRoom = item && item.room ? String(item.room) : "";
    var bar = ensureBanner();
    var text = bar.querySelector(".video-call-alert-text");
    var name = (item && (item.sender_name || item.title)) || "درمانگر";
    if (text) text.textContent = "تماس ورودی از " + name;
    bar.hidden = false;
    if (cfg.ringUrl) {
      if (!ringAudio) { ringAudio = new Audio(cfg.ringUrl); ringAudio.loop = true; ringAudio.playsInline = true; }
      ringAudio.currentTime = 0;
      var play = ringAudio.play(); if (play && play.catch) play.catch(function () {});
    }
    var flip = false;
    titleTimer = setInterval(function () { flip = !flip; document.title = flip ? "تماس ورودی…" : origTitle; }, 900);
    if ("Notification" in window && Notification.permission === "granted") {
      try {
        var note = new Notification("تماس مانا", { body: "تماس ورودی از " + name, tag: "mana-video-incoming", renotify: false });
        note.onclick = function () { window.focus(); goToCall(); };
      } catch (e) {}
    }
    if (navigator.vibrate) navigator.vibrate([200, 100, 200]);
  }

  function tick() {
    if (onLiveKitCallPage()) {
      if (ringing) stopAlert(true);
      return;
    }
    post({ action: "inbox" }).then(function (data) {
      if (document.hidden || !data || !data.ok) return;
      var items = data.items || [];
      if (!items.length) { if (ringing) stopAlert(true); return; }
      var item = items[0];
      if (!item) return;
      if (recentlyAnswered(item.room)) {
        ackRoom(item.room);
        return;
      }
      if (ringing && activeRoom && String(item.room) === activeRoom) return;
      if (seen[item.id]) return;
      seen[item.id] = true;
      startAlert(item);
    }).catch(function () {});
  }

  if ("Notification" in window && Notification.permission === "default") {
    document.addEventListener("click", function () { Notification.requestPermission().catch(function () {}); }, { once: true });
  }
  function schedule() { setTimeout(function () { tick(); schedule(); }, document.hidden ? 25000 : 8000); }
  tick(); schedule();
  document.addEventListener("visibilitychange", function () {
    if (!document.hidden) tick();
  });
})();
