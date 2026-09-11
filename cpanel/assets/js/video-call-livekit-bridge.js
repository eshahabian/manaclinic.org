(function () {
  "use strict";

  var legacyScript = document.createElement("script");
  legacyScript.src = "/assets/js/video-call-lobby-legacy.js?v=20260911g";
  legacyScript.async = false;
  var frame = null;

  function csrfToken() {
    return (document.querySelector('meta[name="csrf-token"]') || {}).content || "";
  }

  function jsonPost(url, body) {
    return fetch(url, {
      method: "POST",
      credentials: "same-origin",
      headers: {
        "Content-Type": "application/json",
        "Accept": "application/json",
        "X-CSRF-Token": csrfToken(),
        "X-Requested-With": "XMLHttpRequest"
      },
      body: JSON.stringify(body || {})
    }).then(function (r) {
      return r.json().then(function (data) {
        if (!r.ok || (data && data.ok === false)) throw new Error((data && data.error) || "خطای ارتباط");
        return data;
      });
    });
  }

  function sendRinging(info) {
    if (!info || !info.canStart || !info.room) return Promise.resolve();
    return jsonPost("/video-signal", { action: "poll", room: info.room }).then(function (data) {
      var members = (data && data.members) || [];
      return Promise.all(members.map(function (m) {
        if (!m || !m.id) return Promise.resolve();
        return jsonPost("/video-signal", {
          action: "send",
          room: info.room,
          kind: "ringing",
          target_id: m.id,
          payload: {
            name: info.title || "تماس مانا",
            media: info.media === "audio" ? "audio" : "video",
            group: !!info.group,
            engine: "livekit"
          }
        }).catch(function () {});
      }));
    }).catch(function () {});
  }

  function showTile(info) {
    var root = document.querySelector("[data-video-call]");
    if (!root || !info || !info.room) return null;
    root.setAttribute("data-room", info.room);
    root.setAttribute("data-peer-name", info.title || "تماس مانا");
    root.setAttribute("data-media", info.media === "audio" ? "audio" : "video");
    root.setAttribute("data-group", info.group ? "1" : "0");
    root.setAttribute("data-can-start", info.canStart ? "1" : "0");
    root.hidden = false;
    root.style.display = "block";
    root.style.visibility = "visible";
    root.style.opacity = "1";
    root.classList.remove("is-black");

    var idle = document.querySelector("[data-vc-idle]");
    if (idle) idle.hidden = true;
    var composer = document.querySelector("[data-vc-composer]");
    if (composer) composer.hidden = true;
    var stage = root.querySelector("[data-video-stage]");
    if (!stage) return null;
    stage.style.position = "relative";
    stage.style.display = "block";
    stage.style.visibility = "visible";
    stage.style.opacity = "1";
    stage.style.height = "100%";
    stage.style.minHeight = "22rem";
    stage.style.overflow = "hidden";

    Array.prototype.forEach.call(stage.children, function (el) {
      if (el && el.getAttribute && el.getAttribute("data-livekit-frame-wrap") !== "1") el.style.display = "none";
    });

    var wrap = stage.querySelector('[data-livekit-frame-wrap="1"]');
    if (!wrap) {
      wrap = document.createElement("div");
      wrap.setAttribute("data-livekit-frame-wrap", "1");
      wrap.style.cssText = "position:absolute;inset:0;width:100%;height:100%;min-height:22rem;background:#0d1a16;border-radius:inherit;overflow:hidden;z-index:20";
      stage.appendChild(wrap);
    }
    wrap.style.display = "block";
    return { root: root, stage: stage, wrap: wrap };
  }

  function resetTile() {
    var root = document.querySelector("[data-video-call]");
    if (!root) return;
    if (frame) {
      try { frame.src = "about:blank"; } catch (e) {}
      frame.remove();
      frame = null;
    }
    var stage = root.querySelector("[data-video-stage]");
    if (stage) {
      var wrap = stage.querySelector('[data-livekit-frame-wrap="1"]');
      if (wrap) wrap.remove();
      Array.prototype.forEach.call(stage.children, function (el) { if (el && el.style) el.style.display = ""; });
      stage.style.height = "";
    }
    root.hidden = true;
    root.removeAttribute("style");
    root.setAttribute("data-room", "");
    var composer = document.querySelector("[data-vc-composer]");
    var idle = document.querySelector("[data-vc-idle]");
    if (composer) composer.hidden = false;
    if (idle) idle.hidden = !!composer;
  }

  function liveKitStart(info) {
    if (!info || !info.room) return;
    var ui = showTile(info);
    if (!ui) return;
    if (frame) frame.remove();
    if (info.canStart) sendRinging(info);

    var q = new URLSearchParams();
    q.set("room", String(info.room));
    q.set("media", info.media === "audio" ? "audio" : "video");
    q.set("auto", "1");
    frame = document.createElement("iframe");
    frame.src = "/video-call-embed?" + q.toString();
    frame.title = "تماس مانا";
    frame.allow = "camera; microphone; autoplay; fullscreen";
    frame.setAttribute("allowfullscreen", "");
    frame.style.cssText = "width:100%;height:100%;min-height:22rem;border:0;display:block;background:#0d1a16";
    ui.wrap.appendChild(frame);
  }

  function installBridge() {
    if (!window.ManaVideoCall || typeof window.ManaVideoCall.start !== "function") return;
    if (!window.ManaVideoCall.legacyStart) window.ManaVideoCall.legacyStart = window.ManaVideoCall.start;
    if (!window.ManaVideoCall.legacyStop && typeof window.ManaVideoCall.stop === "function") window.ManaVideoCall.legacyStop = window.ManaVideoCall.stop;
    window.ManaVideoCall.start = liveKitStart;
    window.ManaVideoCall.stop = resetTile;
  }

  window.addEventListener("message", function (ev) {
    if (ev.origin !== location.origin || !ev.data) return;
    if (ev.data.type === "mana-livekit-leave") resetTile();
  });

  legacyScript.onload = installBridge;
  (document.head || document.documentElement).appendChild(legacyScript);
})();
