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

ob_start();
?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@majidh1/jalalidatepicker/dist/jalalidatepicker.min.css">
<h1>روزهای خالی</h1>
<p class="muted">تاریخ را انتخاب کنید و ساعت‌های خالی (۱۰ تا ۱۷) را مشخص کنید.</p>
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
          <span><?= (int) $hour ?></span>
        </label>
      <?php endforeach; ?>
    </div>
    <p class="muted" style="font-size:.8rem;margin:.5rem 0 0;line-height:1.6">
      ساعت‌های ۱۰ تا ۱۷ — هر جلسه یک ساعت کامل است (مثلاً ۱۷ یعنی ۱۷:۰۰ تا ۱۸:۰۰).
    </p>
    <div style="margin-top:.5rem;display:flex;gap:.5rem;flex-wrap:wrap">
      <button type="button" class="btn btn-outline btn-sm" id="select-all-hours">انتخاب همه (۱۰–۱۷)</button>
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
            <span class="hour-chip<?= in_array($hour, $savedHours, true) ? ' is-active' : ' is-off' ?>"><?= (int) $hour ?></span>
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
  function sync(){
    var t = faToEn(view.value).replace(/-/g,"/").trim();
    var p = t.split("/");
    if (p.length !== 3) { hidden.value = ""; return; }
    var g = jalaali.toGregorian(parseInt(p[0],10), parseInt(p[1],10), parseInt(p[2],10));
    hidden.value = g.gy + "-" + pad(g.gm) + "-" + pad(g.gd);
  }
  jalaliDatepicker.startWatch({
    selector: "#avail-date-view",
    time: false,
    hideAfterChange: true,
    autoReadOnlyInput: true,
    zIndex: 99999
  });
  view.addEventListener("jdp:change", sync);
  view.addEventListener("change", sync);

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
    sync();
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
