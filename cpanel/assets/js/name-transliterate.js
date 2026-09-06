(function (global) {
  function latinWord(value) {
    return String(value || "").toLowerCase().replace(/[^a-z]/g, "");
  }

  function normalizeFa(text) {
    return String(text || "").trim().replace(/[\u200c]/g, "").replace(/ي/g, "ی").replace(/ك/g, "ک");
  }

  function bindNameTransliteration(config) {
    config = config || {};
    var firstNameEl = config.firstName;
    var lastNameEl = config.lastName;
    var nameEnEl = config.nameEn;
    var surnameEl = config.surname;
    var userEl = config.username;
    var usernameHint = config.usernameHint;
    var suggestBtn = config.suggestBtn || null;
    var transliterateUrl = config.transliterateUrl || "";
    var nameDict = config.nameDict || { first: {}, last: {} };
    var emptyHint = config.emptyHint || "با تایپ نام فارسی، معادل انگلیسی و نام کاربری پیشنهاد می‌شود.";
    var usernameReadonly = !!config.usernameReadonly;

    var nameEnTouched = false;
    var surnameTouched = false;
    var usernameTouched = !!usernameReadonly ? false : false;
    var timers = { first: null, last: null };
    var requests = { first: 0, last: 0 };
    var remoteCache = {};

    function baseUsernameFromParts() {
      var first = latinWord(nameEnEl && nameEnEl.value) || latinWord(firstNameEl && firstNameEl.value);
      var last = latinWord(surnameEl && surnameEl.value) || latinWord(lastNameEl && lastNameEl.value);
      if (!first && !last) return "";
      if (!last) return first.slice(0, 32);
      if (!first) return last.slice(0, 32);
      return (first.charAt(0) + last).slice(0, 32);
    }

    function applyUsername(force) {
      if (!userEl) return;
      if (!force && usernameTouched && !usernameReadonly) return;
      var base = baseUsernameFromParts();
      if (!base) {
        if (force || usernameReadonly || !userEl.value.trim()) {
          if (!usernameTouched || usernameReadonly) userEl.value = "";
          if (usernameHint) usernameHint.textContent = emptyHint;
        }
        return;
      }
      var suggested = base.slice(0, 32);
      if (force || !usernameTouched || usernameReadonly) {
        userEl.value = suggested;
      }
      if (usernameHint) {
        usernameHint.textContent = "پیشنهاد: " + suggested;
      }
    }

    function lookupLocal(kind, persianText) {
      var key = normalizeFa(persianText);
      if (!key) return "";
      var map = kind === "first" ? (nameDict.first || {}) : (nameDict.last || {});
      return map[key] || "";
    }

    function applyLatin(kind, latin) {
      if (!latin) return;
      if (kind === "first" && nameEnEl && !nameEnTouched) nameEnEl.value = latin;
      if (kind === "last" && surnameEl && !surnameTouched) surnameEl.value = latin;
      applyUsername(false);
    }

    function requestTransliteration(kind, persianText) {
      if (!transliterateUrl) return;
      var cacheKey = kind + "|" + normalizeFa(persianText);
      if (remoteCache[cacheKey]) {
        applyLatin(kind, remoteCache[cacheKey]);
        return;
      }

      var reqId = ++requests[kind];
      var sourceEl = kind === "first" ? firstNameEl : lastNameEl;
      var targetEl = kind === "first" ? nameEnEl : surnameEl;
      if (targetEl) targetEl.placeholder = "در حال جستجو...";

      fetch(transliterateUrl + "?name=" + encodeURIComponent(persianText) + "&part=" + kind)
        .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
        .then(function (res) {
          if (reqId !== requests[kind]) return;
          if (sourceEl && normalizeFa(sourceEl.value) !== normalizeFa(persianText)) return;
          if (targetEl) targetEl.placeholder = kind === "first" ? "name" : "surname";
          if (!res.ok || !res.j.latin) return;
          remoteCache[cacheKey] = res.j.latin;
          applyLatin(kind, res.j.latin);
        })
        .catch(function () {
          if (reqId !== requests[kind]) return;
          if (targetEl) targetEl.placeholder = kind === "first" ? "name" : "surname";
        });
    }

    function scheduleTransliteration(kind, persianText) {
      clearTimeout(timers[kind]);
      timers[kind] = setTimeout(function () {
        requestTransliteration(kind, persianText);
      }, 250);
    }

    function clearTransliteration(kind) {
      requests[kind]++;
      clearTimeout(timers[kind]);
      timers[kind] = null;
      if (kind === "first" && nameEnEl) {
        nameEnEl.value = "";
        nameEnEl.placeholder = "name";
        nameEnTouched = false;
      } else if (surnameEl) {
        surnameEl.value = "";
        surnameEl.placeholder = "surname";
        surnameTouched = false;
      }
      applyUsername(false);
    }

    function onFirstNameInput() {
      var val = normalizeFa(firstNameEl && firstNameEl.value);
      if (!val) {
        clearTransliteration("first");
        return;
      }
      if (!nameEnTouched) {
        var local = lookupLocal("first", val);
        if (local) {
          applyLatin("first", local);
          return;
        }
        scheduleTransliteration("first", val);
      }
      applyUsername(false);
    }

    function onLastNameInput() {
      var val = normalizeFa(lastNameEl && lastNameEl.value);
      if (!val) {
        clearTransliteration("last");
        return;
      }
      if (!surnameTouched) {
        var local = lookupLocal("last", val);
        if (local) {
          applyLatin("last", local);
          return;
        }
        scheduleTransliteration("last", val);
      }
      applyUsername(false);
    }

    if (nameEnEl) {
      nameEnEl.addEventListener("input", function () {
        nameEnTouched = true;
        applyUsername(false);
      });
    }
    if (surnameEl) {
      surnameEl.addEventListener("input", function () {
        surnameTouched = true;
        applyUsername(false);
      });
    }
    if (firstNameEl) {
      firstNameEl.addEventListener("input", onFirstNameInput);
      firstNameEl.addEventListener("blur", onFirstNameInput);
    }
    if (lastNameEl) {
      lastNameEl.addEventListener("input", onLastNameInput);
      lastNameEl.addEventListener("blur", onLastNameInput);
    }
    if (userEl && !usernameReadonly) {
      userEl.addEventListener("input", function () { usernameTouched = true; });
    }
    if (suggestBtn) {
      suggestBtn.addEventListener("click", function () {
        usernameTouched = false;
        applyUsername(true);
      });
    }

    return {
      applyUsername: applyUsername,
      markLatinTouched: function () {
        if (nameEnEl && nameEnEl.value.trim()) nameEnTouched = true;
        if (surnameEl && surnameEl.value.trim()) surnameTouched = true;
        if (userEl && userEl.value.trim() && !usernameReadonly) usernameTouched = true;
      },
      reset: function () {
        nameEnTouched = false;
        surnameTouched = false;
        usernameTouched = false;
        if (usernameHint) usernameHint.textContent = "";
      }
    };
  }

  global.bindNameTransliteration = bindNameTransliteration;
  global.manaFaToEn = function (str) {
    return String(str).replace(/[۰-۹]/g, function (d) { return "۰۱۲۳۴۵۶۷۸۹".indexOf(d); })
      .replace(/[٠-٩]/g, function (d) { return "٠١٢٣٤٥٦٧٨٩".indexOf(d); });
  };
  global.manaIsValidPhone = function (value) {
    var phone = global.manaFaToEn(value || "").replace(/[\s\-\.\(\)]+/g, "");
    if (!phone) return false;
    if (/^\+[1-9][0-9]{7,14}$/.test(phone)) return true;
    if (/^00[1-9][0-9]{7,14}$/.test(phone)) return true;
    return /^[0-9]{8,15}$/.test(phone);
  };
})(window);
