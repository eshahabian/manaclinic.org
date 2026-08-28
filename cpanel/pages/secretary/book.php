<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/secretary_panel.php';
require_login(['SECRETARY']);

$patients = $pdo->query("SELECT id, name, email, phone FROM users WHERE role='PATIENT' ORDER BY name ASC")->fetchAll();
$doctors = $pdo->query("
  SELECT dp.id, u.name, dp.specialty
  FROM doctor_profiles dp
  JOIN users u ON u.id = dp.user_id
  WHERE dp.is_active = 1
  ORDER BY u.name ASC
")->fetchAll();

$availByDoctor = [];
$stmt = $pdo->query("SELECT doctor_id, date FROM availabilities WHERE date >= CURDATE() ORDER BY date ASC");
foreach ($stmt->fetchAll() as $row) {
    $availByDoctor[$row['doctor_id']][] = $row['date'];
}

ob_start();
?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@majidh1/jalalidatepicker/dist/jalalidatepicker.min.css">
<h1>رزرو نوبت برای بیمار</h1>
<p class="muted">بیمار را انتخاب کنید یا بیمار جدید بسازید، سپس تاریخ و ساعت خالی را مشخص کنید.</p>

<form class="panel form-stack" method="post" action="<?= e(url('/secretary/book')) ?>" id="secretary-book-form" style="margin-top:1rem;max-width:44rem">
  <div>
    <label class="label">بیمار موجود</label>
    <select class="input" name="patient_id" id="patient_id">
      <option value="">— انتخاب بیمار —</option>
      <?php foreach ($patients as $p): ?>
        <option value="<?= e($p['id']) ?>"><?= e($p['name']) ?> (<?= e($p['email']) ?>)</option>
      <?php endforeach; ?>
    </select>
  </div>

  <div class="panel" style="background:var(--bg-soft);border-style:dashed">
    <p style="margin:0 0 .75rem;font-weight:600">یا بیمار جدید</p>
    <div class="grid-2">
      <div><label class="label">نام</label><input class="input" name="new_name" id="new_name"></div>
      <div><label class="label">موبایل</label><input class="input" name="new_phone" id="new_phone" dir="ltr"></div>
      <div style="grid-column:1/-1"><label class="label">ایمیل</label><input class="input" name="new_email" id="new_email" type="email" dir="ltr" placeholder="اختیاری — اگر خالی باشد خودکار ساخته می‌شود"></div>
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
    <label class="label" for="sec-date-view">انتخاب تاریخ</label>
    <input class="input" type="text" id="sec-date-view" data-jdp data-jdp-only-date autocomplete="off" readonly placeholder="تاریخ شمسی">
    <input type="hidden" name="date" id="sec-date" required>
  </div>

  <div>
    <label class="label">ساعت</label>
    <div class="slots" id="sec-slots"><span class="muted">ابتدا دکتر و تاریخ را انتخاب کنید</span></div>
    <input type="hidden" name="time" id="sec-time" required>
  </div>

  <div>
    <label class="label">یادداشت (اختیاری)</label>
    <textarea class="input" name="notes" rows="3"></textarea>
  </div>

  <p id="sec-error" style="color:var(--danger);display:none;font-size:.9rem"></p>
  <button class="btn btn-primary" type="submit">ثبت نوبت (تأیید شده)</button>
</form>

<script src="https://cdn.jsdelivr.net/npm/jalaali-js@1.2.7/dist/jalaali.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/@majidh1/jalalidatepicker/dist/jalalidatepicker.min.js"></script>
<script>
(function(){
  var availByDoctor = <?= json_encode($availByDoctor, JSON_UNESCAPED_UNICODE) ?>;
  var doctorEl = document.getElementById("doctor_id");
  var dateView = document.getElementById("sec-date-view");
  var dateEl = document.getElementById("sec-date");
  var slotsEl = document.getElementById("sec-slots");
  var timeEl = document.getElementById("sec-time");
  var errEl = document.getElementById("sec-error");
  var slotsUrl = <?= json_encode(url('/api/slots')) ?>;

  function faToEn(str){ return String(str).replace(/[۰-۹]/g, function(d){ return "۰۱۲۳۴۵۶۷۸۹".indexOf(d); }); }
  function pad(n){ return (n < 10 ? "0" : "") + n; }
  function jalaliToGregorian(text){
    var t = faToEn(text).replace(/-/g,"/").trim();
    var p = t.split("/");
    if (p.length !== 3) return "";
    var g = jalaali.toGregorian(parseInt(p[0],10), parseInt(p[1],10), parseInt(p[2],10));
    return g.gy + "-" + pad(g.gm) + "-" + pad(g.gd);
  }
  function currentAvailableSet(){
    var id = doctorEl.value;
    var list = availByDoctor[id] || [];
    var set = {};
    list.forEach(function(d){ set[d] = true; });
    return set;
  }
  function loadSlots(){
    timeEl.value = "";
    if (!doctorEl.value || !dateEl.value) {
      slotsEl.innerHTML = "<span class=\\"muted\\">ابتدا دکتر و تاریخ را انتخاب کنید</span>";
      return;
    }
    slotsEl.innerHTML = "در حال بارگذاری...";
    fetch(slotsUrl + "?doctorId=" + encodeURIComponent(doctorEl.value) + "&date=" + encodeURIComponent(dateEl.value))
      .then(function(r){ return r.json(); })
      .then(function(data){
        var slots = data.slots || [];
        if (!slots.length) { slotsEl.innerHTML = "<span class=\\"muted\\">ساعت خالی نیست</span>"; return; }
        slotsEl.innerHTML = "";
        slots.forEach(function(s){
          var b = document.createElement("button");
          b.type = "button"; b.className = "slot-btn"; b.textContent = s;
          b.onclick = function(){
            Array.prototype.forEach.call(slotsEl.querySelectorAll(".slot-btn"), function(x){ x.classList.remove("active"); });
            b.classList.add("active"); timeEl.value = s;
          };
          slotsEl.appendChild(b);
        });
      });
  }
  function onDate(){
    var g = jalaliToGregorian(dateView.value);
    var set = currentAvailableSet();
    errEl.style.display = "none";
    if (!g || !set[g]) {
      dateEl.value = "";
      dateView.value = "";
      errEl.textContent = "این تاریخ برای دکتر انتخاب‌شده خالی نیست.";
      errEl.style.display = "block";
      loadSlots();
      return;
    }
    dateEl.value = g;
    loadSlots();
  }

  jalaliDatepicker.startWatch({
    selector: "#sec-date-view",
    time: false,
    hideAfterChange: true,
    autoReadOnlyInput: true,
    zIndex: 99999
  });
  dateView.addEventListener("jdp:change", onDate);
  dateView.addEventListener("change", onDate);
  doctorEl.addEventListener("change", function(){
    dateView.value = "";
    dateEl.value = "";
    loadSlots();
  });

  document.getElementById("secretary-book-form").addEventListener("submit", function(e){
    var patientId = document.getElementById("patient_id").value;
    var newName = document.getElementById("new_name").value.trim();
    if (!patientId && !newName) {
      e.preventDefault();
      errEl.textContent = "بیمار موجود را انتخاب کنید یا نام بیمار جدید را وارد کنید.";
      errEl.style.display = "block";
      return;
    }
    if (!dateEl.value || !timeEl.value) {
      e.preventDefault();
      errEl.textContent = "تاریخ و ساعت را انتخاب کنید.";
      errEl.style.display = "block";
    }
  });
})();
</script>
<?php
render_secretary_page('رزرو برای بیمار', ob_get_clean());
