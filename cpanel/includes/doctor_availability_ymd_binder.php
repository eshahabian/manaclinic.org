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
$defaultDayId = (string) ($ymdPack['default_day_id'] ?? '');
if ($defaultDayId === '' || str_ends_with($defaultDayId, '-all')) {
    $defaultDayId = ymd_pick_default_day_id($defaultMonth, $todayYmd);
}
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
            $monthOn = $defaultYearId === $yearId && $defaultMonthId === $monthId;
            $monthShort = (string) ($month['short'] ?? $month['tab_label'] ?? 'ماه');
            $monthItems = is_array($month['items'] ?? null) ? $month['items'] : [];
            $itemsByDate = doctor_availability_items_by_date($monthItems);
          ?>
          <section data-ymd-month="<?= e($monthId) ?>"
                   data-ymd-label="<?= e((string) ($month['tab_label'] ?? $monthShort)) ?>"
                   <?= $monthOn ? '' : ' hidden' ?>>
            <?php foreach ($days as $day): ?>
              <?php
                if (!is_array($day)) {
                    continue;
                }
                $did = (string) ($day['id'] ?? '');
                if ($did === '' || str_ends_with($did, '-all')) {
                    continue;
                }
                $dayOn = $monthOn && $defaultDayId === $did;
                $dayItems = is_array($day['items'] ?? null) ? $day['items'] : [];
                $item = $dayItems[0] ?? ($itemsByDate[(string) ($day['date'] ?? '')] ?? null);
                $dayDate = substr((string) (($day['date'] ?? '') ?: ($item['date'] ?? '')), 0, 10);
              ?>
              <section data-ymd-day="<?= e($did) ?>"
                       data-ymd-label="<?= e((string) ($day['tab_label'] ?? $day['label'] ?? '')) ?>"
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
