(function (global) {
  function readJson(raw) {
    try {
      return raw ? JSON.parse(raw) : null;
    } catch (e) {
      return null;
    }
  }

  function bindFormDraft(form, storageKey, options) {
    if (!form || !storageKey || !global.localStorage) return null;
    options = options || {};
    var fields = options.fields || [];
    var exclude = options.exclude || [];
    var statusEl = options.statusEl || form.querySelector("[data-form-draft-status]");
    var clearParams = options.clearParams || [];
    var timer = null;
    var restored = false;

    function params() {
      try {
        return new URLSearchParams(global.location.search);
      } catch (e) {
        return new URLSearchParams();
      }
    }

    function shouldClear() {
      var q = params();
      for (var i = 0; i < clearParams.length; i++) {
        if (q.has(clearParams[i])) return true;
      }
      return false;
    }

    function fieldEls() {
      if (fields.length) {
        return fields.map(function (id) { return form.querySelector("#" + id) || document.getElementById(id); }).filter(Boolean);
      }
      return Array.prototype.filter.call(form.elements, function (el) {
        if (!el || !el.name) return false;
        if (exclude.indexOf(el.name) !== -1 || exclude.indexOf(el.id) !== -1) return false;
        if (el.type === "file" || el.type === "hidden" && el.name === "_csrf") return false;
        if (el.type === "button" || el.type === "submit") return false;
        return true;
      });
    }

    function collect() {
      var data = {};
      fieldEls().forEach(function (el) {
        var key = el.id || el.name;
        if (!key) return;
        if (el.type === "checkbox" || el.type === "radio") {
          data[key] = el.checked ? el.value : "";
        } else {
          data[key] = el.value;
        }
      });
      return data;
    }

    function hasValues(data) {
      if (!data) return false;
      return Object.keys(data).some(function (k) {
        return String(data[k] || "").trim() !== "";
      });
    }

    function showStatus(text) {
      if (!statusEl) return;
      statusEl.hidden = !text;
      statusEl.textContent = text || "";
    }

    function save() {
      var data = collect();
      if (!hasValues(data)) {
        try { localStorage.removeItem(storageKey); } catch (e) {}
        showStatus("");
        return;
      }
      try {
        localStorage.setItem(storageKey, JSON.stringify({ t: Date.now(), data: data }));
        showStatus("پیش‌نویس روی همین دستگاه ذخیره شد؛ اگر صفحه بسته شود یا نت قطع شود، اطلاعات برمی‌گردد.");
      } catch (e) {}
    }

    function clear() {
      try { localStorage.removeItem(storageKey); } catch (e) {}
      showStatus("");
    }

    function restore() {
      if (shouldClear()) {
        clear();
        return false;
      }
      var packed = readJson(localStorage.getItem(storageKey));
      var data = packed && packed.data ? packed.data : packed;
      if (!hasValues(data)) return false;
      fieldEls().forEach(function (el) {
        var key = el.id || el.name;
        if (!key || data[key] == null) return;
        if (el.type === "checkbox" || el.type === "radio") {
          el.checked = !!data[key] && data[key] === el.value;
        } else {
          el.value = data[key];
        }
        el.dispatchEvent(new Event("change", { bubbles: true }));
      });
      restored = true;
      showStatus("اطلاعات ذخیره‌شده قبلی بازیابی شد.");
      if (typeof options.onRestore === "function") options.onRestore(data);
      return true;
    }

    form.addEventListener("input", function () {
      clearTimeout(timer);
      timer = setTimeout(save, 250);
    });
    form.addEventListener("change", function () {
      clearTimeout(timer);
      timer = setTimeout(save, 80);
    });
    form.addEventListener("submit", function () {
      form.dataset.draftPending = "1";
    });

    global.addEventListener("pagehide", save);
    global.addEventListener("beforeunload", save);

    restore();

    return {
      save: save,
      clear: clear,
      restored: function () { return restored; }
    };
  }

  global.bindFormDraft = bindFormDraft;
})(window);
