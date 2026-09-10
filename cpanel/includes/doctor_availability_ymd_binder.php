<?php
declare(strict_types=1);

/** @var array $ymdPack */
/** @var array $bookingHours */
/** @var array $bookedMap */

$ymdPack = is_array($ymdPack ?? null) ? $ymdPack : [];
$bookingHours = $bookingHours ?? appointment_booking_hours();
$bookedMap = $bookedMap ?? [];
$todayYmd = (string) ($todayYmd ?? date('Y-m-d'));
$years = is_array($ymdPack['years'] ?? null) ? $ymdPack['years'] : [];
$defaultYearId = (string) ($ymdPack['default_year_id'] ?? '');
if ($defaultYearId === '' || !isset($years[$defaultYearId])) {
    $defaultYearId = (string) (array_key_first($years) ?? '');
}
if (!$years) {
    echo '<p class="muted">در این بازه هنوز روز خالی ثبت نشده.</p>';
    return;
}
$defaultYear = is_array($years[$defaultYearId] ?? null) ? $years[$defaultYearId] : [];
$defaultMonths = is_array($defaultYear['months'] ?? null) ? $defaultYear['months'] : [];
$defaultMonthId = (string) ($ymdPack['default_month_id'] ?? '');
if ($defaultMonthId === '' || !isset($defaultMonths[$defaultMonthId])) {
    $defaultMonthId = (string) (array_key_first($defaultMonths) ?? '');
}
$defaultMonth = is_array($defaultMonths[$defaultMonthId] ?? null) ? $defaultMonths[$defaultMonthId] : [];
$defaultDayId = (string) ($ymdPack['default_day_id'] ?? $defaultMonth['all_id'] ?? ($defaultMonthId . '-all'));
?>
<div class="ymd-cascade avail-ymd"
     data-ymd-cascade
     data-ymd-initial-year="<?= e($defaultYearId) ?>"
     data-ymd-initial-month="<?= e($defaultMonthId) ?>"
     data-ymd-initial-day="<?= e($defaultDayId) ?>">
  <div class="ymd-cascade-bar">
    <label class="ymd-cascade-field">سال
      <select class="input ymd-cascade-select" data-ymd-select="year" aria-label="سال">
        <?php foreach ($years as $yearId => $year): ?>
          <?php if (!is_array($year)) { continue; } ?>
          <option value="<?= e((string) $yearId) ?>"<?= $defaultYearId === (string) $yearId ? ' selected' : '' ?>>
            <?= e((string) ($year['label'] ?? $yearId)) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </label>
    <label class="ymd-cascade-field">ماه
      <select class="input ymd-cascade-select" data-ymd-select="month" aria-label="ماه"></select>
    </label>
    <label class="ymd-cascade-field">روز
      <select class="input ymd-cascade-select" data-ymd-select="day" aria-label="روز"></select>
    </label>
  </div>
  <div class="ymd-cascade-body">
    <?php foreach ($years as $yearId => $year): ?>
      <?php
        if (!is_array($year)) {
            continue;
        }
        $yearId = (string) $yearId;
        $months = is_array($year['months'] ?? null) ? $year['months'] : [];
      ?>
      <section data-ymd-year="<?= e($yearId) ?>" data-ymd-label="<?= e((string) ($year['label'] ?? $yearId)) ?>"<?= $defaultYearId === $yearId ? '' : ' hidden' ?>>
        <?php foreach ($months as $monthId => $month): ?>
          <?php
            if (!is_array($month)) {
                continue;
            }
            $monthId = (string) $monthId;
            $days = is_array($month['days'] ?? null) ? $month['days'] : [];
            $allId = (string) ($month['all_id'] ?? ($monthId . '-all'));
            $monthOn = $defaultYearId === $yearId && $defaultMonthId === $monthId;
            $monthShort = (string) ($month['short'] ?? $month['tab_label'] ?? 'ماه');
            $monthItems = is_array($month['items'] ?? null) ? $month['items'] : [];
            $itemsByDate = doctor_availability_items_by_date($monthItems);
            $jy = (int) ($month['year'] ?? 0);
            $jm = (int) ($month['month'] ?? 0);
            $monthLen = ($jy > 0 && $jm > 0) ? jalali_month_length($jy, $jm) : 0;
            $rangeLabel = doctor_availability_month_range_label($month);
            $count = count($monthItems);
          ?>
          <section data-ymd-month="<?= e($monthId) ?>"
                   data-ymd-label="<?= e((string) ($month['tab_label'] ?? $monthShort)) ?>"
                   data-ymd-count="<?= e((string) $count) ?>"
                   <?= $monthOn ? '' : ' hidden' ?>>
            <section data-ymd-day="<?= e($allId) ?>"
                     data-ymd-label="<?= e($rangeLabel) ?>"
                     data-ymd-count="<?= e((string) $count) ?>"
                     <?= ($monthOn && $defaultDayId === $allId) ? '' : ' hidden' ?>>
              <h2 class="binder-sub" style="margin-top:0">
                <?= e($rangeLabel) ?>
                <?php if ($monthLen > 0): ?>
                  <span class="muted" style="font-weight:400;font-size:.85rem">
                    · از <?= e(to_fa_digits('1')) ?> تا <?= e(to_fa_digits((string) $monthLen)) ?> <?= e($monthShort) ?>
                    · <?= e(to_fa_digits((string) $count)) ?> روز اعلام‌شده
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
              <?php if ($jy > 0 && $jm > 0): ?>
              <form class="panel form-stack avail-month-apply" method="post" action="<?= e(url('/doctor/availability')) ?>" data-avail-month-form>
                <input type="hidden" name="action" value="save_month">
                <input type="hidden" name="month_key" value="<?= e((string) ($month['key'] ?? '')) ?>">
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
              <?php endif; ?>
              <?php if ($monthItems === []): ?>
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
            <?php foreach ($days as $day): ?>
              <?php
                if (!is_array($day)) {
                    continue;
                }
                $did = (string) ($day['id'] ?? '');
                $dayOn = $monthOn && $defaultDayId === $did;
                $dayItems = is_array($day['items'] ?? null) ? $day['items'] : [];
                $item = $dayItems[0] ?? null;
                $dayDate = substr((string) (($item['date'] ?? '') ?: ($day['date'] ?? '')), 0, 10);
              ?>
              <section data-ymd-day="<?= e($did) ?>"
                       data-ymd-label="<?= e((string) ($day['tab_label'] ?? $day['label'] ?? '')) ?>"
                       data-ymd-count="<?= e((string) count($dayItems)) ?>"
                       <?= $dayOn ? '' : ' hidden' ?>>
                <?php if ($dayDate !== ''): ?>
                  <?= doctor_availability_render_day_card($bookingHours, $bookedMap, $dayDate, is_array($item) ? $item : null) ?>
                <?php endif; ?>
              </section>
            <?php endforeach; ?>
          </section>
        <?php endforeach; ?>
      </section>
    <?php endforeach; ?>
  </div>
</div>
