<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/doctor_panel.php';
require_once __DIR__ . '/../../includes/availability.php';

$ctx = require_doctor_profile($pdo);
ensure_availability_schema($pdo);
ensure_doctor_weekly_hours_schema($pdo);
$items = $pdo->prepare('SELECT * FROM availabilities WHERE doctor_id=? ORDER BY date ASC');
$items->execute([$ctx['profile']['id']]);
$items = $items->fetchAll();
$bookingHours = appointment_booking_hours();
$weeklyMap = doctor_weekly_hours_map($pdo, (string) $ctx['profile']['id']);
$weekdays = doctor_weekdays_sat_first();
$bookedMap = doctor_availability_booked_map($pdo, (string) $ctx['profile']['id']);
$monthPack = doctor_availability_month_groups($items);
$monthGroups = $monthPack['months'];
$defaultMonthId = $monthPack['default_id'];
$tabParam = trim((string) ($_GET['month'] ?? ''));
if ($tabParam !== '' && isset($monthGroups[$tabParam])) {
    $defaultMonthId = $tabParam;
}
$availYmd = doctor_availability_ymd_groups($items);
if (preg_match('/(\d{4})-(\d{2})/', $tabParam, $monthMatch)) {
    $availYmd['default_year_id'] = 'avail-y-' . $monthMatch[1];
    $availYmd['default_month_id'] = 'avail-m-' . $monthMatch[1] . '-' . $monthMatch[2];
}

ob_start();
?>
<h1>روزهای خالی</h1>
<p class="muted">
  روز هفته را بزنید، ساعت شروع و پایان حضور را از منوی کرکره‌ای انتخاب کنید.
  سیستم به‌صورت خودکار اسلات‌های <strong>یک‌ساعته</strong> می‌سازد تا منشی و مراجعه‌کننده بتوانند رزرو کنند.
</p>

<section class="panel avail-week-panel" style="margin-top:1rem">
  <h2 class="avail-week-title">روزهای هفته</h2>
  <div class="avail-weekday-row" role="tablist" aria-label="روزهای هفته">
    <?php foreach ($weekdays as $w => $label): ?>
      <?php $has = isset($weeklyMap[$w]); ?>
      <button type="button"
        class="avail-weekday-btn<?= $has ? ' has-hours' : '' ?>"
        data-weekday-open="<?= (int) $w ?>"
        aria-controls="weekday-panel-<?= (int) $w ?>"
        id="weekday-<?= (int) $w ?>">
        <?= e($label) ?>
        <?php if ($has): ?>
          <span class="avail-weekday-dot" aria-hidden="true"></span>
        <?php endif; ?>
      </button>
    <?php endforeach; ?>
  </div>

  <?php foreach ($weekdays as $w => $label): ?>
    <?php
      $saved = $weeklyMap[$w] ?? [];
      $defaultFrom = $saved[0] ?? 9;
      $defaultTo = $saved !== [] ? $saved[count($saved) - 1] : 13;
    ?>
    <div class="avail-weekday-editor" id="weekday-panel-<?= (int) $w ?>" data-weekday-panel="<?= (int) $w ?>" hidden>
      <div class="avail-weekday-editor-head">
        <strong>ساعت حضور — <?= e($label) ?></strong>
        <?php if ($saved !== []): ?>
          <span class="muted" style="font-size:.85rem">
            الان:
            <?= e(implode(' · ', array_map(
                static fn (int $h): string => appointment_hour_chip_label($h),
                $saved
            ))) ?>
          </span>
        <?php endif; ?>
      </div>
      <form method="post" action="<?= e(url('/doctor/availability')) ?>" class="avail-weekday-form">
        <input type="hidden" name="action" value="save_weekday">
        <input type="hidden" name="weekday" value="<?= (int) $w ?>">
        <div class="avail-weekday-fields">
          <label>
            <span class="label">از ساعت</span>
            <select class="input" name="hour_from" required>
              <?php foreach ($bookingHours as $hour): ?>
                <option value="<?= (int) $hour ?>"<?= (int) $hour === (int) $defaultFrom ? ' selected' : '' ?>>
                  <?= e(appointment_hour_chip_label((int) $hour)) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </label>
          <label>
            <span class="label">تا ساعت</span>
            <select class="input" name="hour_to" required>
              <?php foreach ($bookingHours as $hour): ?>
                <option value="<?= (int) $hour ?>"<?= (int) $hour === (int) $defaultTo ? ' selected' : '' ?>>
                  <?= e(appointment_hour_chip_label((int) $hour)) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </label>
          <label>
            <span class="label">اعمال برای</span>
            <select class="input" name="weeks">
              <option value="4">۴ هفته آینده</option>
              <option value="8">۸ هفته آینده</option>
              <option value="12" selected>۱۲ هفته آینده</option>
              <option value="26">۶ ماه آینده</option>
            </select>
          </label>
        </div>
        <p class="muted" style="font-size:.8rem;margin:.65rem 0 0;line-height:1.65">
          مثلاً از ۹ صبح تا ۱۳ بعدازظهر → اسلات‌های ۹، ۱۰، ۱۱، ۱۲، ۱۳ برای رزرو منشی و مراجعه‌کننده.
        </p>
        <div class="assistant-actions" style="margin-top:.85rem">
          <button class="btn btn-primary" type="submit">ثبت ساعت حضور</button>
        </div>
      </form>
      <?php if ($saved !== []): ?>
        <form method="post" action="<?= e(url('/doctor/availability')) ?>" style="margin-top:.65rem" onsubmit="return confirm('ساعت‌های این روز هفته پاک شود؟');">
          <input type="hidden" name="action" value="clear_weekday">
          <input type="hidden" name="weekday" value="<?= (int) $w ?>">
          <button class="btn btn-outline btn-sm" type="submit">پاک کردن این روز</button>
        </form>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
</section>

<details class="panel" style="margin-top:1.25rem">
  <summary style="cursor:pointer;font-weight:600">تنظیم پیشرفته: یک روز مشخص یا کل ماه</summary>
  <form class="form-stack" method="post" action="<?= e(url('/doctor/availability')) ?>" id="avail-save-form" style="margin-top:1rem">
    <input type="hidden" name="action" id="avail-action" value="save">
    <div class="binder-tile binder-tile--nested" data-binder-tabs data-binder-hash="0" data-binder-initial="avail-day" data-binder-tone="in-person">
      <div class="binder-tabs" role="tablist" aria-label="بازه افزودن">
        <button type="button" class="binder-tab binder-tab-in-person is-active" role="tab" data-binder-tab="avail-day" data-binder-tone="in-person" aria-selected="true">یک روز</button>
        <button type="button" class="binder-tab binder-tab-appts avail-month-range-tab" role="tab" data-binder-tab="avail-month" data-binder-tone="appts" aria-selected="false">کل ماه</button>
      </div>
      <div class="binder-body">
        <section class="binder-panel is-active" data-binder-panel="avail-day" role="tabpanel">
          <div>
            <label class="label" for="avail-date-view">تاریخ (شمسی)</label>
            <input class="input" type="text" id="avail-date-view" name="date_jalali" data-jdp data-jdp-only-date autocomplete="off" readonly placeholder="کلیک کنید تا تقویم باز شود" style="cursor:pointer">
            <input type="hidden" name="date" id="avail-date" value="">
          </div>
        </section>
        <section class="binder-panel" data-binder-panel="avail-month" role="tabpanel" hidden>
          <div>
            <label class="label" for="avail-month-key">ماه</label>
            <select class="input" id="avail-month-key" name="month_key">
              <?php foreach ($monthGroups as $mid => $bucket): ?>
                <?php
                  $jy = (int) ($bucket['year'] ?? 0);
                  $jm = (int) ($bucket['month'] ?? 0);
                  if ($jy < 1 || $jm < 1) {
                      continue;
                  }
                  $monthEnd = jalali_ymd($jy, $jm, jalali_month_length($jy, $jm));
                  if ($monthEnd < date('Y-m-d')) {
                      continue;
                  }
                ?>
                <option value="<?= e((string) ($bucket['key'] ?? '')) ?>"<?= $defaultMonthId === (string) $mid ? ' selected' : '' ?>>
                  <?= e(doctor_availability_month_range_label($bucket)) ?>
                  · <?= e((string) ($bucket['label'] ?? '')) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
        </section>
      </div>
    </div>
    <div>
      <label class="label">ساعت‌های خالی</label>
      <div class="hour-picker" id="hour-picker">
        <?php foreach ($bookingHours as $hour): ?>
          <label class="hour-chip">
            <input type="checkbox" name="hours[]" value="<?= (int) $hour ?>" checked>
            <span class="hour-chip-time"><?= e(appointment_hour_chip_label((int) $hour)) ?></span>
          </label>
        <?php endforeach; ?>
      </div>
      <div style="margin-top:.5rem;display:flex;gap:.5rem;flex-wrap:wrap">
        <button type="button" class="btn btn-outline btn-sm" id="select-all-hours">انتخاب همه</button>
        <button type="button" class="btn btn-outline btn-sm" id="clear-all-hours">پاک کردن</button>
      </div>
    </div>
    <button class="btn btn-primary" type="submit" id="avail-submit-btn">افزودن / به‌روزرسانی</button>
  </form>
</details>

<div style="margin-top:1.5rem">
  <h2 style="font-size:1.05rem;margin:0 0 .75rem">روزهای ثبت‌شده</h2>
<?php
  $ymdPack = $availYmd;
  require __DIR__ . '/../../includes/doctor_availability_ymd_binder.php';
?>
</div>
<?php
$inner = ob_get_clean();
$pageHead = '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@majidh1/jalalidatepicker/dist/jalalidatepicker.min.css">';
$pageScripts = '
<script src="' . e(url('/assets/js/binder-tabs.js')) . '?v=20260907i"></script>
<script src="' . e(url('/assets/js/ymd-cascade.js')) . '?v=20260910r"></script>
<script src="https://cdn.jsdelivr.net/npm/jalaali-js@1.2.7/dist/jalaali.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/@majidh1/jalalidatepicker/dist/jalalidatepicker.min.js"></script>
<script>
(function(){
  var buttons = document.querySelectorAll("[data-weekday-open]");
  var panels = document.querySelectorAll("[data-weekday-panel]");
  function openWeekday(id){
    panels.forEach(function(p){
      var on = String(p.getAttribute("data-weekday-panel")) === String(id);
      p.hidden = !on;
    });
    buttons.forEach(function(b){
      b.classList.toggle("is-active", String(b.getAttribute("data-weekday-open")) === String(id));
    });
  }
  buttons.forEach(function(btn){
    btn.addEventListener("click", function(){
      openWeekday(btn.getAttribute("data-weekday-open"));
    });
  });
  var hash = (location.hash || "").replace("#weekday-", "");
  if (hash !== "" && document.getElementById("weekday-panel-" + hash)) {
    openWeekday(hash);
  }
})();
(function(){
  document.querySelectorAll("[data-avail-hour]").forEach(function(btn){
    btn.addEventListener("click", function(){
      var card = btn.closest(".avail-day-card");
      if (!card) return;
      var detail = card.querySelector(".avail-hour-detail");
      var title = detail && detail.querySelector(".avail-hour-detail-title");
      var body = detail && detail.querySelector(".avail-hour-detail-body");
      var link = detail && detail.querySelector(".avail-hour-detail-link");
      if (!detail || !title || !body || !link) return;
      document.querySelectorAll("[data-avail-hour].is-selected").forEach(function(el){ el.classList.remove("is-selected"); });
      document.querySelectorAll(".avail-hour-detail").forEach(function(el){
        if (el !== detail) el.hidden = true;
      });
      btn.classList.add("is-selected");
      var state = btn.getAttribute("data-state") || "";
      var label = btn.getAttribute("data-label") || "";
      var dateLabel = btn.getAttribute("data-date-label") || "";
      title.textContent = label + (dateLabel ? " · " + dateLabel : "");
      if (state === "booked") {
        var name = btn.getAttribute("data-patient") || "مراجعه‌کننده";
        var phone = btn.getAttribute("data-phone") || "";
        var status = btn.getAttribute("data-status") || "";
        var href = btn.getAttribute("data-href") || "";
        body.textContent = "رزرو شده برای «" + name + "»" + (status ? " — " + status : "") + (phone ? " · " + phone : "");
        if (href) { link.href = href; link.hidden = false; } else { link.hidden = true; }
      } else if (state === "free") {
        body.textContent = "این ساعت اعلام شده و هنوز کسی رزرو نکرده است.";
        link.hidden = true;
      } else {
        body.textContent = "این ساعت برای این روز اعلام نشده است.";
        link.hidden = true;
      }
      detail.hidden = false;
    });
  });
})();
(function(){
  function faToEn(str){ return String(str).replace(/[۰-۹]/g, function(d){ return "۰۱۲۳۴۵۶۷۸۹".indexOf(d); }); }
  function pad(n){ return (n < 10 ? "0" : "") + n; }
  var form = document.getElementById("avail-save-form");
  var view = document.getElementById("avail-date-view");
  var hidden = document.getElementById("avail-date");
  var actionInput = document.getElementById("avail-action");
  var submitBtn = document.getElementById("avail-submit-btn");
  var monthKey = document.getElementById("avail-month-key");
  if (!form || !view || !hidden) return;
  function sync(){
    var t = faToEn(view.value).replace(/-/g,"/").trim();
    var p = t.split("/");
    if (p.length !== 3) { hidden.value = ""; return; }
    var g = jalaali.toGregorian(parseInt(p[0],10), parseInt(p[1],10), parseInt(p[2],10));
    hidden.value = g.gy + "-" + pad(g.gm) + "-" + pad(g.gd);
  }
  function monthModeOn(){
    var panel = form.querySelector("[data-binder-panel=\\"avail-month\\"]");
    return !!(panel && panel.classList.contains("is-active"));
  }
  function refreshSubmit(){
    if (!submitBtn) return;
    submitBtn.textContent = monthModeOn() ? "اعمال به کل ماه" : "افزودن / به‌روزرسانی";
  }
  if (window.jalaliDatepicker && typeof jalaliDatepicker.startWatch === "function") {
    jalaliDatepicker.startWatch({
      selector: "#avail-date-view",
      time: false,
      hideAfterChange: true,
      autoReadOnlyInput: true,
      showTodayBtn: true,
      zIndex: 100000,
      container: "body"
    });
  }
  view.addEventListener("jdp:change", sync);
  view.addEventListener("change", sync);
  var selectAllBtn = document.getElementById("select-all-hours");
  var clearAllBtn = document.getElementById("clear-all-hours");
  if (selectAllBtn) selectAllBtn.addEventListener("click", function(){
    document.querySelectorAll("#hour-picker input[type=checkbox]").forEach(function(cb){ cb.checked = true; });
  });
  if (clearAllBtn) clearAllBtn.addEventListener("click", function(){
    document.querySelectorAll("#hour-picker input[type=checkbox]").forEach(function(cb){ cb.checked = false; });
  });
  window.addEventListener("binder-tab-change", refreshSubmit);
  refreshSubmit();
  form.addEventListener("submit", function(e){
    var checked = form.querySelectorAll("#hour-picker input[type=checkbox]:checked");
    if (monthModeOn()) {
      if (actionInput) actionInput.value = "save_month";
      if (!monthKey || !monthKey.value) { e.preventDefault(); alert("ماه را انتخاب کنید"); return; }
    } else {
      if (actionInput) actionInput.value = "save";
      sync();
      if (!hidden.value) { e.preventDefault(); alert("تاریخ را انتخاب کنید"); return; }
    }
    if (!checked.length) { e.preventDefault(); alert("حداقل یک ساعت خالی انتخاب کنید."); }
  });
})();
</script>
';
render_doctor_page('روزهای خالی', $inner);
