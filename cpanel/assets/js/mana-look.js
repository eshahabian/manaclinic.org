(function () {
  var KEY_THEME = "mana-theme";
  var KEY_LOOK = "mana-look";
  var html = document.documentElement;
  var openBtn = document.getElementById("mana-look-open");
  var screen = document.getElementById("mana-look-screen");
  if (!openBtn || !screen) return;

  var ACCENT_LABELS = {
    mana: "مانا",
    violet: "بنفش",
    blue: "آبی",
    pink: "صورتی",
    orange: "نارنجی",
  };
  var TEXT_LABELS = {
    default: "پیش‌فرض",
    medium: "متوسط",
    large: "بزرگ",
    xlarge: "خیلی بزرگ",
  };
  var UI_LABELS = {
    rounded: "گرد",
    soft: "نرم",
    crisp: "تیز",
  };

  var defaults = {
    theme: html.getAttribute("data-theme") === "dark" ? "dark" : "light",
    accent: "mana",
    text: "default",
    ui: "rounded",
  };

  function readStored() {
    var out = Object.assign({}, defaults);
    try {
      var t = localStorage.getItem(KEY_THEME);
      if (t === "dark" || t === "light") out.theme = t;
      var raw = localStorage.getItem(KEY_LOOK);
      if (raw) {
        var parsed = JSON.parse(raw);
        if (parsed && typeof parsed === "object") {
          if (parsed.theme === "dark" || parsed.theme === "light") out.theme = parsed.theme;
          if (ACCENT_LABELS[parsed.accent]) out.accent = parsed.accent;
          if (TEXT_LABELS[parsed.text]) out.text = parsed.text;
          if (UI_LABELS[parsed.ui]) out.ui = parsed.ui;
        }
      }
    } catch (err) {}
    return out;
  }

  function cloneLook(look) {
    return {
      theme: look.theme,
      accent: look.accent,
      text: look.text,
      ui: look.ui,
    };
  }

  var applied = readStored();
  var draft = cloneLook(applied);

  function applyLook(look, persist) {
    html.setAttribute("data-theme", look.theme === "dark" ? "dark" : "light");
    html.setAttribute("data-accent", look.accent || "mana");
    html.setAttribute("data-text", look.text || "default");
    html.setAttribute("data-ui", look.ui || "rounded");
    var meta = document.querySelector('meta[name="theme-color"]');
    if (meta) {
      meta.setAttribute("content", look.theme === "dark" ? "#101816" : "#1a5c4a");
    }
    var themeBtn = document.getElementById("theme-toggle");
    if (themeBtn) {
      themeBtn.setAttribute("aria-label", look.theme === "dark" ? "حالت روز" : "حالت شب");
      themeBtn.setAttribute("title", look.theme === "dark" ? "حالت روز" : "حالت شب");
    }
    if (persist) {
      try {
        localStorage.setItem(KEY_THEME, look.theme);
        localStorage.setItem(KEY_LOOK, JSON.stringify(look));
      } catch (err) {}
    }
  }

  function countDiff(a, b) {
    var n = 0;
    if (a.theme !== b.theme) n++;
    if (a.accent !== b.accent) n++;
    if (a.text !== b.text) n++;
    if (a.ui !== b.ui) n++;
    return n;
  }

  function faNum(n) {
    return String(n).replace(/\d/g, function (d) {
      return "۰۱۲۳۴۵۶۷۸۹"[d];
    });
  }

  function syncLabels() {
    screen.querySelectorAll("[data-look-accent-label]").forEach(function (el) {
      el.textContent = ACCENT_LABELS[draft.accent] || ACCENT_LABELS.mana;
    });
    screen.querySelectorAll("[data-look-text-label]").forEach(function (el) {
      el.textContent = TEXT_LABELS[draft.text] || TEXT_LABELS.default;
    });
    screen.querySelectorAll("[data-look-ui-label]").forEach(function (el) {
      el.textContent = UI_LABELS[draft.ui] || UI_LABELS.rounded;
    });
    screen.querySelectorAll("[data-look-summary]").forEach(function (el) {
      el.textContent =
        (ACCENT_LABELS[draft.accent] || "مانا") +
        " · " +
        (TEXT_LABELS[draft.text] || "پیش‌فرض") +
        " · " +
        (UI_LABELS[draft.ui] || "گرد");
    });
    var hint = draft.theme === "dark" ? "برای روز بزن" : "راحت‌تر برای چشم در شب";
    screen.querySelectorAll("[data-look-theme-hint]").forEach(function (el) {
      el.textContent = hint;
    });
    screen.querySelectorAll("[data-look-accent]").forEach(function (btn) {
      btn.classList.toggle("is-selected", btn.getAttribute("data-look-accent") === draft.accent);
    });
    screen.querySelectorAll("[data-look-text]").forEach(function (btn) {
      btn.classList.toggle("is-selected", btn.getAttribute("data-look-text") === draft.text);
    });
    screen.querySelectorAll("[data-look-ui]").forEach(function (btn) {
      btn.classList.toggle("is-selected", btn.getAttribute("data-look-ui") === draft.ui);
    });
    var changes = countDiff(applied, draft);
    var bar = screen.querySelector("[data-look-bar]");
    if (bar) {
      if (changes > 0) bar.removeAttribute("hidden");
      else bar.setAttribute("hidden", "");
    }
    screen.querySelectorAll("[data-look-change-count]").forEach(function (el) {
      el.textContent = changes === 1 ? "۱ تغییر" : faNum(changes) + " تغییر";
    });
  }

  function showView(name) {
    screen.querySelectorAll("[data-look-view]").forEach(function (view) {
      var on = view.getAttribute("data-look-view") === name;
      if (on) {
        view.removeAttribute("hidden");
        view.classList.add("is-active");
      } else {
        view.setAttribute("hidden", "");
        view.classList.remove("is-active");
      }
    });
  }

  function openScreen() {
    draft = cloneLook(applied);
    applyLook(draft, false);
    syncLabels();
    showView("hub");
    screen.removeAttribute("hidden");
    screen.setAttribute("aria-hidden", "false");
    document.body.classList.add("mana-look-open");
  }

  function closeScreen() {
    draft = cloneLook(applied);
    applyLook(applied, false);
    syncLabels();
    screen.setAttribute("hidden", "");
    screen.setAttribute("aria-hidden", "true");
    document.body.classList.remove("mana-look-open");
  }

  function previewDraft() {
    applyLook(draft, false);
    syncLabels();
  }

  openBtn.addEventListener("click", openScreen);
  screen.querySelectorAll("[data-look-close]").forEach(function (btn) {
    btn.addEventListener("click", closeScreen);
  });
  screen.querySelectorAll("[data-look-goto]").forEach(function (btn) {
    btn.addEventListener("click", function () {
      showView(btn.getAttribute("data-look-goto") || "hub");
    });
  });
  screen.querySelectorAll("[data-look-toggle-theme]").forEach(function (btn) {
    btn.addEventListener("click", function () {
      draft.theme = draft.theme === "dark" ? "light" : "dark";
      previewDraft();
    });
  });
  screen.querySelectorAll("[data-look-accent]").forEach(function (btn) {
    btn.addEventListener("click", function () {
      var v = btn.getAttribute("data-look-accent");
      if (!ACCENT_LABELS[v]) return;
      draft.accent = v;
      previewDraft();
    });
  });
  screen.querySelectorAll("[data-look-text]").forEach(function (btn) {
    btn.addEventListener("click", function () {
      var v = btn.getAttribute("data-look-text");
      if (!TEXT_LABELS[v]) return;
      draft.text = v;
      previewDraft();
    });
  });
  screen.querySelectorAll("[data-look-ui]").forEach(function (btn) {
    btn.addEventListener("click", function () {
      var v = btn.getAttribute("data-look-ui");
      if (!UI_LABELS[v]) return;
      draft.ui = v;
      previewDraft();
    });
  });
  var discardBtn = screen.querySelector("[data-look-discard]");
  if (discardBtn) {
    discardBtn.addEventListener("click", function () {
      draft = cloneLook(applied);
      previewDraft();
    });
  }
  var reviewBtn = screen.querySelector("[data-look-review]");
  if (reviewBtn) {
    reviewBtn.addEventListener("click", function () {
      applied = cloneLook(draft);
      applyLook(applied, true);
      syncLabels();
      closeScreen();
    });
  }

  document.addEventListener("keydown", function (e) {
    if (e.key === "Escape" && !screen.hasAttribute("hidden")) {
      closeScreen();
    }
  });

  // Keep header theme toggle in sync (applies immediately + saves)
  var themeBtn = document.getElementById("theme-toggle");
  if (themeBtn) {
    themeBtn.addEventListener(
      "click",
      function () {
        window.setTimeout(function () {
          var now = html.getAttribute("data-theme") === "dark" ? "dark" : "light";
          applied.theme = now;
          draft.theme = now;
          try {
            var look = readStored();
            look.theme = now;
            localStorage.setItem(KEY_LOOK, JSON.stringify(look));
          } catch (err) {}
          syncLabels();
        }, 0);
      },
      true
    );
  }

  function paintAvatarFace(url, initial) {
    var faces = document.querySelectorAll("[data-look-avatar-face]");
    faces.forEach(function (face) {
      face.innerHTML = "";
      if (url) {
        var img = document.createElement("img");
        img.src = url;
        img.alt = "";
        face.appendChild(img);
      } else {
        face.textContent = initial || "م";
      }
    });
    var header = openBtn;
    header.innerHTML = "";
    if (url) {
      var himg = document.createElement("img");
      himg.src = url;
      himg.alt = "";
      himg.width = 36;
      himg.height = 36;
      header.appendChild(himg);
    } else {
      var span = document.createElement("span");
      span.className = "header-avatar-letter";
      span.setAttribute("aria-hidden", "true");
      span.textContent = initial || "م";
      header.appendChild(span);
    }
    header.setAttribute("data-avatar-url", url || "");
    var removeBtn = screen.querySelector("[data-look-avatar-remove]");
    if (removeBtn) {
      if (url) removeBtn.removeAttribute("hidden");
      else removeBtn.setAttribute("hidden", "");
    }
  }

  paintAvatarFace(openBtn.getAttribute("data-avatar-url") || "", openBtn.getAttribute("data-avatar-initial") || "م");

  var canUpload = openBtn.getAttribute("data-can-upload") === "1";
  var fileInput = screen.querySelector("[data-look-avatar-file]");
  var pickBtn = screen.querySelector("[data-look-avatar-pick]");
  var removeBtn = screen.querySelector("[data-look-avatar-remove]");
  var postUrl = openBtn.getAttribute("data-avatar-post") || "";

  if (!canUpload) {
    if (pickBtn) pickBtn.setAttribute("disabled", "");
    screen.querySelectorAll(".mana-look-avatar-cam").forEach(function (el) {
      el.style.display = "none";
    });
  }

  if (canUpload && pickBtn && fileInput && postUrl) {
    pickBtn.addEventListener("click", function () {
      fileInput.click();
    });
    fileInput.addEventListener("change", function () {
      var file = fileInput.files && fileInput.files[0];
      if (!file) return;
      var fd = new FormData();
      fd.append("avatar", file);
      fd.append("action", "upload");
      pickBtn.disabled = true;
      fetch(postUrl, {
        method: "POST",
        body: fd,
        headers: { Accept: "application/json", "X-Requested-With": "XMLHttpRequest" },
        credentials: "same-origin",
      })
        .then(function (r) {
          return r.json().then(function (data) {
            if (!r.ok || !data.ok) throw new Error((data && data.error) || "آپلود ناموفق بود");
            return data;
          });
        })
        .then(function (data) {
          paintAvatarFace(data.avatar_url || "", data.initial || openBtn.getAttribute("data-avatar-initial") || "م");
        })
        .catch(function (err) {
          window.alert(err.message || "آپلود ناموفق بود");
        })
        .finally(function () {
          pickBtn.disabled = false;
          fileInput.value = "";
        });
    });
  }

  if (canUpload && removeBtn && postUrl) {
    removeBtn.addEventListener("click", function () {
      if (!window.confirm("عکس پروفایل حذف شود؟")) return;
      var fd = new FormData();
      fd.append("action", "remove");
      fetch(postUrl, {
        method: "POST",
        body: fd,
        headers: { Accept: "application/json", "X-Requested-With": "XMLHttpRequest" },
        credentials: "same-origin",
      })
        .then(function (r) {
          return r.json().then(function (data) {
            if (!r.ok || !data.ok) throw new Error((data && data.error) || "حذف ناموفق بود");
            return data;
          });
        })
        .then(function (data) {
          paintAvatarFace("", data.initial || openBtn.getAttribute("data-avatar-initial") || "م");
        })
        .catch(function (err) {
          window.alert(err.message || "حذف ناموفق بود");
        });
    });
  }

  applyLook(applied, false);
  syncLabels();
  window.ManaLook = {
    apply: function (look) {
      applied = Object.assign(cloneLook(defaults), look || {});
      draft = cloneLook(applied);
      applyLook(applied, true);
      syncLabels();
    },
    get: function () {
      return cloneLook(applied);
    },
  };
})();
