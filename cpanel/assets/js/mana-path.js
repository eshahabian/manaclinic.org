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

  var shell = document.querySelector(".mpath-shell");
  var rail = document.getElementById("mpath-rail");
  function setNav(open) {
    if (!shell) return;
    shell.classList.toggle("is-nav", open);
    document.querySelectorAll("[data-mpath-menu]").forEach(function (b) {
      if (b.getAttribute("aria-expanded") !== null) b.setAttribute("aria-expanded", open ? "true" : "false");
      if (b.classList.contains("mpath-rail-mask")) b.hidden = !open;
    });
  }
  document.querySelectorAll("[data-mpath-menu]").forEach(function (btn) {
    btn.addEventListener("click", function () {
      setNav(!(shell && shell.classList.contains("is-nav")));
    });
  });
  if (rail) {
    rail.querySelectorAll("a").forEach(function (a) {
      a.addEventListener("click", function () { setNav(false); });
    });
  }

  document.querySelectorAll("[data-mpath-mood] [data-mood]").forEach(function (btn) {
    btn.addEventListener("mouseenter", function () {
      if (shell) shell.setAttribute("data-mood", btn.getAttribute("data-mood") || "0");
      document.querySelectorAll(".mr-stage").forEach(function (st) {
        st.setAttribute("data-mood", btn.getAttribute("data-mood") || "0");
      });
    });
  });

  document.querySelectorAll(".mr-gender-card input").forEach(function (input) {
    function sync() {
      document.querySelectorAll(".mr-gender-card").forEach(function (card) {
        card.classList.toggle("is-on", card.querySelector("input") && card.querySelector("input").checked);
      });
    }
    input.addEventListener("change", sync);
    sync();
  });

  document.querySelectorAll(".mpath-task.is-done").forEach(function (el) {
    el.classList.add("is-pop");
  });

  (function () {
    var box = document.querySelector("[data-mpath-world]");
    var room = document.querySelector(".mr-room");
    var props = document.querySelector(".mr-props");
    if (!box || !room) return;
    var btns = Array.prototype.slice.call(box.querySelectorAll("[data-prop]"));
    var key = "mpath-world-toggles";
    var fa = "۰۱۲۳۴۵۶۷۸۹";
    function toFa(n) {
      return String(n).replace(/\d/g, function (d) { return fa[d]; });
    }
    function applySaved() {
      try {
        var saved = JSON.parse(sessionStorage.getItem(key) || "{}");
        btns.forEach(function (btn) {
          var id = btn.getAttribute("data-prop");
          if (Object.prototype.hasOwnProperty.call(saved, id)) {
            btn.classList.toggle("is-on", !!saved[id]);
            btn.classList.toggle("is-off", !saved[id]);
            btn.setAttribute("aria-pressed", saved[id] ? "true" : "false");
          }
        });
      } catch (e) {}
    }
    function sync() {
      var onMap = {};
      var onCount = 0;
      btns.forEach(function (btn) {
        var id = btn.getAttribute("data-prop");
        var on = btn.classList.contains("is-on");
        onMap[id] = on;
        if (on) onCount += 1;
        document.querySelectorAll('.mr-prop[data-prop="' + id + '"]').forEach(function (img) {
          img.classList.toggle("is-on", on);
        });
      });
      var allOn = onCount === btns.length;
      if (props) props.hidden = allOn;
      var full = room.getAttribute("data-room-full");
      var base = room.getAttribute("data-room-base");
      if (allOn && full) room.src = full;
      else if (base) room.src = base;
      var bar = document.querySelector("[data-mpath-world-bar]");
      if (bar) bar.style.width = Math.round(100 * onCount / Math.max(1, btns.length)) + "%";
      var count = document.querySelector("[data-mpath-world-count]");
      if (count) count.textContent = toFa(onCount) + " / " + toFa(btns.length) + " آیتم باز شده";
      try { sessionStorage.setItem(key, JSON.stringify(onMap)); } catch (e) {}
    }
    applySaved();
    sync();
    btns.forEach(function (btn) {
      btn.addEventListener("click", function () {
        var on = !btn.classList.contains("is-on");
        btn.classList.toggle("is-on", on);
        btn.classList.toggle("is-off", !on);
        btn.setAttribute("aria-pressed", on ? "true" : "false");
        sync();
      });
    });
  })();
})();