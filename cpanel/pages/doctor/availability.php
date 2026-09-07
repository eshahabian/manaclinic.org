<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/doctor_panel.php';
require_once __DIR__ . '/../../includes/availability.php';

$ctx = require_doctor_profile($pdo);
ensure_availability_schema($pdo);
$items = $pdo->prepare('SELECT * FROM availabilities WHERE doctor_id=? ORDER BY date ASC');
$items->execute([$ctx['profile']['id']]);
$items = $items->fetchAll();
$bookingHours = appointment_booking_hours();
$savedHoursByDate = [];
foreach ($items as $item) {
    $key = substr((string) ($item['date'] ?? ''), 0, 10);
    if ($key !== '') {
        $savedHoursByDate[$key] = appointment_availability_hours($item);
    }
}
$bookedMap = doctor_availability_booked_map($pdo, (string) $ctx['profile']['id']);
$monthPack = doctor_availability_month_groups($items);
$monthGroups = $monthPack['months'];
$defaultMonthId = $monthPack['default_id'];
$tabParam = trim((string) ($_GET['month'] ?? ''));
if ($tabParam !== '' && isset($monthGroups[$tabParam])) {
    $defaultMonthId = $tabParam;
}

ob_start();
?>
<h1>روزهای خالی</h1>
<p class="muted">روی فیلد تاریخ بزنید تا تقویم شمسی باز شود؛ بعد ساعت‌های خالی را مشخص کنید. روزهای ذخیره‌شده را از تب ماه ببینید و روی هر ساعت کلیک کنید تا خالی بودن یا نام رزروکننده مشخص شود.</p>
<form class="panel form-stack" method="post" action="<?= e(url('/doctor/availability')) ?>" style="margin-top:1rem;max-width:40rem">
  <input type="hidden" name="action" value="save">
  <div>
    <label class="label" for="avail-date-view">تاریخ (شمسی)</label>
    <input class="input" type="text" id="avail-date-view" name="date_jalali" data-jdp data-jdp-only-date autocomplete="off" readonly required placeholder="کلیک کنید تا تقویم باز شود" style="cursor:pointer">
    <input type="hidden" name="date" id="avail-date" value="">
  </div>
  <div>
    <label class="label">ساعت‌های خالی</label>
    <div class="hour-picker" id="hour-picker">
      <?php foreach ($bookingHours as $hour): ?>
        <label class="hour-chip">
          <input type="checkbox" name="hours[]" value="<?= (int) $hour ?>" checked>
          <span class="hour-chip-time"><?= e(appointment_hour_chip_label((int) $hour)) ?></span>
          <span class="hour-chip-date" data-hour-day="<?= appointment_hour_is_next_day((int) $hour) ? 'next' : 'same' ?>"></span>
        </label>
      <?php endforeach; ?>
    </div>
    <p class="muted" style="font-size:.8rem;margin:.5rem 0 0;line-height:1.6">
      ۲۴ ساعت کامل: از ۶ صبح این تاریخ تا ۶ صبح روز بعد. اگر این تاریخ قبلاً ذخیره شده باشد، همان ساعت‌ها بارگذاری می‌شود تا بتوانید کم‌وزیاد کنید.
    </p>
    <div style="margin-top:.5rem;display:flex;gap:.5rem;flex-wrap:wrap">
      <button type="button" class="btn btn-outline btn-sm" id="select-all-hours">انتخاب همه (۲۴ ساعت)</button>
      <button type="button" class="btn btn-outline btn-sm" id="clear-all-hours">پاک کردن</button>
    </div>
  </div>
  <button class="btn btn-primary" type="submit">افزودن / به‌روزرسانی</button>
</form>
<div class="binder-tile" data-binder-tabs data-binder-hash="0" data-binder-initial="<?= e($defaultMonthId) ?>" data-binder-tone="<?= e((string) ($monthGroups[$defaultMonthId]['tone'] ?? 'in-person')) ?>" style="margin-top:1.5rem">
  <div class="binder-tabs" role="tablist" aria-label="ماه روزهای خالی">
    <?php foreach ($monthGroups as $mid => $bucket): ?>
      <button type="button"
        class="binder-tab <?= e((string) ($bucket['class'] ?? 'binder-tab-in-person')) ?><?= $defaultMonthId === $mid ? ' is-active' : '' ?>"
        role="tab"
        data-binder-tab="<?= e((string) $mid) ?>"
        data-binder-tone="<?= e((string) ($bucket['tone'] ?? 'in-person')) ?>"
        aria-selected="<?= $defaultMonthId === $mid ? 'true' : 'false' ?>">
        <?= e((string) ($bucket['tab_label'] ?? $bucket['short'] ?? $mid)) ?>
        <span class="binder-tab-count"><?= to_fa_digits((string) count($bucket['items'] ?? [])) ?></span>
      </button>
    <?php endforeach; ?>
  </div>
  <div class="binder-body">
    <?php foreach ($monthGroups as $mid => $bucket): ?>
      <section class="binder-panel<?= $defaultMonthId === $mid ? ' is-active' : '' ?>" data-binder-panel="<?= e((string) $mid) ?>" role="tabpanel"<?= $defaultMonthId === $mid ? '' : ' hidden' ?>>
        <h2 class="binder-sub" style="margin-top:0">روزهای خالی <?= e((string) ($bucket['label'] ?? '')) ?></h2>
        <?php if (empty($bucket['items'])): ?>
          <p class="muted">در این ماه روز خالی ثبت نشده است.</p>
        <?php else: ?>
          <div class="stack">
            <?php foreach ($bucket['items'] as $item): ?>
              <?php
                $dayDate = substr((string) ($item['date'] ?? ''), 0, 10);
                $savedHours = appointment_availability_hours($item);
              ?>
              <div class="panel avail-day-card">
                <div class="row-between">
                  <div>
                    <strong><?= e(to_jalali_label($dayDate)) ?></strong>
                    <div class="muted" style="font-size:.85rem;margin-top:.35rem">روی ساعت بزنید تا وضعیت رزرو را ببینید.</div>
                  </div>
                  <form method="post" action="<?= e(url('/doctor/availability')) ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= e($item['id']) ?>">
                    <button class="btn btn-danger btn-sm" type="submit">حذف</button>
                  </form>
                </div>
                <div class="hour-picker hour-picker-readonly" style="margin-top:.75rem">
                  <?php foreach ($bookingHours as $hour): ?>
                    <?php
                      $hour = (int) $hour;
                      $startsAt = appointment_slot_starts_at($dayDate, $hour);
                      $slotKey = substr(str_replace('T', ' ', $startsAt), 0, 16);
                      $booked = $bookedMap[$slotKey] ?? null;
                      $isOpen = in_array($hour, $savedHours, true);
                      $state = !$isOpen ? 'off' : ($booked ? 'booked' : 'free');
                      $patientName = trim((string) ($booked['patient_name'] ?? ''));
                      $firstName = $patientName !== '' ? (preg_split('/\s+/u', $patientName)[0] ?? $patientName) : '';
                      $statusLabel = $booked ? appointment_row_status_label($booked) : '';
                      $patientHref = $booked ? url('/doctor/patients/' . (string) $booked['patient_id']) : '';
                    ?>
                    <button type="button"
                      class="hour-chip is-<?= e($state) ?>"
                      data-avail-hour
                      data-state="<?= e($state) ?>"
                      data-label="<?= e(appointment_hour_chip_label($hour)) ?>"
                      data-date-label="<?= e(appointment_hour_date_for($dayDate, $hour)) ?>"
                      data-patient="<?= e($patientName) ?>"
                      data-phone="<?= e((string) ($booked['phone'] ?? '')) ?>"
                      data-status="<?= e($statusLabel) ?>"
                      data-href="<?= e($patientHref) ?>">
                      <span class="hour-chip-time"><?= e(appointment_hour_chip_label($hour)) ?></span>
                      <span class="hour-chip-date"><?= e(appointment_hour_date_for($dayDate, $hour)) ?></span>
                      <?php if ($state === 'booked' && $firstName !== ''): ?>
                        <span class="hour-chip-who"><?= e($firstName) ?></span>
                      <?php elseif ($state === 'free'): ?>
                        <span class="hour-chip-who">خالی</span>
                      <?php endif; ?>
                    </button>
                  <?php endforeach; ?>
                </div>
                <div class="avail-hour-detail" hidden>
                  <strong class="avail-hour-detail-title"></strong>
                  <p class="avail-hour-detail-body muted" style="margin:.35rem 0 0;font-size:.9rem;line-height:1.7"></p>
                  <a class="btn btn-outline btn-sm avail-hour-detail-link" hidden href="#">پرونده مراجعه‌کننده</a>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </section>
    <?php endforeach; ?>
  </div>
</div>
<?php
$inner = ob_get_clean();
$pageHead = '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@majidh1/jalalidatepicker/dist/jalalidatepicker.min.css">';
$pageScripts = '
<script src="' . e(url('/assets/js/binder-tabs.js')) . '?v=20260907a"></script>
<script src="https://cdn.jsdelivr.net/npm/jalaali-js@1.2.7/dist/jalaali.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/@majidh1/jalalidatepicker/dist/jalalidatepicker.min.js"></script>
<script>
(function(){
  var selected = null;
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
      selected = btn;
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
        if (href) {
          link.href = href;
          link.hidden = false;
        } else {
          link.hidden = true;
        }
      } else if (state === "free") {
        body.textContent = "این ساعت اعلام شده و هنوز کسی رزرو نکرده است.";
        link.hidden = true;
      } else {
        body.textContent = "این ساعت برای این روز اعلام نشده است.";
        link.hidden = true;
      }
      detail.hidden = false;
      detail.scrollIntoView({ block: "nearest", behavior: "smooth" });
    });
  });
})();
(function(){
  function faToEn(str){ return String(str).replace(/[۰-۹]/g, function(d){ return "۰۱۲۳۴۵۶۷۸۹".indexOf(d); }); }
  function pad(n){ return (n < 10 ? "0" : "") + n; }
  var view = document.getElementById("avail-date-view");
  var hidden = document.getElementById("avail-date");
  if (!view || !hidden) return;
  var months = ["","فروردین","اردیبهشت","خرداد","تیر","مرداد","شهریور","مهر","آبان","آذر","دی","بهمن","اسفند"];
  function toFa(n){ return String(n).replace(/[0-9]/g, function(d){ return "۰۱۲۳۴۵۶۷۸۹"[d]; }); }
  function jalaliShort(gy, gm, gd){
    var j = jalaali.toJalaali(gy, gm, gd);
    return toFa(j.jd) + " " + (months[j.jm] || "");
  }
  function fillHourDates(){
    if (!hidden.value) {
      document.querySelectorAll("#hour-picker [data-hour-day]").forEach(function(el){ el.textContent = ""; });
      return;
    }
    var parts = hidden.value.split("-");
    if (parts.length !== 3) return;
    var gy = parseInt(parts[0],10), gm = parseInt(parts[1],10), gd = parseInt(parts[2],10);
    var same = jalaliShort(gy, gm, gd);
    var next = new Date(gy, gm - 1, gd + 1);
    var nextLabel = jalaliShort(next.getFullYear(), next.getMonth() + 1, next.getDate());
    document.querySelectorAll("#hour-picker [data-hour-day]").forEach(function(el){
      el.textContent = el.getAttribute("data-hour-day") === "next" ? nextLabel : same;
    });
  }
  var savedHoursByDate = ' . json_encode($savedHoursByDate) . ';
  var lastAppliedDate = "";
  function applySavedHours(){
    if (!hidden.value || hidden.value === lastAppliedDate) return;
    lastAppliedDate = hidden.value;
    var boxes = document.querySelectorAll("#hour-picker input[type=checkbox]");
    var saved = savedHoursByDate[hidden.value];
    if (!saved || !saved.length) {
      boxes.forEach(function(cb){ cb.checked = true; });
      return;
    }
    var set = {};
    saved.forEach(function(h){ set[parseInt(h,10)] = true; });
    boxes.forEach(function(cb){ cb.checked = !!set[parseInt(cb.value,10)]; });
  }
  function sync(loadSaved){
    var t = faToEn(view.value).replace(/-/g,"/").trim();
    var p = t.split("/");
    if (p.length !== 3) { hidden.value = ""; lastAppliedDate = ""; fillHourDates(); return; }
    var g = jalaali.toGregorian(parseInt(p[0],10), parseInt(p[1],10), parseInt(p[2],10));
    hidden.value = g.gy + "-" + pad(g.gm) + "-" + pad(g.gd);
    fillHourDates();
    if (loadSaved) applySavedHours();
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
  view.addEventListener("jdp:change", function(){ sync(true); });
  view.addEventListener("change", function(){ sync(true); });
  var selectAllBtn = document.getElementById("select-all-hours");
  var clearAllBtn = document.getElementById("clear-all-hours");
  if (selectAllBtn) {
    selectAllBtn.addEventListener("click", function(){
      document.querySelectorAll("#hour-picker input[type=checkbox]").forEach(function(cb){ cb.checked = true; });
    });
  }
  if (clearAllBtn) {
    clearAllBtn.addEventListener("click", function(){
      document.querySelectorAll("#hour-picker input[type=checkbox]").forEach(function(cb){ cb.checked = false; });
    });
  }

  view.closest("form").addEventListener("submit", function(e){
    sync(false);
    if (!hidden.value) {
      e.preventDefault();
      alert("تاریخ را انتخاب کنید");
      return;
    }
    var checked = document.querySelectorAll("#hour-picker input[type=checkbox]:checked");
    if (!checked.length) {
      e.preventDefault();
      alert("حداقل یک ساعت خالی انتخاب کنید.");
    }
  });
})();
</script>
';
render_doctor_page('روزهای خالی', $inner);
