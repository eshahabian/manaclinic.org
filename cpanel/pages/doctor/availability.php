<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/doctor_panel.php';
require_once __DIR__ . '/../../includes/availability.php';

$ctx = require_doctor_profile($pdo);
ensure_availability_schema($pdo);
ensure_doctor_weekly_hours_schema($pdo);
$bookingHours = appointment_booking_hours();
$weeklyMap = doctor_weekly_hours_map($pdo, (string) $ctx['profile']['id']);
$weekdays = doctor_weekdays_sat_first();
$weekdaySummary = doctor_weekday_presence_summary($pdo, (string) $ctx['profile']['id']);
$hasAnyPresence = false;
foreach ($weekdaySummary as $row) {
    if (!empty($row['hours'])) {
        $hasAnyPresence = true;
        break;
    }
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

<section class="panel avail-summary-panel" style="margin-top:1.25rem">
  <h2 class="avail-week-title">خلاصه حضور</h2>
  <p class="muted" style="margin:0 0 1rem;font-size:.88rem;line-height:1.65">
    برای هر روز هفته، ساعت‌های اعلام‌شده و وضعیت نزدیک‌ترین تاریخ (خالی یا پر) را می‌بینید.
  </p>
  <?php if (!$hasAnyPresence): ?>
    <p class="muted" style="margin:0">هنوز ساعت حضوری ثبت نشده است. از بالا یک روز هفته را انتخاب کنید.</p>
  <?php else: ?>
    <div class="avail-summary-list">
      <?php foreach ($weekdaySummary as $row): ?>
        <?php if (empty($row['hours'])) {
            continue;
        } ?>
        <article class="avail-summary-card">
          <div class="avail-summary-head">
            <strong><?= e((string) $row['label']) ?></strong>
            <?php if (!empty($row['next_label'])): ?>
              <span class="muted" style="font-size:.82rem">نزدیک‌ترین: <?= e((string) $row['next_label']) ?></span>
            <?php endif; ?>
          </div>
          <div class="avail-summary-line">
            <span class="avail-summary-k">حضور:</span>
            <span><?= e(implode(' · ', array_map(
                static fn (int $h): string => appointment_hour_chip_label($h),
                $row['hours']
            ))) ?></span>
          </div>
          <div class="avail-summary-line avail-summary-free">
            <span class="avail-summary-k">خالی:</span>
            <?php if (empty($row['free'])): ?>
              <span class="muted">—</span>
            <?php else: ?>
              <span><?= e(implode(' · ', array_map(
                  static fn (int $h): string => appointment_hour_chip_label($h),
                  $row['free']
              ))) ?></span>
            <?php endif; ?>
          </div>
          <div class="avail-summary-line avail-summary-booked">
            <span class="avail-summary-k">پر شده:</span>
            <?php if (empty($row['booked'])): ?>
              <span class="muted">هنوز کسی رزرو نکرده</span>
            <?php else: ?>
              <span>
                <?php
                  $bits = [];
                  foreach ($row['booked'] as $b) {
                      $hLabel = appointment_hour_chip_label((int) ($b['hour'] ?? 0));
                      $who = trim((string) ($b['patient'] ?? ''));
                      $bits[] = $who !== '' ? ($hLabel . ' (' . $who . ')') : $hLabel;
                  }
                  echo e(implode(' · ', $bits));
                ?>
              </span>
            <?php endif; ?>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>
<?php
$inner = ob_get_clean();
$pageScripts = '
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
</script>
';
render_doctor_page('روزهای خالی', $inner);
