(function () {
  document.querySelectorAll("[data-mpath-breathe]").forEach(function (box) {
    var btn = box.querySelector("[data-breathe-start]");
    var label = box.querySelector(".mpath-breathe-label");
    if (!btn) return;
    btn.addEventListener("click", function () {
      box.classList.add("is-run");
      btn.disabled = true;
      var phases = ["دم", "نگه دار", "بازدم", "آرام"];
      var i = 0;
      var started = Date.now();
      function tick() {
        var elapsed = Date.now() - started;
        if (label) {
          var min = Math.floor(elapsed / 60000);
          var sec = Math.floor((elapsed / 1000) % 60);
          label.textContent = phases[i % phases.length] + " · " + min + ":" + String(sec).padStart(2, "0");
        }
        i += 1;
        if (elapsed < 4 * 60 * 1000) {
          window.setTimeout(tick, 4000);
        } else if (label) {
          label.textContent = "چهار دقیقه تمام شد. اگر خواستی ثبتش کن.";
          btn.disabled = false;
          btn.textContent = "دوباره";
          box.classList.remove("is-run");
        }
      }
      tick();
    });
  });
})();
