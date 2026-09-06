<?php
declare(strict_types=1);
if (empty($secretaryBookEmbedded)) {
    require_once __DIR__ . '/../../includes/secretary_panel.php';
    require_login(['SECRETARY']);
    redirect('/secretary/appointments?tab=new');
}

$patients = $pdo->query("
  SELECT u.id, u.name, u.username, u.phone, u.preferred_doctor_id, du.name AS doctor_name
  FROM users u
  LEFT JOIN doctor_profiles dp ON dp.id = u.preferred_doctor_id
  LEFT JOIN users du ON du.id = dp.user_id
  WHERE u.role='PATIENT'
  ORDER BY u.name ASC
")->fetchAll();
$doctors = secretary_active_doctors($pdo);
$nameDict = build_name_transliterations_client_map($pdo);

$availByDoctor = [];
$stmt = $pdo->query("
  SELECT doctor_id, DATE_FORMAT(`date`, '%Y-%m-%d') AS d
  FROM availabilities
  WHERE `date` >= CURDATE()
  ORDER BY `date` ASC
");
foreach ($stmt->fetchAll() as $row) {
    $d = (string) $row['d'];
    if ($d === '') {
        continue;
    }
    $availByDoctor[(string) $row['doctor_id']][] = $d;
}

ob_start();
?>
<p class="muted" style="margin:0 0 1rem">ابتدا دکتر را انتخاب کنید؛ فقط روزهایی که دکتر وقت خالی گذاشته قابل انتخاب هستند.</p>

<form class="panel form-stack" method="post" action="<?= e(url('/secretary/book')) ?>" id="secretary-book-form" style="margin-top:0;max-width:44rem" enctype="multipart/form-data">
  <?= csrf_field() ?>
  <div>
    <label class="label">مراجعه‌کننده</label>
    <select class="input" name="patient_id" id="patient_id">
      <option value="">— انتخاب مراجعه‌کننده —</option>
      <?php foreach ($patients as $p): ?>
        <option value="<?= e($p['id']) ?>"><?= e($p['name']) ?> (<?= e((string)$p['username']) ?>)<?= !empty($p['doctor_name']) ? ' — ' . e($p['doctor_name']) : '' ?></option>
      <?php endforeach; ?>
    </select>
  </div>

  <div class="panel" style="background:var(--bg-soft);border-style:dashed">
    <p style="margin:0 0 .75rem;font-weight:600">یا مراجعه‌کننده جدید</p>
    <?php
      $secretaryPatientHint = 'مراجعه‌کننده با نام کاربری و رمز زیر می‌تواند بعداً وارد شود. با تایپ نام فارسی، معادل انگلیسی پیشنهاد می‌شود. اگر نت قطع شود، اطلاعات واردشده روی همین دستگاه می‌ماند.';
      require __DIR__ . '/../../includes/secretary_new_patient_fields.php';
    ?>
  </div>

  <div>
    <label class="label">دکتر</label>
    <select class="input" name="doctor_id" id="doctor_id" required>
      <option value="">انتخاب کنید</option>
      <?php foreach ($doctors as $d): ?>
        <option value="<?= e($d['id']) ?>"><?= e($d['name']) ?> — <?= e($d['specialty']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>

  <div>
    <label class="label">روزهای خالی دکتر</label>
    <div class="slots" id="sec-date-chips"><span class="muted">ابتدا دکتر را انتخاب کنید</span></div>
  </div>

  <div>
    <label class="label" for="sec-date-view">یا انتخاب از تقویم</label>
    <input class="input" type="text" id="sec-date-view" data-jdp data-jdp-only-date autocomplete="off" readonly placeholder="ابتدا دکتر را انتخاب کنید" disabled>
    <input type="hidden" name="date" id="sec-date" required>
  </div>

  <div>
    <label class="label">ساعت</label>
    <div class="slots" id="sec-slots"><span class="muted">ابتدا تاریخ را انتخاب کنید</span></div>
    <input type="hidden" name="time" id="sec-time" required>
  </div>

  <div>
    <label class="label">یادداشت (اختیاری)</label>
    <textarea class="input" name="notes" id="notes" rows="3"></textarea>
  </div>

  <div>
    <label class="label" for="receipt">رسید پرداخت (اختیاری)</label>
    <input class="input" type="file" name="receipt" id="receipt" accept="image/jpeg,image/png,image/webp,application/pdf">
    <p class="muted" style="margin:.4rem 0 0;font-size:.8rem">تصویر یا PDF تا ۵ مگابایت. بعداً هم می‌توانید از تب نوبت‌ها آپلود کنید.</p>
  </div>

  <p id="sec-error" style="color:var(--danger);display:none;font-size:.9rem"></p>
  <button class="btn btn-primary" type="submit">ثبت نوبت (تأیید شده)</button>
</form>
<?php
$secretaryBookFormHtml = ob_get_clean();

$secretaryBookScripts = '
<script src="' . e(url('/assets/js/search-select.js')) . '?v=20260906p"></script>
<script src="' . e(url('/assets/js/name-transliterate.js')) . '?v=20260906p"></script>
<script src="' . e(url('/assets/js/form-draft.js')) . '?v=20260906p"></script>
<script src="' . e(url('/assets/js/secretary-patient-form.js')) . '?v=20260906p"></script>
<script src="https://cdn.jsdelivr.net/npm/jalaali-js@1.2.7/dist/jalaali.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/@majidh1/jalalidatepicker/dist/jalalidatepicker.min.js"></script>
<script>
(function(){
  var availByDoctor = ' . json_encode($availByDoctor, JSON_UNESCAPED_UNICODE) . ';

  var form = document.getElementById("secretary-book-form");
  var doctorEl = document.getElementById("doctor_id");
  var patientEl = document.getElementById("patient_id");
  var firstNameEl = document.getElementById("new_first_name");
  var lastNameEl = document.getElementById("new_last_name");
  var nameEnEl = document.getElementById("new_name_en");
  var surnameEl = document.getElementById("new_surname");
  var preferredDoctorEl = document.getElementById("new_preferred_doctor_id");
  var newUserEl = document.getElementById("new_username");
  var newPassEl = document.getElementById("new_password");
  var newPassConfirmEl = document.getElementById("new_password_confirm");
  var newPhoneEl = document.getElementById("new_phone");
  var usernameHint = document.getElementById("username-hint");

  if (window.enhanceSearchSelect) {
    enhanceSearchSelect(patientEl, { placeholder: "جستجو یا انتخاب مراجعه‌کننده" });
    enhanceSearchSelect(doctorEl, { placeholder: "جستجو یا انتخاب دکتر" });
  }

  var bound = bindSecretaryPatientFields({
    form: form,
    transliterateUrl: ' . json_encode(url('/api/transliterate-name')) . ',
    nameDict: ' . json_encode($nameDict, JSON_UNESCAPED_UNICODE) . ',
    draftKey: "mana.secretary.book.new-patient",
    clearParams: ["booked"],
    enhanceSelects: true,
    draftFields: [
      "new_first_name", "new_last_name", "new_name_en", "new_surname",
      "new_preferred_doctor_id", "new_phone", "new_username",
      "new_password", "new_password_confirm", "doctor_id", "sec-date", "notes"
    ]
  });

  function clearExistingPatient() {
    if (patientEl.value) patientEl.value = "";
  }
  [firstNameEl, lastNameEl, nameEnEl, surnameEl, preferredDoctorEl, newUserEl, newPassEl, newPassConfirmEl, newPhoneEl].forEach(function(el){
    if (!el) return;
    el.addEventListener("input", clearExistingPatient);
    el.addEventListener("change", clearExistingPatient);
  });
  patientEl.addEventListener("change", function(){
    if (!patientEl.value) return;
    firstNameEl.value = "";
    lastNameEl.value = "";
    nameEnEl.value = "";
    surnameEl.value = "";
    preferredDoctorEl.value = "";
    newUserEl.value = "";
    newPassEl.value = "";
    newPassConfirmEl.value = "";
    newPhoneEl.value = "";
    if (bound && bound.names) bound.names.reset();
    if (usernameHint) usernameHint.textContent = "";
  });

  doctorEl.addEventListener("change", function(){
    if (!patientEl.value && !preferredDoctorEl.value && doctorEl.value) {
      preferredDoctorEl.value = doctorEl.value;
    }
  });

  var dateView = document.getElementById("sec-date-view");
  var dateEl = document.getElementById("sec-date");
  var timeEl = document.getElementById("sec-time");
  var chipsEl = document.getElementById("sec-date-chips");
  var slotsEl = document.getElementById("sec-slots");
  var errEl = document.getElementById("sec-error");
  var slotsUrl = ' . json_encode(url('/api/slots')) . ';
  var daysUrl = ' . json_encode(url('/api/availability-days')) . ';
  var selectedTime = "";
  var refreshTimer = null;

  function pad(n){ return (n < 10 ? "0" : "") + n; }
  function faToEn(str){
    return (window.manaFaToEn || function(s){ return String(s); })(str);
  }
  function jalaliToGregorian(text){
    var t = faToEn(text).replace(/-/g,"/").trim();
    var p = t.split("/");
    if (p.length !== 3) return "";
    var jy = parseInt(p[0],10), jm = parseInt(p[1],10), jd = parseInt(p[2],10);
    if (!jy || !jm || !jd) return "";
    var g = jalaali.toGregorian(jy, jm, jd);
    return g.gy + "-" + pad(g.gm) + "-" + pad(g.gd);
  }
  function gregorianToJalaliText(ymd){
    var p = String(ymd).substring(0,10).split("-");
    if (p.length !== 3) return ymd;
    var j = jalaali.toJalaali(parseInt(p[0],10), parseInt(p[1],10), parseInt(p[2],10));
    return j.jy + "/" + pad(j.jm) + "/" + pad(j.jd);
  }
  function availableList(){
    return (availByDoctor[doctorEl.value] || []).map(function(d){ return String(d).substring(0,10); });
  }
  function availableSet(){
    var set = {};
    availableList().forEach(function(d){ set[d] = true; });
    return set;
  }

  function renderSlotButtons(slots, keepTime){
    var prev = keepTime ? (timeEl.value || selectedTime) : "";
    timeEl.value = "";
    slotsEl.innerHTML = "";
    if (!slots.length) {
      slotsEl.innerHTML = "<span class=\\"muted\\">ساعت خالی نیست</span>";
      return;
    }
    slots.forEach(function(s){
      var value = typeof s === "string" ? s : (s.value || "");
      var label = typeof s === "string" ? value.replace(/:00$/, "") : (s.label || value.replace(/:00$/, ""));
      var dateLabel = typeof s === "object" && s.date_label ? s.date_label : "";
      var b = document.createElement("button");
      b.type = "button";
      b.className = "slot-btn" + (prev && prev === value ? " active" : "");
      if (prev && prev === value) timeEl.value = value;
      if (dateLabel) {
        var timeElChip = document.createElement("span");
        timeElChip.className = "hour-chip-time";
        timeElChip.textContent = label;
        var dateElChip = document.createElement("span");
        dateElChip.className = "hour-chip-date";
        dateElChip.textContent = dateLabel;
        b.appendChild(timeElChip);
        b.appendChild(dateElChip);
      } else {
        b.textContent = label;
      }
      b.onclick = function(){
        Array.prototype.forEach.call(slotsEl.querySelectorAll(".slot-btn"), function(x){ x.classList.remove("active"); });
        b.classList.add("active");
        timeEl.value = value;
        selectedTime = value;
        errEl.style.display = "none";
      };
      slotsEl.appendChild(b);
    });
  }

  function loadSlots(opts){
    opts = opts || {};
    if (!opts.keepTime) {
      timeEl.value = "";
      selectedTime = "";
    }
    if (!doctorEl.value || !dateEl.value) {
      slotsEl.innerHTML = "<span class=\\"muted\\">ابتدا تاریخ را انتخاب کنید</span>";
      return;
    }
    if (!opts.silent) slotsEl.innerHTML = "در حال بارگذاری...";
    fetch(slotsUrl + "?doctorId=" + encodeURIComponent(doctorEl.value) + "&date=" + encodeURIComponent(dateEl.value) + "&include_past=1")
      .then(function(r){ return r.json(); })
      .then(function(data){
        renderSlotButtons(data.slots || [], !!opts.keepTime);
      })
      .catch(function(){
        if (!opts.silent) slotsEl.innerHTML = "<span class=\\"muted\\">خطا در دریافت ساعت‌ها</span>";
      });
  }

  function refreshAvailability(opts){
    opts = opts || {};
    if (!doctorEl.value) return Promise.resolve();
    return fetch(daysUrl + "?doctorId=" + encodeURIComponent(doctorEl.value))
      .then(function(r){ return r.json(); })
      .then(function(data){
        availByDoctor[doctorEl.value] = data.days || [];
        renderChips();
        if (dateEl.value) loadSlots({ keepTime: true, silent: !!opts.silent });
      })
      .catch(function(){});
  }

  function selectDate(gDate){
    errEl.style.display = "none";
    var g = String(gDate).substring(0,10);
    if (!doctorEl.value) {
      errEl.textContent = "ابتدا دکتر را انتخاب کنید.";
      errEl.style.display = "block";
      return;
    }
    var finish = function(){
      var set = availableSet();
      if (!set[g]) {
        dateEl.value = "";
        dateView.value = "";
        errEl.textContent = "این تاریخ برای دکتر انتخاب‌شده خالی نیست.";
        errEl.style.display = "block";
        loadSlots();
        renderChips();
        return;
      }
      dateEl.value = g;
      dateView.value = gregorianToJalaliText(g);
      renderChips();
      loadSlots();
    };
    if (!availableSet()[g]) {
      refreshAvailability().then(finish);
      return;
    }
    finish();
  }

  function renderChips(){
    var list = availableList();
    chipsEl.innerHTML = "";
    if (!doctorEl.value) {
      chipsEl.innerHTML = "<span class=\\"muted\\">ابتدا دکتر را انتخاب کنید</span>";
      return;
    }
    if (!list.length) {
      chipsEl.innerHTML = "<span class=\\"muted\\">برای این دکتر روز خالی آینده‌ای ثبت نشده</span>";
      return;
    }
    list.forEach(function(g){
      var b = document.createElement("button");
      b.type = "button";
      b.className = "slot-btn" + (dateEl.value === g ? " active" : "");
      b.textContent = gregorianToJalaliText(g);
      b.onclick = function(){ selectDate(g); };
      chipsEl.appendChild(b);
    });
  }

  jalaliDatepicker.startWatch({
    selector: "#sec-date-view",
    time: false,
    hideAfterChange: true,
    autoReadOnlyInput: true,
    zIndex: 99999,
    dayRendering: function(dayOptions){
      if (!doctorEl.value) {
        return { isValid: false };
      }
      var g = jalaali.toGregorian(dayOptions.year, dayOptions.month, dayOptions.day);
      var key = g.gy + "-" + pad(g.gm) + "-" + pad(g.gd);
      return { isValid: !!availableSet()[key] };
    }
  });

  dateView.addEventListener("jdp:change", function(){
    var g = jalaliToGregorian(dateView.value);
    if (g) selectDate(g);
  });
  dateView.addEventListener("change", function(){
    var g = jalaliToGregorian(dateView.value);
    if (g) selectDate(g);
  });

  doctorEl.addEventListener("change", function(){
    dateView.value = "";
    dateEl.value = "";
    timeEl.value = "";
    selectedTime = "";
    errEl.style.display = "none";
    var hasDoctor = !!doctorEl.value;
    dateView.disabled = !hasDoctor;
    dateView.placeholder = hasDoctor ? "تاریخ شمسی خالی" : "ابتدا دکتر را انتخاب کنید";
    renderChips();
    loadSlots();
    refreshAvailability();
  });

  document.addEventListener("visibilitychange", function(){
    if (!document.hidden && doctorEl.value) refreshAvailability({ silent: true });
  });
  refreshTimer = setInterval(function(){
    if (document.hidden || !doctorEl.value) return;
    refreshAvailability({ silent: true });
  }, 20000);

  form.addEventListener("submit", function(e){
    var patientId = patientEl.value;
    var newName = ((firstNameEl.value || "").trim() + " " + (lastNameEl.value || "").trim()).trim();
    if (!patientId && !newName) {
      e.preventDefault();
      errEl.textContent = "مراجعه‌کننده را انتخاب کنید یا نام و نام خانوادگی مراجعه‌کننده جدید را وارد کنید.";
      errEl.style.display = "block";
      return;
    }
    if (!patientId && !validateSecretaryNewPatient(errEl)) {
      e.preventDefault();
      return;
    }
    if (!doctorEl.value) {
      e.preventDefault();
      errEl.textContent = "دکتر را انتخاب کنید.";
      errEl.style.display = "block";
      var doctorInput = doctorEl.closest(".search-select") && doctorEl.closest(".search-select").querySelector(".search-select-input");
      (doctorInput || doctorEl).focus();
      return;
    }
    if (!dateEl.value || !timeEl.value) {
      e.preventDefault();
      errEl.textContent = "تاریخ و ساعت را انتخاب کنید.";
      errEl.style.display = "block";
    }
  });

  renderChips();
  if (doctorEl.value) {
    dateView.disabled = false;
    dateView.placeholder = "تاریخ شمسی خالی";
    if (dateEl.value) dateView.value = gregorianToJalaliText(dateEl.value);
    refreshAvailability();
  }
})();
</script>
';
