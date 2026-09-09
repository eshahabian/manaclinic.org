(function () {
  if (document.querySelector("[data-video-call]")) return;
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

  function goToCall() {
    try { sessionStorage.setItem("mana-video-auto-answer", "1"); } catch (e) {}
    var url = cfg.callUrl || "/video-call";
    if (current && current.room) {
      url += (url.indexOf("?") >= 0 ? "&" : "?") + "room=" + encodeURIComponent(current.room) + "&answer=1";
    } else {
      url += (url.indexOf("?") >= 0 ? "&" : "?") + "answer=1";
    }
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
        var note = new Notification("تماس تصویری مانا", { body: "تماس ورودی از " + name, tag: "mana-video-incoming", renotify: true });
        note.onclick = function () { window.focus(); goToCall(); };
      } catch (e) {}
    }
    if (navigator.vibrate) navigator.vibrate([200, 100, 200]);
  }

  function tick() {
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
  document.addEventListener("visibilitychange", function () {
    if (!document.hidden) tick();
  });
})();
