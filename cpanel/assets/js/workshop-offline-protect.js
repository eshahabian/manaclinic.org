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

    document.addEventListener("keydown", function (e) {
      var key = (e.key || "").toLowerCase();
      if (key === "printscreen") {
        pauseAll();
        blockEvent(e);
        return;
      }
      if ((e.ctrlKey || e.metaKey) && (key === "s" || key === "p")) {
        blockEvent(e);
      }
    });

    document.querySelectorAll(".wm-video-box video, .wm-pdf-frame, .protected-audio").forEach(function (v) {
      v.addEventListener("contextmenu", blockEvent);
      v.addEventListener("dragstart", blockEvent);
    });

    document.querySelectorAll("video").forEach(function (video) {
      try { video.disablePictureInPicture = true; } catch (e) {}
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
