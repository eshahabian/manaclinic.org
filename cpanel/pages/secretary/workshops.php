<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/secretary_panel.php';
require_once __DIR__ . '/../../includes/workshops.php';
require_once __DIR__ . '/../../includes/workshop_media.php';

$user = require_login(['SECRETARY']);
ensure_workshop_schema($pdo);
ensure_workshop_media_schema($pdo);

$doctors = workshop_approved_doctors($pdo);
$editId = trim((string) ($_GET['edit'] ?? ''));
$editWorkshop = null;
if ($editId !== '') {
    $es = $pdo->prepare('SELECT * FROM workshops WHERE id=? LIMIT 1');
    $es->execute([$editId]);
    $editWorkshop = $es->fetch();
    if (!$editWorkshop) {
        flash_set('error', 'کارگاه برای ویرایش یافت نشد.');
        redirect('/secretary/workshops');
    }
}

$workshops = $pdo->query('
  SELECT w.*, u.name AS doctor_name,
    (SELECT COUNT(*) FROM workshop_enrollments e
     WHERE e.workshop_id = w.id AND e.status IN ("PENDING_PAYMENT","CONFIRMED","COMPLETED")) AS enrolled_count,
    (SELECT COUNT(*) FROM workshop_media_items m WHERE m.workshop_id = w.id AND m.kind = "VIDEO") AS video_count,
    (SELECT COUNT(*) FROM workshop_media_items m WHERE m.workshop_id = w.id AND m.kind = "AUDIO") AS audio_count
  FROM workshops w
  JOIN doctor_profiles dp ON dp.id = w.doctor_id
  JOIN users u ON u.id = dp.user_id
  ORDER BY w.created_at DESC
')->fetchAll();

$grouped = workshop_group_for_tabs($workshops);
$tabParam = trim((string) ($_GET['tab'] ?? ''));
if ($editWorkshop) {
    $binderInitial = 'new';
} elseif (in_array($tabParam, ['in-person', 'online', 'offline', 'new', 'archive'], true)) {
    $binderInitial = $tabParam;
} else {
    $binderInitial = '';
}

$flash = flash_get();
$formAction = $editWorkshop ? 'update' : 'create';
$formTitle = $editWorkshop ? 'ویرایش کارگاه' : 'کارگاه جدید';
$formSubmit = $editWorkshop ? 'ذخیره تغییرات' : 'ایجاد کارگاه';
$formData = $editWorkshop ?: [];
$selectedDoctorId = (string) ($formData['doctor_id'] ?? '');

if ($editWorkshop) {
    $startParts = workshop_datetime_parts((string) $editWorkshop['starts_at']);
    $endParts = workshop_datetime_parts((string) $editWorkshop['ends_at']);
} else {
    $startParts = ['date' => '', 'time' => '10:00', 'jalali' => ''];
    $endParts = ['date' => '', 'time' => '18:00', 'jalali' => ''];
}

$workshopMedia = [];
if ($editWorkshop) {
    $workshopMedia = workshop_media_list($pdo, (string) $editWorkshop['id']);
}
global $config;
$mediaMaxMb = (int) ($config['workshop_media_max_mb'] ?? 300);

ob_start();
?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@majidh1/jalalidatepicker/dist/jalalidatepicker.min.css">
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">
<h1>کارگاه‌ها و دوره‌ها</h1>
<p class="muted" style="margin-top:.35rem;font-size:.9rem">کارگاه‌ها را از تب‌های رنگی جدا ببینید. کارگاه حضوری و آنلاین پس از پایان زمان، خودکار به آرشیو می‌رود.</p>

<?php if ($flash): ?>
  <div class="panel" style="margin-top:1rem;font-size:.9rem;border-color:<?= $flash['type'] === 'success' ? 'var(--success)' : 'var(--danger)' ?>;color:<?= $flash['type'] === 'success' ? 'var(--success)' : 'var(--danger)' ?>">
    <?= e($flash['message']) ?>
  </div>
<?php endif; ?>

<div class="binder-tile" data-binder-tabs data-binder-initial="<?= e($binderInitial) ?>" data-binder-tone="<?= e($binderInitial !== '' ? $binderInitial : 'in-person') ?>" style="margin-top:1.5rem">
  <div class="binder-tabs" role="tablist" aria-label="دسته‌بندی کارگاه‌ها">
    <button type="button" class="binder-tab binder-tab-in-person is-active" role="tab" data-binder-tab="in-person" aria-selected="true">
      حضوری <span class="binder-tab-count"><?= count($grouped['in-person']) ?></span>
    </button>
    <button type="button" class="binder-tab binder-tab-online" role="tab" data-binder-tab="online" aria-selected="false">
      آنلاین <span class="binder-tab-count"><?= count($grouped['online']) ?></span>
    </button>
    <button type="button" class="binder-tab binder-tab-offline" role="tab" data-binder-tab="offline" aria-selected="false">
      آفلاین <span class="binder-tab-count"><?= count($grouped['offline']) ?></span>
    </button>
    <button type="button" class="binder-tab binder-tab-new" role="tab" data-binder-tab="new" aria-selected="false">
      <?= $editWorkshop ? 'ویرایش کارگاه' : 'کارگاه جدید' ?>
    </button>
    <button type="button" class="binder-tab binder-tab-archive" role="tab" data-binder-tab="archive" aria-selected="false">
      آرشیو <span class="binder-tab-count"><?= count($grouped['archive']) ?></span>
    </button>
  </div>
  <div class="binder-body">
    <section class="binder-panel is-active" data-binder-panel="in-person" role="tabpanel">
      <?php $workshopList = $grouped['in-person']; $workshopEmpty = 'کارگاه حضوری فعالی نیست.'; $workshopRole = 'secretary'; require __DIR__ . '/../../includes/workshop_manage_cards.php'; ?>
    </section>
    <section class="binder-panel" data-binder-panel="online" role="tabpanel" hidden>
      <?php $workshopList = $grouped['online']; $workshopEmpty = 'کارگاه آنلاین فعالی نیست.'; $workshopRole = 'secretary'; require __DIR__ . '/../../includes/workshop_manage_cards.php'; ?>
    </section>
    <section class="binder-panel" data-binder-panel="offline" role="tabpanel" hidden>
      <?php $workshopList = $grouped['offline']; $workshopEmpty = 'دوره آفلاین فعالی نیست.'; $workshopRole = 'secretary'; require __DIR__ . '/../../includes/workshop_manage_cards.php'; ?>
    </section>
    <section class="binder-panel" data-binder-panel="archive" role="tabpanel" hidden>
      <p class="muted" style="margin:0 0 .85rem;font-size:.9rem">کارگاه‌هایی که زمانشان تمام شده، خودکار به آرشیو می‌آیند.</p>
      <?php $workshopList = $grouped['archive']; $workshopEmpty = 'هنوز کارگاهی در آرشیو نیست.'; $workshopRole = 'secretary'; require __DIR__ . '/../../includes/workshop_manage_cards.php'; ?>
    </section>
    <section class="binder-panel" data-binder-panel="new" role="tabpanel" hidden>
      <div class="stack">
<?php if ($editWorkshop && $workshopMedia): ?>
  <section class="panel stack" style="margin-bottom:0">
    <div class="row-between" style="align-items:center;gap:.75rem;flex-wrap:wrap">
      <h3 style="margin:0;font-size:.95rem">فایل‌های بارگذاری‌شده</h3>
      <?= workshop_media_counts_html(workshop_media_kind_counts_from_list($workshopMedia), false) ?>
    </div>
    <?php foreach ($workshopMedia as $media): ?>
      <div class="panel" style="padding:.75rem;font-size:.9rem">
        <div class="row-between" style="align-items:flex-start;gap:.75rem">
          <div style="min-width:0">
            <strong><?= e($media['title']) ?></strong>
            <span class="badge" style="margin-right:.35rem"><?= e(workshop_media_kind_label($media['kind'])) ?></span>
            <div class="muted" style="font-size:.8rem;margin-top:.25rem"><?= e($media['original_name']) ?> · <?= e(workshop_media_format_size((int)$media['file_size'])) ?></div>
          </div>
          <form method="post" action="<?= e(url('/secretary/workshop-media')) ?>" onsubmit="return confirm('این فایل حذف شود؟')">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="workshop_id" value="<?= e($editWorkshop['id']) ?>">
            <input type="hidden" name="item_id" value="<?= e($media['id']) ?>">
            <button type="submit" class="btn btn-danger btn-sm">حذف</button>
          </form>
        </div>
      </div>
    <?php endforeach; ?>
  </section>
<?php endif; ?>

<form class="panel form-stack" id="workshop-form" method="post" action="<?= e(url('/secretary/workshops')) ?>" enctype="multipart/form-data">
  <input type="hidden" name="action" value="<?= e($formAction) ?>">
  <?php if ($editWorkshop): ?>
    <input type="hidden" name="id" value="<?= e($editWorkshop['id']) ?>">
  <?php endif; ?>
  <div class="row-between" style="align-items:center;margin-bottom:.25rem">
    <h2 style="margin:0;font-size:1.05rem"><?= e($formTitle) ?></h2>
    <?php if ($editWorkshop): ?>
      <a href="<?= e(url('/secretary/workshops')) ?>" style="font-size:.85rem;color:var(--primary)">انصراف از ویرایش</a>
    <?php endif; ?>
  </div>

  <div>
    <label class="label">درمانگر مسئول</label>
    <select class="input" name="doctor_id" required>
      <option value="">انتخاب درمانگر...</option>
      <?php foreach ($doctors as $doc): ?>
        <option value="<?= e($doc['id']) ?>"<?= $selectedDoctorId === $doc['id'] ? ' selected' : '' ?>><?= e($doc['name']) ?></option>
      <?php endforeach; ?>
    </select>
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
  <div id="field-schedule" class="workshop-type-block">
    <div class="grid-2">
      <div>
        <label class="label">شروع — تاریخ (شمسی)</label>
        <input class="input workshop-date-view" type="text" id="workshop-start-date-view" data-jdp data-jdp-only-date autocomplete="off" readonly placeholder="انتخاب تاریخ" value="<?= e($startParts['jalali']) ?>">
        <input type="hidden" name="start_date" id="workshop-start-date" value="<?= e($startParts['date']) ?>">
      </div>
      <div>
        <label class="label">شروع — ساعت</label>
        <input class="input" type="time" name="start_time" id="workshop-start-time" value="<?= e($startParts['time']) ?>">
      </div>
    </div>
    <div class="grid-2" style="margin-top:.75rem">
      <div>
        <label class="label">پایان — تاریخ (شمسی)</label>
        <input class="input workshop-date-view" type="text" id="workshop-end-date-view" data-jdp data-jdp-only-date autocomplete="off" readonly placeholder="انتخاب تاریخ" value="<?= e($endParts['jalali']) ?>">
        <input type="hidden" name="end_date" id="workshop-end-date" value="<?= e($endParts['date']) ?>">
      </div>
      <div>
        <label class="label">پایان — ساعت</label>
        <input class="input" type="time" name="end_time" id="workshop-end-time" value="<?= e($endParts['time']) ?>">
      </div>
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
    <label class="label" for="workshop-location">آدرس محل برگزاری</label>
    <textarea class="input" name="location" id="workshop-location" rows="3"><?= e((string) ($formData['location'] ?? '')) ?></textarea>
    <input type="hidden" name="location_lat" id="workshop-location-lat" value="<?= e((string) ($formData['location_lat'] ?? '')) ?>">
    <input type="hidden" name="location_lng" id="workshop-location-lng" value="<?= e((string) ($formData['location_lng'] ?? '')) ?>">
    <p class="muted" id="workshop-coords-hint" style="font-size:.8rem;margin:.5rem 0 0;line-height:1.6"></p>
    <button type="button" class="btn btn-outline btn-sm" id="toggle-workshop-map" style="margin-top:.5rem">انتخاب موقعیت روی نقشه</button>
    <div id="workshop-map-wrap" hidden style="margin-top:.75rem">
      <div id="workshop-map" class="workshop-map-panel"></div>
    </div>
  </div>

  <div id="field-online" class="workshop-type-block" hidden>
    <label class="label">لینک جلسه آنلاین</label>
    <input class="input" name="meeting_url" dir="ltr" placeholder="https://..." value="<?= e((string) ($formData['meeting_url'] ?? '')) ?>">
  </div>

  <div>
    <label class="label">لینک گروه تلگرام / واتساپ (اختیاری)</label>
    <input class="input" name="group_url" dir="ltr" placeholder="https://t.me/... یا https://chat.whatsapp.com/..." value="<?= e((string) ($formData['group_url'] ?? '')) ?>">
    <p class="muted" style="font-size:.8rem;margin:.35rem 0 0;line-height:1.6">مراجعان پس از ثبت‌نام و تأیید، این لینک را برای عضویت در گروه می‌بینند.</p>
  </div>

  <div id="field-session-media" class="panel" style="padding:1rem;background:var(--bg-soft,#f8fafc);border-style:dashed">
    <h3 style="margin:0;font-size:.95rem">ضبط جلسات (ویدیو / صوت)</h3>
    <p class="muted" style="font-size:.85rem;line-height:1.65;margin:.35rem 0 0">حداکثر <?= (int) $mediaMaxMb ?> مگابایت برای هر فایل — برای آفلاین حداقل یک فایل لازم است.</p>
    <div id="session-media-rows" class="stack" style="margin-top:1rem"></div>
    <button type="button" class="btn btn-outline btn-sm" id="add-session-media-row" style="margin-top:.75rem">+ افزودن ویدیو / صوت</button>
  </div>

  <div>
    <label class="label">توضیح کوتاه</label>
    <textarea class="input" name="description" rows="2"><?= e((string) ($formData['description'] ?? '')) ?></textarea>
  </div>
  <div>
    <label class="label">موارد همراه</label>
    <textarea class="input" name="items_to_bring" rows="2"><?= e((string) ($formData['items_to_bring'] ?? '')) ?></textarea>
  </div>
  <div>
    <label class="label">یادداشت</label>
    <textarea class="input" name="notes" rows="2"><?= e((string) ($formData['notes'] ?? '')) ?></textarea>
  </div>
  <button class="btn btn-primary" type="submit"><?= e($formSubmit) ?></button>
</form>
      </div>
    </section>
  </div>
</div>

<template id="session-media-row-template">
  <div class="session-media-row panel" style="padding:.75rem;font-size:.9rem;background:#fff">
    <div class="grid-2">
      <div>
        <label class="label">نوع</label>
        <select class="input" name="media_kind[]">
          <option value="VIDEO">ویدیو</option>
          <option value="AUDIO">صوت</option>
        </select>
      </div>
      <div>
        <label class="label">عنوان</label>
        <input class="input" name="media_title[]" placeholder="مثلاً: جلسه اول">
      </div>
    </div>
    <div style="margin-top:.5rem">
      <label class="label">توضیح (اختیاری)</label>
      <textarea class="input" name="media_description[]" rows="2"></textarea>
    </div>
    <div style="margin-top:.5rem">
      <label class="label">فایل</label>
      <input class="input session-media-file" type="file" name="media_files[]" accept="video/*,audio/*,.mp3,.m4a,.wav,.ogg,.mp4,.webm,.mov">
    </div>
    <button type="button" class="btn btn-outline btn-sm remove-session-media-row" style="margin-top:.5rem">حذف این ردیف</button>
  </div>
</template>

<script src="<?= e(url('/assets/js/binder-tabs.js')) ?>?v=20260904u"></script>
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
  var blockSchedule = document.getElementById("field-schedule");
  var blockInPerson = document.getElementById("field-in-person");
  var blockOnline = document.getElementById("field-online");
  var sessionRows = document.getElementById("session-media-rows");
  var sessionRowTemplate = document.getElementById("session-media-row-template");
  var addSessionRowBtn = document.getElementById("add-session-media-row");
  var startView = document.getElementById("workshop-start-date-view");
  var endView = document.getElementById("workshop-end-date-view");
  var startTime = document.getElementById("workshop-start-time");
  var endTime = document.getElementById("workshop-end-time");
  var hasExistingMedia = <?= $editWorkshop && $workshopMedia ? 'true' : 'false' ?>;
  var locationInput = document.getElementById("workshop-location");
  var locationLatInput = document.getElementById("workshop-location-lat");
  var locationLngInput = document.getElementById("workshop-location-lng");
  var coordsHint = document.getElementById("workshop-coords-hint");
  var mapWrap = document.getElementById("workshop-map-wrap");
  var mapToggle = document.getElementById("toggle-workshop-map");
  var mapInstance = null;
  var mapMarker = null;

  function updateCoordsHint(){
    if (!coordsHint) return;
    var lat = locationLatInput && locationLatInput.value;
    var lng = locationLngInput && locationLngInput.value;
    coordsHint.textContent = (lat && lng) ? ("موقعیت نقشه ثبت شد: " + lat + " ، " + lng) : "هنوز موقعیت روی نقشه انتخاب نشده است.";
  }
  function setMapCoords(lat, lng, pan){
    if (locationLatInput) locationLatInput.value = Number(lat).toFixed(6);
    if (locationLngInput) locationLngInput.value = Number(lng).toFixed(6);
    updateCoordsHint();
    if (mapInstance) {
      var ll = L.latLng(lat, lng);
      if (mapMarker) mapMarker.setLatLng(ll);
      else mapMarker = L.marker(ll).addTo(mapInstance);
      if (pan) mapInstance.setView(ll, 15);
    }
  }
  function addSessionMediaRow(){
    if (!sessionRows || !sessionRowTemplate) return;
    var node = sessionRowTemplate.content.cloneNode(true);
    sessionRows.appendChild(node);
    var rows = sessionRows.querySelectorAll(".session-media-row");
    var last = rows[rows.length - 1];
    if (last) {
      var removeBtn = last.querySelector(".remove-session-media-row");
      if (removeBtn) removeBtn.addEventListener("click", function(){
        var isOffline = typeSelect && typeSelect.value === "OFFLINE";
        if (isOffline && sessionRows.querySelectorAll(".session-media-row").length <= 1 && !hasExistingMedia) {
          alert("حداقل یک فایل برای دوره آفلاین لازم است.");
          return;
        }
        last.remove();
      });
    }
  }
  function syncTypeBlocks(){
    if (!typeSelect) return;
    var t = typeSelect.value;
    var isOffline = t === "OFFLINE";
    if (blockSchedule) blockSchedule.hidden = isOffline;
    if (blockInPerson) blockInPerson.hidden = t !== "IN_PERSON";
    if (blockOnline) blockOnline.hidden = t !== "ONLINE";
    if (locationInput) locationInput.required = t === "IN_PERSON";
    if (startView) startView.required = !isOffline;
    if (endView) endView.required = !isOffline;
    if (startTime) startTime.required = !isOffline;
    if (endTime) endTime.required = !isOffline;
    if (isOffline && sessionRows && !sessionRows.children.length) addSessionMediaRow();
  }
  function initWorkshopMap(){
    if (mapInstance || !window.L) return;
    var el = document.getElementById("workshop-map");
    if (!el) return;
    var lat = locationLatInput && locationLatInput.value ? parseFloat(locationLatInput.value) : 35.6892;
    var lng = locationLngInput && locationLngInput.value ? parseFloat(locationLngInput.value) : 51.3890;
    var zoom = (locationLatInput && locationLatInput.value) ? 15 : 11;
    mapInstance = L.map(el, { scrollWheelZoom: true }).setView([lat, lng], zoom);
    L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", { maxZoom: 19, attribution: "&copy; OpenStreetMap" }).addTo(mapInstance);
    if (locationLatInput && locationLatInput.value && locationLngInput && locationLngInput.value) {
      mapMarker = L.marker([lat, lng]).addTo(mapInstance);
    }
    mapInstance.on("click", function(e){ setMapCoords(e.latlng.lat, e.latlng.lng, false); });
    setTimeout(function(){ mapInstance.invalidateSize(); }, 200);
  }

  updateCoordsHint();
  if (mapToggle && mapWrap) {
    mapToggle.addEventListener("click", function(){
      var open = mapWrap.hidden;
      mapWrap.hidden = !open;
      mapToggle.textContent = open ? "بستن نقشه" : "انتخاب روی نقشه";
      if (open) initWorkshopMap();
    });
  }
  if (addSessionRowBtn) addSessionRowBtn.addEventListener("click", addSessionMediaRow);
  if (typeSelect) { typeSelect.addEventListener("change", syncTypeBlocks); syncTypeBlocks(); }

  jalaliDatepicker.startWatch({
    selector: ".workshop-date-view", time: false, hideAfterChange: true,
    showTodayBtn: true, showEmptyBtn: true, autoReadOnlyInput: true, zIndex: 100000, container: "body"
  });
  ["workshop-start-date-view","workshop-end-date-view"].forEach(function(id){
    var el = document.getElementById(id);
    if (!el) return;
    var hidden = id.indexOf("start") >= 0 ? "workshop-start-date" : "workshop-end-date";
    el.addEventListener("jdp:change", function(){ syncJalali(id, hidden); });
    el.addEventListener("change", function(){ syncJalali(id, hidden); });
  });

  var form = document.getElementById("workshop-form");
  if (form) {
    form.addEventListener("submit", function(e){
      var t = typeSelect ? typeSelect.value : "";
      if (t !== "OFFLINE") {
        syncJalali("workshop-start-date-view", "workshop-start-date");
        syncJalali("workshop-end-date-view", "workshop-end-date");
        var sd = document.getElementById("workshop-start-date");
        var ed = document.getElementById("workshop-end-date");
        if (!sd || !sd.value || !ed || !ed.value) {
          e.preventDefault(); alert("تاریخ شروع و پایان را انتخاب کنید."); return;
        }
      } else {
        var hasNewFile = false;
        if (sessionRows) sessionRows.querySelectorAll(".session-media-file").forEach(function(input){
          if (input.files && input.files.length) hasNewFile = true;
        });
        if (!hasNewFile && !hasExistingMedia) {
          e.preventDefault(); alert("حداقل یک ویدیو یا فایل صوتی انتخاب کنید."); return;
        }
      }
      if (t === "IN_PERSON") {
        if (locationInput && !locationInput.value.trim()) { e.preventDefault(); alert("آدرس را بنویسید."); return; }
        if (!locationLatInput.value || !locationLngInput.value) { e.preventDefault(); alert("موقعیت روی نقشه را انتخاب کنید."); }
      }
    });
  }
})();
</script>
<?php
render_secretary_page('کارگاه‌ها', ob_get_clean());
