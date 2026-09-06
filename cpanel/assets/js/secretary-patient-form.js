(function (global) {
  function bindSecretaryPatientFields(opts) {
    opts = opts || {};
    var form = opts.form;
    if (!form) return null;

    var names = global.bindNameTransliteration({
      firstName: document.getElementById("new_first_name"),
      lastName: document.getElementById("new_last_name"),
      nameEn: document.getElementById("new_name_en"),
      surname: document.getElementById("new_surname"),
      username: document.getElementById("new_username"),
      usernameHint: document.getElementById("username-hint"),
      suggestBtn: document.getElementById("suggest-username"),
      transliterateUrl: opts.transliterateUrl,
      nameDict: opts.nameDict || {},
      emptyHint: "با تایپ نام فارسی، معادل انگلیسی و نام کاربری پیشنهاد می‌شود."
    });

    var draft = null;
    if (global.bindFormDraft && opts.draftKey) {
      draft = global.bindFormDraft(form, opts.draftKey, {
        fields: opts.draftFields || [
          "new_first_name", "new_last_name", "new_name_en", "new_surname",
          "new_preferred_doctor_id", "new_phone", "new_username",
          "new_password", "new_password_confirm"
        ],
        exclude: opts.draftExclude || ["receipt", "_csrf"],
        clearParams: opts.clearParams || ["added"],
        onRestore: function () {
          if (names) names.markLatinTouched();
          if (typeof opts.onRestore === "function") opts.onRestore();
        }
      });
    }

    if (opts.enhanceSelects !== false && global.enhanceSearchSelect) {
      global.enhanceSearchSelect(document.getElementById("new_preferred_doctor_id"), {
        placeholder: "جستجو یا انتخاب درمانگر"
      });
    }

    return { names: names, draft: draft };
  }

  function validateSecretaryNewPatient(errEl) {
    var firstNameEl = document.getElementById("new_first_name");
    var lastNameEl = document.getElementById("new_last_name");
    var nameEnEl = document.getElementById("new_name_en");
    var surnameEl = document.getElementById("new_surname");
    var preferredDoctorEl = document.getElementById("new_preferred_doctor_id");
    var newUserEl = document.getElementById("new_username");
    var newPassEl = document.getElementById("new_password");
    var newPassConfirmEl = document.getElementById("new_password_confirm");
    var newPhoneEl = document.getElementById("new_phone");

    function fail(msg, el) {
      if (errEl) {
        errEl.textContent = msg;
        errEl.style.display = "block";
      } else {
        alert(msg);
      }
      if (el && el.focus) el.focus();
      return false;
    }

    var firstName = firstNameEl ? firstNameEl.value.trim() : "";
    var lastName = lastNameEl ? lastNameEl.value.trim() : "";
    if (!firstName || !lastName) {
      return fail("نام و نام خانوادگی مراجعه‌کننده جدید الزامی است.", firstName ? lastNameEl : firstNameEl);
    }
    if (!nameEnEl || !nameEnEl.value.trim() || !surnameEl || !surnameEl.value.trim()) {
      return fail("فیلدهای name و surname هم الزامی هستند.", (!nameEnEl || !nameEnEl.value.trim()) ? nameEnEl : surnameEl);
    }
    if (!preferredDoctorEl || !preferredDoctorEl.value) {
      return fail("درمانگر مربوط به مراجعه‌کننده را انتخاب کنید.", preferredDoctorEl);
    }
    if (!newPhoneEl || !global.manaIsValidPhone(newPhoneEl.value.trim())) {
      return fail("موبایل الزامی است. شماره ایران یا بین‌المللی معتبر وارد کنید.", newPhoneEl);
    }
    var newUser = newUserEl ? newUserEl.value.trim().toLowerCase() : "";
    if (!/^[a-z0-9._-]{3,32}$/.test(newUser)) {
      return fail("نام کاربری مراجعه‌کننده جدید الزامی است (۳ تا ۳۲ کاراکتر انگلیسی).", newUserEl);
    }
    if (!newPassEl || newPassEl.value.length < 6) {
      return fail("رمز عبور مراجعه‌کننده جدید الزامی است و حداقل ۶ کاراکتر باشد.", newPassEl);
    }
    if (!newPassConfirmEl || newPassEl.value !== newPassConfirmEl.value) {
      return fail("رمز عبور و تکرار آن یکسان نیست.", newPassConfirmEl);
    }
    if (errEl) errEl.style.display = "none";
    return true;
  }

  global.bindSecretaryPatientFields = bindSecretaryPatientFields;
  global.validateSecretaryNewPatient = validateSecretaryNewPatient;
})(window);
