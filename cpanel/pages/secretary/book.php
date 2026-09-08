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
    var halfTab = chipsEl.querySelector("[data-avail-half-root] > .binder-tabs > .is-active");
    var monthTab = chipsEl.querySelector("[data-avail-half-root] > .binder-body > .binder-panel.is-active [data-avail-month-root] > .binder-tabs > .is-active");
    return {
      halfId: halfTab ? (halfTab.getAttribute("data-binder-tab") || "") : "",
      monthId: monthTab ? (monthTab.getAttribute("data-binder-tab") || "") : ""
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
    if (!j) return { halfId: "", monthId: "" };
    return {
      halfId: "h-" + j.jy + "-" + (j.jm <= 6 ? 1 : 2),
      monthId: "m-" + j.jy + "-" + pad(j.jm)
    };
  }
  function groupDaysByHalf(list){
    var byYear = {};
    list.forEach(function(g){
      g = String(g).substring(0,10);
      var j = jalaliFromGregorian(g);
      if (!j) return;
      var half = j.jm <= 6 ? 1 : 2;
      if (!byYear[j.jy]) byYear[j.jy] = { 1: {}, 2: {} };
      if (!byYear[j.jy][half][j.jm]) byYear[j.jy][half][j.jm] = [];
      var gp = g.split("-");
      var dt = new Date(parseInt(gp[0],10), parseInt(gp[1],10) - 1, parseInt(gp[2],10));
      byYear[j.jy][half][j.jm].push({
        g: g,
        jd: j.jd,
        weekday: WEEKDAYS[dt.getDay()] || "",
        isToday: g === todayYmd()
      });
    });
    var years = Object.keys(byYear).map(Number).sort(function(a,b){ return a - b; });
    var yearCount = years.length;
    var halves = [];
    years.forEach(function(year){
      [1, 2].forEach(function(half){
        var from = half === 1 ? 1 : 7;
        var to = half === 1 ? 6 : 12;
        var months = [];
        var presentCount = 0;
        for (var m = from; m <= to; m++) {
          var days = (byYear[year][half][m] || []).slice().sort(function(a,b){
            return a.g < b.g ? -1 : a.g > b.g ? 1 : 0;
          });
          presentCount += days.length;
          var tone = MONTH_TONES[(m - 1) % MONTH_TONES.length];
          months.push({
            id: "m-" + year + "-" + pad(m),
            month: m,
            label: MONTH_NAMES[m] || String(m),
            year: year,
            days: days,
            cls: tone.cls,
            tone: tone.tone
          });
        }
        halves.push({
          id: "h-" + year + "-" + half,
          year: year,
          half: half,
          label: (half === 1 ? "شش ماه اول سال" : "شش ماه دوم سال") + (yearCount > 1 ? " " + toFa(year) : ""),
          cls: half === 1 ? "binder-tab-online" : "binder-tab-offline",
          tone: half === 1 ? "online" : "offline",
          months: months,
          presentCount: presentCount
        });
      });
    });
    return halves;
  }
  function pickDefaultNav(halves, selectedG){
    var ids = {};
    halves.forEach(function(half){
      ids[half.id] = {};
      half.months.forEach(function(month){ ids[half.id][month.id] = month; });
    });
    function valid(halfId, monthId){
      return !!(halfId && monthId && ids[halfId] && ids[halfId][monthId]);
    }
    var fromDate = idsForJalali(jalaliFromGregorian(selectedG));
    if (valid(fromDate.halfId, fromDate.monthId)) return fromDate;
    var prev = readChipsNav();
    if (valid(prev.halfId, prev.monthId)) return prev;
    var now = new Date();
    var todayJ = window.jalaali ? jalaali.toJalaali(now.getFullYear(), now.getMonth() + 1, now.getDate()) : null;
    var fromToday = idsForJalali(todayJ);
    if (valid(fromToday.halfId, fromToday.monthId) && ids[fromToday.halfId][fromToday.monthId].days.length) {
      return fromToday;
    }
    for (var i = 0; i < halves.length; i++) {
      var half = halves[i];
      for (var k = 0; k < half.months.length; k++) {
        if (half.months[k].days.length) {
          return { halfId: half.id, monthId: half.months[k].id };
        }
      }
    }
    if (halves[0] && halves[0].months[0]) {
      return { halfId: halves[0].id, monthId: halves[0].months[0].id };
    }
    return { halfId: "", monthId: "" };
  }
  function updateActiveDayChip(){
    var selected = dateEl.value;
    Array.prototype.forEach.call(chipsEl.querySelectorAll("[data-avail-day]"), function(b){
      b.classList.toggle("active", b.getAttribute("data-avail-day") === selected);
    });
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
    if (!nav.halfId) {
      updateActiveDayChip();
      return;
    }
    var halfRoot = chipsEl.querySelector("[data-avail-half-root]");
    var halfTabs = halfRoot ? halfRoot.querySelector(":scope > .binder-tabs") : null;
    var halfTab = findBinderNode("data-binder-tab", nav.halfId, halfTabs);
    if (halfTab) halfTab.click();
    var halfPanel = findBinderNode("data-binder-panel", nav.halfId, halfRoot);
    var monthTab = findBinderNode("data-binder-tab", nav.monthId, halfPanel);
    if (monthTab) monthTab.click();
    updateActiveDayChip();
  }
  function renderDayButtons(days){
    if (!days.length) {
      return "<p class=\\"muted\\" style=\\"margin:0\\">در این ماه روز خالی ثبت نشده.</p>";
    }
    var html = "<div class=\\"slots avail-date-days\\">";
    days.forEach(function(day){
      var active = dateEl.value === day.g ? " active" : "";
      var title = escHtml(gregorianToJalaliText(day.g));
      html += "<button type=\\"button\\" class=\\"slot-btn" + active + "\\" data-avail-day=\\"" + escHtml(day.g) + "\\" title=\\"" + title + "\\">";
      html += "<span class=\\"hour-chip-time\\">" + (day.isToday ? "امروز" : escHtml(toFa(day.jd))) + "</span>";
      html += "<span class=\\"hour-chip-date\\">" + escHtml(day.isToday ? toFa(day.jd) : day.weekday) + "</span>";
      html += "</button>";
    });
    html += "</div>";
    return html;
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
    if (structureKey === lastChipsStructureKey && chipsEl.querySelector("[data-avail-half-root]")) {
      revealDateInChips(dateEl.value);
      return;
    }
    lastChipsStructureKey = structureKey;
    var halves = groupDaysByHalf(list);
    if (!halves.length) {
      chipsEl.innerHTML = "<span class=\\"muted\\">برای این دکتر روز خالی آینده‌ای ثبت نشده</span>";
      return;
    }
    var nav = pickDefaultNav(halves, dateEl.value);
    var html = "";
    html += "<div class=\\"binder-tile binder-tile--nested\\" data-avail-half-root data-binder-tabs data-binder-hash=\\"0\\" data-binder-initial=\\"" + escHtml(nav.halfId) + "\\" data-binder-tone=\\"online\\">";
    html += "<div class=\\"binder-tabs\\" role=\\"tablist\\" aria-label=\\"شش‌ماه سال\\">";
    halves.forEach(function(half){
      var on = nav.halfId === half.id;
      html += "<button type=\\"button\\" class=\\"binder-tab " + half.cls + (on ? " is-active" : "") + "\\" role=\\"tab\\" data-binder-tab=\\"" + escHtml(half.id) + "\\" data-binder-tone=\\"" + half.tone + "\\" aria-selected=\\"" + (on ? "true" : "false") + "\\">";
      html += escHtml(half.label);
      if (half.presentCount > 0) html += "<span class=\\"binder-tab-count\\">" + escHtml(toFa(half.presentCount)) + "</span>";
      html += "</button>";
    });
    html += "</div><div class=\\"binder-body\\">";
    halves.forEach(function(half){
      var onHalf = nav.halfId === half.id;
      var monthInitial = nav.halfId === half.id ? nav.monthId : (half.months[0] ? half.months[0].id : "");
      if (nav.halfId === half.id) {
        var hasMonth = half.months.some(function(m){ return m.id === monthInitial; });
        if (!hasMonth && half.months[0]) monthInitial = half.months[0].id;
      } else {
        var firstWithDays = half.months.filter(function(m){ return m.days.length; })[0];
        monthInitial = (firstWithDays || half.months[0] || {}).id || "";
      }
      html += "<section class=\\"binder-panel" + (onHalf ? " is-active" : "") + "\\" data-binder-panel=\\"" + escHtml(half.id) + "\\" role=\\"tabpanel\\"" + (onHalf ? "" : " hidden") + ">";
      html += "<div class=\\"binder-tile binder-tile--nested avail-date-month-tabs\\" data-avail-month-root data-binder-tabs data-binder-hash=\\"0\\" data-binder-initial=\\"" + escHtml(monthInitial) + "\\" data-binder-tone=\\"" + half.tone + "\\">";
      html += "<div class=\\"binder-tabs\\" role=\\"tablist\\" aria-label=\\"ماه\\">";
      half.months.forEach(function(month){
        var on = monthInitial === month.id;
        html += "<button type=\\"button\\" class=\\"binder-tab " + month.cls + (on ? " is-active" : "") + "\\" role=\\"tab\\" data-binder-tab=\\"" + escHtml(month.id) + "\\" data-binder-tone=\\"" + month.tone + "\\" aria-selected=\\"" + (on ? "true" : "false") + "\\">";
        html += escHtml(month.label);
        if (month.days.length) html += "<span class=\\"binder-tab-count\\">" + escHtml(toFa(month.days.length)) + "</span>";
        html += "</button>";
      });
      html += "</div><div class=\\"binder-body\\">";
      half.months.forEach(function(month){
        var on = monthInitial === month.id;
        html += "<section class=\\"binder-panel" + (on ? " is-active" : "") + "\\" data-binder-panel=\\"" + escHtml(month.id) + "\\" role=\\"tabpanel\\"" + (on ? "" : " hidden") + ">";
        html += "<h3 class=\\"avail-date-month-heading\\">" + escHtml(month.label);
        html += " <span class=\\"muted\\">" + escHtml(toFa(month.year)) + " · " + escHtml(toFa(month.days.length)) + " روز</span></h3>";
        html += renderDayButtons(month.days);
        html += "</section>";
      });
      html += "</div></div></section>";
    });
    html += "</div></div>";
    chipsEl.innerHTML = html;
    if (window.initBinderTabs) window.initBinderTabs(chipsEl);
  }

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
