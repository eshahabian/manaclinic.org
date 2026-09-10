(function () {
  var card = document.querySelector("[data-auth-card]");
  var cfg = window.ManaAuthGate || {};
  var moving = false;

  function later(fn) {
    setTimeout(fn, 160);
  }

  function setInert(el, on) {
    if (!el) return;
    if (on) el.setAttribute("inert", "");
    else el.removeAttribute("inert");
    el.inert = on;
  }

  function switchMode(next) {
    if (!card || (next !== "login" && next !== "register") || moving) return;
    if (card.getAttribute("data-mode") === next) return;
    moving = true;
    var other = next === "login" ? "register" : "login";
    var panes = {
      login: card.querySelector('[data-auth-pane="login"]'),
      register: card.querySelector('[data-auth-pane="register"]')
    };
    var pages = {
      login: card.querySelector('[data-band="login"]'),
      register: card.querySelector('[data-band="register"]')
    };
    card.setAttribute("data-mode", next);
    later(function () {
      if (panes[next]) panes[next].classList.add("is-on");
      if (panes[other]) panes[other].classList.remove("is-on");
      if (pages[next]) pages[next].classList.add("is-on");
      if (pages[other]) pages[other].classList.remove("is-on");
      setInert(panes[other], true);
      setInert(panes[next], false);
      var url = next === "login" ? card.getAttribute("data-login-url") : card.getAttribute("data-register-url");
      if (url && window.history && window.history.pushState) {
        window.history.pushState({ auth: next }, "", url);
        document.title = next === "login" ? "ورود | مانا کلینیک" : "ثبت‌نام | مانا کلینیک";
      }
      moving = false;
    });
  }

  if (card) {
    card.addEventListener("click", function (ev) {
      var link = ev.target.closest("[data-auth-switch]");
      if (!link) return;
      ev.preventDefault();
      switchMode(link.getAttribute("data-auth-switch") || "login");
    });
    window.addEventListener("popstate", function () {
      var path = (window.location.pathname || "").replace(/\/+$/, "");
      switchMode(path.indexOf("register") !== -1 ? "register" : "login");
    });
  }

  var roleEl = document.getElementById("role");
  if (roleEl) {
    roleEl.addEventListener("change", function () {
      var next = cfg.authNext ? "&next=" + encodeURIComponent(cfg.authNext) : "";
      window.location.href = (cfg.registerRoleUrl || "/register?role=") + encodeURIComponent(roleEl.value) + next;
    });
  }

  var firstNameEl = document.getElementById("first_name");
  var lastNameEl = document.getElementById("last_name");
  var nameEnEl = document.getElementById("name_en");
  var surnameEl = document.getElementById("surname");
  var userEl = document.getElementById("username");
  var passEl = document.getElementById("password");
  var passConfirmEl = document.getElementById("password_confirm");
  var isDoctor = !!cfg.isDoctor;

  if (!isDoctor && nameEnEl && surnameEl && typeof bindNameTransliteration === "function") {
    bindNameTransliteration({
      firstName: firstNameEl,
      lastName: lastNameEl,
      nameEn: nameEnEl,
      surname: surnameEl,
      username: userEl,
      usernameHint: document.getElementById("username-hint"),
      transliterateUrl: cfg.transliterateUrl,
      nameDict: cfg.nameDict || {},
      usernameReadonly: true,
      emptyHint: "با وارد کردن نام، به‌صورت خودکار ساخته می‌شود."
    });
  }

  var form = document.getElementById("register-form");
  if (form) {
    form.addEventListener("submit", function (e) {
      var phoneEl = document.getElementById("phone");
      if (!isDoctor && nameEnEl && surnameEl && (!nameEnEl.value.trim() || !surnameEl.value.trim())) {
        e.preventDefault();
        alert("فیلدهای انگلیسی نام و نام خانوادگی الزامی هستند.");
        (!nameEnEl.value.trim() ? nameEnEl : surnameEl).focus();
        return;
      }
      if (phoneEl && window.manaIsValidPhone && !window.manaIsValidPhone(phoneEl.value.trim())) {
        e.preventDefault();
        alert("موبایل الزامی است. شماره ایران یا بین‌المللی معتبر وارد کنید.");
        phoneEl.focus();
        return;
      }
      var user = userEl ? userEl.value.trim().toLowerCase() : "";
      if (!/^[a-z0-9._-]{3,32}$/.test(user)) {
        e.preventDefault();
        alert(isDoctor ? "نام کاربری را با حروف انگلیسی وارد کنید." : "نام کاربری معتبر ساخته نشد. فیلدهای انگلیسی را بررسی کنید.");
        if (userEl) userEl.focus();
        return;
      }
      var minPass = cfg.minPass || 7;
      if (passEl && passEl.value.length < minPass) {
        e.preventDefault();
        alert("رمز عبور حداقل " + minPass + " کاراکتر باشد.");
        passEl.focus();
        return;
      }
      if (passEl && (/[^\x00-\x7F]/.test(passEl.value) || (passConfirmEl && /[^\x00-\x7F]/.test(passConfirmEl.value)))) {
        e.preventDefault();
        alert("زبان صفحه‌کلید را انگلیسی کنید.");
        passEl.focus();
        return;
      }
      if (passEl && passConfirmEl && passEl.value !== passConfirmEl.value) {
        e.preventDefault();
        alert("رمز عبور و تکرار آن یکسان نیست.");
        passConfirmEl.focus();
      }
    });
  }
})();
