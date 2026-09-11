(function () {
  "use strict";
  window.__MANA_LIVEKIT_EMBED__ = true;
  window.__MANA_LIVEKIT_REDIRECT__ = true;

  var oldStart = window.ManaVideoCall && window.ManaVideoCall.start;

  function csrf() {
    return (document.querySelector('meta[name="csrf-token"]') || {}).content || "";
  }

  function post(b) {
    return fetch("/video-signal", {
      method: "POST",
      credentials: "same-origin",
      headers: {
        "Content-Type": "application/json",
        Accept: "application/json",
        "X-CSRF-Token": csrf(),
        "X-Requested-With": "XMLHttpRequest"
      },
      body: JSON.stringify(b || {})
    }).then(function (r) { return r.json(); });
  }

  function ring(i) {
    if (!i || !i.canStart || !i.room) return Promise.resolve();
    return post({ action: "poll", room: i.room }).then(function (d) {
      return Promise.all(((d && d.members) || []).map(function (m) {
        if (!m || !m.id) return Promise.resolve();
        return post({
          action: "send",
          room: i.room,
          kind: "ringing",
          target_id: m.id,
          payload: {
            name: i.title || "تماس مانا",
            media: i.media === "audio" ? "audio" : "video",
            group: !!i.group,
            engine: "livekit-standalone"
          }
        }).catch(function () {});
      }));
    }).catch(function () {});
  }

  function stopMedia() {
    try {
      var s = window.__VC_PRESTREAM__;
      if (s && s.getTracks) s.getTracks().forEach(function (t) { try { t.stop(); } catch (e) {} });
      window.__VC_PRESTREAM__ = null;
      window.__VC_PRIMING__ = null;
    } catch (e) {}
  }

  function callUrl(i, extra) {
    var q = new URLSearchParams();
    q.set("room", String(i.room));
    q.set("media", i.media === "audio" ? "audio" : "video");
    if (extra) {
      Object.keys(extra).forEach(function (k) {
        if (extra[k] != null && extra[k] !== "") q.set(k, String(extra[k]));
      });
    }
    return "/video-call-live?" + q.toString();
  }

  // iframe روی خیلی از موبایل‌ها دوربین را نمی‌دهد؛ هر دو طرف صفحهٔ کامل LiveKit
  function go(i) {
    if (!i || !i.room) return;
    stopMedia();
    if (i.canStart) {
      var target = callUrl(i, { start: "1" });
      ring(i).finally(function () { location.replace(target); });
      return;
    }
    location.replace(callUrl(i, i.answer ? { answer: "1" } : null));
  }

  function install() {
    window.ManaVideoCall = window.ManaVideoCall || {};
    if (oldStart && !window.ManaVideoCall.legacyStart) window.ManaVideoCall.legacyStart = oldStart;
    window.ManaVideoCall.start = go;
    window.ManaVideoCall.stop = function () { location.href = "/video-call"; };
  }

  install();
  var s = document.createElement("script");
  s.src = "/assets/js/video-call-lobby-legacy.js?v=20260911p";
  s.async = false;
  s.onload = install;
  (document.head || document.documentElement).appendChild(s);
})();
