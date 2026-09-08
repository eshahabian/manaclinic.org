(function () {
  if (document.querySelector("[data-video-call]")) return;
  var cfg = window.__VIDEO_CALL_WATCH__;
  if (!cfg || !cfg.signalUrl) return;

  var token = (document.querySelector('meta[name="csrf-token"]') || {}).content || "";
  var lastAfter = "";
  var seen = {};
  var ringing = false;
  var ringAudio = null;
  var titleTimer = null;
  var origTitle = document.title;
  var banner = null;

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

  function askNotify() {
    if (!("Notification" in window)) return;
    if (Notification.permission === "default") Notification.requestPermission().catch(function () {});
  }

  function goToCall() {
    window.location.href = cfg.callUrl;
  }

  function stopAlert() {
    ringing = false;
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
    banner.querySelector("[data-vc-alert-dismiss]").addEventListener("click", function () {
      stopAlert();
    });
    return banner;
  }

  function startAlert() {
    if (ringing) return;
    ringing = true;
    var bar = ensureBanner();
    var text = bar.querySelector(".video-call-alert-text");
    if (text) text.textContent = "تماس ورودی از " + cfg.peerName;
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
        var note = new Notification("تماس تصویری مانا کلینیک", {
          body: "تماس ورودی از " + cfg.peerName,
          tag: "mana-video-incoming",
          renotify: true
        });
        note.onclick = function () {
          window.focus();
          goToCall();
        };
      } catch (e) {}
    }
    if (navigator.vibrate) navigator.vibrate([200, 100, 200]);
  }

  function handle(sig) {
    if (!sig || seen[sig.id]) return;
    seen[sig.id] = true;
    if (sig.kind === "offer") startAlert();
    if (sig.kind === "hangup") stopAlert();
  }

  function poll() {
    post({ action: "poll", after: lastAfter }).then(function (data) {
      if (!data || !data.ok) return;
      (data.signals || []).forEach(function (sig) {
        handle(sig);
        if (sig.created_at && sig.created_at > lastAfter) lastAfter = sig.created_at;
      });
    }).catch(function () {});
  }

  document.addEventListener("click", askNotify, { once: true });
  askNotify();
  poll();
  setInterval(poll, 2000);
})();
