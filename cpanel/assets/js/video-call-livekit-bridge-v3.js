(function () {
  "use strict";
  // Route lobby starts to the same standalone LiveKit page for both sides.
  // Do not open therapist media in an iframe (mobile camera + split engines).
  window.__MANA_LIVEKIT_EMBED__ = true;
  window.__MANA_LIVEKIT_REDIRECT__ = true;

  var oldStart = window.ManaVideoCall && window.ManaVideoCall.start;
  var booted = false;

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

  function go(i) {
    if (!i || !i.room) return;
    stopMedia();
    if (i.canStart) {
      // Therapist and patient use the exact same LiveKit page.
      // Ringing is inserted once by video_call_livekit.php when start=1.
      location.replace(callUrl(i, { start: "1" }));
      return;
    }
    location.replace(callUrl(i, i.answer || i.autoStart ? { answer: "1" } : null));
  }

  function install() {
    window.ManaVideoCall = window.ManaVideoCall || {};
    if (oldStart && !window.ManaVideoCall.legacyStart) {
      window.ManaVideoCall.legacyStart = oldStart;
    }
    window.ManaVideoCall.start = go;
    window.ManaVideoCall.stop = function () { location.href = "/video-call"; };
  }

  function bootFromDeepLink() {
    if (booted) return;
    var qs = new URLSearchParams(location.search || "");
    var should = qs.has("peer") || qs.has("answer") || qs.get("start") === "1" || qs.has("workshop");
    if (!should) return;
    var root = document.querySelector("[data-video-call]");
    var bootRoom = root && root.getAttribute("data-room");
    if (!bootRoom) return;
    booted = true;
    go({
      room: bootRoom,
      title: root.getAttribute("data-peer-name") || "تماس مانا",
      media: root.getAttribute("data-media") || "video",
      group: root.getAttribute("data-group") === "1",
      canStart: root.getAttribute("data-can-start") === "1",
      answer: qs.get("answer") === "1"
    });
  }

  install();

  var s = document.createElement("script");
  s.src = "/assets/js/video-call-lobby-legacy.js?v=20260911r";
  s.async = false;
  s.onload = function () {
    install();
    bootFromDeepLink();
  };
  (document.head || document.documentElement).appendChild(s);
  setTimeout(bootFromDeepLink, 50);
})();
