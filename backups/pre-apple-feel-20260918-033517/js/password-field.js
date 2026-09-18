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

  function init() {
    document.querySelectorAll("[data-password-field]").forEach(bindField);
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }
})();
