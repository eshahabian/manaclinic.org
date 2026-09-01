<?php
declare(strict_types=1);

$id = (string) ($_GET['id'] ?? '');
$stmt = $pdo->prepare("
  SELECT dp.*, u.name, u.email
  FROM doctor_profiles dp
  JOIN users u ON u.id = dp.user_id
  WHERE dp.id = ? AND dp.is_active = 1 AND dp.is_approved = 1
");
$stmt->execute([$id]);
$doctor = $stmt->fetch();
if (!$doctor) {
    http_response_code(404);
    $pageTitle = 'یافت نشد';
    require __DIR__ . '/404.php';
    exit;
}

$av = $pdo->prepare('SELECT date FROM availabilities WHERE doctor_id = ? AND date >= CURDATE() ORDER BY date ASC');
$av->execute([$doctor['id']]);
$dates = array_column($av->fetchAll(), 'date');

$articles = $pdo->prepare('SELECT title, slug FROM articles WHERE author_id = ? AND published = 1 ORDER BY published_at DESC LIMIT 3');
$articles->execute([$doctor['user_id']]);
$articles = $articles->fetchAll();

$currentUser = current_user();
$isPatientViewer = $currentUser && ($currentUser['role'] ?? '') === 'PATIENT';
$pageTitle = $doctor['name'];
$pageHead = '
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@majidh1/jalalidatepicker/dist/jalalidatepicker.min.css">
';

ob_start();
?>
<div class="<?= $isPatientViewer ? 'section patient-panel-inner' : 'container-page section' ?>">
  <a href="<?= e(url('/doctors')) ?>" style="color:var(--primary);font-size:.9rem">← بازگشت به لیست</a>
  <div class="grid-2" style="margin-top:1.5rem;align-items:start">
    <div class="panel">
      <div style="display:flex;gap:1rem;align-items:start">
        <div class="avatar" style="width:80px;height:80px;font-size:1.5rem;margin:0"><?= e(mb_substr($doctor['name'], 0, 1)) ?></div>
        <div>
          <h1 style="margin:0"><?= e($doctor['name']) ?></h1>
          <p style="color:var(--primary);margin:.35rem 0 0"><?= e($doctor['specialty']) ?></p>
        </div>
      </div>
      <div class="muted whitespace-pre" style="margin-top:1.5rem;line-height:1.9">
        <?php foreach (preg_split("/\n+/", $doctor['bio']) as $line): ?>
          <p style="margin:.35rem 0"><?= e($line) ?></p>
        <?php endforeach; ?>
      </div>
      <?php if ($articles): ?>
        <div style="margin-top:2rem;border-top:1px solid var(--line);padding-top:1.5rem">
          <h2 style="font-size:1.1rem">مقالات این متخصص</h2>
          <ul class="stack">
            <?php foreach ($articles as $a): ?>
              <li><a href="<?= e(url('/articles/' . $a['slug'])) ?>" style="color:var(--primary)"><?= e($a['title']) ?></a></li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>
    </div>

    <div class="panel stack" id="booking-box">
      <h2 style="margin:0">رزرو نوبت آنلاین</h2>
      <div>
        <label class="label" for="book-date-view">انتخاب تاریخ</label>
        <input
          class="input"
          id="book-date-view"
          type="text"
          placeholder="تاریخ را انتخاب کنید"
          data-jdp
          data-jdp-only-date
          autocomplete="off"
          readonly
        >
        <input type="hidden" id="book-date" value="">
        <p class="muted" style="font-size:.8rem;margin:.4rem 0 0">فقط روزهای خالی دکتر قابل انتخاب هستند.</p>
      </div>
      <div>
        <label class="label">ساعت‌های خالی</label>
        <div class="slots" id="book-slots"><span class="muted">ابتدا تاریخ را انتخاب کنید</span></div>
      </div>
      <input type="hidden" id="book-time" value="">
      <p id="book-error" style="color:var(--danger);font-size:.9rem;display:none"></p>
      <button type="button" class="btn btn-primary" id="book-submit">رزرو و پرداخت</button>
    </div>
  </div>
</div>
<?php
$content = ob_get_clean();
$pageScripts = '
<script src="https://cdn.jsdelivr.net/npm/jalaali-js@1.2.7/dist/jalaali.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/@majidh1/jalalidatepicker/dist/jalalidatepicker.min.js"></script>
<script>
(function(){
  var doctorId = ' . json_encode($doctor['id']) . ';
  var available = ' . json_encode(array_values($dates), JSON_UNESCAPED_UNICODE) . ';
  var availableSet = {};
  available.forEach(function(d){ availableSet[d] = true; });

  var dateView = document.getElementById("book-date-view");
  var dateEl = document.getElementById("book-date");
  var slotsEl = document.getElementById("book-slots");
  var timeEl = document.getElementById("book-time");
  var errEl = document.getElementById("book-error");
  var loggedIn = ' . json_encode((bool) $currentUser) . ';
  var isPatient = ' . json_encode(($currentUser['role'] ?? '') === 'PATIENT') . ';
  var loginUrl = ' . json_encode(url('/login') . '?next=' . urlencode(url('/doctors/' . $doctor['id']))) . ';
  var slotsUrl = ' . json_encode(url('/api/slots')) . ';
  var bookUrl = ' . json_encode(url('/book')) . ';

  function faToEn(str){
    return String(str).replace(/[۰-۹]/g, function(d){ return "۰۱۲۳۴۵۶۷۸۹".indexOf(d); });
  }
  function pad(n){ return (n < 10 ? "0" : "") + n; }
  function jalaliTextToGregorian(text){
    var t = faToEn(text).replace(/-/g, "/").trim();
    var p = t.split("/");
    if (p.length !== 3) return "";
    var jy = parseInt(p[0], 10), jm = parseInt(p[1], 10), jd = parseInt(p[2], 10);
    if (!jy || !jm || !jd) return "";
    var g = jalaali.toGregorian(jy, jm, jd);
    return g.gy + "-" + pad(g.gm) + "-" + pad(g.gd);
  }
  function gregorianToJalaliText(ymd){
    var p = ymd.split("-");
    if (p.length !== 3) return "";
    var j = jalaali.toJalaali(parseInt(p[0],10), parseInt(p[1],10), parseInt(p[2],10));
    return j.jy + "/" + pad(j.jm) + "/" + pad(j.jd);
  }

  function loadSlots(){
    timeEl.value = "";
    slotsEl.innerHTML = "در حال بارگذاری...";
    if (!dateEl.value) {
      slotsEl.innerHTML = "<span class=\\"muted\\">ابتدا تاریخ را انتخاب کنید</span>";
      return;
    }
    fetch(slotsUrl + "?doctorId=" + encodeURIComponent(doctorId) + "&date=" + encodeURIComponent(dateEl.value))
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

  function onDatePicked(){
    var g = jalaliTextToGregorian(dateView.value);
    errEl.style.display = "none";
    if (!g || !availableSet[g]) {
      dateEl.value = "";
      dateView.value = "";
      errEl.textContent = "این تاریخ در روزهای خالی دکتر نیست.";
      errEl.style.display = "block";
      slotsEl.innerHTML = "<span class=\\"muted\\">ابتدا تاریخ را انتخاب کنید</span>";
      return;
    }
    dateEl.value = g;
    loadSlots();
  }

  jalaliDatepicker.startWatch({
    selector: "#book-date-view",
    time: false,
    hideAfterChange: true,
    showTodayBtn: false,
    showEmptyBtn: true,
    autoReadOnlyInput: true,
    zIndex: 100000,
    container: "body"
  });

  dateView.addEventListener("jdp:change", onDatePicked);
  dateView.addEventListener("change", onDatePicked);

  document.getElementById("book-submit").onclick = function(){
    errEl.style.display = "none";
    if (!loggedIn) { location.href = loginUrl; return; }
    if (!isPatient) { errEl.textContent = "فقط مراجعان می‌توانند از این صفحه نوبت رزرو کنند. منشی از پنل منشی رزرو کند."; errEl.style.display="block"; return; }
    if (!dateEl.value || !timeEl.value) { errEl.textContent = "تاریخ و ساعت را انتخاب کنید."; errEl.style.display="block"; return; }
    var fd = new FormData();
    fd.append("doctorId", doctorId);
    fd.append("date", dateEl.value);
    fd.append("time", timeEl.value);
    fetch(bookUrl, { method: "POST", body: fd })
      .then(function(r){ return r.json().then(function(j){ return {ok:r.ok, j:j}; }); })
      .then(function(res){
        if (!res.ok) { errEl.textContent = res.j.error || "رزرو ناموفق بود"; errEl.style.display="block"; return; }
        if (res.j.paymentUrl) location.href = res.j.paymentUrl;
        else location.href = ' . json_encode(url('/dashboard/appointments')) . ';
      })
      .catch(function(){ errEl.textContent = "خطای شبکه"; errEl.style.display="block"; });
  };
})();
</script>';
$GLOBALS['pageHead'] = $pageHead;
$GLOBALS['pageScripts'] = $pageScripts;
require_once __DIR__ . '/../includes/patient_panel.php';
finish_patient_or_public_page($pageTitle, $content);
