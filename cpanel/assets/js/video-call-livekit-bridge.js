(function () {
  "use strict";

  // Load the original lobby controller first, then replace only the media start
  // action. The lobby/contact/group UI remains unchanged.
  window.__MANA_LIVEKIT_EMBED__ = true;
  window.__MANA_LIVEKIT_REDIRECT__ = true;

  var legacyScript = document.createElement("script");
  legacyScript.src = "/assets/js/video-call-lobby-legacy.js?v=20260911h";
  legacyScript.async = false;

  function csrfToken() {
    return (document.querySelector('meta[name="csrf-token"]') || {}).content || "";
  }

  function post(body) {
    return fetch("/video-signal", {
      method: "POST",
      credentials: "same-origin",
      headers: {
        "Content-Type": "application/json",
        "Accept": "application/json",
        "X-CSRF-Token": csrfToken(),
        "X-Requested-With": "XMLHttpRequest"
      },
      body: JSON.stringify(body || {})
    }).then(function (r) { return r.json(); });
  }

  function sendRinging(info) {
    if (!info || !info.canStart || !info.room) return Promise.resolve();
    return post({ action: "poll", room: info.room }).then(function (data) {
      var members = (data && data.members) || [];
      return Promise.all(members.map(function (m) {
        if (!m || !m.id) return Promise.resolve();
        return post({
          action: "send",
          room: info.room,
          kind: "ringing",
          target_id: m.id,
          payload: {
            name: info.title || "تماس مانا",
            media: info.media === "audio" ? "audio" : "video",
            group: !!info.group,
            engine: "livekit-standalone"
          }
        }).catch(function () {});
      }));
    }).catch(function () {});
  }

  function stopPrestream() {
    try {
      var s = window.__VC_PRESTREAM__;
      if (s && s.getTracks) s.getTracks().forEach(function (t) { try { t.stop(); } catch (e) {} });
      window.__VC_PRESTREAM__ = null;
      window.__VC_PRIMING__ = null;
    } catch (e) {}
  }

  function goStandalone(info) {
    if (!info || !info.room) return;
    var q = new URLSearchParams();
    q.set("room", String(info.room));
    q.set("media", info.media === "audio" ? "audio" : "video");
    stopPrestream();
    var url = "/video-call-live?" + q.toString();
    if (info.canStart) {
      sendRinging(info).finally(function () { window.location.href = url; });
    } else {
      window.location.href = url;
    }
  }

  function install() {
    if (!window.ManaVideoCall || typeof window.ManaVideoCall.start !== "function") return;
    if (!window.ManaVideoCall.legacyStart) window.ManaVideoCall.legacyStart = window.ManaVideoCall.start;
    window.ManaVideoCall.start = goStandalone;
    window.ManaVideoCall.stop = function () { window.location.href = "/video-call"; };
  }

  legacyScript.onload = install;
  (document.head || document.documentElement).appendChild(legacyScript);
})();
