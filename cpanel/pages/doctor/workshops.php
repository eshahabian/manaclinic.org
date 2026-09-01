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
$locationPresets = workshop_location_presets();

$formAction = $editWorkshop ? 'update' : 'create';
$formTitle = $editWorkshop ? 'ویرایش کارگاه' : 'کارگاه جدید';
$formSubmit = $editWorkshop ? 'ذخیره تغییرات' : 'ایجاد کارگاه';
$formData = $editWorkshop ?: [];

if ($editWorkshop) {
    $startParts = workshop_datetime_parts((string) $editWorkshop['starts_at']);
    $endParts = workshop_datetime_parts((string) $editWorkshop['ends_at']);
    $locationKey = workshop_location_preset_key($editWorkshop['location'] ?? null);
    $locationCustom = $locationKey === '__custom__' ? (string) ($editWorkshop['location'] ?? '') : '';
} else {
    $startParts = ['date' => '', 'time' => '10:00', 'jalali' => ''];
    $endParts = ['date' => '', 'time' => '12:00', 'jalali' => ''];
    $locationKey = '';
    $locationCustom = '';
}

ob_start();
?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@majidh1/jalalidatepicker/dist/jalalidatepicker.min.css">
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
    <label class="label">محل برگزاری</label>
    <select class="input" name="location_preset" id="location-preset">
      <option value="">— انتخاب محل —</option>
      <?php foreach ($locationPresets as $key => $label): ?>
        <option value="<?= e($key) ?>"<?= $locationKey === $key ? ' selected' : '' ?>><?= e($label) ?></option>
      <?php endforeach; ?>
    </select>
    <input
      class="input"
      type="text"
      name="location_custom"
      id="location-custom"
      style="margin-top:.5rem"
      placeholder="آدرس کامل را بنویسید"
      value="<?= e($locationCustom) ?>"
    >
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
  var locationPreset = document.getElementById("location-preset");
  var locationCustom = document.getElementById("location-custom");

  function syncLocationCustom(){
    if (!locationPreset || !locationCustom) return;
    var show = locationPreset.value === "__custom__";
    locationCustom.style.display = show ? "block" : "none";
    locationCustom.required = show && typeSelect && typeSelect.value === "IN_PERSON";
  }

  function syncTypeBlocks(){
    if (!typeSelect) return;
    var t = typeSelect.value;
    if (blockInPerson) blockInPerson.hidden = t !== "IN_PERSON";
    if (blockOnline) blockOnline.hidden = t !== "ONLINE";
    if (blockOffline) blockOffline.hidden = t !== "OFFLINE";
    syncLocationCustom();
  }

  if (typeSelect) {
    typeSelect.addEventListener("change", syncTypeBlocks);
    syncTypeBlocks();
  }
  if (locationPreset) {
    locationPreset.addEventListener("change", syncLocationCustom);
    syncLocationCustom();
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
      if (typeSelect && typeSelect.value === "IN_PERSON" && locationPreset) {
        if (!locationPreset.value) {
          e.preventDefault();
          alert("محل برگزاری را انتخاب کنید.");
          return;
        }
        if (locationPreset.value === "__custom__" && locationCustom && !locationCustom.value.trim()) {
          e.preventDefault();
          alert("آدرس محل برگزاری را بنویسید.");
        }
      }
    });
  }
})();
</script>
<?php
render_doctor_page('کارگاه‌ها', ob_get_clean());
