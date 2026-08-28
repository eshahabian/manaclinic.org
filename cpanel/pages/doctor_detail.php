<?php
declare(strict_types=1);

$id = (string) ($_GET['id'] ?? '');
$stmt = $pdo->prepare("
  SELECT dp.*, u.name, u.email
  FROM doctor_profiles dp
  JOIN users u ON u.id = dp.user_id
  WHERE dp.id = ? AND dp.is_active = 1
");
$stmt->execute([$id]);
$doctor = $stmt->fetch();
if (!$doctor) {
    http_response_code(404);
    $pageTitle = 'یافت نشد';
    require __DIR__ . '/404.php';
    exit;
}

$av = $pdo->prepare('SELECT date FROM availabilities WHERE doctor_id = ? ORDER BY date ASC');
$av->execute([$doctor['id']]);
$dates = array_column($av->fetchAll(), 'date');

$articles = $pdo->prepare('SELECT title, slug FROM articles WHERE author_id = ? AND published = 1 ORDER BY published_at DESC LIMIT 3');
$articles->execute([$doctor['user_id']]);
$articles = $articles->fetchAll();

$pageTitle = $doctor['name'];
ob_start();
?>
<div class="container-page section">
  <a href="<?= e(url('/doctors')) ?>" style="color:var(--primary);font-size:.9rem">← بازگشت به لیست</a>
  <div class="grid-2" style="margin-top:1.5rem;align-items:start">
    <div class="panel">
      <div style="display:flex;gap:1rem;align-items:start">
        <div class="avatar" style="width:80px;height:80px;font-size:1.5rem;margin:0"><?= e(mb_substr($doctor['name'], 0, 1)) ?></div>
        <div>
          <h1 style="margin:0"><?= e($doctor['name']) ?></h1>
          <p style="color:var(--primary);margin:.35rem 0 0"><?= e($doctor['specialty']) ?></p>
          <p style="font-weight:700;margin:.5rem 0 0">هزینه هر جلسه: <?= e(format_price((int)$doctor['session_price'])) ?></p>
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
      <p class="muted" style="margin:0">هزینه جلسه: <?= e(format_price((int)$doctor['session_price'])) ?></p>
      <div>
        <label class="label">انتخاب تاریخ</label>
        <select class="input" id="book-date">
          <option value="">انتخاب کنید</option>
          <?php foreach ($dates as $d): ?>
            <option value="<?= e($d) ?>"><?= e($d) ?></option>
          <?php endforeach; ?>
        </select>
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
$pageScripts = '<script>
(function(){
  var doctorId = ' . json_encode($doctor['id']) . ';
  var dateEl = document.getElementById("book-date");
  var slotsEl = document.getElementById("book-slots");
  var timeEl = document.getElementById("book-time");
  var errEl = document.getElementById("book-error");
  var loggedIn = ' . json_encode((bool) current_user()) . ';
  var isPatient = ' . json_encode((current_user()['role'] ?? '') === 'PATIENT') . ';
  var loginUrl = ' . json_encode(url('/login') . '?next=' . urlencode(url('/doctors/' . $doctor['id']))) . ';
  var slotsUrl = ' . json_encode(url('/api/slots')) . ';
  var bookUrl = ' . json_encode(url('/book')) . ';

  dateEl.addEventListener("change", function(){
    timeEl.value = "";
    slotsEl.innerHTML = "در حال بارگذاری...";
    if (!dateEl.value) { slotsEl.innerHTML = "<span class=\\"muted\\">ابتدا تاریخ را انتخاب کنید</span>"; return; }
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
  });

  document.getElementById("book-submit").onclick = function(){
    errEl.style.display = "none";
    if (!loggedIn) { location.href = loginUrl; return; }
    if (!isPatient) { errEl.textContent = "فقط بیماران می‌توانند نوبت رزرو کنند."; errEl.style.display="block"; return; }
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
require __DIR__ . '/../includes/layout.php';
