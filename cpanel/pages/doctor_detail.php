<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/booking_terms.php';

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
    $pageRobots = 'noindex,follow';
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
$approaches = doctor_profile_filter_keys(doctor_profile_json_list($doctor['approaches_json'] ?? ''), doctor_approach_options());
$domains = doctor_profile_filter_keys(doctor_profile_json_list($doctor['domains_json'] ?? ''), doctor_domain_options());
$focus = doctor_profile_filter_keys(doctor_profile_json_list($doctor['focus_json'] ?? ''), doctor_focus_options());
$degreeLabel = doctor_degree_label((string) ($doctor['degree'] ?? ''));
$expLabel = doctor_experience_label(isset($doctor['started_year']) ? (int) $doctor['started_year'] : null);
$courses = doctor_courses_list((string) ($doctor['courses'] ?? ''));
$domainLine = doctor_domains_line($doctor);
$bioExcerpt = trim((string) ($doctor['bio'] ?? ''));
if (function_exists('mb_substr') && function_exists('mb_strlen') && mb_strlen($bioExcerpt) > 160) {
    $bioExcerpt = mb_substr($bioExcerpt, 0, 157) . '…';
} elseif (strlen($bioExcerpt) > 160) {
    $bioExcerpt = substr($bioExcerpt, 0, 157) . '…';
}
$pageTitle = $doctor['name'] . ($degreeLabel !== '' ? ' — ' . $degreeLabel : ' — روانشناس');
$pageDescription = $bioExcerpt !== ''
    ? $bioExcerpt
    : ($doctor['name'] . '، روانشناس مانا کلینیک سعادت‌آباد' . ($domainLine !== '' ? ' — ' . $domainLine : '') . '. رزرو نوبت آنلاین.');
$pageCanonical = url('/doctors/' . $doctor['id']);
$pageKeywords = $doctor['name'] . ', روانشناس سعادت آباد, مانا کلینیک' . ($domainLine !== '' ? ', ' . $domainLine : '');
$pageImage = doctor_avatar_src($doctor['avatar_url'] ?? null) ?: null;
if ($pageImage) {
    $pageImage = seo_absolute_url($pageImage);
}
$pageOgType = 'profile';
$pageJsonLd = [
    '@type' => 'Person',
    'name' => $doctor['name'],
    'jobTitle' => $degreeLabel !== '' ? $degreeLabel : 'روانشناس',
    'url' => seo_absolute_url($pageCanonical),
    'worksFor' => ['@id' => seo_absolute_url('/') . '#clinic'],
];
if (!empty($pageImage)) {
    $pageJsonLd['image'] = $pageImage;
}
$knows = doctor_profile_labels($focus, doctor_focus_options());
if ($knows !== []) {
    $pageJsonLd['knowsAbout'] = $knows;
}
$pageJsonLd = [
    '@graph' => [
        $pageJsonLd,
        [
            '@type' => 'BreadcrumbList',
            'itemListElement' => [
                ['@type' => 'ListItem', 'position' => 1, 'name' => 'خانه', 'item' => seo_absolute_url('/')],
                ['@type' => 'ListItem', 'position' => 2, 'name' => 'متخصصان', 'item' => seo_absolute_url('/doctors')],
                ['@type' => 'ListItem', 'position' => 3, 'name' => $doctor['name'], 'item' => seo_absolute_url($pageCanonical)],
            ],
        ],
    ],
];
$pageHead = '
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@majidh1/jalalidatepicker/dist/jalalidatepicker.min.css">
';

ob_start();
?>
<div class="<?= $isPatientViewer ? 'section patient-panel-inner' : 'container-page section' ?>">
  <a href="<?= e(url('/doctors')) ?>" style="color:var(--primary);font-size:.9rem">← بازگشت به لیست</a>
  <div class="grid-2" style="margin-top:1.5rem;align-items:start">
    <div class="panel doctor-public-profile">
      <div class="doctor-public-hero">
        <?= doctor_photo_html($doctor, 'doctor-photo doctor-public-photo') ?>
        <div>
          <h1><?= e($doctor['name']) ?></h1>
          <?php if ($degreeLabel !== ''): ?>
            <p class="doctor-public-degree"><?= e($degreeLabel) ?></p>
          <?php endif; ?>
          <?php if (!empty($doctor['started_year'])): ?>
            <p class="doctor-public-exp"><?= e($expLabel) ?></p>
          <?php endif; ?>
        </div>
      </div>

      <?php if ($domains !== []): ?>
        <div class="doctor-public-block">
          <h2>حوزه درمان</h2>
          <?= doctor_chips_html($domains, doctor_domain_options()) ?>
        </div>
      <?php endif; ?>

      <?php if ($approaches !== []): ?>
        <div class="doctor-public-block">
          <h2>رویکرد درمانی</h2>
          <?= doctor_chips_html($approaches, doctor_approach_options()) ?>
        </div>
      <?php endif; ?>

      <?php if ($focus !== []): ?>
        <div class="doctor-public-block">
          <h2>زمینه تخصصی</h2>
          <?= doctor_chips_html($focus, doctor_focus_options()) ?>
        </div>
      <?php endif; ?>

      <?php if (trim((string) ($doctor['bio'] ?? '')) !== ''): ?>
        <div class="doctor-public-block">
          <h2>درباره من</h2>
          <div class="doctor-public-bio">
            <?php foreach (preg_split("/\n+/", (string) $doctor['bio']) as $line): ?>
              <?php if (trim($line) !== ''): ?>
                <p><?= e($line) ?></p>
              <?php endif; ?>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endif; ?>

      <?php if ($courses !== []): ?>
        <div class="doctor-public-block">
          <h2>دوره‌های تخصصی</h2>
          <ul class="doctor-public-courses">
            <?php foreach ($courses as $course): ?>
              <li><?= e($course) ?></li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>

      <?php if (trim((string) ($doctor['license_no'] ?? '')) !== ''): ?>
        <div class="doctor-public-block">
          <h2>پروانه نظام</h2>
          <p class="doctor-public-license" dir="ltr"><?= e((string) $doctor['license_no']) ?></p>
        </div>
      <?php endif; ?>

      <?php if ($articles): ?>
        <div class="doctor-public-block">
          <h2>مقالات این متخصص</h2>
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
      <p class="muted" style="margin:.35rem 0 0;font-size:.9rem;line-height:1.7">پس از رزرو، پرداخت از بخش «نوبت‌های من» انجام می‌شود.</p>
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
      <?= booking_terms_acceptance_html('terms-accept') ?>
      <button type="button" class="btn btn-primary" id="book-submit" disabled>رزرو نوبت</button>
    </div>
  </div>
</div>
<?= booking_terms_modal_html() ?>
<?= booking_terms_styles() ?>
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
          var value = typeof s === "string" ? s : (s.value || "");
          var label = typeof s === "string" ? value.replace(/:00$/, "") : (s.label || value.replace(/:00$/, ""));
          var dateLabel = typeof s === "object" && s.date_label ? s.date_label : "";
          var b = document.createElement("button");
          b.type = "button";
          b.className = "slot-btn";
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
            b.classList.add("active"); timeEl.value = value;
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
    if (!isPatient) { errEl.textContent = "فقط مراجعه‌کنندگان می‌توانند از این صفحه نوبت رزرو کنند. منشی از پنل منشی رزرو کند."; errEl.style.display="block"; return; }
    if (!dateEl.value || !timeEl.value) { errEl.textContent = "تاریخ و ساعت را انتخاب کنید."; errEl.style.display="block"; return; }
    var termsCb = document.getElementById("terms-accept");
    if (!termsCb || !termsCb.checked) { errEl.textContent = "لطفاً شرایط رزرو را بپذیرید."; errEl.style.display="block"; return; }
    var fd = new FormData();
    fd.append("doctorId", doctorId);
    fd.append("date", dateEl.value);
    fd.append("time", timeEl.value);
    fd.append("accept_terms", "1");
    fetch(bookUrl, { method: "POST", body: fd })
      .then(function(r){ return r.json().then(function(j){ return {ok:r.ok, j:j}; }); })
      .then(function(res){
        if (!res.ok) { errEl.textContent = res.j.error || "رزرو ناموفق بود"; errEl.style.display="block"; return; }
        if (res.j.paymentUrl) { location.href = res.j.paymentUrl; return; }
        location.href = ' . json_encode(url('/dashboard/appointments?booked=1')) . ';
      })
      .catch(function(){ errEl.textContent = "خطای شبکه"; errEl.style.display="block"; });
  };
})();
</script>' . booking_terms_script('terms-accept', '#book-submit');
$GLOBALS['pageHead'] = $pageHead;
$GLOBALS['pageScripts'] = $pageScripts;
$GLOBALS['pageDescription'] = $pageDescription;
$GLOBALS['pageCanonical'] = $pageCanonical;
$GLOBALS['pageKeywords'] = $pageKeywords;
$GLOBALS['pageOgType'] = $pageOgType;
$GLOBALS['pageJsonLd'] = $pageJsonLd;
if (!empty($pageImage)) {
    $GLOBALS['pageImage'] = $pageImage;
}
require_once __DIR__ . '/../includes/patient_panel.php';
finish_patient_or_public_page($pageTitle, $content);
