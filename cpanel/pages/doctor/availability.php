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
$todayYmd = date('Y-m-d');

ob_start();
?>
<h1>روزهای خالی</h1>
<p class="muted">یک روز را از تقویم بزنید، یا تب <strong>کل ماه</strong> را انتخاب کنید تا ساعت‌ها روی همهٔ روزهای باقی‌مانده همان ماه اعمال شود. پایین صفحه، تب هر ماه با «کل شهریور» / «کل مهر» بازهٔ کل ماه را نشان می‌دهد؛ روی ساعت هر روز بزنید تا خالی بودن یا نام رزروکننده مشخص شود.</p>
<form class="panel form-stack" method="post" action="<?= e(url('/doctor/availability')) ?>" id="avail-save-form" style="margin-top:1rem;max-width:40rem">
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
                if ($monthEnd < $todayYmd) {
                    continue;
                }
              ?>
              <option value="<?= e((string) ($bucket['key'] ?? '')) ?>"<?= $defaultMonthId === (string) $mid ? ' selected' : '' ?>>
                <?= e(doctor_availability_month_range_label($bucket)) ?>
                · <?= e((string) ($bucket['label'] ?? '')) ?>
              </option>
            <?php endforeach; ?>
          </select>
          <p class="muted" style="font-size:.8rem;margin:.45rem 0 0;line-height:1.6">ساعت‌های پایین روی همهٔ روزهای باقی‌مانده این ماه (از امروز به بعد) ذخیره می‌شود. روزهای گذشته عوض نمی‌شوند.</p>
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
  <button class="btn btn-primary" type="submit" id="avail-submit-btn">افزودن / به‌روزرسانی</button>
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
      <?php
        $mid = (string) $mid;
        $monthShort = (string) ($bucket['short'] ?? $bucket['tab_label'] ?? 'ماه');
        $monthAllId = $mid . '-all';
        $monthItems = is_array($bucket['items'] ?? null) ? $bucket['items'] : [];
        $itemsByDate = doctor_availability_items_by_date($monthItems);
        $jy = (int) ($bucket['year'] ?? 0);
        $jm = (int) ($bucket['month'] ?? 0);
        $monthLen = ($jy > 0 && $jm > 0) ? jalali_month_length($jy, $jm) : 0;
        $rangeLabel = doctor_availability_month_range_label($bucket);
      ?>
      <section class="binder-panel<?= $defaultMonthId === $mid ? ' is-active' : '' ?>" data-binder-panel="<?= e($mid) ?>" role="tabpanel"<?= $defaultMonthId === $mid ? '' : ' hidden' ?>>
        <div class="binder-tile binder-tile--nested" data-binder-tabs data-binder-hash="0" data-binder-initial="<?= e($monthAllId) ?>" data-binder-tone="<?= e((string) ($bucket['tone'] ?? 'in-person')) ?>">
          <div class="binder-tabs" role="tablist" aria-label="<?= e($rangeLabel) ?>">
            <button type="button"
              class="binder-tab binder-tab-appts avail-month-range-tab is-active"
              role="tab"
              data-binder-tab="<?= e($monthAllId) ?>"
              data-binder-tone="appts"
              aria-selected="true">
              <?= e($rangeLabel) ?>
            </button>
            <?php foreach ($monthItems as $item): ?>
              <?php
                $dayDate = substr((string) ($item['date'] ?? ''), 0, 10);
                if ($dayDate === '') {
                    continue;
                }
                $dayId = $mid . '-d-' . $dayDate;
                $parts = jalali_day_parts($dayDate . ' 12:00:00') ?? [];
                $dayTab = $dayDate === $todayYmd ? 'امروز' : (string) ($parts['day_fa'] ?? to_fa_digits((string) ($parts['day'] ?? '')));
              ?>
              <button type="button"
                class="binder-tab <?= e((string) ($bucket['class'] ?? 'binder-tab-in-person')) ?>"
                role="tab"
                data-binder-tab="<?= e($dayId) ?>"
                data-binder-tone="<?= e((string) ($bucket['tone'] ?? 'in-person')) ?>"
                aria-selected="false">
                <?= e($dayTab) ?>
              </button>
            <?php endforeach; ?>
          </div>
          <div class="binder-body">
            <section class="binder-panel is-active" data-binder-panel="<?= e($monthAllId) ?>" role="tabpanel">
              <h2 class="binder-sub" style="margin-top:0">
                <?= e($rangeLabel) ?>
                <?php if ($monthLen > 0): ?>
                  <span class="muted" style="font-weight:400;font-size:.85rem">
                    · از <?= e(to_fa_digits('1')) ?> تا <?= e(to_fa_digits((string) $monthLen)) ?> <?= e($monthShort) ?>
                    · <?= e(to_fa_digits((string) count($monthItems))) ?> روز اعلام‌شده
                  </span>
                <?php endif; ?>
              </h2>
              <?php if ($monthLen > 0 && $jy > 0): ?>
                <div class="avail-month-days" aria-label="روزهای <?= e($monthShort) ?>">
                  <?php for ($jd = 1; $jd <= $monthLen; $jd++): ?>
                    <?php
                      $gdate = jalali_ymd($jy, $jm, $jd);
                      $saved = $itemsByDate[$gdate] ?? null;
                      $cls = 'avail-month-day';
                      if ($gdate === $todayYmd) {
                          $cls .= ' is-today';
                      }
                      if ($gdate < $todayYmd) {
                          $cls .= ' is-past';
                      }
                      if ($saved) {
                          $cls .= ' is-saved';
                      }
                    ?>
                    <span class="<?= e($cls) ?>" title="<?= e(to_jalali_label($gdate)) ?>">
                      <?= e(to_fa_digits((string) $jd)) ?>
                    </span>
                  <?php endfor; ?>
                </div>
              <?php endif; ?>
              <form class="panel form-stack avail-month-apply" method="post" action="<?= e(url('/doctor/availability')) ?>" data-avail-month-form>
                <input type="hidden" name="action" value="save_month">
                <input type="hidden" name="month_key" value="<?= e((string) ($bucket['key'] ?? '')) ?>">
                <p class="muted" style="margin:0;font-size:.85rem;line-height:1.7">ساعت‌های انتخابی روی <strong>همهٔ روزهای باقی‌مانده <?= e($monthShort) ?></strong> اعمال می‌شود (از امروز به بعد).</p>
                <div class="hour-picker" data-hour-picker>
                  <?php foreach ($bookingHours as $hour): ?>
                    <label class="hour-chip">
                      <input type="checkbox" name="hours[]" value="<?= (int) $hour ?>" checked>
                      <span class="hour-chip-time"><?= e(appointment_hour_chip_label((int) $hour)) ?></span>
                    </label>
                  <?php endforeach; ?>
                </div>
                <div style="display:flex;gap:.5rem;flex-wrap:wrap">
                  <button type="button" class="btn btn-outline btn-sm" data-select-all-hours>انتخاب همه (۲۴ ساعت)</button>
                  <button type="button" class="btn btn-outline btn-sm" data-clear-all-hours>پاک کردن</button>
                </div>
                <button class="btn btn-primary" type="submit">اعمال به <?= e($rangeLabel) ?></button>
              </form>
              <?php if (empty($monthItems)): ?>
                <p class="muted">در این ماه هنوز روز خالی ثبت نشده. از فرم بالا ساعت را به کل ماه اعمال کنید.</p>
              <?php else: ?>
                <div class="stack">
                  <?php foreach ($monthItems as $item): ?>
                    <?= doctor_availability_render_day_card(
                        $bookingHours,
                        $bookedMap,
                        substr((string) ($item['date'] ?? ''), 0, 10),
                        $item
                    ) ?>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </section>
            <?php foreach ($monthItems as $item): ?>
              <?php
                $dayDate = substr((string) ($item['date'] ?? ''), 0, 10);
                if ($dayDate === '') {
                    continue;
                }
                $dayId = $mid . '-d-' . $dayDate;
              ?>
              <section class="binder-panel" data-binder-panel="<?= e($dayId) ?>" role="tabpanel" hidden>
                <?= doctor_availability_render_day_card($bookingHours, $bookedMap, $dayDate, $item) ?>
              </section>
            <?php endforeach; ?>
          </div>
        </div>
      </section>
    <?php endforeach; ?>
  </div>
</div>
<?php
$inner = ob_get_clean();
$pageHead = '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@majidh1/jalalidatepicker/dist/jalalidatepicker.min.css">';
$pageScripts = '
<script src="' . e(url('/assets/js/binder-tabs.js')) . '?v=20260907i"></script>
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
  var form = document.getElementById("avail-save-form");
  var view = document.getElementById("avail-date-view");
  var hidden = document.getElementById("avail-date");
  var actionInput = document.getElementById("avail-action");
  var submitBtn = document.getElementById("avail-submit-btn");
  var monthKey = document.getElementById("avail-month-key");
  if (!form || !view || !hidden) return;
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
  window.addEventListener("binder-tab-change", refreshSubmit);
  refreshSubmit();

  form.addEventListener("submit", function(e){
    var checked = form.querySelectorAll("#hour-picker input[type=checkbox]:checked");
    if (monthModeOn()) {
      if (actionInput) actionInput.value = "save_month";
      view.removeAttribute("required");
      if (!monthKey || !monthKey.value) {
        e.preventDefault();
        alert("ماه را انتخاب کنید");
        return;
      }
    } else {
      if (actionInput) actionInput.value = "save";
      sync(false);
      if (!hidden.value) {
        e.preventDefault();
        alert("تاریخ را انتخاب کنید");
        return;
      }
    }
    if (!checked.length) {
      e.preventDefault();
      alert("حداقل یک ساعت خالی انتخاب کنید.");
    }
  });
})();
(function(){
  document.querySelectorAll("[data-avail-month-form]").forEach(function(form){
    var picker = form.querySelector("[data-hour-picker]");
    if (!picker) return;
    var selectAll = form.querySelector("[data-select-all-hours]");
    var clearAll = form.querySelector("[data-clear-all-hours]");
    if (selectAll) {
      selectAll.addEventListener("click", function(){
        picker.querySelectorAll("input[type=checkbox]").forEach(function(cb){ cb.checked = true; });
      });
    }
    if (clearAll) {
      clearAll.addEventListener("click", function(){
        picker.querySelectorAll("input[type=checkbox]").forEach(function(cb){ cb.checked = false; });
      });
    }
    form.addEventListener("submit", function(e){
      if (!picker.querySelectorAll("input[type=checkbox]:checked").length) {
        e.preventDefault();
        alert("حداقل یک ساعت خالی انتخاب کنید.");
      }
    });
  });
})();
</script>
';
render_doctor_page('روزهای خالی', $inner);
