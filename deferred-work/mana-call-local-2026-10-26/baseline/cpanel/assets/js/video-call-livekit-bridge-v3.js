(function () {
  "use strict";
  // Start the call inside the lobby «خانه» pane when that frame is on the page.
  // Standalone /video-call-live remains the fallback. Do not iframe therapist media.
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

  function signalUrl() {
    var el = document.querySelector("[data-signal-url]");
    return (el && el.getAttribute("data-signal-url")) || "/video-signal";
  }

  function csrf() {
    return (document.querySelector('meta[name="csrf-token"]') || {}).content || "";
  }

  function postSignal(body) {
    return fetch(signalUrl(), {
      method: "POST",
      credentials: "same-origin",
      headers: {
        "Content-Type": "application/json",
        Accept: "application/json",
        "X-CSRF-Token": csrf(),
        "X-Requested-With": "XMLHttpRequest"
      },
      body: JSON.stringify(body)
    }).then(function (r) { return r.json(); });
  }

  function ringRoom(i) {
    var meEl = document.querySelector("[data-me]");
    var me = meEl ? (meEl.getAttribute("data-me") || "") : "";
    return postSignal({ action: "poll", room: String(i.room) }).then(function (data) {
      var members = (data && data.members) || [];
      var jobs = [];
      members.forEach(function (m) {
        if (!m || !m.id || String(m.id) === me) return;
        jobs.push(postSignal({
          action: "send",
          room: String(i.room),
          kind: "ringing",
          target_id: String(m.id),
          payload: {
            name: i.title || "تماس مانا",
            media: i.media === "audio" ? "audio" : "video",
            group: !!i.group,
            engine: "livekit"
          }
        }));
      });
      return Promise.all(jobs);
    }).catch(function () {});
  }

  function go(i) {
    if (!i || !i.room) return;
    stopMedia();
    var home = document.querySelector("[data-livekit-home]");
    if (home && window.ManaLiveKitUi && typeof window.ManaLiveKitUi.open === "function") {
      if (i.canStart) ringRoom(i);
      window.ManaLiveKitUi.open(i);
      return;
    }
    if (i.canStart) {
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
