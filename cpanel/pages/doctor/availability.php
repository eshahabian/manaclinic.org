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
