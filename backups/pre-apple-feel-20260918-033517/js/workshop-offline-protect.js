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

    document.querySelectorAll(".audio-play-btn").forEach(function (btn) {
      btn.addEventListener("click", function () {
        var id = btn.getAttribute("data-audio-id");
        var audio = document.getElementById("audio-" + id);
        var status = document.getElementById("audio-status-" + id);
        var streamUrl = audioStreams[id];
        if (!audio || !streamUrl) return;
        btn.disabled = true;
        if (status) status.textContent = "در حال آماده‌سازی پخش...";
        fetch(streamUrl, { credentials: "same-origin", cache: "no-store" })
          .then(function (res) {
            if (!res.ok) throw new Error("stream");
            return res.blob();
          })
          .then(function (blob) {
            var blobUrl = URL.createObjectURL(blob);
            blobUrls.push(blobUrl);
            audio.src = blobUrl;
            audio.style.display = "block";
            btn.style.display = "none";
            if (status) status.textContent = "در حال پخش — فقط داخل پنل؛ دانلود و ضبط صفحه مجاز نیست.";
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
