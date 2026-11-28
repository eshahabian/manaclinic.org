(function () {
  var dialog;
  var message;
  var field;
  var labels = {
    patient_id: "مراجعه‌کننده",
    doctor_id: "درمانگر",
    new_preferred_doctor_id: "درمانگر",
    preferred_doctor_id: "درمانگر",
    legacy_doctor_id: "درمانگر",
    new_first_name: "نام",
    new_last_name: "نام خانوادگی",
    new_name_en: "نام به انگلیسی",
    new_surname: "نام خانوادگی به انگلیسی",
    new_phone: "شماره موبایل",
    legacy_phone: "شماره موبایل",
    new_username: "نام کاربری",
    new_password: "رمز عبور",
    new_password_confirm: "تکرار رمز عبور",
    new_referral_source: "معرف",
    legacy_referral: "معرف",
    fee_residence: "محل اقامت",
    fee_amount: "مبلغ ویزیت",
    pay_method: "روش پرداخت",
    receipt: "فیش پرداخت"
  };

  function focusField() {
    if (!field || !field.isConnected) return;
    var search = field.closest(".search-select");
    var target = (search && search.querySelector(".search-select-input")) || field;
    target.scrollIntoView({ block: "center", inline: "nearest" });
    target.focus({ preventScroll: true });
  }

  function showError(text, target) {
    if (!dialog) {
      dialog = document.createElement("dialog");
      dialog.className = "secretary-validation-dialog";
      dialog.dir = "rtl";
      dialog.setAttribute("aria-labelledby", "secretary-validation-title");
      dialog.setAttribute("aria-describedby", "secretary-validation-text");
      dialog.innerHTML = '<h2 id="secretary-validation-title">اطلاعات فرم را بررسی کنید</h2>'
        + '<p id="secretary-validation-text"></p>'
        + '<form method="dialog"><button class="btn btn-primary" autofocus>باشه</button></form>';
      document.body.append(dialog);
      message = dialog.querySelector("p");
      dialog.addEventListener("close", focusField);
      dialog.addEventListener("keydown", function (event) {
        if (event.key === "Escape") event.stopPropagation();
      });
    }
    if (dialog.open) return;
    field = target;
    message.textContent = text;
    dialog.showModal();
  }

  document.addEventListener("invalid", function (event) {
    var input = event.target;
    if (!input.form || !input.form.hasAttribute("data-secretary-validation")) return;
    event.preventDefault();
    var label = labels[input.name] || input.getAttribute("aria-label") || "این فیلد";
    var text = label + " را به‌درستی وارد کنید.";
    if (input.validity.valueMissing) {
      if (input.name === "fee_confirmation") text = "محل اقامت و مبلغ ویزیت را تأیید کنید.";
      else if (input.type === "file") text = label + " را بارگذاری کنید.";
      else text = label + (input.tagName === "SELECT" ? " را انتخاب کنید." : " را وارد کنید.");
    } else if (input.validity.tooShort && input.minLength > 0) {
      text = label + " باید حداقل " + input.minLength.toLocaleString("fa-IR") + " کاراکتر باشد.";
    }
    showError(text, input);
  }, true);

  document.addEventListener("submit", function (event) {
    var form = event.target;
    if (!form.hasAttribute("data-secretary-validation")) return;
    var residence = form.elements.namedItem("fee_residence");
    var amount = form.elements.namedItem("fee_amount");
    if (!residence || !amount || amount.matches(":disabled")) return;
    if (!residence.value && amount.value.trim()) {
      event.preventDefault();
      showError("محل اقامت را انتخاب کنید.", residence);
    } else if ((residence.value || amount.required) && !amount.value.trim()) {
      event.preventDefault();
      showError("مبلغ ویزیت را وارد کنید.", amount);
    }
  }, true);

  window.manaSecretaryFieldError = showError;
})();
