<?php
declare(strict_types=1);

/** پنل روزهای حضور یک درمانگر. $doctorId شناسه doctor_profiles است. */
function therapist_weekday_panel_html(PDO $pdo, string $doctorId, string $doctorName): string
{
    if (!function_exists('doctor_availability_resync_weekly')) {
        require_once __DIR__ . '/availability.php';
    }
    ensure_availability_schema($pdo);
    ensure_doctor_weekly_hours_schema($pdo);
    if ($doctorId !== '' && function_exists('doctor_availability_resync_weekly')) {
        doctor_availability_resync_weekly($pdo, $doctorId, 12);
    }
    $bookingHours = appointment_booking_hours();
    $weekdays = doctor_weekdays_sat_first();
    $weeklyMap = $doctorId !== '' ? doctor_weekly_hours_map($pdo, $doctorId) : [];
    $weekdaySummary = $doctorId !== '' ? doctor_weekday_presence_summary($pdo, $doctorId) : [];
    $summaryByWeekday = [];
    foreach ($weekdaySummary as $row) {
        $summaryByWeekday[(int) ($row['weekday'] ?? -1)] = $row;
    }
    ob_start();
    ?>
    <p class="muted" style="margin:.2rem 0 .8rem;line-height:1.7">روز هفته را بزنید و ساعت شروع و پایان حضور را انتخاب کنید. اسلات‌ها یک‌ساعته ساخته می‌شوند. گرفتن نوبت فقط تا دو هفته آینده باز است.</p>
    <div class="avail-weekday-row" role="tablist" aria-label="روزهای هفته">
      <?php foreach ($weekdays as $w => $label): ?>
        <?php $has = isset($weeklyMap[$w]); ?>
        <button type="button" class="avail-weekday-btn<?= $has ? ' has-hours' : '' ?>" data-weekday-open="<?= (int) $w ?>">
          <?= e($label) ?>
          <?php if ($has): ?><span class="avail-weekday-dot" aria-hidden="true"></span><?php endif; ?>
        </button>
      <?php endforeach; ?>
    </div>
    <?php foreach ($weekdays as $w => $label): ?>
      <?php
        $saved = $weeklyMap[$w] ?? [];
        $defaultFrom = $saved[0] ?? 9;
        $defaultTo = $saved !== [] ? $saved[count($saved) - 1] : 13;
      ?>
      <div class="avail-weekday-editor" data-weekday-panel="<?= (int) $w ?>" hidden>
        <div class="avail-weekday-editor-head">
          <strong>ساعت حضور — <?= e($label) ?></strong>
          <?php if ($saved !== []): ?>
            <span class="muted" style="font-size:.85rem">الان: <?= e(implode(' · ', array_map(static fn (int $h): string => appointment_hour_chip_label($h), $saved))) ?></span>
          <?php endif; ?>
        </div>
        <form method="post" action="<?= e(url('/secretary/therapist-days')) ?>" class="avail-weekday-form">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="save_weekday">
          <input type="hidden" name="doctor_id" value="<?= e($doctorId) ?>">
          <input type="hidden" name="weekday" value="<?= (int) $w ?>">
          <div class="avail-weekday-fields">
            <label>
              <span class="label">از ساعت</span>
              <select class="input" name="hour_from" required>
                <?php foreach ($bookingHours as $hour): ?>
                  <option value="<?= (int) $hour ?>"<?= (int) $hour === (int) $defaultFrom ? ' selected' : '' ?>><?= e(appointment_hour_chip_label((int) $hour)) ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <label>
              <span class="label">تا ساعت</span>
              <select class="input" name="hour_to" required>
                <?php foreach ($bookingHours as $hour): ?>
                  <option value="<?= (int) $hour ?>"<?= (int) $hour === (int) $defaultTo ? ' selected' : '' ?>><?= e(appointment_hour_chip_label((int) $hour)) ?></option>
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
          <div style="margin-top:.85rem">
            <button class="btn btn-primary" type="submit">ثبت ساعت حضور</button>
          </div>
        </form>
        <?php if ($saved !== []): ?>
          <form method="post" action="<?= e(url('/secretary/therapist-days')) ?>" style="margin-top:.65rem" onsubmit="return confirm('ساعت‌های این روز هفته پاک شود؟');">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="clear_weekday">
            <input type="hidden" name="doctor_id" value="<?= e($doctorId) ?>">
            <input type="hidden" name="weekday" value="<?= (int) $w ?>">
            <button class="btn btn-outline btn-sm" type="submit">پاک کردن این روز</button>
          </form>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
    <section class="avail-summary-panel" id="avail-summary-panel" hidden style="margin-top:1rem">
      <h3 style="font-size:1rem;margin:0 0 .6rem">خلاصه حضور</h3>
      <?php foreach ($weekdays as $w => $label): ?>
        <?php
          $row = $summaryByWeekday[(int) $w] ?? null;
          $hours = is_array($row) ? ($row['hours'] ?? []) : [];
          $chips = is_array($row) && is_array($row['chips'] ?? null) ? $row['chips'] : [];
        ?>
        <div data-summary-weekday="<?= (int) $w ?>" hidden>
          <?php if ($hours === []): ?>
            <p class="muted" style="margin:0">برای «<?= e($label) ?>» هنوز ساعت حضوری ثبت نشده است.</p>
          <?php else: ?>
            <div class="avail-summary-hours">
              <?php foreach ($chips as $chip): ?>
                <?php
                  if (!is_array($chip)) {
                      continue;
                  }
                  $who = trim((string) ($chip['patient'] ?? ''));
                  $patientId = trim((string) ($chip['patient_id'] ?? ''));
                  $face = (string) ($chip['label'] ?? '');
                  $isTaken = !empty($chip['taken']);
                  $text = $who !== '' ? ($face . ' · ' . $who) : $face;
                ?>
                <?php if ($patientId !== ''): ?>
                  <a class="avail-hour-pill is-booked" href="<?= e(url('/secretary/patients/' . $patientId)) ?>"><?= e($text) ?></a>
                <?php else: ?>
                  <span class="avail-hour-pill<?= $isTaken ? ' is-booked' : '' ?>"><?= e($text) ?></span>
                <?php endif; ?>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </section>
    <?php

    return (string) ob_get_clean();
}
