<?php
declare(strict_types=1);
if (empty($secretaryBookEmbedded)) {
    require_once __DIR__ . '/../../includes/secretary_panel.php';
    require_login(['SECRETARY']);
    redirect('/secretary/appointments?tab=new');
}

$patients = secretary_patient_directory($pdo);
if (!function_exists('patient_fee_ensure_schema')) {
    require_once __DIR__ . '/../../includes/patient_fee.php';
}
patient_fee_ensure_schema($pdo);
$walletMap = [];
try {
    require_once __DIR__ . '/../../includes/wallet.php';
    wallet_ensure_patient_wallets($pdo);
    $walletMap = wallet_balance_map($pdo);
} catch (Throwable $e) {
    $walletMap = [];
}
$doctors = secretary_active_doctors($pdo);
$preDoctorId = trim((string) ($_GET['doctor_id'] ?? ''));
$preDate = trim((string) ($_GET['date'] ?? ''));
$preTime = trim((string) ($_GET['time'] ?? ''));
if ($preDoctorId !== '' && !array_filter($doctors, static fn(array $d): bool => (string) ($d['id'] ?? '') === $preDoctorId)) {
    $preDoctorId = '';
}
if ($preDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $preDate)) {
    $preDate = '';
}
if (preg_match('/^(\d{1,2}):(\d{2})/', $preTime, $timeMatch)) {
    $preTime = sprintf('%02d:%02d', (int) $timeMatch[1], (int) $timeMatch[2]);
} else {
    $preTime = '';
}
$preDateJalali = '';
if ($preDate !== '') {
    [$gy, $gm, $gd] = array_map('intval', explode('-', $preDate));
    if ($gy > 0 && $gm > 0 && $gd > 0) {
        [$jy, $jm, $jd] = gregorian_to_jalali($gy, $gm, $gd);
        $preDateJalali = $jy . '/' . sprintf('%02d', $jm) . '/' . sprintf('%02d', $jd);
    }
}
$slotPrefillActive = $preDoctorId !== '' && ($preDate !== '' || $preTime !== '');

require_once __DIR__ . '/../../includes/availability.php';
ensure_availability_schema($pdo);

$availByDoctor = [];
$stmt = $pdo->prepare("
  SELECT doctor_id, DATE_FORMAT(`date`, '%Y-%m-%d') AS d, available_hours
  FROM availabilities
  WHERE `date` >= CURDATE() AND `date` <= ?
  ORDER BY `date` ASC
");
$stmt->execute([appointment_booking_horizon_end()]);
foreach ($stmt->fetchAll() as $row) {
    $d = (string) ($row['d'] ?? '');
    if ($d === '') {
        continue;
    }
    if (appointment_hours_decode((string) ($row['available_hours'] ?? '')) === []) {
        continue;
    }
    if (!function_exists('therapist_date_is_closed')) {
        require_once __DIR__ . '/../../includes/therapist_presence.php';
    }
    if (therapist_date_is_closed($pdo, (string) $row['doctor_id'], $d)) {
        continue;
    }
    $availByDoctor[(string) $row['doctor_id']][] = $d;
}

ob_start();
?>
<p class="muted" style="margin:0 0 1rem">ابتدا دکتر را انتخاب کنید؛ فقط روزهایی که دکتر وقت خالی گذاشته قابل انتخاب هستند.</p>

<form class="panel form-stack" method="post" action="<?= e(url((!empty($secretaryBookNext) && is_string($secretaryBookNext) && str_starts_with($secretaryBookNext, '/app')) ? $secretaryBookNext : '/secretary/book')) ?>" id="secretary-book-form" style="margin-top:0" enctype="multipart/form-data">
  <?= csrf_field() ?>
  <?php if (!empty($secretaryBookNext) && is_string($secretaryBookNext) && str_starts_with($secretaryBookNext, '/app')): ?>
    <input type="hidden" name="next" value="<?= e($secretaryBookNext) ?>">
  <?php endif; ?>
  <?php if ($slotPrefillActive): ?>
    <p class="muted" style="margin:0 0 1rem;font-size:.9rem">
      از جدول هفت‌روزه انتخاب شد<?= $preDateJalali !== '' ? ' · تاریخ ' . e(to_fa_digits($preDateJalali)) : '' ?><?= $preTime !== '' ? ' · ساعت ' . e(to_fa_digits($preTime)) : '' ?>.
      فقط مراجعه‌کننده را انتخاب کنید.
    </p>
  <?php endif; ?>
  <div>
    <label class="label" for="patient_id">مراجعه‌کننده</label>
    <select class="input" name="patient_id" id="patient_id">
      <option value="">— انتخاب مراجعه‌کننده —</option>
      <?php foreach ($patients as $p): ?>
        <?php
          $phone = trim((string) ($p['phone'] ?? ''));
          $uname = trim((string) ($p['username'] ?? ''));
          $pname = trim((string) ($p['name'] ?? ''));
          $doc = trim((string) ($p['doctor_name'] ?? ''));
          $label = $pname;
          if ($uname !== '') {
              $label .= ' (' . $uname . ')';
          }
          if ($phone !== '') {
              $label .= ' · ' . $phone;
          }
          if ($doc !== '') {
              $label .= ' — ' . $doc;
          }
          $searchBits = trim($pname . ' ' . $uname . ' ' . $phone . ' ' . $doc);
          $savedReferral = function_exists('user_referral_normalize')
              ? (user_referral_normalize((string) ($p['referral_source'] ?? '')) ?? '')
              : '';
          $bookKey = (string) ($p['book_id'] ?? $p['id'] ?? '');
          $walletAttr = array_key_exists($bookKey, $walletMap) ? (string) (int) $walletMap[$bookKey] : '';
          $customFeeOn = (int) ($p['custom_fee_on'] ?? 0) === 1;
          $customFeeToman = (int) ($p['custom_fee_toman'] ?? 0);
          $feeAttr = ($customFeeOn && $customFeeToman > 0) ? (string) $customFeeToman : '';
          $inactive = function_exists('secretary_patient_is_inactive') && secretary_patient_is_inactive($p);
          $needsSetup = !$inactive && function_exists('secretary_patient_needs_setup') && secretary_patient_needs_setup($p);
          if ($inactive) {
              $label .= ' — غیرفعال';
          } elseif ($needsSetup) {
              $label .= ' — تکمیل پروفایل لازم است';
          }
        ?>
        <option value="<?= e((string) ($p['book_id'] ?? $p['id'])) ?>" data-search="<?= e($searchBits) ?>" data-referral="<?= e($savedReferral) ?>" data-needs-setup="<?= $needsSetup ? '1' : '0' ?>" data-inactive="<?= $inactive ? '1' : '0' ?>" data-muted="<?= $inactive ? '1' : '0' ?>" data-phone="<?= e($phone) ?>" data-doctor="<?= e((string) ($p['preferred_doctor_id'] ?? '')) ?>" data-wallet="<?= e($walletAttr) ?>" data-fee="<?= e($feeAttr) ?>"><?= e($label) ?></option>
      <?php endforeach; ?>
    </select>
    <p class="muted" style="margin:.35rem 0 0;font-size:.8rem">در باکس بالا نام، نام کاربری یا شماره موبایل را تایپ کنید.</p>
    <div id="legacy-patient-setup" class="panel" hidden style="margin-top:.75rem;background:#fff8e8;border-color:#e6c56a">
      <p style="margin:0 0 .7rem;font-weight:700">این کاربر قدیمی و غیرفعال است یا پروفایل کامل ندارد. تا فعال نشود و این موارد پر نشود، نوبت ثبت نمی‌شود.</p>
      <label>
        <span class="label">موبایل</span>
        <input class="input" name="legacy_phone" id="legacy-phone" dir="ltr" inputmode="tel" autocomplete="tel">
      </label>
      <label style="display:block;margin-top:.6rem">
        <span class="label">درمانگر</span>
        <select class="input" name="legacy_doctor_id" id="legacy-doctor">
          <option value="">انتخاب درمانگر</option>
          <?php foreach ($doctors as $d): ?>
            <option value="<?= e((string) $d['id']) ?>"><?= e((string) $d['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label style="display:block;margin-top:.6rem">
        <span class="label">معرف</span>
        <?php
          if (!function_exists('user_referral_options_html')) {
              require_once __DIR__ . '/../../includes/user_referral.php';
          }
          echo user_referral_options_html('legacy_referral', 'legacy-referral', '', false);
        ?>
      </label>
    </div>
  </div>

  <div>
    <label class="label">دکتر</label>
    <select class="input" name="doctor_id" id="doctor_id" required>
      <option value="">انتخاب کنید</option>
      <?php foreach ($doctors as $d): ?>
        <option value="<?= e($d['id']) ?>" data-price="<?= e((string) (int) ($d['session_price'] ?? 0)) ?>"<?= $preDoctorId === (string) $d['id'] ? ' selected' : '' ?>><?= e($d['name']) ?> — <?= e($d['specialty']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>

  <div>
    <label class="label" for="sec-date-view">انتخاب از روی تقویم</label>
    <input class="input" type="text" id="sec-date-view" data-jdp data-jdp-only-date autocomplete="off" readonly placeholder="<?= $preDoctorId !== '' ? 'تاریخ شمسی خالی' : 'ابتدا دکتر را انتخاب کنید' ?>"<?= $preDoctorId !== '' ? '' : ' disabled' ?> value="<?= e($preDateJalali) ?>">
    <input type="hidden" name="date" id="sec-date" required value="<?= e($preDate) ?>">
  </div>

  <div>
    <label class="label">ساعت</label>
    <div class="slots" id="sec-slots"><span class="muted">ابتدا تاریخ را انتخاب کنید</span></div>
    <?php
      $preMinute = '00';
      if (preg_match('/^\d{2}:(\d{2})$/', $preTime, $minuteMatch) && ((int) $minuteMatch[1]) % 5 === 0) {
          $preMinute = $minuteMatch[1];
      }
      echo appointment_minute_select_html('sec-minute', $preMinute);
    ?>
    <input type="hidden" name="time" id="sec-time" required value="<?= e($preTime) ?>">
  </div>

  <?php
    if (!function_exists('appointment_session_mode_pick_html') && is_file(__DIR__ . '/../../includes/appointment_session.php')) {
        require_once __DIR__ . '/../../includes/appointment_session.php';
    }
    echo function_exists('appointment_session_mode_pick_html')
        ? appointment_session_mode_pick_html('session_mode', 'IN_PERSON', 'sec-sm')
        : '';
    echo function_exists('appointment_room_pick_html')
        ? appointment_room_pick_html('room_no', 'sec-room')
        : '';
  ?>

  <div>
    <label class="label" for="sec-repeat">تکرار این نوبت</label>
    <select class="input" name="repeat_every" id="sec-repeat">
      <option value="">بدون تکرار</option>
      <option value="week">هر هفته</option>
      <option value="biweek">هر دو هفته</option>
      <option value="month">هر ماه</option>
    </select>
    <div id="sec-repeat-count" style="display:none;margin-top:.65rem">
      <label class="label" for="sec-repeat-n">چند نوبت پشت‌سرهم، همین ساعت</label>
      <input class="input" id="sec-repeat-n" name="repeat_count" type="text" inputmode="numeric" autocomplete="off" value="4" style="max-width:8rem">
      <p class="muted" style="margin:.35rem 0 0;font-size:.82rem;line-height:1.7">نوبت اول با همین پرداخت ثبت می‌شود. نوبت‌های بعدی پرداخت‌بعدی می‌مانند. اگر در یکی از روزها درمانگر نباشد یا اتاق پر باشد، همان نوبت رد می‌شود و بقیه ثبت می‌شوند.</p>
    </div>
  </div>

  <div>
    <label class="label">یادداشت (اختیاری)</label>
    <textarea class="input" name="notes" id="notes" rows="3" data-emoji-field></textarea>
  </div>

  <div>
    <label class="label" for="sec-pay-method">نوع پرداخت</label>
    <select class="input" name="pay_method" id="sec-pay-method" required>
      <option value="wallet">از کیف پول مراجع</option>
      <option value="card">کارت به کارت، با آپلود فیش</option>
      <option value="later">بعداً پرداخت می‌کند</option>
    </select>
    <p class="muted" id="sec-pay-hint" style="margin:.35rem 0 0;font-size:.8rem">مبلغ حق جلسه از کیف پول مراجع کم می‌شود.</p>
  </div>

  <div id="sec-receipt-box">
    <label class="label" for="receipt">فیش کارت به کارت</label>
    <input class="input" type="file" name="receipt" id="receipt" accept="image/jpeg,image/png,image/webp,application/pdf">
    <p class="muted" style="margin:.4rem 0 0;font-size:.8rem">برای کارت به کارت، تصویر یا PDF فیش لازم است.</p>
  </div>

  <p id="sec-error" style="color:var(--danger);display:none;font-size:.9rem"></p>
  <p id="sec-fee-line" style="display:none;margin:0 0 .35rem;font-weight:800;line-height:1.7;color:#1a7a45"></p>
  <p id="sec-wallet-line" style="display:none;margin:0 0 .75rem;font-weight:800;line-height:1.7"></p>
  <button class="btn btn-primary" type="submit">ثبت نوبت (تأیید شده)</button>
</form>
<div class="patient-more-modal" id="inactive-patient-modal" hidden>
  <div class="patient-more-backdrop" data-inactive-close></div>
  <div class="patient-more-panel" role="dialog" aria-modal="true" aria-labelledby="inactive-patient-title">
    <div class="patient-more-head">
      <h2 id="inactive-patient-title">اول کاربر را فعال کنید</h2>
      <button type="button" class="patient-more-close" data-inactive-close aria-label="بستن">×</button>
    </div>
    <p style="margin:.8rem 0 0;line-height:1.8">این مراجعه‌کننده غیرفعال است. تا از فهرست مراجعه‌کنندگان دکمه فعال زده شود و پروفایلش ساخته شود، نوبت ثبت نمی‌شود.</p>
    <p style="margin:1rem 0 0"><a class="btn btn-primary btn-sm" href="<?= e(url('/secretary/patients')) ?>">رفتن به مراجعه‌کنندگان</a></p>
  </div>
</div>
<?php
$secretaryBookFormHtml = ob_get_clean();

$secretaryBookScripts = '
<script src="' . e(url('/assets/js/search-select.js')) . '?v=20261013pat"></script>
<script src="/assets/vendor/jalali/jalaali.min.js"></script>
<script src="/assets/vendor/jalali/jalalidatepicker.min.js"></script>
<script>
(function(){
  var availByDoctor = ' . json_encode($availByDoctor, JSON_UNESCAPED_UNICODE) . ';

  var form = document.getElementById("secretary-book-form");
  var doctorEl = document.getElementById("doctor_id");
  var patientEl = document.getElementById("patient_id");

  var doctorSearchApi = null;
  if (window.enhanceSearchSelect) {
    enhanceSearchSelect(patientEl, { placeholder: "جستجو با نام، یوزر یا موبایل…" });
    doctorSearchApi = enhanceSearchSelect(doctorEl, { placeholder: "جستجو یا انتخاب دکتر" });
  }

  var legacyBox = document.getElementById("legacy-patient-setup");
  var legacyPhone = document.getElementById("legacy-phone");
  var legacyDoctor = document.getElementById("legacy-doctor");
  var legacyReferral = document.getElementById("legacy-referral");
  function syncLegacySetup() {
    if (!legacyBox || !patientEl) return;
    var opt = patientEl.options[patientEl.selectedIndex];
    var needs = !!(opt && opt.getAttribute("data-needs-setup") === "1");
    legacyBox.hidden = !needs;
    [legacyPhone, legacyDoctor, legacyReferral].forEach(function (el) {
      if (!el) return;
      el.required = needs;
      el.disabled = !needs;
    });
    if (needs && opt) {
      if (legacyPhone && !legacyPhone.value) legacyPhone.value = opt.getAttribute("data-phone") || "";
      if (legacyDoctor && opt.getAttribute("data-doctor")) legacyDoctor.value = opt.getAttribute("data-doctor");
      if (legacyReferral && opt.getAttribute("data-referral")) legacyReferral.value = opt.getAttribute("data-referral");
    }
  }
  var inactiveModal = document.getElementById("inactive-patient-modal");
  function selectedPatientInactive() {
    if (!patientEl || !patientEl.value) return false;
    var opt = patientEl.options[patientEl.selectedIndex];
    return !!(opt && (opt.getAttribute("data-inactive") === "1" || String(patientEl.value).indexOf("outreach:") === 0));
  }
  function closeInactiveModal() {
    if (!inactiveModal) return;
    inactiveModal.hidden = true;
    inactiveModal.classList.remove("is-open");
    document.body.classList.remove("patient-more-locked");
  }
  function openInactiveModal() {
    if (!inactiveModal) return;
    inactiveModal.hidden = false;
    inactiveModal.classList.add("is-open");
    document.body.classList.add("patient-more-locked");
  }
  if (inactiveModal) {
    inactiveModal.querySelectorAll("[data-inactive-close]").forEach(function (el) {
      el.addEventListener("click", closeInactiveModal);
    });
    document.addEventListener("keydown", function (e) {
      if (e.key === "Escape" && !inactiveModal.hidden) closeInactiveModal();
    });
  }
  var walletLine = document.getElementById("sec-wallet-line");
  var feeLine = document.getElementById("sec-fee-line");
  var doctorEl = document.getElementById("doctor_id");
  function faMoney(n) {
    var s = String(Math.abs(n));
    var grouped = "";
    var count = 0;
    var i;
    for (i = s.length - 1; i >= 0; i--) {
      grouped = s.charAt(i) + grouped;
      count++;
      if (count === 3 && i > 0) {
        grouped = "," + grouped;
        count = 0;
      }
    }
    var map = "۰۱۲۳۴۵۶۷۸۹";
    var out = "";
    for (i = 0; i < grouped.length; i++) {
      var ch = grouped.charAt(i);
      out += (ch >= "0" && ch <= "9") ? map.charAt(parseInt(ch, 10)) : ch;
    }
    return out;
  }
  function syncFeeLine() {
    if (!feeLine || !patientEl) return;
    var patientOpt = patientEl.options[patientEl.selectedIndex];
    if (!patientOpt || !patientOpt.value) {
      feeLine.style.setProperty("display", "none", "important");
      feeLine.textContent = "";
      return;
    }
    var customRaw = patientOpt.getAttribute("data-fee") || "";
    var customFee = parseInt(customRaw, 10);
    var doctorPrice = 0;
    if (doctorEl) {
      var doctorOpt = doctorEl.options[doctorEl.selectedIndex];
      if (doctorOpt && doctorOpt.value) {
        doctorPrice = parseInt(doctorOpt.getAttribute("data-price") || "0", 10);
        if (isNaN(doctorPrice)) doctorPrice = 0;
      }
    }
    var fee = (!isNaN(customFee) && customFee > 0) ? customFee : doctorPrice;
    if (fee <= 0) {
      feeLine.style.setProperty("display", "none", "important");
      feeLine.textContent = "";
      return;
    }
    var source = (!isNaN(customFee) && customFee > 0) ? "مبلغ سفارشی پروفایل" : "تعرفه درمانگر";
    feeLine.textContent = "حق جلسه این نوبت: " + faMoney(fee) + " تومان (" + source + ")";
    feeLine.style.setProperty("display", "block", "important");
  }
  function syncWalletLine() {
    if (!walletLine || !patientEl) return;
    var opt = patientEl.options[patientEl.selectedIndex];
    var raw = opt ? opt.getAttribute("data-wallet") : "";
    if (!opt || !opt.value || raw === null || raw === "") {
      walletLine.style.setProperty("display", "none", "important");
      walletLine.textContent = "";
      syncFeeLine();
      return;
    }
    var amount = parseInt(raw, 10);
    if (isNaN(amount)) amount = 0;
    var state = amount < 0 ? "بدهکار" : (amount > 0 ? "بستانکار" : "تسویه");
    var sign = amount < 0 ? "−" : "";
    walletLine.textContent = "کیف پول: " + sign + faMoney(amount) + " تومان (" + state + ")";
    walletLine.style.color = amount < 0 ? "#a33b3b" : (amount > 0 ? "#1a7a45" : "");
    walletLine.style.setProperty("display", "block", "important");
    syncFeeLine();
  }
  patientEl.addEventListener("change", function(){
    if (selectedPatientInactive()) {
      openInactiveModal();
      patientEl.value = "";
      syncLegacySetup();
      syncWalletLine();
      return;
    }
    syncLegacySetup();
    syncWalletLine();
  });
  if (doctorEl) {
    doctorEl.addEventListener("change", syncFeeLine);
  }
  syncWalletLine();

  var dateView = document.getElementById("sec-date-view");
  var dateEl = document.getElementById("sec-date");
  var timeEl = document.getElementById("sec-time");
  var minuteEl = document.getElementById("sec-minute");
  var slotsEl = document.getElementById("sec-slots");
  var errEl = document.getElementById("sec-error");
  var slotsUrl = ' . json_encode(url('/api/slots')) . ';
  var daysUrl = ' . json_encode(url('/api/availability-days')) . ';
  var selectedTime = (timeEl && timeEl.value) ? timeEl.value : "";
  var pickedHour = hourFromTime(selectedTime);
  if (minuteEl) {
    minuteEl.addEventListener("click", function(e){
      var btn = e.target.closest("[data-minute]");
      if (!btn || btn.hidden) return;
      Array.prototype.forEach.call(minuteEl.querySelectorAll("[data-minute]"), function(el){
        el.classList.toggle("is-on", el === btn);
      });
      if (pickedHour) applyBookedTime(pickedHour);
    });
  }
  var applyingPrefill = false;
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
  function availableList(){
    return (availByDoctor[doctorEl.value] || []).map(function(d){ return String(d).substring(0,10); });
  }
  function availableSet(){
    var set = {};
    availableList().forEach(function(d){ set[d] = true; });
    return set;
  }

  function minuteKey(value){
    var match = String(value || "").match(/^(\d{2}):(\d{2})$/);
    return match ? match[2] : "00";
  }
  function hourFromTime(value){
    var match = String(value || "").match(/^(\d{2}):(\d{2})$/);
    return match ? match[1] + ":00" : "";
  }
  function hourPart(value){
    var match = String(value || "").match(/^(\d{2}):(\d{2})$/);
    return match ? match[1] : "";
  }
  function sameHour(a, b){
    var hour = hourPart(a);
    return hour !== "" && hour === hourPart(b);
  }
  function currentMinute(){
    var on = minuteEl && minuteEl.querySelector(".book-minute-btn.is-on");
    var minute = on ? String(on.getAttribute("data-minute") || "") : "00";
    return /^\d{2}$/.test(minute) ? minute : "00";
  }
  function fillMinutes(minutes, prefer){
    if (!minuteEl) return;
    var allowed = null;
    if (minutes && minutes.length) {
      allowed = {};
      minutes.forEach(function(raw){
        var text = String(raw);
        if (/^\d$/.test(text)) text = "0" + text;
        if (/^\d{2}$/.test(text)) allowed[text] = true;
      });
      if (!Object.keys(allowed).length) allowed = null;
    }
    var buttons = minuteEl.querySelectorAll("[data-minute]");
    var any = false;
    Array.prototype.forEach.call(buttons, function(btn){
      var minute = btn.getAttribute("data-minute") || "";
      var ok = !allowed || !!allowed[minute];
      btn.hidden = !ok;
      if (ok) any = true;
    });
    if (!any) {
      Array.prototype.forEach.call(buttons, function(btn){ btn.hidden = false; });
    }
    var keep = prefer && (!allowed || allowed[prefer]) ? prefer : "";
    if (!keep) {
      Array.prototype.forEach.call(buttons, function(btn){
        if (!keep && !btn.hidden) keep = btn.getAttribute("data-minute") || "";
      });
    }
    Array.prototype.forEach.call(buttons, function(btn){
      btn.classList.toggle("is-on", (btn.getAttribute("data-minute") || "") === keep && !btn.hidden);
    });
  }
  function hourKey(value){
    return hourFromTime(value);
  }
  function paintChosenTime(){
    var readout = document.getElementById("sec-minute-readout");
    var match = String(pickedHour || "").match(/^(\d{2})/);
    if (!match) {
      if (readout) { readout.hidden = true; readout.textContent = ""; }
      return;
    }
    var clock = toFa(match[1] + ":" + currentMinute());
    if (readout) {
      readout.hidden = false;
      readout.textContent = "زمان نوبت: " + clock;
    }
    Array.prototype.forEach.call(slotsEl.querySelectorAll(".slot-btn"), function(btn){
      var chip = btn.querySelector(".hour-chip-time");
      var base = btn.getAttribute("data-base-label") || "";
      if (btn.classList.contains("active")) {
        if (chip) chip.textContent = clock;
        else if (!btn.querySelector(".hour-chip-date")) btn.textContent = clock;
      } else if (base) {
        if (chip) chip.textContent = base;
        else if (!btn.querySelector(".hour-chip-date")) btn.textContent = base;
      }
    });
  }
  function applyBookedTime(hourValue){
    var hour = hourKey(hourValue);
    if (!hour) {
      pickedHour = "";
      timeEl.value = "";
      selectedTime = "";
      paintChosenTime();
      return;
    }
    pickedHour = hour;
    timeEl.value = hour.slice(0, 2) + ":" + currentMinute();
    selectedTime = timeEl.value;
    paintChosenTime();
  }
  function renderSlotButtons(slots, keepTime, emptyMsg, booked){
    booked = booked || [];
    var prev = keepTime ? (timeEl.value || selectedTime) : "";
    if (!keepTime) timeEl.value = "";
    slotsEl.innerHTML = "";
    if (!slots.length && !booked.length) {
      slotsEl.innerHTML = "<span class=\\"muted\\">" + escHtml(emptyMsg || "در این تاریخ درمانگر وقت خالی ندارد") + "</span>";
      return;
    }
    if (!slots.length) {
      var note = document.createElement("span");
      note.className = "muted";
      note.style.flexBasis = "100%";
      note.textContent = emptyMsg || "ساعت خالی در این تاریخ باقی نمانده است";
      slotsEl.appendChild(note);
    }
    function slotRank(value){
      var match = String(value || "").match(/^(\d{2}):(\d{2})$/);
      if (!match) return 9999;
      var hour = parseInt(match[1], 10);
      var minute = parseInt(match[2], 10);
      var rank = hour >= 6 ? hour : hour + 24;
      return rank * 60 + minute;
    }
    var rows = [];
    slots.forEach(function(s){
      var value = typeof s === "string" ? s : (s.value || "");
      rows.push({ kind: "free", slot: s, rank: slotRank(value) });
    });
    booked.forEach(function(item){
      rows.push({ kind: "taken", slot: item, rank: slotRank(item.clock || item.value || "") });
    });
    rows.sort(function(a, b){ return a.rank - b.rank; });
    rows.forEach(function(row){
      if (row.kind === "taken") {
        var item = row.slot;
        var clock = toFa(item.clock || item.label || "");
        var who = item.patient ? String(item.patient) : "";
        var taken = document.createElement("button");
        taken.type = "button";
        taken.disabled = true;
        taken.className = "slot-btn is-taken";
        taken.textContent = who ? (clock + " · " + who) : (clock || "پر");
        taken.title = who ? ("رزرو شده برای " + who) : "این ساعت پر است";
        slotsEl.appendChild(taken);
        return;
      }
      var s = row.slot;
      var value = typeof s === "string" ? s : (s.value || "");
      var label = toFa(typeof s === "string" ? value : (s.label || value));
      var dateLabel = toFa(typeof s === "object" && s.date_label ? s.date_label : "");
      var minutes = (typeof s === "object" && s.minutes && s.minutes.length) ? s.minutes : null;
      var b = document.createElement("button");
      b.type = "button";
      b.className = "slot-btn" + (sameHour(prev, value) ? " active" : "");
      b.setAttribute("data-base-label", label);
      if (minutes) b.setAttribute("data-minutes", minutes.join(","));
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
      if (sameHour(prev, value)) {
        fillMinutes(minutes, minuteKey(prev));
        applyBookedTime(value);
      }
      b.onclick = function(){
        Array.prototype.forEach.call(slotsEl.querySelectorAll(".slot-btn"), function(x){ x.classList.remove("active"); });
        b.classList.add("active");
        fillMinutes((b.getAttribute("data-minutes") || "").split(",").filter(Boolean), minuteKey(value));
        applyBookedTime(value);
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
      pickedHour = "";
      paintChosenTime();
    }
    if (!doctorEl.value || !dateEl.value) {
      slotsEl.innerHTML = "<span class=\\"muted\\">ابتدا تاریخ را انتخاب کنید</span>";
      return;
    }
    if (!opts.silent) slotsEl.innerHTML = "در حال بارگذاری...";
    fetch(slotsUrl + "?doctorId=" + encodeURIComponent(doctorEl.value) + "&date=" + encodeURIComponent(dateEl.value) + "&include_past=1")
      .then(function(r){ return r.json(); })
      .then(function(data){
        renderSlotButtons(data.slots || [], !!opts.keepTime, data.message || "", data.booked || []);
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
        if (dateEl.value) loadSlots({ keepTime: true, silent: !!opts.silent });
      })
      .catch(function(){});
  }

  function selectDate(gDate, opts){
    opts = opts || {};
    errEl.style.display = "none";
    var g = String(gDate).substring(0,10);
    if (!doctorEl.value) {
      errEl.textContent = "ابتدا دکتر را انتخاب کنید.";
      errEl.style.display = "block";
      return;
    }
    var finish = function(){
      var set = availableSet();
      if (!set[g] && !opts.force) {
        dateEl.value = "";
        dateView.value = "";
        errEl.textContent = "این تاریخ برای دکتر انتخاب‌شده خالی نیست.";
        errEl.style.display = "block";
        loadSlots();
        return;
      }
      dateEl.value = g;
      dateView.value = gregorianToJalaliText(g);
      loadSlots({ keepTime: !!selectedTime || !!timeEl.value || !!opts.keepTime });
    };
    if (!availableSet()[g]) {
      refreshAvailability().then(finish);
      return;
    }
    finish();
  }


  document.addEventListener("focusin", function(ev){
    if (ev.target && ev.target.getAttribute && ev.target.getAttribute("data-jdp") !== null) {
      window.__manaJdpInput = ev.target;
    }
  });
  jalaliDatepicker.startWatch({
    selector: "[data-jdp]",
    time: false,
    hideAfterChange: true,
    showTodayBtn: true,
    showEmptyBtn: true,
    autoReadOnlyInput: true,
    persianDigits: true,
    zIndex: 100000,
    container: "body",
    dayRendering: function(dayOptions, input){
      var field = (input && input.id) ? input : window.__manaJdpInput;
      if (!field || field.id !== "sec-date-view") {
        return {};
      }
      if (!doctorEl.value) {
        return { isValid: false };
      }
      var g = jalaali.toGregorian(dayOptions.year, dayOptions.month, dayOptions.day);
      var key = g.gy + "-" + pad(g.gm) + "-" + pad(g.gd);
      return { isValid: !!availableSet()[key] };
    }
  });

  function applyCalendarValue(){
    var g = jalaliToGregorian(dateView.value);
    if (g) {
      selectDate(g);
      return;
    }
    dateEl.value = "";
    loadSlots();
  }
  dateView.addEventListener("jdp:change", applyCalendarValue);
  dateView.addEventListener("change", applyCalendarValue);

  doctorEl.addEventListener("change", function(){
    if (applyingPrefill) return;
    dateView.value = "";
    dateEl.value = "";
    timeEl.value = "";
    selectedTime = "";
    errEl.style.display = "none";
    var hasDoctor = !!doctorEl.value;
    dateView.disabled = !hasDoctor;
    dateView.placeholder = hasDoctor ? "تاریخ شمسی خالی" : "ابتدا دکتر را انتخاب کنید";
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
    if (selectedPatientInactive() || String(patientId || "").indexOf("outreach:") === 0) {
      e.preventDefault();
      openInactiveModal();
      patientEl.value = "";
      return;
    }
    if (!patientId) {
      e.preventDefault();
      errEl.textContent = "مراجعه‌کننده را انتخاب کنید.";
      errEl.style.display = "block";
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
      return;
    }
    var roomPick = document.getElementById("sec-room");
    var modePick = form.querySelector("input[name=session_mode]:checked");
    var roomOnline = !!(modePick && modePick.value === "ONLINE");
    if (!roomOnline && roomPick && !roomPick.value) {
      e.preventDefault();
      errEl.textContent = "برای جلسه حضوری اتاق را انتخاب کنید.";
      errEl.style.display = "block";
      var roomBtn = document.getElementById("sec-room-btn");
      if (roomBtn) roomBtn.focus();
    }
  });

  var repeatEl = document.getElementById("sec-repeat");
  var repeatBox = document.getElementById("sec-repeat-count");
  function syncRepeat() {
    if (!repeatEl || !repeatBox) return;
    repeatBox.style.setProperty("display", repeatEl.value === "" ? "none" : "block", "important");
  }
  if (repeatEl) repeatEl.addEventListener("change", syncRepeat);
  syncRepeat();

  if (doctorEl.value) {
    dateView.disabled = false;
    dateView.placeholder = "تاریخ شمسی خالی";
    if (dateEl.value) dateView.value = gregorianToJalaliText(dateEl.value);
  }

  // پیش‌پر کردن از کلیک روی ساعت آزاد جدول هفت‌روزه: ?tab=new&doctor_id=&date=&time=
  (function applySlotPrefill(){
    var params = new URLSearchParams(window.location.search);
    var preDoctor = (params.get("doctor_id") || doctorEl.value || "").trim();
    var preDate = (params.get("date") || dateEl.value || "").trim();
    var preTime = (params.get("time") || timeEl.value || "").trim();
    if (preTime && preTime.length === 4) preTime = "0" + preTime;
    if (!preDoctor && !preDate && !preTime) {
      if (doctorEl.value) refreshAvailability();
      return;
    }
    applyingPrefill = true;
    if (preDoctor && Array.prototype.some.call(doctorEl.options, function(o){ return o.value === preDoctor; })) {
      if (doctorSearchApi && typeof doctorSearchApi.setValue === "function") {
        doctorSearchApi.setValue(preDoctor, false);
      } else {
        doctorEl.value = preDoctor;
      }
    }
    if (preTime) {
      selectedTime = preTime;
      timeEl.value = preTime;
    }
    if (doctorEl.value) {
      dateView.disabled = false;
      dateView.placeholder = "تاریخ شمسی خالی";
    }
    function finishDate(){
      if (preDate && doctorEl.value) {
        if (preTime) {
          selectedTime = preTime;
          timeEl.value = preTime;
        }
        selectDate(preDate, { force: true, keepTime: true });
        if (preTime) {
          selectedTime = preTime;
          timeEl.value = preTime;
          loadSlots({ keepTime: true });
        }
      } else {
        if (dateEl.value) loadSlots({ keepTime: true });
      }
      applyingPrefill = false;
    }
    if (doctorEl.value) {
      refreshAvailability().then(finishDate);
    } else {
      applyingPrefill = false;
    }
  })();

  var roomEl = document.getElementById("sec-room");
  var roomHint = document.getElementById("sec-room-hint");
  var roomBox = roomEl ? roomEl.closest("[data-appt-room-pick]") : null;
  function syncRoomRule() {
    if (!roomEl) return;
    var picked = form.querySelector("input[name=session_mode]:checked");
    var online = !!(picked && picked.value === "ONLINE");
    roomEl.required = false;
    roomEl.disabled = online;
    if (online) roomEl.value = "";
    roomEl.dispatchEvent(new Event("change", { bubbles: true }));
    if (roomBox) roomBox.hidden = online;
    if (roomHint) {
      roomHint.textContent = online
        ? "جلسه آنلاین است؛ انتخاب اتاق لازم نیست."
        : "برای جلسه حضوری اتاق را انتخاب کنید.";
    }
  }
  var payEl = document.getElementById("sec-pay-method");
  var payHint = document.getElementById("sec-pay-hint");
  var receiptBox = document.getElementById("sec-receipt-box");
  var receiptEl = document.getElementById("receipt");
  function syncPayRule() {
    var method = payEl ? payEl.value : "wallet";
    var card = method === "card";
    if (receiptBox) receiptBox.hidden = !card;
    if (receiptEl) {
      receiptEl.required = card;
      receiptEl.disabled = !card;
    }
    if (!payHint) return;
    if (method === "card") payHint.textContent = "فیش کارت به کارت را همین‌جا بارگذاری کنید.";
    else if (method === "later") payHint.textContent = "نوبت ثبت می‌شود و به اندازه حق ویزیت همین درمانگر از کیف پول کم می‌شود. موجودی می‌تواند منفی شود تا فیش تأیید گردد.";
    else payHint.textContent = "مبلغ حق جلسه از کیف پول مراجع کم می‌شود. اگر موجودی کافی نباشد، نوبت ثبت نمی‌شود.";
  }
  if (payEl) payEl.addEventListener("change", syncPayRule);
  syncPayRule();
  form.querySelectorAll("input[name=session_mode]").forEach(function (el) {
    el.addEventListener("change", syncRoomRule);
  });
  syncRoomRule();
  syncLegacySetup();
})();
</script>
';
