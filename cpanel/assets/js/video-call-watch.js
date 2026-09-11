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

  function mediaOf(item) {
    return item && item.media === "audio" ? "audio" : "video";
  }
  function primeMedia(audioOnly) {
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
      return Promise.resolve(null);
    }
    var pre = null;
    try { pre = window.__VC_PRESTREAM__; } catch (e) {}
    if (pre && pre.getTracks) {
      var hasAudio = pre.getTracks().some(function (t) { return t.kind === "audio" && t.readyState === "live"; });
      var hasVideo = pre.getTracks().some(function (t) { return t.kind === "video" && t.readyState === "live"; });
      if (hasAudio && (audioOnly || hasVideo)) return Promise.resolve(pre);
    }
    if (window.__VC_PRIMING__) return window.__VC_PRIMING__;
    var req = audioOnly
      ? { audio: true, video: false }
      : { audio: true, video: { facingMode: "user" } };
    window.__VC_PRIMING__ = navigator.mediaDevices.getUserMedia(req).then(function (stream) {
      window.__VC_PRESTREAM__ = stream;
      window.__VC_PRIMING__ = null;
      return stream;
    }).catch(function () {
      if (audioOnly) {
        window.__VC_PRIMING__ = null;
        return null;
      }
      return navigator.mediaDevices.getUserMedia({ audio: true, video: true }).then(function (stream) {
        window.__VC_PRESTREAM__ = stream;
        window.__VC_PRIMING__ = null;
        return stream;
      }).catch(function () {
        window.__VC_PRIMING__ = null;
        return null;
      });
    });
    return window.__VC_PRIMING__;
  }

  // Mobile patient safeguard: WebRTC can be live while the call tile remains visually blank.
  // Keep only the patient-side active call container and stage explicitly visible; do not touch clinician UI.
  function fixPatientCallUi() {
    var root = document.querySelector("[data-video-call]");
    if (!root || root.getAttribute("data-can-start") === "1") return;
    var room = root.getAttribute("data-room") || "";
    if (!room || root.hidden) return;

    root.style.display = "block";
    root.style.visibility = "visible";
    root.style.opacity = "1";
    root.style.minHeight = "min(62vh, 28rem)";

    var main = root.closest(".vc-lobby-main");
    if (main) {
      main.style.display = "block";
      main.style.visibility = "visible";
      main.style.opacity = "1";
      main.style.minHeight = "min(62vh, 28rem)";
    }

    var stage = root.querySelector("[data-video-stage]");
    if (stage) {
      stage.style.display = "block";
      stage.style.visibility = "visible";
      stage.style.opacity = "1";
      stage.style.position = "relative";
      stage.style.width = "100%";
      stage.style.minHeight = "min(62vh, 28rem)";
      stage.style.height = "min(62vh, 28rem)";
      stage.style.background = "#12241f";
    }

    // Permission dialogs on mobile can briefly trigger visibilitychange. Restore the UI once visible again.
    if (!document.hidden) {
      root.classList.remove("is-black");
      var blackout = root.querySelector("[data-video-blackout]");
      if (blackout) blackout.hidden = true;
      var local = root.querySelector("[data-video-local]");
      if (local) {
        local.style.visibility = "visible";
        local.style.opacity = "1";
        local.style.filter = "none";
        if (local.srcObject) local.play().catch(function () {});
      }
      root.querySelectorAll("[data-video-remote]").forEach(function (vid) {
        vid.style.visibility = "visible";
        vid.style.opacity = "1";
        vid.style.filter = "none";
        if (vid.srcObject) vid.play().catch(function () {});
      });
    }
  }

  function goToCall() {
    try { sessionStorage.setItem("mana-video-auto-answer", "1"); } catch (e) {}
    var media = mediaOf(current);
    // دوربین را همان لحظهٔ لمس «پاسخ» باز کن تا موبایل فقط صدا نگیرد
    primeMedia(media === "audio");
    if (window.ManaVideoCall && window.ManaVideoCall.start && current && current.room && document.querySelector("[data-vc-idle]")) {
      post({ action: "open", room: current.room, media: media }).then(function (data) {
        if (data && data.ok) {
          data.answer = true;
          data.media = media;
          window.ManaVideoCall.start(data);
          setTimeout(fixPatientCallUi, 0);
          setTimeout(fixPatientCallUi, 250);
          setTimeout(fixPatientCallUi, 1000);
          stopAlert();
        }
      }).catch(function () {
        window.location.href = (cfg.callUrl || "/video-call") + "?room=" + encodeURIComponent(current.room) + "&answer=1&media=" + encodeURIComponent(media);
      });
      return;
    }
    var url = cfg.callUrl || "/video-call";
    var q = "answer=1&media=" + encodeURIComponent(media);
    if (current && current.room) q = "room=" + encodeURIComponent(current.room) + "&" + q;
    url += (url.indexOf("?") >= 0 ? "&" : "?") + q;
    window.location.href = url;
  }

  function stopAlert() {
    ringing = false;
    current = null;
    if (ringAudio) {
      ringAudio.pause();
      try { ringAudio.currentTime = 0; } catch (e) {}
    }
    if (titleTimer) {
      clearInterval(titleTimer);
      titleTimer = null;
    }
    document.title = origTitle;
    if (banner) banner.hidden = true;
  }

  function ensureBanner() {
    if (banner) return banner;
    banner = document.createElement("div");
    banner.className = "video-call-alert";
    banner.hidden = true;
    banner.innerHTML =
      '<p class="video-call-alert-text"></p>' +
      '<div class="video-call-alert-actions">' +
      '<button type="button" class="btn btn-primary btn-sm" data-vc-alert-open>پاسخ</button>' +
      '<button type="button" class="btn btn-outline btn-sm" data-vc-alert-dismiss>بعداً</button>' +
      "</div>";
    document.body.appendChild(banner);
    banner.querySelector("[data-vc-alert-open]").addEventListener("click", goToCall);
    banner.querySelector("[data-vc-alert-dismiss]").addEventListener("click", stopAlert);
    return banner;
  }

  function startAlert(item) {
    if (ringing) return;
    ringing = true;
    current = item;
    var bar = ensureBanner();
    var text = bar.querySelector(".video-call-alert-text");
    var name = (item && (item.sender_name || item.title)) || "درمانگر";
    if (text) text.textContent = "تماس ورودی از " + name;
    bar.hidden = false;
    if (cfg.ringUrl) {
      if (!ringAudio) {
        ringAudio = new Audio(cfg.ringUrl);
        ringAudio.loop = true;
        ringAudio.playsInline = true;
      }
      ringAudio.currentTime = 0;
      var play = ringAudio.play();
      if (play && play.catch) play.catch(function () {});
    }
    var flip = false;
    titleTimer = setInterval(function () {
      flip = !flip;
      document.title = flip ? "تماس ورودی…" : origTitle;
    }, 900);
    if ("Notification" in window && Notification.permission === "granted") {
      try {
        var note = new Notification("تماس مانا", { body: "تماس ورودی از " + name, tag: "mana-video-incoming", renotify: true });
        note.onclick = function () { window.focus(); goToCall(); };
      } catch (e) {}
    }
    if (navigator.vibrate) navigator.vibrate([200, 100, 200]);
  }

  function tick() {
    fixPatientCallUi();
    var onCall = document.querySelector("[data-video-call]");
    if (onCall && onCall.getAttribute("data-room")) return;
    post({ action: "inbox" }).then(function (data) {
      if (document.hidden) return;
      if (!data || !data.ok) return;
      var items = data.items || [];
      if (!items.length) {
        if (ringing) stopAlert();
        return;
      }
      var item = items[0];
      if (seen[item.id]) return;
      seen[item.id] = true;
      startAlert(item);
    }).catch(function () {});
  }

  if ("Notification" in window && Notification.permission === "default") {
    document.addEventListener("click", function () {
      Notification.requestPermission().catch(function () {});
    }, { once: true });
  }
  function schedule() {
    setTimeout(function () {
      tick();
      schedule();
    }, document.hidden ? 25000 : 8000);
  }
  tick();
  schedule();
  var uiTimer = setInterval(fixPatientCallUi, 600);
  document.addEventListener("visibilitychange", function () {
    if (!document.hidden) {
      fixPatientCallUi();
      setTimeout(fixPatientCallUi, 250);
      tick();
    }
  });
  window.addEventListener("pagehide", function () {
    clearInterval(uiTimer);
  }, { once: true });
})();