<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/secretary_panel.php';
require_login(['SECRETARY']);

$patients = $pdo->query("SELECT id, name, username, phone FROM users WHERE role='PATIENT' ORDER BY name ASC")->fetchAll();
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
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@majidh1/jalalidatepicker/dist/jalalidatepicker.min.css">
<h1>رزرو نوبت برای بیمار</h1>
<p class="muted">ابتدا دکتر را انتخاب کنید؛ فقط روزهایی که دکتر وقت خالی گذاشته قابل انتخاب هستند.</p>

<form class="panel form-stack" method="post" action="<?= e(url('/secretary/book')) ?>" id="secretary-book-form" style="margin-top:1rem;max-width:44rem">
  <div>
    <label class="label">بیمار موجود</label>
    <select class="input" name="patient_id" id="patient_id">
      <option value="">— انتخاب بیمار —</option>
      <?php foreach ($patients as $p): ?>
        <option value="<?= e($p['id']) ?>"><?= e($p['name']) ?> (<?= e((string)$p['username']) ?>)</option>
      <?php endforeach; ?>
    </select>
  </div>

  <div class="panel" style="background:var(--bg-soft);border-style:dashed">
    <p style="margin:0 0 .75rem;font-weight:600">یا بیمار جدید</p>
    <p class="muted" style="margin:0 0 .75rem;font-size:.85rem;line-height:1.7">نام را به انگلیسی وارد کنید تا نام کاربری پیشنهاد شود. بیمار با همین نام کاربری و رمز می‌تواند بعداً وارد شود و نوبت بگیرد.</p>
    <div class="grid-2">
      <div style="grid-column:1/-1">
        <label class="label" for="new_name">نام و نام خانوادگی (انگلیسی)</label>
        <input class="input" name="new_name" id="new_name" dir="ltr" lang="en" autocomplete="name" placeholder="Emad Shahabian">
      </div>
      <div>
        <label class="label" for="new_phone">موبایل</label>
        <input class="input" name="new_phone" id="new_phone" dir="ltr" placeholder="09...">
      </div>
      <div>
        <label class="label" for="new_password">رمز عبور</label>
        <input class="input" name="new_password" id="new_password" type="password" dir="ltr" minlength="6" autocomplete="new-password" placeholder="حداقل ۶ کاراکتر">
      </div>
      <div style="grid-column:1/-1">
        <label class="label" for="new_username">نام کاربری</label>
        <div style="display:flex;gap:.5rem;align-items:stretch">
          <input class="input" name="new_username" id="new_username" dir="ltr" pattern="[A-Za-z0-9._-]{3,32}" placeholder="مثلاً eshahabian" style="flex:1">
          <button type="button" class="btn btn-outline" id="suggest-username" title="پیشنهاد از روی نام">پیشنهاد</button>
        </div>
        <p class="muted" id="username-hint" style="margin:.4rem 0 0;font-size:.8rem;line-height:1.6"></p>
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

  <p id="sec-error" style="color:var(--danger);display:none;font-size:.9rem"></p>
  <button class="btn btn-primary" type="submit">ثبت نوبت (تأیید شده)</button>
</form>
<?php
$inner = ob_get_clean();

$pageScripts = '
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
  var newNameEl = document.getElementById("new_name");
  var newUserEl = document.getElementById("new_username");
  var newPassEl = document.getElementById("new_password");
  var newPhoneEl = document.getElementById("new_phone");
  var suggestBtn = document.getElementById("suggest-username");
  var usernameHint = document.getElementById("username-hint");
  var usernameTouched = false;

  function latinParts(name) {
    return String(name || "").toLowerCase().replace(/[^a-z\\s]/g, " ").trim().split(/\\s+/).filter(Boolean);
  }

  function baseUsernameFromName(name) {
    var parts = latinParts(name);
    if (!parts.length) return "";
    if (parts.length === 1) return parts[0].slice(0, 32);
    return (parts[0].charAt(0) + parts[parts.length - 1]).slice(0, 32);
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
    if (!force && usernameTouched && newUserEl.value.trim()) return;
    var base = baseUsernameFromName(newNameEl.value);
    if (!base) {
      usernameHint.textContent = "برای پیشنهاد، نام را به انگلیسی بنویسید (مثل Emad Shahabian).";
      return;
    }
    var suggested = uniqueUsername(base);
    if (!suggested) {
      usernameHint.textContent = "پیشنهاد معتبری پیدا نشد؛ نام کاربری را دستی وارد کنید.";
      return;
    }
    newUserEl.value = suggested;
    usernameHint.textContent = suggested === base
      ? "پیشنهاد: " + suggested
      : "پیشنهاد (بدون تکرار): " + suggested;
  }

  newUserEl.addEventListener("input", function(){ usernameTouched = true; });
  newNameEl.addEventListener("blur", function(){ applySuggestion(false); });
  newNameEl.addEventListener("change", function(){ applySuggestion(false); });
  suggestBtn.addEventListener("click", function(){
    usernameTouched = false;
    applySuggestion(true);
  });

  function clearExistingPatient() {
    if (patientEl.value) patientEl.value = "";
  }
  [newNameEl, newUserEl, newPassEl, newPhoneEl].forEach(function(el){
    el.addEventListener("input", clearExistingPatient);
  });
  patientEl.addEventListener("change", function(){
    if (!patientEl.value) return;
    newNameEl.value = "";
    newUserEl.value = "";
    newPassEl.value = "";
    newPhoneEl.value = "";
    usernameTouched = false;
    usernameHint.textContent = "";
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
          var b = document.createElement("button");
          b.type = "button";
          b.className = "slot-btn";
          b.textContent = s;
          b.onclick = function(){
            Array.prototype.forEach.call(slotsEl.querySelectorAll(".slot-btn"), function(x){ x.classList.remove("active"); });
            b.classList.add("active");
            timeEl.value = s;
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
    var newName = newNameEl.value.trim();
    var newUser = newUserEl.value.trim().toLowerCase();
    var newPass = newPassEl.value;
    if (!patientId && !newName) {
      e.preventDefault();
      errEl.textContent = "بیمار موجود را انتخاب کنید یا اطلاعات بیمار جدید را وارد کنید.";
      errEl.style.display = "block";
      return;
    }
    if (!patientId) {
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

render_secretary_page('رزرو برای بیمار', $inner);
