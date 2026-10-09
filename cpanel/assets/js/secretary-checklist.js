(function () {
  "use strict";
  if (window.__rxChecklist) return;
  window.__rxChecklist = true;

  function faDigits(n) {
    return String(n).replace(/\d/g, function (d) { return "۰۱۲۳۴۵۶۷۸۹"[d]; });
  }

  function showError(sheet, message) {
    var el = sheet.querySelector("[data-rx-error]");
    if (!el) return;
    el.hidden = false;
    el.textContent = message || "ذخیره نشد.";
  }

  function clearError(sheet) {
    var el = sheet.querySelector("[data-rx-error]");
    if (!el) return;
    el.hidden = true;
    el.textContent = "";
  }

  function paint(sheet, done, required, skipped) {
    var bar = sheet.querySelector("[data-rx-progress]");
    var summary = sheet.querySelector("[data-rx-summary]");
    var doneNum = Number(done);
    var requiredNum = Number(required);
    var skippedNum = Number(skipped);
    if (!Number.isFinite(doneNum)) doneNum = 0;
    if (!Number.isFinite(requiredNum)) requiredNum = 0;
    if (!Number.isFinite(skippedNum)) skippedNum = 0;
    if (bar) {
      bar.max = requiredNum > 0 ? requiredNum : 1;
      bar.value = doneNum;
    }
    if (summary) {
      var text = faDigits(doneNum) + " از " + faDigits(requiredNum) + " کار انجام شده";
      if (skippedNum) text += " · " + faDigits(skippedNum) + " مورد امروز نیاز نیست";
      summary.textContent = text;
    }
  }

  function lockItem(item) {
    item.querySelectorAll("input[type=checkbox]").forEach(function (box) {
      box.setAttribute("data-rx-lock", box.disabled ? "1" : "0");
      box.disabled = true;
    });
  }

  function unlockItem(item) {
    item.querySelectorAll("input[type=checkbox]").forEach(function (box) {
      if (!box.hasAttribute("data-rx-lock")) return;
      box.disabled = box.getAttribute("data-rx-lock") === "1";
      box.removeAttribute("data-rx-lock");
    });
  }

  function applyState(item, sheet, status, timeText) {
    var live = sheet.getAttribute("data-rx-live") === "1";
    var on = status === "done";
    var skip = status === "skip";
    var doneBox = item.querySelector("[data-rx-done]");
    var skipBox = item.querySelector("[data-rx-skip]");
    var time = item.querySelector("[data-rx-time]");
    item.querySelectorAll("[data-rx-lock]").forEach(function (box) {
      box.removeAttribute("data-rx-lock");
    });
    if (doneBox) {
      doneBox.checked = on;
      doneBox.disabled = !live || skip;
      doneBox.setAttribute("data-rx-was", on ? "1" : "0");
    }
    if (skipBox) {
      skipBox.checked = skip;
      skipBox.disabled = !live;
      skipBox.setAttribute("data-rx-was", skip ? "1" : "0");
    }
    item.classList.toggle("is-done", on);
    item.classList.toggle("is-skip", skip);
    if (time) time.textContent = on && timeText ? timeText : "";
  }

  function restoreItem(item) {
    unlockItem(item);
    item.querySelectorAll("input[type=checkbox]").forEach(function (box) {
      box.checked = box.getAttribute("data-rx-was") === "1";
    });
  }

  document.addEventListener("change", function (e) {
    var input = e.target;
    if (!input || input.type !== "checkbox") return;
    var item = input.closest("[data-rx-item]");
    var sheet = input.closest("[data-rx-sheet]");
    if (!item || !sheet) return;
    var previous = input.getAttribute("data-rx-was") === "1";
    if (sheet.getAttribute("data-rx-live") !== "1") {
      input.checked = previous;
      return;
    }
    var url = sheet.getAttribute("data-rx-url") || "";
    var csrfEl = sheet.querySelector("[data-rx-csrf]");
    if (item.getAttribute("data-busy") === "1") {
      input.checked = previous;
      return;
    }
    if (!url || !csrfEl || !csrfEl.value) {
      input.checked = previous;
      showError(sheet, "ذخیره ممکن نیست. صفحه را تازه کنید و دوباره تلاش کنید.");
      return;
    }
    var status = input.getAttribute("data-rx-skip") === "1"
      ? (input.checked ? "skip" : "pending")
      : (input.checked ? "done" : "pending");
    var data = new FormData();
    data.set("_csrf", csrfEl.value);
    data.set("task_date", item.getAttribute("data-rx-date") || "");
    data.set("task_key", item.getAttribute("data-rx-key") || "");
    data.set("status", status);
    item.setAttribute("data-busy", "1");
    lockItem(item);
    clearError(sheet);
    fetch(url, {
      method: "POST",
      body: data,
      credentials: "same-origin",
      headers: {
        "X-Requested-With": "XMLHttpRequest",
        "Accept": "application/json",
        "X-CSRF-Token": csrfEl.value
      }
    }).then(function (r) {
      return r.text().then(function (text) {
        var body = null;
        try { body = text ? JSON.parse(text) : null; } catch (err) { body = null; }
        if (!r.ok || !body || body.ok !== true) {
          var message = body && (body.error || body.message)
            ? String(body.error || body.message)
            : "پاسخ سرور قابل خواندن نیست. صفحه را تازه کنید و دوباره تلاش کنید.";
          throw new Error(message);
        }
        return body;
      });
    }).then(function (res) {
      applyState(item, sheet, res.status, res.time || "");
      paint(sheet, res.done_count, res.required, res.skipped_count);
      clearError(sheet);
    }).catch(function (err) {
      restoreItem(item);
      showError(sheet, err && err.message ? err.message : "ذخیره نشد.");
    }).then(function () {
      item.removeAttribute("data-busy");
    });
  });

  window.manaInitSecretaryChecklist = function () {};
})();
