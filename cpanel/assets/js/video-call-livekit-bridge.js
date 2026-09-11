(function () {
  "use strict";

  var legacyScript = document.createElement("script");
  legacyScript.src = "/assets/js/video-call-lobby-legacy.js?v=20260911g";
  legacyScript.async = false;

  function liveKitStart(info) {
    if (!info || !info.room) return;

    var params = new URLSearchParams();
    params.set("room", String(info.room));
    params.set("media", info.media === "audio" ? "audio" : "video");
    if (info.canStart) params.set("start", "1");
    if (info.answer) params.set("answer", "1");

    window.location.href = "/video-call-v2?" + params.toString();
  }

  function installBridge() {
    if (!window.ManaVideoCall || typeof window.ManaVideoCall.start !== "function") return;

    // Keep the old P2P implementation available in memory for emergency rollback,
    // but route normal starts/answers to the tested LiveKit engine.
    if (!window.ManaVideoCall.legacyStart) {
      window.ManaVideoCall.legacyStart = window.ManaVideoCall.start;
    }
    window.ManaVideoCall.start = liveKitStart;
  }

  legacyScript.onload = installBridge;
  legacyScript.onerror = function () {
    // If the legacy lobby asset cannot load, keep the page usable rather than
    // replacing any existing call implementation.
  };
  (document.head || document.documentElement).appendChild(legacyScript);
})();
