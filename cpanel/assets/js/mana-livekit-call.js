/**
 * Shared LiveKit call helpers for Mana Clinic.
 * Fixes: video kind detection, camera-without-mic failure, missing attach of existing tracks,
 * and adaptiveStream needing a visible <video> element.
 */
(function (global) {
  "use strict";

  function isVideoTrack(track) {
    if (!track) return false;
    var kind = track.kind;
    if (kind === "video") return true;
    try {
      if (global.LivekitClient && LivekitClient.Track && LivekitClient.Track.Kind) {
        return kind === LivekitClient.Track.Kind.Video;
      }
    } catch (e) {}
    return String(kind || "").toLowerCase() === "video";
  }

  function safePlay(el) {
    if (!el || !el.play) return;
    var p = el.play();
    if (p && p.catch) p.catch(function () {});
  }

  function attachTrack(track, container, opts) {
    opts = opts || {};
    if (!track || !container) return null;
    var el = track.attach();
    el.autoplay = true;
    el.playsInline = true;
    el.muted = !!opts.muted;
    el.setAttribute("playsinline", "");
    el.setAttribute("webkit-playsinline", "");
    if (opts.mirrored) el.style.transform = "scaleX(-1)";

    if (isVideoTrack(track)) {
      el.style.cssText =
        (el.style.cssText || "") +
        ";width:100%;height:100%;min-height:12rem;object-fit:cover;display:block;background:#0d1a16;";
      var old = container.querySelector("video");
      if (old && old !== el) {
        try { track.detach(old); } catch (e) {}
        old.remove();
      }
      if (opts.prepend) container.insertBefore(el, container.firstChild);
      else container.appendChild(el);
      if (opts.onVideo) opts.onVideo(el);
    } else {
      el.style.display = "none";
      (opts.audioRoot || document.body).appendChild(el);
    }
    safePlay(el);
    return el;
  }

  function eachPublication(participant, fn) {
    if (!participant) return;
    var pubs = participant.trackPublications || participant.tracks;
    if (!pubs || typeof pubs.forEach !== "function") return;
    pubs.forEach(function (pub) { fn(pub); });
  }

  function attachParticipantTracks(participant, getContainer, local) {
    eachPublication(participant, function (pub) {
      if (pub && pub.track) {
        attachTrack(pub.track, getContainer(participant, local), {
          muted: !!local && isVideoTrack(pub.track),
          mirrored: !!local && isVideoTrack(pub.track),
          prepend: true
        });
      }
    });
  }

  async function publishLocal(room, audioOnly, onStatus) {
    if (!room || !room.localParticipant) return { mic: false, cam: false };
    var mic = false;
    var cam = false;
    try {
      await room.localParticipant.setMicrophoneEnabled(true);
      mic = true;
    } catch (e) {
      if (onStatus) onStatus("اجازه میکروفون داده نشد.");
      throw e;
    }
    if (audioOnly) return { mic: mic, cam: false };
    try {
      await room.localParticipant.setCameraEnabled(true);
      cam = true;
    } catch (e) {
      if (onStatus) onStatus("صدا وصل شد؛ دوربین در دسترس نیست. یک‌بار روی «فعال‌سازی دوربین» بزنید.");
    }
    return { mic: mic, cam: cam };
  }

  function wireRoom(room, hooks) {
    hooks = hooks || {};
    room.on(LivekitClient.RoomEvent.TrackSubscribed, function (track, pub, participant) {
      if (hooks.onTrack) hooks.onTrack(track, pub, participant, false);
    });
    room.on(LivekitClient.RoomEvent.TrackUnsubscribed, function (track) {
      if (hooks.onTrackGone) hooks.onTrackGone(track);
      else {
        try { track.detach().forEach(function (el) { el.remove(); }); } catch (e) {}
      }
    });
    room.on(LivekitClient.RoomEvent.LocalTrackPublished, function (pub) {
      if (hooks.onLocalPub) hooks.onLocalPub(pub);
    });
    room.on(LivekitClient.RoomEvent.ParticipantConnected, function (p) {
      if (hooks.onParticipant) hooks.onParticipant(p);
      if (hooks.getContainer) attachParticipantTracks(p, hooks.getContainer, false);
    });
    room.on(LivekitClient.RoomEvent.ParticipantDisconnected, function (p) {
      if (hooks.onParticipantLeft) hooks.onParticipantLeft(p);
    });
    room.on(LivekitClient.RoomEvent.Reconnecting, function () {
      if (hooks.onStatus) hooks.onStatus("در حال اتصال مجدد…");
    });
    room.on(LivekitClient.RoomEvent.Reconnected, function () {
      if (hooks.onStatus) hooks.onStatus("تماس برقرار است.");
    });
    room.on(LivekitClient.RoomEvent.Disconnected, function () {
      if (hooks.onDisconnected) hooks.onDisconnected();
    });
  }

  async function connectRoom(opts) {
    opts = opts || {};
    if (!global.LivekitClient) throw new Error("کتابخانه تماس بارگذاری نشد.");
    var tokenUrl = opts.tokenUrl || "/livekit-token";
    var roomKey = opts.roomKey || "";
    var csrf = opts.csrf || "";
    var audioOnly = !!opts.audioOnly;

    var headers = {
      "Content-Type": "application/json",
      Accept: "application/json"
    };
    if (csrf) headers["X-CSRF-Token"] = csrf;

    var r = await fetch(tokenUrl, {
      method: "POST",
      credentials: "same-origin",
      headers: headers,
      body: JSON.stringify({ room: roomKey })
    });
    var d = await r.json();
    if (!r.ok || !d.ok) throw new Error((d && d.error) || "خطای اتصال");

    // adaptiveStream فقط وقتی المان ویدیو واقعاً دیده می‌شود مفید است؛
    // dynacast گاهی روی موبایل لایه ویدیو را صفر می‌کند — فعلاً خاموش.
    var room = new LivekitClient.Room({
      adaptiveStream: false,
      dynacast: false,
      videoCaptureDefaults: {
        resolution: LivekitClient.VideoPresets && LivekitClient.VideoPresets.h540
          ? LivekitClient.VideoPresets.h540.resolution
          : { width: 960, height: 540, frameRate: 24 }
      }
    });

    wireRoom(room, opts.hooks || {});
    await room.connect(d.serverUrl, d.participantToken, { autoSubscribe: true });

    // ترک‌های از قبل منتشرشده را هم بچسبان
    if (opts.hooks && opts.hooks.getContainer) {
      room.remoteParticipants.forEach(function (p) {
        attachParticipantTracks(p, opts.hooks.getContainer, false);
      });
    }

    var media = await publishLocal(room, audioOnly, opts.onStatus);
    if (opts.hooks && opts.hooks.onLocalPub) {
      room.localParticipant.trackPublications.forEach(function (pub) {
        opts.hooks.onLocalPub(pub);
      });
    }
    return { room: room, media: media, token: d };
  }

  global.ManaLiveKit = {
    isVideoTrack: isVideoTrack,
    attachTrack: attachTrack,
    attachParticipantTracks: attachParticipantTracks,
    publishLocal: publishLocal,
    connectRoom: connectRoom,
    safePlay: safePlay
  };
})(window);
