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

ob_start();
?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@majidh1/jalalidatepicker/dist/jalalidatepicker.min.css">
<h1>روزهای خالی</h1>
<p class="muted">تاریخ را انتخاب کنید و ساعت‌های خالی را از ۶ صبح همان روز تا ۶ صبح فردا مشخص کنید.</p>
<form class="panel form-stack" method="post" action="<?= e(url('/doctor/availability')) ?>" style="margin-top:1rem;max-width:40rem">
  <input type="hidden" name="action" value="save">
  <div>
    <label class="label">تاریخ (شمسی)</label>
    <input class="input" type="text" id="avail-date-view" name="date_jalali" data-jdp data-jdp-only-date autocomplete="off" readonly required placeholder="انتخاب تاریخ">
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
<div class="stack" style="margin-top:1.5rem">
  <?php foreach ($items as $item): ?>
    <?php $savedHours = appointment_availability_hours($item); ?>
    <div class="panel row-between">
      <div>
        <strong><?= e(to_jalali_label($item['date'])) ?></strong>
        <div class="muted" style="font-size:.85rem;margin-top:.35rem">ساعت‌های خالی:</div>
        <div class="hour-picker hour-picker-readonly" style="margin-top:.35rem">
          <?php foreach ($bookingHours as $hour): ?>
            <span class="hour-chip<?= in_array($hour, $savedHours, true) ? ' is-active' : ' is-off' ?>">
              <span class="hour-chip-time"><?= e(appointment_hour_chip_label((int) $hour)) ?></span>
              <span class="hour-chip-date"><?= e(appointment_hour_date_for((string) $item['date'], (int) $hour)) ?></span>
            </span>
          <?php endforeach; ?>
        </div>
      </div>
      <form method="post" action="<?= e(url('/doctor/availability')) ?>">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="id" value="<?= e($item['id']) ?>">
        <button class="btn btn-danger btn-sm" type="submit">حذف</button>
      </form>
    </div>
  <?php endforeach; ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/jalaali-js@1.2.7/dist/jalaali.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/@majidh1/jalalidatepicker/dist/jalalidatepicker.min.js"></script>
<script>
(function(){
  function faToEn(str){ return String(str).replace(/[۰-۹]/g, function(d){ return "۰۱۲۳۴۵۶۷۸۹".indexOf(d); }); }
  function pad(n){ return (n < 10 ? "0" : "") + n; }
  var view = document.getElementById("avail-date-view");
  var hidden = document.getElementById("avail-date");
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
    var boxes = document.querySelectorAll(\'#hour-picker input[name="hours[]"]\');
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
  jalaliDatepicker.startWatch({
    selector: "#avail-date-view",
    time: false,
    hideAfterChange: true,
    autoReadOnlyInput: true,
    zIndex: 99999
  });
  view.addEventListener("jdp:change", function(){ sync(true); });
  view.addEventListener("change", function(){ sync(true); });
  var selectAllBtn = document.getElementById("select-all-hours");
  var clearAllBtn = document.getElementById("clear-all-hours");
  if (selectAllBtn) {
    selectAllBtn.addEventListener("click", function(){
      document.querySelectorAll('#hour-picker input[name="hours[]"]').forEach(function(cb){ cb.checked = true; });
    });
  }
  if (clearAllBtn) {
    clearAllBtn.addEventListener("click", function(){
      document.querySelectorAll('#hour-picker input[name="hours[]"]').forEach(function(cb){ cb.checked = false; });
    });
  }

  view.closest("form").addEventListener("submit", function(e){
    sync(false);
    if (!hidden.value) {
      e.preventDefault();
      alert("تاریخ را انتخاب کنید");
      return;
    }
    var checked = document.querySelectorAll('#hour-picker input[name="hours[]"]:checked');
    if (!checked.length) {
      e.preventDefault();
      alert("حداقل یک ساعت خالی انتخاب کنید.");
    }
  });
})();
</script>
<?php
render_doctor_page('روزهای خالی', ob_get_clean());
