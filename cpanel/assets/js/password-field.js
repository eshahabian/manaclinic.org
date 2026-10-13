(function () {
  function hasNonEnglish(value) {
    return /[^\x00-\x7F]/.test(String(value || ""));
  }

  function setHidden(el, hidden) {
    if (!el) return;
    el.hidden = !!hidden;
  }

  function bindField(root) {
    var input = root.querySelector("[data-password-input]");
    var toggle = root.querySelector("[data-password-toggle]");
    var langWarn = root.querySelector("[data-password-lang]");
    var matchWarn = root.querySelector("[data-password-match]");
    if (!input) return;

    function pairInput() {
      var pairId = input.getAttribute("data-password-pair");
      return pairId ? document.getElementById(pairId) : null;
    }

    function refresh() {
      var value = input.value || "";
      setHidden(langWarn, value === "" || !hasNonEnglish(value));
      if (matchWarn) {
        var other = pairInput();
        var otherVal = other ? other.value : "";
        setHidden(matchWarn, value === "" || otherVal === "" || value === otherVal);
      }
    }

    if (toggle) {
      toggle.addEventListener("click", function () {
        var show = input.type === "password";
        input.type = show ? "text" : "password";
        toggle.classList.toggle("is-on", show);
        toggle.setAttribute("aria-label", show ? "پنهان کردن رمز" : "نمایش رمز");
        toggle.setAttribute("title", show ? "پنهان کردن رمز" : "نمایش رمز");
      });
    }

    input.addEventListener("input", refresh);
    input.addEventListener("blur", refresh);
    var other = pairInput();
    if (other) other.addEventListener("input", refresh);
    refresh();
  }

  function pickChar(set) {
    var buf = new Uint32Array(1);
    if (window.crypto && crypto.getRandomValues) {
      crypto.getRandomValues(buf);
    } else {
      buf[0] = Math.floor(Math.random() * 0xffffffff);
    }
    return set.charAt(buf[0] % set.length);
  }

  function suggestClinicPassword() {
    var lower = "abcdefghijkmnopqrstuvwxyz";
    var upper = "ABCDEFGHJKLMNPQRSTUVWXYZ";
    var digits = "23456789";
    var chars = [pickChar(upper), pickChar(digits)];
    while (chars.length < 7) chars.push(pickChar(lower));
    for (var i = chars.length - 1; i > 0; i--) {
      var buf = new Uint32Array(1);
      if (window.crypto && crypto.getRandomValues) crypto.getRandomValues(buf);
      else buf[0] = Math.floor(Math.random() * 0xffffffff);
      var j = buf[0] % (i + 1);
      var tmp = chars[i];
      chars[i] = chars[j];
      chars[j] = tmp;
    }
    return chars.join("");
  }

  function revealPassword(input) {
    if (!input) return;
    input.type = "text";
    var box = input.parentElement;
    var toggle = box ? box.querySelector("[data-password-toggle]") : null;
    if (toggle) {
      toggle.classList.add("is-on");
      toggle.setAttribute("aria-label", "پنهان کردن رمز");
      toggle.setAttribute("title", "پنهان کردن رمز");
    }
  }

  function applySuggestedPassword(input, confirm) {
    if (!input) return;
    var value = suggestClinicPassword();
    input.value = value;
    revealPassword(input);
    input.dispatchEvent(new Event("input", { bubbles: true }));
    if (confirm) {
      confirm.value = value;
      revealPassword(confirm);
      confirm.dispatchEvent(new Event("input", { bubbles: true }));
    }
  }

  function bindSuggest(btn) {
    btn.addEventListener("click", function () {
      var root = btn.closest("[data-password-field]");
      var input = root ? root.querySelector("[data-password-input]") : null;
      if (!input) {
        var targetId = btn.getAttribute("data-password-target") || "";
        input = targetId ? document.getElementById(targetId) : null;
      }
      if (!input) return;
      var confirm = null;
      var confirmId = btn.getAttribute("data-password-confirm-target") || "";
      if (confirmId) confirm = document.getElementById(confirmId);
      if (!confirm && input.id) {
        confirm = document.querySelector('[data-password-pair="' + input.id + '"]');
      }
      applySuggestedPassword(input, confirm);
    });
  }

  function init() {
    document.querySelectorAll("[data-password-field]").forEach(bindField);
    document.querySelectorAll("[data-password-suggest]").forEach(bindSuggest);
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }
})();
