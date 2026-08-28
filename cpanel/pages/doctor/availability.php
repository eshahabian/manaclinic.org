<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/doctor_panel.php';
$ctx = require_doctor_profile($pdo);
$items = $pdo->prepare('SELECT * FROM availabilities WHERE doctor_id=? ORDER BY date ASC');
$items->execute([$ctx['profile']['id']]);
$items = $items->fetchAll();

ob_start();
?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@majidh1/jalalidatepicker/dist/jalalidatepicker.min.css">
<h1>روزهای خالی</h1>
<p class="muted">تاریخ‌هایی که بیماران می‌توانند در آن‌ها نوبت بگیرند را مشخص کنید.</p>
<form class="panel form-stack" method="post" action="<?= e(url('/doctor/availability')) ?>" style="margin-top:1rem;max-width:40rem">
  <input type="hidden" name="action" value="save">
  <div>
    <label class="label">تاریخ (شمسی)</label>
    <input class="input" type="text" id="avail-date-view" name="date_jalali" data-jdp data-jdp-only-date autocomplete="off" readonly required placeholder="انتخاب تاریخ">
    <input type="hidden" name="date" id="avail-date" value="">
  </div>
  <div class="grid-2">
    <div><label class="label">از ساعت</label><input class="input" type="time" name="start_time" value="10:00" required></div>
    <div><label class="label">تا ساعت</label><input class="input" type="time" name="end_time" value="14:00" required></div>
  </div>
  <div><label class="label">مدت هر جلسه (دقیقه)</label><input class="input" type="number" name="slot_minutes" value="50" min="20" max="180" required></div>
  <button class="btn btn-primary" type="submit">افزودن / به‌روزرسانی</button>
</form>
<div class="stack" style="margin-top:1.5rem">
  <?php foreach ($items as $item): ?>
    <div class="panel row-between">
      <div>
        <strong><?= e(to_jalali_label($item['date'])) ?></strong>
        <div class="muted" style="font-size:.85rem"><?= e($item['start_time']) ?> تا <?= e($item['end_time']) ?> — هر اسلات <?= e((string)$item['slot_minutes']) ?> دقیقه</div>
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
  view.closest("form").addEventListener("submit", function(e){
    sync();
    if (!hidden.value) { e.preventDefault(); alert("تاریخ را انتخاب کنید"); }
  });
})();
</script>
<?php
render_doctor_page('روزهای خالی', ob_get_clean());
