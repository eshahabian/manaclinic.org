<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/doctor_panel.php';
require_once __DIR__ . '/../../includes/workshops.php';

$ctx = require_doctor_profile($pdo);
ensure_workshop_schema($pdo);

$editId = trim((string) ($_GET['edit'] ?? ''));
$editWorkshop = null;
if ($editId !== '') {
    $es = $pdo->prepare('SELECT * FROM workshops WHERE id=? AND doctor_id=? LIMIT 1');
    $es->execute([$editId, $ctx['profile']['id']]);
    $editWorkshop = $es->fetch();
    if (!$editWorkshop) {
        flash_set('error', 'کارگاه برای ویرایش یافت نشد.');
        redirect('/doctor/workshops');
    }
}

$stmt = $pdo->prepare('
  SELECT w.*,
    (SELECT COUNT(*) FROM workshop_enrollments e
     WHERE e.workshop_id = w.id AND e.status IN ("PENDING_PAYMENT","CONFIRMED","COMPLETED")) AS enrolled_count
  FROM workshops w
  WHERE w.doctor_id = ?
  ORDER BY w.created_at DESC
');
$stmt->execute([$ctx['profile']['id']]);
$workshops = $stmt->fetchAll();
$doctorWallet = ensure_wallet($pdo, $ctx['user']['id']);
$flash = flash_get();

$formAction = $editWorkshop ? 'update' : 'create';
$formTitle = $editWorkshop ? 'ویرایش کارگاه' : 'کارگاه جدید';
$formSubmit = $editWorkshop ? 'ذخیره تغییرات' : 'ایجاد کارگاه';
$formData = $editWorkshop ?: [];

if ($editWorkshop) {
    $startParts = workshop_datetime_parts((string) $editWorkshop['starts_at']);
    $endParts = workshop_datetime_parts((string) $editWorkshop['ends_at']);
} else {
    $startParts = ['date' => '', 'time' => '10:00', 'jalali' => ''];
    $endParts = ['date' => '', 'time' => '12:00', 'jalali' => ''];
}

ob_start();
?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@majidh1/jalalidatepicker/dist/jalalidatepicker.min.css">
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">
<h1>کارگاه‌ها و دوره‌ها</h1>
<p class="muted" style="margin-top:.35rem;font-size:.9rem">کارگاه برگزار کنید؛ مراجعان از بخش «دوره‌های من» ثبت‌نام می‌کنند.</p>

<?php if ($flash): ?>
  <div class="panel" style="margin-top:1rem;font-size:.9rem;border-color:<?= $flash['type'] === 'success' ? 'var(--success)' : 'var(--danger)' ?>;color:<?= $flash['type'] === 'success' ? 'var(--success)' : 'var(--danger)' ?>">
    <?= e($flash['message']) ?>
  </div>
<?php endif; ?>

<div class="panel row-between" style="margin-top:1rem;font-size:.9rem">
  <div>
    <span class="muted">کیف پول شما — </span>
    <strong><?= e(format_price((int)$doctorWallet['balance'])) ?></strong>
    <span class="muted"> قابل برداشت · </span>
    <strong><?= e(format_price((int)$doctorWallet['held_balance'])) ?></strong>
    <span class="muted"> امانی (تا پایان کارگاه)</span>
  </div>
</div>

<div class="stack" style="margin-top:1.5rem">
  <h2 style="margin:0;font-size:1.05rem">کارگاه‌های من</h2>
  <?php foreach ($workshops as $workshop): ?>
    <div class="panel" id="workshop-<?= e($workshop['id']) ?>">
      <div class="row-between" style="align-items:flex-start">
        <div>
          <strong><?= e($workshop['title']) ?></strong>
          <span class="badge" style="margin-right:.5rem"><?= e(workshop_type_label($workshop['type'])) ?></span>
          <div class="muted" style="font-size:.85rem;margin-top:.35rem">
            <?= e(format_workshop_datetime_fa($workshop['starts_at'])) ?> — <?= e(format_workshop_datetime_fa($workshop['ends_at'])) ?>
          </div>
          <div class="muted" style="font-size:.85rem;margin-top:.25rem">
            <?= e(format_price((int)$workshop['price'])) ?>
            · ثبت‌نام: <?= (int)$workshop['enrolled_count'] ?><?= $workshop['capacity'] ? ' / ' . (int)$workshop['capacity'] : '' ?>
            · <?= $workshop['is_published'] ? 'منتشر شده' : 'پیش‌نویس' ?>
            · <?= $workshop['status'] === 'COMPLETED' ? 'برگزار شده' : ($workshop['status'] === 'CANCELLED' ? 'لغو شده' : 'فعال') ?>
          </div>
          <?php if ($workshop['type'] === 'IN_PERSON' && $workshop['location']): ?>
            <div class="muted" style="font-size:.8rem;margin-top:.35rem">محل: <?= e($workshop['location']) ?></div>
          <?php endif; ?>
        </div>
        <div style="display:flex;flex-wrap:wrap;gap:.5rem;justify-content:flex-end">
          <?php if ($workshop['status'] !== 'COMPLETED' && $workshop['status'] !== 'CANCELLED'): ?>
            <a class="btn btn-outline btn-sm" href="<?= e(url('/doctor/workshops?edit=' . $workshop['id'])) ?>#workshop-form">ویرایش</a>
          <?php endif; ?>
          <?php if ($workshop['status'] === 'PUBLISHED' || ($workshop['is_published'] && $workshop['status'] !== 'COMPLETED' && $workshop['status'] !== 'CANCELLED')): ?>
            <form method="post" action="<?= e(url('/doctor/workshops')) ?>">
              <input type="hidden" name="action" value="complete">
              <input type="hidden" name="id" value="<?= e($workshop['id']) ?>">
              <button class="btn btn-outline btn-sm" type="submit">تسویه و پایان</button>
            </form>
          <?php endif; ?>
          <form method="post" action="<?= e(url('/doctor/workshops')) ?>">
            <input type="hidden" name="action" value="toggle">
            <input type="hidden" name="id" value="<?= e($workshop['id']) ?>">
            <button class="btn btn-outline btn-sm" type="submit"><?= $workshop['is_published'] ? 'لغو انتشار' : 'انتشار' ?></button>
          </form>
          <form method="post" action="<?= e(url('/doctor/workshops')) ?>" onsubmit="return confirm('حذف شود؟')">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= e($workshop['id']) ?>">
            <button class="btn btn-danger btn-sm" type="submit">حذف</button>
          </form>
        </div>
      </div>
      <?php if ($workshop['items_to_bring']): ?>
        <p style="font-size:.85rem;margin:.75rem 0 0"><strong>موارد همراه:</strong> <?= e($workshop['items_to_bring']) ?></p>
      <?php endif; ?>
      <?php if ($workshop['notes']): ?>
        <p class="muted" style="font-size:.85rem;margin:.5rem 0 0"><strong>یادداشت:</strong> <?= e($workshop['notes']) ?></p>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
  <?php if (!$workshops): ?><p class="muted">هنوز کارگاهی ثبت نشده است.</p><?php endif; ?>
</div>

<form class="panel form-stack" id="workshop-form" method="post" action="<?= e(url('/doctor/workshops')) ?>" style="margin-top:1.5rem">
  <input type="hidden" name="action" value="<?= e($formAction) ?>">
  <?php if ($editWorkshop): ?>
    <input type="hidden" name="id" value="<?= e($editWorkshop['id']) ?>">
  <?php endif; ?>
  <div class="row-between" style="align-items:center;margin-bottom:.25rem">
    <h2 style="margin:0;font-size:1.05rem"><?= e($formTitle) ?></h2>
    <?php if ($editWorkshop): ?>
      <a href="<?= e(url('/doctor/workshops')) ?>" style="font-size:.85rem;color:var(--primary)">انصراف از ویرایش</a>
    <?php endif; ?>
  </div>

  <div><label class="label">نام کارگاه</label><input class="input" name="title" required value="<?= e($formData['title'] ?? '') ?>"></div>
  <div>
    <label class="label">نوع</label>
    <select class="input" name="type" id="workshop-type" required>
      <?php
        $types = ['IN_PERSON' => 'حضوری', 'ONLINE' => 'آنلاین', 'OFFLINE' => 'آفلاین (فایل/ویدیو)'];
        $currentType = $formData['type'] ?? 'IN_PERSON';
        foreach ($types as $val => $label):
      ?>
        <option value="<?= e($val) ?>"<?= $currentType === $val ? ' selected' : '' ?>><?= e($label) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="grid-2">
    <div>
      <label class="label">شروع — تاریخ (شمسی)</label>
      <input class="input workshop-date-view" type="text" id="workshop-start-date-view" data-jdp data-jdp-only-date autocomplete="off" readonly placeholder="انتخاب تاریخ" required value="<?= e($startParts['jalali']) ?>">
      <input type="hidden" name="start_date" id="workshop-start-date" value="<?= e($startParts['date']) ?>">
    </div>
    <div>
      <label class="label">شروع — ساعت</label>
      <input class="input" type="time" name="start_time" value="<?= e($startParts['time']) ?>" required>
    </div>
  </div>
  <div class="grid-2">
    <div>
      <label class="label">پایان — تاریخ (شمسی)</label>
      <input class="input workshop-date-view" type="text" id="workshop-end-date-view" data-jdp data-jdp-only-date autocomplete="off" readonly placeholder="انتخاب تاریخ" required value="<?= e($endParts['jalali']) ?>">
      <input type="hidden" name="end_date" id="workshop-end-date" value="<?= e($endParts['date']) ?>">
    </div>
    <div>
      <label class="label">پایان — ساعت</label>
      <input class="input" type="time" name="end_time" value="<?= e($endParts['time']) ?>" required>
    </div>
  </div>
  <div class="grid-2">
    <div>
      <label class="label">هزینه (تومان)</label>
      <input class="input" name="price" type="number" min="0" step="1000" value="<?= e((string) ($formData['price'] ?? 0)) ?>" required>
    </div>
    <div>
      <label class="label">ظرفیت (خالی = نامحدود)</label>
      <input class="input" name="capacity" type="number" min="1" value="<?= e((string) ($formData['capacity'] ?? '')) ?>">
    </div>
  </div>

  <div id="field-in-person" class="workshop-type-block" hidden>
    <label class="label" for="workshop-location">محل برگزاری</label>
    <textarea
      class="input"
      name="location"
      id="workshop-location"
      rows="2"
      placeholder="آدرس کامل محل برگزاری را بنویسید"
    ><?= e((string) ($formData['location'] ?? '')) ?></textarea>
    <p class="muted" style="font-size:.8rem;margin:.5rem 0 0;line-height:1.6">
      آدرس را دستی بنویسید یا با کلیک روی نقشه، موقعیت را انتخاب کنید (در صورت امکان آدرس خودکار پر می‌شود).
    </p>
    <button type="button" class="btn btn-outline btn-sm" id="toggle-workshop-map" style="margin-top:.5rem">انتخاب روی نقشه</button>
    <div id="workshop-map-wrap" hidden style="margin-top:.75rem">
      <div id="workshop-map" style="height:280px;border-radius:.75rem;border:1px solid var(--line);z-index:1"></div>
    </div>
  </div>

  <div id="field-online" class="workshop-type-block" hidden>
    <label class="label">لینک جلسه آنلاین</label>
    <input class="input" name="meeting_url" dir="ltr" placeholder="https://..." value="<?= e((string) ($formData['meeting_url'] ?? '')) ?>">
  </div>

  <div id="field-offline" class="workshop-type-block" hidden>
    <label class="label">لینک محتوا (آفلاین)</label>
    <input class="input" name="content_url" dir="ltr" placeholder="https://..." value="<?= e((string) ($formData['content_url'] ?? '')) ?>">
  </div>

  <div>
    <label class="label">موارد همراه</label>
    <textarea class="input" name="items_to_bring" rows="3" placeholder="مثلاً: دفترچه، مداد، ..."><?= e((string) ($formData['items_to_bring'] ?? '')) ?></textarea>
  </div>
  <div>
    <label class="label">توضیح کوتاه</label>
    <textarea class="input" name="description" rows="2"><?= e((string) ($formData['description'] ?? '')) ?></textarea>
  </div>
  <div>
    <label class="label">یادداشت</label>
    <textarea class="input" name="notes" rows="3" placeholder="یادداشت داخلی یا توضیحات تکمیلی"><?= e((string) ($formData['notes'] ?? '')) ?></textarea>
  </div>
  <label style="display:flex;gap:.5rem;align-items:center;font-size:.9rem">
    <input type="checkbox" name="published" <?= ($editWorkshop ? (bool)$editWorkshop['is_published'] : true) ? 'checked' : '' ?>> انتشار برای مراجعان
  </label>
  <button class="btn btn-primary" type="submit"><?= e($formSubmit) ?></button>
</form>

<script src="https://cdn.jsdelivr.net/npm/jalaali-js@1.2.7/dist/jalaali.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/@majidh1/jalalidatepicker/dist/jalalidatepicker.min.js"></script>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
<script>
(function(){
  function faToEn(str){ return String(str).replace(/[۰-۹]/g, function(d){ return "۰۱۲۳۴۵۶۷۸۹".indexOf(d); }); }
  function pad(n){ return (n < 10 ? "0" : "") + n; }
  function syncJalali(viewId, hiddenId){
    var view = document.getElementById(viewId);
    var hidden = document.getElementById(hiddenId);
    if (!view || !hidden) return;
    var t = faToEn(view.value).replace(/-/g, "/").trim();
    var p = t.split("/");
    if (p.length !== 3) { hidden.value = ""; return; }
    var g = jalaali.toGregorian(parseInt(p[0],10), parseInt(p[1],10), parseInt(p[2],10));
    hidden.value = g.gy + "-" + pad(g.gm) + "-" + pad(g.gd);
  }

  var typeSelect = document.getElementById("workshop-type");
  var blockInPerson = document.getElementById("field-in-person");
  var blockOnline = document.getElementById("field-online");
  var blockOffline = document.getElementById("field-offline");
  var locationInput = document.getElementById("workshop-location");
  var mapWrap = document.getElementById("workshop-map-wrap");
  var mapToggle = document.getElementById("toggle-workshop-map");
  var mapInstance = null;
  var mapMarker = null;

  function syncTypeBlocks(){
    if (!typeSelect) return;
    var t = typeSelect.value;
    if (blockInPerson) blockInPerson.hidden = t !== "IN_PERSON";
    if (blockOnline) blockOnline.hidden = t !== "ONLINE";
    if (blockOffline) blockOffline.hidden = t !== "OFFLINE";
    if (locationInput) {
      locationInput.required = t === "IN_PERSON";
    }
  }

  function initWorkshopMap(){
    if (mapInstance || !window.L) return;
    var el = document.getElementById("workshop-map");
    if (!el) return;
    mapInstance = L.map(el, { scrollWheelZoom: true }).setView([35.6892, 51.3890], 11);
    L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
      maxZoom: 19,
      attribution: "&copy; OpenStreetMap"
    }).addTo(mapInstance);
    mapInstance.on("click", function(e){
      var lat = e.latlng.lat;
      var lng = e.latlng.lng;
      if (mapMarker) mapMarker.setLatLng(e.latlng);
      else mapMarker = L.marker(e.latlng).addTo(mapInstance);
      if (locationInput) {
        locationInput.value = "در حال دریافت آدرس...";
      }
      fetch("https://nominatim.openstreetmap.org/reverse?format=json&lat=" + lat + "&lon=" + lng + "&accept-language=fa", {
        headers: { "Accept-Language": "fa" }
      })
        .then(function(r){ return r.json(); })
        .then(function(data){
          if (locationInput && data && data.display_name) {
            locationInput.value = data.display_name;
          } else if (locationInput) {
            locationInput.value = lat.toFixed(5) + ", " + lng.toFixed(5);
          }
        })
        .catch(function(){
          if (locationInput) {
            locationInput.value = lat.toFixed(5) + ", " + lng.toFixed(5) + " (آدرس را تکمیل کنید)";
          }
        });
    });
    setTimeout(function(){ mapInstance.invalidateSize(); }, 200);
  }

  if (mapToggle && mapWrap) {
    mapToggle.addEventListener("click", function(){
      var open = mapWrap.hidden;
      mapWrap.hidden = !open;
      mapToggle.textContent = open ? "بستن نقشه" : "انتخاب روی نقشه";
      if (open) initWorkshopMap();
    });
  }

  if (typeSelect) {
    typeSelect.addEventListener("change", syncTypeBlocks);
    syncTypeBlocks();
  }

  jalaliDatepicker.startWatch({
    selector: ".workshop-date-view",
    time: false,
    hideAfterChange: true,
    showTodayBtn: true,
    showEmptyBtn: true,
    autoReadOnlyInput: true,
    zIndex: 100000,
    container: "body"
  });

  var startView = document.getElementById("workshop-start-date-view");
  var endView = document.getElementById("workshop-end-date-view");
  if (startView) {
    startView.addEventListener("jdp:change", function(){ syncJalali("workshop-start-date-view", "workshop-start-date"); });
    startView.addEventListener("change", function(){ syncJalali("workshop-start-date-view", "workshop-start-date"); });
  }
  if (endView) {
    endView.addEventListener("jdp:change", function(){ syncJalali("workshop-end-date-view", "workshop-end-date"); });
    endView.addEventListener("change", function(){ syncJalali("workshop-end-date-view", "workshop-end-date"); });
  }

  var form = document.getElementById("workshop-form");
  if (form) {
    form.addEventListener("submit", function(e){
      syncJalali("workshop-start-date-view", "workshop-start-date");
      syncJalali("workshop-end-date-view", "workshop-end-date");
      var sd = document.getElementById("workshop-start-date");
      var ed = document.getElementById("workshop-end-date");
      if (!sd || !sd.value || !ed || !ed.value) {
        e.preventDefault();
        alert("تاریخ شروع و پایان را از تقویم شمسی انتخاب کنید.");
        return;
      }
      if (typeSelect && typeSelect.value === "IN_PERSON" && locationInput && !locationInput.value.trim()) {
        e.preventDefault();
        alert("آدرس محل برگزاری را بنویسید.");
      }
    });
  }
})();
</script>
<?php
render_doctor_page('کارگاه‌ها', ob_get_clean());
