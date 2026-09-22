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

  var openSheet = null;
  function closeSheets() {
    document.querySelectorAll("[data-mpath-sheet-panel]").forEach(function (el) {
      el.hidden = true;
    });
    document.querySelectorAll(".mpath-tab-btn.is-on").forEach(function (b) {
      b.classList.remove("is-on");
    });
    document.body.classList.remove("mpath-sheet-lock");
    openSheet = null;
  }
  function openNamed(name, trigger) {
    var panel = document.querySelector('[data-mpath-sheet-panel="' + name + '"]');
    if (!panel) return;
    closeSheets();
    panel.hidden = false;
    if (trigger) trigger.classList.add("is-on");
    document.body.classList.add("mpath-sheet-lock");
    openSheet = panel;
  }
  document.querySelectorAll("[data-mpath-sheet]").forEach(function (btn) {
    btn.addEventListener("click", function () {
      openNamed(btn.getAttribute("data-mpath-sheet") || "", btn);
    });
  });
  document.querySelectorAll("[data-mpath-sheet-close]").forEach(function (btn) {
    btn.addEventListener("click", closeSheets);
  });
  document.addEventListener("keydown", function (e) {
    if (e.key === "Escape" && openSheet) closeSheets();
  });
})();
