(function () {
  function pauseAll() {
    document.querySelectorAll("[data-offline-protect] video, [data-offline-protect] audio, .offline-course-page video, .offline-course-page audio").forEach(function (el) {
      try { el.pause(); } catch (e) {}
    });
  }

  function blockEvent(e) {
    e.preventDefault();
    e.stopPropagation();
    return false;
  }

  window.workshopOfflineProtect = function () {
    var audioStreams = window.workshopOfflineAudioStreams || {};
    var blobUrls = [];

    function revokeAllBlobs() {
      blobUrls.forEach(function (u) {
        try { URL.revokeObjectURL(u); } catch (e) {}
      });
      blobUrls = [];
    }
    window.addEventListener("pagehide", revokeAllBlobs);

    document.addEventListener("visibilitychange", function () {
      if (document.hidden) pauseAll();
    });
    window.addEventListener("blur", pauseAll);
    window.addEventListener("pagehide", pauseAll);
    window.addEventListener("freeze", pauseAll);

    // اگر تب در حالت capture/share باشد، پخش را متوقف کن (محدودیت مرورگر؛ مانع کامل نیست)
    try {
      if (navigator.mediaDevices && typeof navigator.mediaDevices.getDisplayMedia === "function") {
        var originalGetDisplayMedia = navigator.mediaDevices.getDisplayMedia.bind(navigator.mediaDevices);
        navigator.mediaDevices.getDisplayMedia = function () {
          pauseAll();
          return originalGetDisplayMedia.apply(navigator.mediaDevices, arguments).then(function (stream) {
            pauseAll();
            stream.getTracks().forEach(function (track) {
              track.addEventListener("ended", pauseAll);
            });
            return stream;
          });
        };
      }
    } catch (e) {}

    document.addEventListener("keydown", function (e) {
      var key = (e.key || "").toLowerCase();
      if (key === "printscreen") {
        pauseAll();
        blockEvent(e);
        return;
      }
      if ((e.ctrlKey || e.metaKey) && (key === "s" || key === "p" || key === "u")) {
        blockEvent(e);
      }
    });

    document.addEventListener("contextmenu", function (e) {
      if (e.target && e.target.closest && e.target.closest("[data-offline-protect], .offline-course-page video, .offline-course-page audio, .wm-pdf-frame")) {
        blockEvent(e);
      }
    });

    document.querySelectorAll(".wm-video-box video, .wm-pdf-frame, .protected-audio").forEach(function (v) {
      v.addEventListener("contextmenu", blockEvent);
      v.addEventListener("dragstart", blockEvent);
    });

    document.querySelectorAll("video").forEach(function (video) {
      try { video.disablePictureInPicture = true; } catch (e) {}
      try {
        if (video.hasAttribute("controlsList")) {
          var list = (video.getAttribute("controlsList") || "") + " nodownload noplaybackrate noremoteplayback";
          video.setAttribute("controlsList", list.trim());
        }
      } catch (e2) {}
      video.addEventListener("enterpictureinpicture", function (e) {
        try { e.preventDefault(); } catch (err) {}
        try { document.exitPictureInPicture(); } catch (err2) {}
        pauseAll();
      });
    });

    function streamEntry(id) {
      var entry = audioStreams[id];
      if (!entry) return null;
      if (typeof entry === "string") return { url: entry, mask: "", mime: "audio/mpeg" };
      return { url: entry.url || "", mask: entry.mask || "", mime: entry.mime || "audio/mpeg" };
    }

    function unmaskBuffer(buffer, mask) {
      var bytes = new Uint8Array(buffer);
      if (!mask) return bytes;
      var key = [];
      for (var i = 0; i < mask.length; i++) key.push(mask.charCodeAt(i) & 255);
      if (!key.length) return bytes;
      for (var j = 0; j < bytes.length; j++) bytes[j] ^= key[j % key.length];
      return bytes;
    }

    function markTimes(duration, id) {
      var dur = duration;
      if (!dur || !isFinite(dur) || dur < 3) return [];
      var h = 0;
      var key = String(id || "");
      var i;
      for (i = 0; i < key.length; i++) h = (h * 33 + key.charCodeAt(i)) >>> 0;
      var a = 0.14 + (h % 16) / 100;
      var b = 0.44 + ((h >>> 4) % 14) / 100;
      var c = 0.74 + ((h >>> 8) % 12) / 100;
      var t1 = Math.max(4, dur * a);
      var t2 = Math.max(t1 + Math.min(20, dur * 0.12), dur * b);
      var t3 = Math.min(dur - 1.5, Math.max(t2 + Math.min(20, dur * 0.12), dur * c));
      if (dur < 20) {
        return [dur * 0.22, dur * 0.5, dur * 0.78];
      }
      return [t1, t2, t3];
    }

    function sayThenResume(audio) {
      var label = window.workshopOfflineWatermark || "";
      if (!audio) return;
      audio.pause();
      var resumed = false;
      function resume() {
        if (resumed) return;
        resumed = true;
        audio.play().catch(function () {});
      }
      if (!label || !window.speechSynthesis) {
        resume();
        return;
      }
      var utter = new SpeechSynthesisUtterance("این نسخه برای " + label + " است");
      utter.lang = "fa-IR";
      utter.rate = 0.92;
      utter.onend = resume;
      utter.onerror = resume;
      setTimeout(resume, 5000);
      try {
        window.speechSynthesis.cancel();
        window.speechSynthesis.speak(utter);
      } catch (e) {
        resume();
      }
    }

    function bindSpokenMark(audio, id) {
      if (!audio || audio.getAttribute("data-wm-bound") === "1") return;
      audio.setAttribute("data-wm-bound", "1");
      var times = null;
      var done = [false, false, false];
      var busy = false;
      audio.addEventListener("timeupdate", function () {
        if (busy || audio.paused) return;
        if (!times) {
          if (!audio.duration || !isFinite(audio.duration)) return;
          times = markTimes(audio.duration, id);
        }
        var now = audio.currentTime || 0;
        var i;
        for (i = 0; i < times.length; i++) {
          if (!done[i] && now >= times[i]) {
            done[i] = true;
            busy = true;
            sayThenResume(audio);
            setTimeout(function () { busy = false; }, 5200);
            break;
          }
        }
      });
    }

    document.addEventListener("click", function (e) {
      var pdfBtn = e.target && e.target.closest ? e.target.closest(".js-pdf-show") : null;
      if (!pdfBtn) return;
      var box = pdfBtn.closest(".wm-pdf-box");
      var frame = box ? box.querySelector(".wm-pdf-frame") : null;
      var iframe = frame ? frame.querySelector("iframe") : null;
      if (!frame || !iframe) return;
      if (!iframe.getAttribute("src")) iframe.setAttribute("src", pdfBtn.getAttribute("data-src") || "");
      frame.hidden = false;
      pdfBtn.disabled = true;
    });

    document.querySelectorAll(".audio-play-btn").forEach(function (btn) {
      btn.addEventListener("click", function () {
        var id = btn.getAttribute("data-audio-id");
        var audio = document.getElementById("audio-" + id);
        var status = document.getElementById("audio-status-" + id);
        var entry = streamEntry(id);
        if (!audio || !entry || !entry.url) return;
        btn.disabled = true;
        if (status) status.textContent = "در حال آماده‌سازی پخش...";
        fetch(entry.url, {
          credentials: "same-origin",
          cache: "no-store",
          headers: { "X-Mana-Player": "1" }
        })
          .then(function (res) {
            if (!res.ok) throw new Error("stream");
            return res.arrayBuffer();
          })
          .then(function (buffer) {
            var bytes = unmaskBuffer(buffer, entry.mask);
            var blob = new Blob([bytes], { type: entry.mime || "audio/mpeg" });
            var blobUrl = URL.createObjectURL(blob);
            blobUrls.push(blobUrl);
            audio.src = blobUrl;
            audio.style.display = "block";
            btn.style.display = "none";
            if (status) status.textContent = "در حال پخش. سه بار صدا قطع می‌شود و نام شما گفته می‌شود.";
            bindSpokenMark(audio, id);
            return audio.play();
          })
          .catch(function () {
            btn.disabled = false;
            if (status) status.textContent = "خطا در پخش. صفحه را رفرش کنید.";
          });
      });
    });
  };
})();
