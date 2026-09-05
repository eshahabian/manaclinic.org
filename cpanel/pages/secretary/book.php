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
$takenUsernames = $pdo->query("SELECT username FROM users WHERE username IS NOT NULL AND username <> ''")->fetchAll(PDO::FETCH_COLUMN);
$doctors = $pdo->query("
  SELECT dp.id, u.name, dp.specialty
  FROM doctor_profiles dp
  JOIN users u ON u.id = dp.user_id
  WHERE dp.is_active = 1 AND dp.is_approved = 1
  ORDER BY u.name ASC
")->fetchAll();

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
  <div>
    <label class="label">بیمار موجود</label>
    <select class="input" name="patient_id" id="patient_id">
      <option value="">— انتخاب بیمار —</option>
      <?php foreach ($patients as $p): ?>
        <option value="<?= e($p['id']) ?>"><?= e($p['name']) ?> (<?= e((string)$p['username']) ?>)<?= !empty($p['doctor_name']) ? ' — ' . e($p['doctor_name']) : '' ?></option>
      <?php endforeach; ?>
    </select>
  </div>

  <div class="panel" style="background:var(--bg-soft);border-style:dashed">
    <p style="margin:0 0 .75rem;font-weight:600">یا بیمار جدید</p>
    <p class="muted" style="margin:0 0 .75rem;font-size:.85rem;line-height:1.7">بیمار با نام کاربری و رمز زیر می‌تواند بعداً وارد شود و نوبت بگیرد. برای پیشنهاد نام کاربری، فیلدهای انگلیسی را هم پر کنید.</p>
    <div class="grid-2">
      <div>
        <label class="label" for="new_first_name">نام</label>
        <input class="input input-rtl" name="new_first_name" id="new_first_name" dir="rtl" autocomplete="given-name" placeholder="نام">
      </div>
      <div>
        <label class="label label-ltr" for="new_name_en">name</label>
        <input class="input" name="new_name_en" id="new_name_en" dir="ltr" lang="en" autocomplete="off" placeholder="name">
      </div>
      <div>
        <label class="label" for="new_last_name">نام خانوادگی</label>
        <input class="input input-rtl" name="new_last_name" id="new_last_name" dir="rtl" autocomplete="family-name" placeholder="نام خانوادگی">
      </div>
      <div>
        <label class="label label-ltr" for="new_surname">surname</label>
        <input class="input" name="new_surname" id="new_surname" dir="ltr" lang="en" autocomplete="off" placeholder="surname">
      </div>
      <div style="grid-column:1/-1">
        <label class="label" for="new_preferred_doctor_id">درمانگر مربوط به مراجعه‌کننده</label>
        <select class="input" name="new_preferred_doctor_id" id="new_preferred_doctor_id">
          <option value="">انتخاب درمانگر</option>
          <?php foreach ($doctors as $d): ?>
            <option value="<?= e($d['id']) ?>"><?= e($d['name']) ?> — <?= e($d['specialty']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div style="grid-column:1/-1">
        <label class="label" for="new_phone">موبایل</label>
        <input class="input" name="new_phone" id="new_phone" dir="ltr" placeholder="09..." pattern="09[0-9]{9}" title="شماره موبایل ۱۱ رقمی با ۰۹">
      </div>
      <div style="grid-column:1/-1">
        <label class="label" for="new_username">نام کاربری</label>
        <div style="display:flex;gap:.5rem;align-items:stretch">
          <input class="input" name="new_username" id="new_username" dir="ltr" pattern="[A-Za-z0-9._-]{3,32}" placeholder="حروف انگلیسی، عدد و ._- " style="flex:1">
          <button type="button" class="btn btn-outline" id="suggest-username" title="پیشنهاد از روی name و surname">پیشنهاد</button>
        </div>
        <p class="muted" id="username-hint" style="margin:.4rem 0 0;font-size:.8rem;line-height:1.6"></p>
      </div>
      <div>
        <label class="label" for="new_password">رمز عبور</label>
        <input class="input" name="new_password" id="new_password" type="password" dir="ltr" minlength="6" autocomplete="new-password" placeholder="حداقل ۶ کاراکتر">
      </div>
      <div>
        <label class="label" for="new_password_confirm">تکرار رمز عبور</label>
        <input class="input" name="new_password_confirm" id="new_password_confirm" type="password" dir="ltr" minlength="6" autocomplete="new-password" placeholder="تکرار رمز">
      </div>
    </div>
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
    <textarea class="input" name="notes" rows="3"></textarea>
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
<script src="https://cdn.jsdelivr.net/npm/jalaali-js@1.2.7/dist/jalaali.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/@majidh1/jalalidatepicker/dist/jalalidatepicker.min.js"></script>
<script>
(function(){
  var availByDoctor = ' . json_encode($availByDoctor, JSON_UNESCAPED_UNICODE) . ';
  var takenUsernames = ' . json_encode(array_values(array_map(static fn($u) => mb_strtolower((string) $u), $takenUsernames)), JSON_UNESCAPED_UNICODE) . ';
  var takenSet = {};
  takenUsernames.forEach(function(u){ if (u) takenSet[u] = true; });

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
  var suggestBtn = document.getElementById("suggest-username");
  var usernameHint = document.getElementById("username-hint");
  var usernameTouched = false;

  function latinWord(value) {
    return String(value || "").toLowerCase().replace(/[^a-z]/g, "");
  }

  function baseUsernameFromParts() {
    var first = latinWord(nameEnEl.value) || latinWord(firstNameEl.value);
    var last = latinWord(surnameEl.value) || latinWord(lastNameEl.value);
    if (!first && !last) return "";
    if (!last) return first.slice(0, 32);
    if (!first) return last.slice(0, 32);
    return (first.charAt(0) + last).slice(0, 32);
  }

  function uniqueUsername(base) {
    if (!base || base.length < 3) return "";
    var candidate = base;
    var n = 1;
    while (takenSet[candidate]) {
      var suffix = String(n++);
      candidate = (base.slice(0, Math.max(3, 32 - suffix.length)) + suffix);
      if (n > 999) return "";
    }
    return candidate;
  }

  function applySuggestion(force) {
    if (!force && usernameTouched) return;
    var base = baseUsernameFromParts();
    if (!base) {
      if (force || !newUserEl.value.trim()) {
        if (!usernameTouched) newUserEl.value = "";
        usernameHint.textContent = "با تایپ name و surname پیشنهاد نام کاربری همین‌جا می‌آید.";
      }
      return;
    }
    var suggested = uniqueUsername(base);
    if (!suggested) {
      usernameHint.textContent = "پیشنهاد معتبری پیدا نشد؛ نام کاربری را دستی وارد کنید.";
      return;
    }
    if (force || !usernameTouched) {
      newUserEl.value = suggested;
    }
    usernameHint.textContent = suggested === base
      ? "پیشنهاد: " + suggested
      : "پیشنهاد (بدون تکرار): " + suggested;
  }

  newUserEl.addEventListener("input", function(){ usernameTouched = true; });
  [firstNameEl, lastNameEl, nameEnEl, surnameEl].forEach(function(el){
    el.addEventListener("input", function(){ applySuggestion(false); });
    el.addEventListener("blur", function(){ applySuggestion(false); });
  });
  suggestBtn.addEventListener("click", function(){
    usernameTouched = false;
    applySuggestion(true);
  });

  function clearExistingPatient() {
    if (patientEl.value) patientEl.value = "";
  }
  [firstNameEl, lastNameEl, nameEnEl, surnameEl, preferredDoctorEl, newUserEl, newPassEl, newPassConfirmEl, newPhoneEl].forEach(function(el){
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
    usernameTouched = false;
    usernameHint.textContent = "";
  });

  // اگر درمانگر نوبت انتخاب شد و درمانگر بیمار خالی است، همان را پیشنهاد بده
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

  function pad(n){ return (n < 10 ? "0" : "") + n; }
  function faToEn(str){
    return String(str).replace(/[۰-۹]/g, function(d){ return "۰۱۲۳۴۵۶۷۸۹".indexOf(d); })
      .replace(/[٠-٩]/g, function(d){ return "٠١٢٣٤٥٦٧٨٩".indexOf(d); });
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

  function loadSlots(){
    timeEl.value = "";
    if (!doctorEl.value || !dateEl.value) {
      slotsEl.innerHTML = "<span class=\\"muted\\">ابتدا تاریخ را انتخاب کنید</span>";
      return;
    }
    slotsEl.innerHTML = "در حال بارگذاری...";
    fetch(slotsUrl + "?doctorId=" + encodeURIComponent(doctorEl.value) + "&date=" + encodeURIComponent(dateEl.value))
      .then(function(r){ return r.json(); })
      .then(function(data){
        var slots = data.slots || [];
        if (!slots.length) {
          slotsEl.innerHTML = "<span class=\\"muted\\">ساعت خالی نیست</span>";
          return;
        }
        slotsEl.innerHTML = "";
        slots.forEach(function(s){
          var value = typeof s === "string" ? s : (s.value || "");
          var label = typeof s === "string" ? value.replace(/:00$/, "") : (s.label || value.replace(/:00$/, ""));
          var b = document.createElement("button");
          b.type = "button";
          b.className = "slot-btn";
          b.textContent = label;
          b.onclick = function(){
            Array.prototype.forEach.call(slotsEl.querySelectorAll(".slot-btn"), function(x){ x.classList.remove("active"); });
            b.classList.add("active");
            timeEl.value = value;
            errEl.style.display = "none";
          };
          slotsEl.appendChild(b);
        });
      })
      .catch(function(){
        slotsEl.innerHTML = "<span class=\\"muted\\">خطا در دریافت ساعت‌ها</span>";
      });
  }

  function selectDate(gDate){
    errEl.style.display = "none";
    var g = String(gDate).substring(0,10);
    var set = availableSet();
    if (!doctorEl.value) {
      errEl.textContent = "ابتدا دکتر را انتخاب کنید.";
      errEl.style.display = "block";
      return;
    }
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
    errEl.style.display = "none";
    var hasDoctor = !!doctorEl.value;
    dateView.disabled = !hasDoctor;
    dateView.placeholder = hasDoctor ? "تاریخ شمسی خالی" : "ابتدا دکتر را انتخاب کنید";
    renderChips();
    loadSlots();
  });

  document.getElementById("secretary-book-form").addEventListener("submit", function(e){
    var patientId = patientEl.value;
    var firstName = firstNameEl.value.trim();
    var lastName = lastNameEl.value.trim();
    var newName = (firstName + " " + lastName).trim();
    var newUser = newUserEl.value.trim().toLowerCase();
    var newPass = newPassEl.value;
    var newPassConfirm = newPassConfirmEl.value;
    if (!patientId && !newName) {
      e.preventDefault();
      errEl.textContent = "بیمار موجود را انتخاب کنید یا نام و نام خانوادگی بیمار جدید را وارد کنید.";
      errEl.style.display = "block";
      return;
    }
    if (!patientId) {
      if (!firstName || !lastName) {
        e.preventDefault();
        errEl.textContent = "نام و نام خانوادگی بیمار جدید الزامی است.";
        errEl.style.display = "block";
        (firstName ? lastNameEl : firstNameEl).focus();
        return;
      }
      if (!nameEnEl.value.trim() || !surnameEl.value.trim()) {
        e.preventDefault();
        errEl.textContent = "فیلدهای name و surname هم الزامی هستند.";
        errEl.style.display = "block";
        (!nameEnEl.value.trim() ? nameEnEl : surnameEl).focus();
        return;
      }
      if (!preferredDoctorEl.value) {
        e.preventDefault();
        errEl.textContent = "درمانگر مربوط به مراجعه‌کننده را انتخاب کنید.";
        errEl.style.display = "block";
        preferredDoctorEl.focus();
        return;
      }
      if (!/^09[0-9]{9}$/.test(newPhoneEl.value.trim())) {
        e.preventDefault();
        errEl.textContent = "موبایل الزامی است و باید ۱۱ رقم با ۰۹ باشد.";
        errEl.style.display = "block";
        newPhoneEl.focus();
        return;
      }
      if (!/^[a-z0-9._-]{3,32}$/.test(newUser)) {
        e.preventDefault();
        errEl.textContent = "نام کاربری بیمار جدید الزامی است (۳ تا ۳۲ کاراکتر انگلیسی).";
        errEl.style.display = "block";
        newUserEl.focus();
        return;
      }
      if (takenSet[newUser]) {
        e.preventDefault();
        errEl.textContent = "این نام کاربری قبلاً ثبت شده است. پیشنهاد دیگری بزنید.";
        errEl.style.display = "block";
        newUserEl.focus();
        return;
      }
      if (newPass.length < 6) {
        e.preventDefault();
        errEl.textContent = "رمز عبور بیمار جدید الزامی است و حداقل ۶ کاراکتر باشد.";
        errEl.style.display = "block";
        newPassEl.focus();
        return;
      }
      if (newPass !== newPassConfirm) {
        e.preventDefault();
        errEl.textContent = "رمز عبور و تکرار آن یکسان نیست.";
        errEl.style.display = "block";
        newPassConfirmEl.focus();
        return;
      }
    }
    if (!dateEl.value || !timeEl.value) {
      e.preventDefault();
      errEl.textContent = "تاریخ و ساعت را انتخاب کنید.";
      errEl.style.display = "block";
    }
  });

  renderChips();
})();
</script>
';
