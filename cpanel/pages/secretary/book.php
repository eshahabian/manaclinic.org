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
    <div class="avail-date-chips" id="sec-date-chips"><span class="muted">ابتدا دکتر را انتخاب کنید</span></div>
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
<script src="' . e(url('/assets/js/ymd-cascade.js')) . '?v=20260910r"></script>
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
  function toFa(n){
    return String(n).replace(/[0-9]/g, function(d){ return "۰۱۲۳۴۵۶۷۸۹"[d]; })
      .replace(/[٠-٩]/g, function(d){ return "۰۱۲۳۴۵۶۷۸۹"["٠١٢٣٤٥٦٧٨٩".indexOf(d)]; });
  }
  function faToEn(str){
    var s = (window.manaFaToEn || function(x){ return String(x); })(str);
    return String(s).replace(/[۰-۹]/g, function(d){ return "۰۱۲۳۴۵۶۷۸۹".indexOf(d); })
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
    if (p.length !== 3) return toFa(ymd);
    var j = jalaali.toJalaali(parseInt(p[0],10), parseInt(p[1],10), parseInt(p[2],10));
    return toFa(j.jy + "/" + pad(j.jm) + "/" + pad(j.jd));
  }
  function escHtml(s){
    return String(s).replace(/[&<>"]/g, function(c){
      return { "&": "&amp;", "<": "&lt;", ">": "&gt;", "\"": "&quot;" }[c];
    });
  }
  function todayYmd(){
    var n = new Date();
    return n.getFullYear() + "-" + pad(n.getMonth() + 1) + "-" + pad(n.getDate());
  }
  var MONTH_NAMES = ["", "فروردین", "اردیبهشت", "خرداد", "تیر", "مرداد", "شهریور", "مهر", "آبان", "آذر", "دی", "بهمن", "اسفند"];
  var MONTH_TONES = [
    { cls: "binder-tab-in-person", tone: "in-person" },
    { cls: "binder-tab-online", tone: "online" },
    { cls: "binder-tab-offline", tone: "offline" },
    { cls: "binder-tab-archive", tone: "archive" },
    { cls: "binder-tab-new", tone: "new" }
  ];
  var WEEKDAYS = ["یکشنبه", "دوشنبه", "سه‌شنبه", "چهارشنبه", "پنجشنبه", "جمعه", "شنبه"];
  var lastChipsStructureKey = "";
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
      var label = toFa(typeof s === "string" ? value.replace(/:00$/, "") : (s.label || value.replace(/:00$/, "")));
      var dateLabel = toFa(typeof s === "object" && s.date_label ? s.date_label : "");
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

  function readChipsNav(){
    var root = chipsEl.querySelector("[data-ymd-cascade]");
    var yearSel = root ? root.querySelector("[data-ymd-select=year]") : null;
    var monthSel = root ? root.querySelector("[data-ymd-select=month]") : null;
    var daySel = root ? root.querySelector("[data-ymd-select=day]") : null;
    return {
      yearId: yearSel ? (yearSel.value || "") : "",
      monthId: monthSel ? (monthSel.value || "") : "",
      dayId: daySel ? (daySel.value || "") : ""
    };
  }
  function jalaliFromGregorian(g){
    var p = String(g).substring(0,10).split("-");
    if (p.length !== 3) return null;
    var gy = parseInt(p[0],10), gm = parseInt(p[1],10), gd = parseInt(p[2],10);
    if (!gy || !gm || !gd || !window.jalaali) return null;
    return jalaali.toJalaali(gy, gm, gd);
  }
  function idsForJalali(j){
    if (!j) return { yearId: "", monthId: "" };
    return {
      yearId: "y-" + j.jy,
      monthId: "m-" + j.jy + "-" + pad(j.jm)
    };
  }
  function currentJalali(){
    var now = new Date();
    if (!window.jalaali) return null;
    return jalaali.toJalaali(now.getFullYear(), now.getMonth() + 1, now.getDate());
  }
  function monthLength(jy, jm){
    if (window.jalaali && typeof jalaali.jalaaliMonthLength === "function") {
      return jalaali.jalaaliMonthLength(jy, jm);
    }
    if (jm <= 6) return 31;
    if (jm <= 11) return 30;
    return 29;
  }
  function groupDaysByYear(list){
    var byYear = {};
    list.forEach(function(g){
      g = String(g).substring(0,10);
      var j = jalaliFromGregorian(g);
      if (!j) return;
      if (!byYear[j.jy]) byYear[j.jy] = {};
      if (!byYear[j.jy][j.jm]) byYear[j.jy][j.jm] = {};
      var gp = g.split("-");
      var dt = new Date(parseInt(gp[0],10), parseInt(gp[1],10) - 1, parseInt(gp[2],10));
      byYear[j.jy][j.jm][g] = {
        g: g,
        jd: j.jd,
        weekday: WEEKDAYS[dt.getDay()] || "",
        isToday: g === todayYmd()
      };
    });
    var cur = currentJalali();
    var currentYear = cur ? cur.jy : 1405;
    var years = Object.keys(byYear).map(Number);
    years.push(currentYear);
    years.push(1410);
    years = years.filter(function(y){ return y > 0; });
    var minY = Math.min.apply(null, years);
    var maxY = Math.max(Math.max.apply(null, years), 1410);
    var out = [];
    for (var year = maxY; year >= minY; year--) {
      var months = [];
      for (var m = 1; m <= 12; m++) {
        var len = monthLength(year, m);
        var days = [];
        for (var d = 1; d <= len; d++) {
          var gdate = "";
          if (window.jalaali) {
            var g = jalaali.toGregorian(year, m, d);
            gdate = g.gy + "-" + pad(g.gm) + "-" + pad(g.gd);
          }
          var existing = byYear[year] && byYear[year][m] && byYear[year][m][gdate] ? byYear[year][m][gdate] : null;
          var dt = gdate ? new Date(gdate + "T12:00:00") : null;
          days.push(existing || {
            g: gdate,
            jd: d,
            weekday: dt ? (WEEKDAYS[dt.getDay()] || "") : "",
            isToday: gdate === todayYmd()
          });
        }
        months.push({
          id: "m-" + year + "-" + pad(m),
          month: m,
          label: MONTH_NAMES[m] || String(m),
          year: year,
          days: days
        });
      }
      out.push({
        id: "y-" + year,
        year: year,
        label: toFa(year),
        months: months
      });
    }
    return out;
  }
  function pickDefaultNav(years, selectedG){
    var ids = {};
    years.forEach(function(year){
      ids[year.id] = {};
      year.months.forEach(function(month){ ids[year.id][month.id] = month; });
    });
    function valid(yearId, monthId){
      return !!(yearId && monthId && ids[yearId] && ids[yearId][monthId]);
    }
    var cur = currentJalali();
    var fromToday = idsForJalali(cur);
    if (valid(fromToday.yearId, fromToday.monthId)) {
      return fromToday;
    }
    var fromDate = idsForJalali(jalaliFromGregorian(selectedG));
    if (valid(fromDate.yearId, fromDate.monthId)) return fromDate;
    var prev = readChipsNav();
    if (valid(prev.yearId, prev.monthId)) return { yearId: prev.yearId, monthId: prev.monthId };
    if (years[0] && years[0].months[0]) {
      return { yearId: years[0].id, monthId: years[0].months[0].id };
    }
    return { yearId: "", monthId: "" };
  }
  function findBinderNode(attr, id, root){
    var nodes = (root || chipsEl).querySelectorAll("[" + attr + "]");
    for (var i = 0; i < nodes.length; i++) {
      if (nodes[i].getAttribute(attr) === id) return nodes[i];
    }
    return null;
  }
  function revealDateInChips(g){
    var nav = idsForJalali(jalaliFromGregorian(g));
    var root = chipsEl.querySelector("[data-ymd-cascade]");
    if (!root || !nav.yearId) return;
    var yearSel = root.querySelector("[data-ymd-select=year]");
    var monthSel = root.querySelector("[data-ymd-select=month]");
    var daySel = root.querySelector("[data-ymd-select=day]");
    if (yearSel && nav.yearId && yearSel.value !== nav.yearId) {
      yearSel.value = nav.yearId;
      yearSel.dispatchEvent(new Event("change"));
    }
    if (monthSel && nav.monthId && monthSel.value !== nav.monthId) {
      monthSel.value = nav.monthId;
      monthSel.dispatchEvent(new Event("change"));
    }
    if (daySel && g) daySel.value = g;
  }
  function renderChips(){
    var list = availableList();
    if (!doctorEl.value) {
      lastChipsStructureKey = "";
      chipsEl.innerHTML = "<span class=\\"muted\\">ابتدا دکتر را انتخاب کنید</span>";
      return;
    }
    if (!list.length) {
      lastChipsStructureKey = doctorEl.value + "|";
      chipsEl.innerHTML = "<span class=\\"muted\\">برای این دکتر روز خالی آینده‌ای ثبت نشده</span>";
      return;
    }
    if (!window.jalaali || !jalaali.toJalaali) {
      chipsEl.innerHTML = "";
      list.forEach(function(g){
        var b = document.createElement("button");
        b.type = "button";
        b.className = "slot-btn" + (dateEl.value === g ? " active" : "");
        b.textContent = gregorianToJalaliText(g);
        b.setAttribute("data-avail-day", g);
        chipsEl.appendChild(b);
      });
      return;
    }
    var structureKey = doctorEl.value + "|" + list.join(",");
    if (structureKey === lastChipsStructureKey && chipsEl.querySelector("[data-ymd-cascade]")) {
      revealDateInChips(dateEl.value);
      return;
    }
    lastChipsStructureKey = structureKey;
    var years = groupDaysByYear(list);
    if (!years.length) {
      chipsEl.innerHTML = "<span class=\\"muted\\">برای این دکتر روز خالی آینده‌ای ثبت نشده</span>";
      return;
    }
    var nav = pickDefaultNav(years, dateEl.value);
    var html = "";
    html += "<div class=\\"ymd-cascade\\" data-ymd-cascade data-avail-year-root data-ymd-initial-year=\\"" + escHtml(nav.yearId) + "\\" data-ymd-initial-month=\\"" + escHtml(nav.monthId) + "\\" data-ymd-initial-day=\\"" + escHtml(dateEl.value || "") + "\\">";
    html += "<div class=\\"ymd-cascade-bar\\">";
    html += "<label class=\\"ymd-cascade-field\\">سال<select class=\\"input ymd-cascade-select\\" data-ymd-select=\\"year\\" aria-label=\\"سال\\">";
    years.forEach(function(year){
      html += "<option value=\\"" + escHtml(year.id) + "\\"" + (nav.yearId === year.id ? " selected" : "") + ">" + escHtml(year.label) + "</option>";
    });
    html += "</select></label>";
    html += "<label class=\\"ymd-cascade-field\\">ماه<select class=\\"input ymd-cascade-select\\" data-ymd-select=\\"month\\" aria-label=\\"ماه\\"></select></label>";
    html += "<label class=\\"ymd-cascade-field\\">روز<select class=\\"input ymd-cascade-select\\" data-ymd-select=\\"day\\" aria-label=\\"روز\\"></select></label>";
    html += "</div><div class=\\"ymd-cascade-body\\">";
    years.forEach(function(year){
      html += "<section data-ymd-year=\\"" + escHtml(year.id) + "\\" data-ymd-label=\\"" + escHtml(year.label) + "\\"" + (nav.yearId === year.id ? "" : " hidden") + ">";
      year.months.forEach(function(month){
        html += "<section data-ymd-month=\\"" + escHtml(month.id) + "\\" data-ymd-label=\\"" + escHtml(month.label) + "\\" hidden>";
        month.days.forEach(function(day){
          var lab = day.isToday ? "امروز" : toFa(day.jd);
          html += "<section data-ymd-day=\\"" + escHtml(day.g) + "\\" data-ymd-label=\\"" + escHtml(lab) + "\\" hidden></section>";
        });
        html += "</section>";
      });
      html += "</section>";
    });
    html += "</div></div>";
    chipsEl.innerHTML = html;
    if (window.initYmdCascade) window.initYmdCascade(chipsEl);
    var daySel = chipsEl.querySelector("[data-ymd-select=day]");
    if (daySel && !dateEl.value && daySel.value) {
      selectDate(daySel.value);
    } else if (dateEl.value) {
      revealDateInChips(dateEl.value);
    }
  }

  chipsEl.addEventListener("change", function(e){
    var sel = e.target && e.target.closest ? e.target.closest("[data-ymd-select=day]") : null;
    if (!sel || !chipsEl.contains(sel) || !sel.value) return;
    selectDate(sel.value);
  });
  chipsEl.addEventListener("click", function(e){
    var btn = e.target.closest("[data-avail-day]");
    if (!btn || !chipsEl.contains(btn)) return;
    selectDate(btn.getAttribute("data-avail-day"));
  });

  jalaliDatepicker.startWatch({
    selector: "#sec-date-view",
    time: false,
    hideAfterChange: true,
    autoReadOnlyInput: true,
    persianDigits: true,
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
    lastChipsStructureKey = "";
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
