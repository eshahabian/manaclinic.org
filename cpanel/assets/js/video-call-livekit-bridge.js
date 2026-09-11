(function () {
  "use strict";

  var legacyScript = document.createElement("script");
  legacyScript.src = "/assets/js/video-call-lobby-legacy.js?v=20260911g";
  legacyScript.async = false;

  var activeRoom = null;
  var sdkPromise = null;
  var connectSeq = 0;

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
        if (!r.ok || (data && data.ok === false)) {
          throw new Error((data && data.error) || "خطای ارتباط");
        }
        return data;
      });
    });
  }

  function ensureSdk() {
    if (window.LivekitClient) return Promise.resolve(window.LivekitClient);
    if (sdkPromise) return sdkPromise;
    sdkPromise = new Promise(function (resolve, reject) {
      var s = document.createElement("script");
      s.src = "https://cdn.jsdelivr.net/npm/livekit-client@2.22.3/dist/livekit-client.umd.min.js";
      s.async = true;
      s.onload = function () {
        if (window.LivekitClient) resolve(window.LivekitClient);
        else reject(new Error("موتور تماس بارگذاری نشد."));
      };
      s.onerror = function () { reject(new Error("موتور تماس بارگذاری نشد.")); };
      (document.head || document.documentElement).appendChild(s);
    });
    return sdkPromise;
  }

  function setStatus(root, text, show) {
    var el = root && root.querySelector("[data-video-status]");
    if (!el) return;
    el.textContent = text || "";
    el.hidden = !show;
  }

  function showCallUi(root, info) {
    if (!root) return;
    root.setAttribute("data-room", info.room || "");
    root.setAttribute("data-peer-name", info.title || "تماس مانا");
    root.setAttribute("data-media", info.media === "audio" ? "audio" : "video");
    root.setAttribute("data-group", info.group ? "1" : "0");
    root.setAttribute("data-can-start", info.canStart ? "1" : "0");
    root.hidden = false;
    root.style.display = "block";
    root.style.visibility = "visible";
    root.style.opacity = "1";

    var idle = document.querySelector("[data-vc-idle]");
    if (idle) idle.hidden = true;
    var composer = document.querySelector("[data-vc-composer]");
    if (composer) composer.hidden = true;

    var stage = root.querySelector("[data-video-stage]");
    if (stage) {
      stage.classList.toggle("is-group", !!info.group);
      stage.classList.toggle("is-audio", info.media === "audio");
      stage.style.visibility = "visible";
      stage.style.opacity = "1";
    }
    root.classList.toggle("is-audio", info.media === "audio");
    root.classList.remove("is-black");
    var blackout = root.querySelector("[data-video-blackout]");
    if (blackout) blackout.hidden = true;

    var mark = root.querySelector("[data-vc-watermark]");
    if (mark) mark.textContent = info.title ? ("در حال تماس با " + info.title) : "تماس مانا";
    var startBtn = root.querySelector("[data-video-start]");
    if (startBtn) startBtn.hidden = true;
    var hangBtn = root.querySelector("[data-video-hangup]");
    if (hangBtn) hangBtn.hidden = false;
    var incoming = root.querySelector("[data-video-incoming]");
    if (incoming) incoming.hidden = true;
    var permit = root.querySelector("[data-video-permit]");
    if (permit) permit.hidden = true;
    var record = root.querySelector("[data-video-record]");
    if (record) record.hidden = true;

    var shareWrap = document.querySelector("[data-vc-share-wrap]");
    var shareInput = document.getElementById("vc-share-link");
    if (shareInput) shareInput.value = info.shareUrl || "";
    if (shareWrap) shareWrap.hidden = !info.shareUrl;
  }

  function resetCallUi(root) {
    if (!root) return;
    root.hidden = true;
    root.removeAttribute("style");
    root.setAttribute("data-room", "");
    root.classList.remove("is-audio", "is-black");
    var remotes = root.querySelector("[data-video-remotes]");
    if (remotes) remotes.innerHTML = "";
    var local = root.querySelector("[data-video-local]");
    if (local) {
      try { local.pause(); } catch (e) {}
      local.srcObject = null;
    }
    var composer = document.querySelector("[data-vc-composer]");
    var idle = document.querySelector("[data-vc-idle]");
    if (composer) composer.hidden = false;
    if (idle) idle.hidden = !!composer;
    setStatus(root, "", false);
  }

  function tileId(identity) {
    return "lk-" + String(identity || "remote").replace(/[^a-zA-Z0-9_-]/g, "_");
  }

  function remoteTile(root, participant) {
    var remotes = root.querySelector("[data-video-remotes]");
    if (!remotes) return null;
    var id = tileId(participant && participant.identity);
    var tile = document.getElementById(id);
    if (tile) return tile;
    tile = document.createElement("div");
    tile.id = id;
    tile.className = "vc-remote-tile";
    tile.setAttribute("data-livekit-participant", participant && participant.identity ? participant.identity : "remote");
    remotes.appendChild(tile);
    return tile;
  }

  function attachRemoteTrack(root, LK, track, participant) {
    var tile = remoteTile(root, participant);
    if (!tile) return;
    if (track.kind === LK.Track.Kind.Video) {
      var video = tile.querySelector("video");
      if (!video) {
        video = document.createElement("video");
        video.autoplay = true;
        video.playsInline = true;
        video.setAttribute("playsinline", "");
        video.setAttribute("webkit-playsinline", "");
        video.setAttribute("data-video-remote", "1");
        tile.appendChild(video);
      }
      track.attach(video);
      video.play().catch(function () {});
      var stage = root.querySelector("[data-video-stage]");
      if (stage) stage.classList.add("is-live");
    } else if (track.kind === LK.Track.Kind.Audio) {
      var audio = tile.querySelector("audio");
      if (!audio) {
        audio = document.createElement("audio");
        audio.autoplay = true;
        audio.playsInline = true;
        audio.style.display = "none";
        tile.appendChild(audio);
      }
      track.attach(audio);
      audio.play().catch(function () {});
    }
  }

  function attachLocalTrack(root, LK, track) {
    if (!root || track.kind !== LK.Track.Kind.Video) return;
    var local = root.querySelector("[data-video-local]");
    if (!local) return;
    local.autoplay = true;
    local.muted = true;
    local.playsInline = true;
    local.setAttribute("playsinline", "");
    local.setAttribute("webkit-playsinline", "");
    track.attach(local);
    local.play().catch(function () {});
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

  function disconnectActive(reset) {
    connectSeq++;
    if (activeRoom) {
      try { activeRoom.disconnect(); } catch (e) {}
      activeRoom = null;
    }
    if (reset !== false) resetCallUi(document.querySelector("[data-video-call]"));
  }

  function liveKitStart(info) {
    if (!info || !info.room) return;
    var root = document.querySelector("[data-video-call]");
    if (!root) return;
    var seq = ++connectSeq;

    if (activeRoom) {
      try { activeRoom.disconnect(); } catch (e) {}
      activeRoom = null;
    }

    showCallUi(root, info);
    setStatus(root, "در حال اتصال…", true);
    sendRinging(info);

    Promise.all([
      ensureSdk(),
      jsonPost("/livekit-token", { room: info.room })
    ]).then(async function (parts) {
      if (seq !== connectSeq) return;
      var LK = parts[0];
      var tokenData = parts[1];
      var room = new LK.Room({ adaptiveStream: true, dynacast: true });
      activeRoom = room;

      room.on(LK.RoomEvent.TrackSubscribed, function (track, publication, participant) {
        attachRemoteTrack(root, LK, track, participant);
      });
      room.on(LK.RoomEvent.TrackUnsubscribed, function (track) {
        try { track.detach().forEach(function (el) { el.remove(); }); } catch (e) {}
      });
      room.on(LK.RoomEvent.LocalTrackPublished, function (publication) {
        if (publication && publication.track) attachLocalTrack(root, LK, publication.track);
      });
      room.on(LK.RoomEvent.ParticipantDisconnected, function (participant) {
        var el = document.getElementById(tileId(participant && participant.identity));
        if (el) el.remove();
      });
      room.on(LK.RoomEvent.Reconnecting, function () { setStatus(root, "در حال اتصال مجدد…", true); });
      room.on(LK.RoomEvent.Reconnected, function () { setStatus(root, "تماس برقرار است.", false); });
      room.on(LK.RoomEvent.Disconnected, function () {
        if (activeRoom === room) activeRoom = null;
      });

      await room.connect(tokenData.serverUrl, tokenData.participantToken, { autoSubscribe: true });
      if (seq !== connectSeq) {
        room.disconnect();
        return;
      }

      if (info.media === "audio") {
        await room.localParticipant.setMicrophoneEnabled(true);
      } else {
        await room.localParticipant.enableCameraAndMicrophone();
      }
      room.localParticipant.trackPublications.forEach(function (pub) {
        if (pub && pub.track) attachLocalTrack(root, LK, pub.track);
      });
      setStatus(root, "تماس برقرار است.", false);
    }).catch(function (err) {
      if (seq !== connectSeq) return;
      setStatus(root, err && err.message ? err.message : "اتصال برقرار نشد.", true);
      var permit = root.querySelector("[data-video-permit]");
      if (permit) {
        permit.hidden = false;
        var p = permit.querySelector("p");
        if (p) p.textContent = "دسترسی دوربین/میکروفون یا اتصال تماس برقرار نشد. دوباره تلاش کنید.";
      }
    });
  }

  function wireTileControls() {
    var root = document.querySelector("[data-video-call]");
    if (!root || root.getAttribute("data-livekit-wired") === "1") return;
    root.setAttribute("data-livekit-wired", "1");

    var hang = root.querySelector("[data-video-hangup]");
    if (hang) hang.addEventListener("click", function (ev) {
      ev.preventDefault();
      ev.stopImmediatePropagation();
      var roomKey = root.getAttribute("data-room") || "";
      if (roomKey) {
        jsonPost("/video-signal", { action: "send", room: roomKey, kind: "hangup", target_id: "", payload: null }).catch(function () {});
      }
      disconnectActive(true);
    }, true);

    root.querySelectorAll("[data-video-fs]").forEach(function (btn) {
      btn.addEventListener("click", function (ev) {
        ev.preventDefault();
        ev.stopImmediatePropagation();
        var stage = root.querySelector("[data-video-stage]") || root;
        if (document.fullscreenElement) document.exitFullscreen().catch(function () {});
        else if (stage.requestFullscreen) stage.requestFullscreen().catch(function () {});
      }, true);
    });

    var enhance = root.querySelector("[data-video-enhance]");
    if (enhance) enhance.addEventListener("click", function (ev) {
      ev.preventDefault();
      ev.stopImmediatePropagation();
      var stage = root.querySelector("[data-video-stage]");
      if (!stage) return;
      var on = !stage.classList.contains("is-enhanced");
      stage.classList.toggle("is-enhanced", on);
      enhance.setAttribute("aria-pressed", on ? "true" : "false");
    }, true);

    var permitBtn = root.querySelector("[data-video-permit-btn]");
    if (permitBtn) permitBtn.addEventListener("click", function (ev) {
      ev.preventDefault();
      ev.stopImmediatePropagation();
      var info = {
        room: root.getAttribute("data-room") || "",
        title: root.getAttribute("data-peer-name") || "تماس مانا",
        media: root.getAttribute("data-media") === "audio" ? "audio" : "video",
        group: root.getAttribute("data-group") === "1",
        canStart: root.getAttribute("data-can-start") === "1"
      };
      if (info.room) liveKitStart(info);
    }, true);
  }

  function installBridge() {
    if (!window.ManaVideoCall || typeof window.ManaVideoCall.start !== "function") return;
    if (!window.ManaVideoCall.legacyStart) window.ManaVideoCall.legacyStart = window.ManaVideoCall.start;
    if (!window.ManaVideoCall.legacyStop && typeof window.ManaVideoCall.stop === "function") {
      window.ManaVideoCall.legacyStop = window.ManaVideoCall.stop;
    }
    window.ManaVideoCall.start = liveKitStart;
    window.ManaVideoCall.stop = function () { disconnectActive(true); };
    wireTileControls();

    var root = document.querySelector("[data-video-call]");
    var bootRoom = root && root.getAttribute("data-room");
    if (bootRoom) {
      liveKitStart({
        room: bootRoom,
        title: root.getAttribute("data-peer-name") || "تماس مانا",
        media: root.getAttribute("data-media") === "audio" ? "audio" : "video",
        group: root.getAttribute("data-group") === "1",
        canStart: root.getAttribute("data-can-start") === "1",
        answer: /(?:^|[?&])answer=1(?:&|$)/.test(location.search || "")
      });
    }
  }

  legacyScript.onload = installBridge;
  legacyScript.onerror = function () {};
  (document.head || document.documentElement).appendChild(legacyScript);

  window.addEventListener("pagehide", function () { disconnectActive(false); });
})();
