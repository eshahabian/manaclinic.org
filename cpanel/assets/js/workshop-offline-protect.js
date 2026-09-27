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

    function speakOver(audio) {
      var label = window.workshopOfflineWatermark || "";
      if (!label || !window.speechSynthesis || !audio) return;
      var prev = audio.volume;
      var utter = new SpeechSynthesisUtterance("این نسخه برای " + label + " است");
      utter.lang = "fa-IR";
      utter.rate = 0.92;
      audio.volume = 0.28;
      utter.onend = function () {
        try { audio.volume = prev || 1; } catch (e) {}
      };
      try {
        window.speechSynthesis.cancel();
        window.speechSynthesis.speak(utter);
      } catch (e2) {
        try { audio.volume = prev || 1; } catch (e3) {}
      }
    }

    function bindSpokenMark(audio) {
      if (!audio || audio.getAttribute("data-wm-bound") === "1") return;
      audio.setAttribute("data-wm-bound", "1");
      var started = false;
      var mid = false;
      var last = 0;
      function say() {
        last = audio.currentTime || last;
        speakOver(audio);
      }
      audio.addEventListener("playing", function () {
        if (started) return;
        started = true;
        setTimeout(function () {
          if (!audio.paused) say();
        }, 7000);
      });
      audio.addEventListener("timeupdate", function () {
        var now = audio.currentTime || 0;
        var dur = audio.duration;
        if (dur && !mid && now > dur / 2 && now > 12) {
          mid = true;
          say();
          return;
        }
        if (now - last > 180 && now > 20) say();
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
        speakOver(audio);
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
            if (status) status.textContent = "در حال پخش. نام شما وسط فایل گفته می‌شود.";
            bindSpokenMark(audio);
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
