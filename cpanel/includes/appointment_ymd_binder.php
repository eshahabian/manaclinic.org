<?php
declare(strict_types=1);

/** @var array $ymdPack */
/** @var string $ymdEmpty */
/** @var callable|null $ymdRenderItems */

$ymdPack = is_array($ymdPack ?? null) ? $ymdPack : [];
$ymdEmpty = (string) ($ymdEmpty ?? 'نوبتی در این بخش نیست.');
$ymdRenderItems = $ymdRenderItems ?? null;
$ymdNoun = (string) ($ymdNoun ?? 'نوبت');
$ymdAllPrefix = (string) ($ymdAllPrefix ?? 'نوبت‌های');
$ymdShowPeople = $ymdShowPeople ?? true;
$ymdWrapClass = trim((string) ($ymdExtraClass ?? $ymdClass ?? ''));
if ($ymdWrapClass === '' || !str_contains($ymdWrapClass, 'ymd-cascade')) {
    $ymdWrapClass = trim('ymd-cascade appt-ymd ' . $ymdWrapClass);
}
$years = is_array($ymdPack['years'] ?? null) && $ymdPack['years'] !== []
    ? $ymdPack['years']
    : (is_array($ymdPack['halves'] ?? null) ? $ymdPack['halves'] : []);
$defaultYearId = (string) ($ymdPack['default_year_id'] ?? $ymdPack['default_half_id'] ?? '');
if ($defaultYearId === '' || !isset($years[$defaultYearId])) {
    $defaultYearId = (string) (array_key_first($years) ?? '');
}

if (!$years) {
    echo '<p class="muted binder-empty">' . e($ymdEmpty) . '</p>';
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
<div class="<?= e($ymdWrapClass) ?>"
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
        <?php if (!$months): ?>
          <p class="muted">ماهی در این سال نیست.</p>
        <?php else: ?>
          <?php foreach ($months as $monthId => $month): ?>
            <?php
              if (!is_array($month)) {
                  continue;
              }
              $monthId = (string) $monthId;
              $days = is_array($month['days'] ?? null) ? $month['days'] : [];
              $allId = (string) ($month['all_id'] ?? ($monthId . '-all'));
              $people = (int) ($month['people'] ?? 0);
              $itemCount = count($month['items'] ?? []);
              $slotCount = count($month['open_slots'] ?? []);
              $count = (int) ($month['count'] ?? ($itemCount + $slotCount));
              $names = is_array($month['people_names'] ?? null) ? $month['people_names'] : [];
              $monthOn = $defaultYearId === $yearId && $defaultMonthId === $monthId;
            ?>
            <section data-ymd-month="<?= e($monthId) ?>"
                     data-ymd-label="<?= e((string) ($month['tab_label'] ?? $month['short'] ?? $monthId)) ?>"
                     data-ymd-count="<?= e((string) $count) ?>"
                     <?= $monthOn ? '' : ' hidden' ?>>
              <section class="ymd-day-panel<?= ($monthOn && $defaultDayId === $allId) ? ' is-active' : '' ?>"
                       data-ymd-day="<?= e($allId) ?>"
                       data-ymd-label="کل <?= e((string) ($month['short'] ?? 'ماه')) ?>"
                       data-ymd-count="<?= e((string) $count) ?>"
                       <?= ($monthOn && $defaultDayId === $allId) ? '' : ' hidden' ?>>
                <h2 class="binder-sub" style="margin-top:0"><?= e($ymdAllPrefix) ?> <?= e((string) ($month['label'] ?? '')) ?></h2>
                <p class="appt-ymd-summary muted">
                  <?= e(to_fa_digits((string) $itemCount)) ?> <?= e($ymdNoun) ?>
                  <?php if ($ymdShowPeople && $people > 0): ?>
                    · <?= e(to_fa_digits((string) $people)) ?> نفر
                  <?php endif; ?>
                  <?php if ($slotCount > 0): ?>
                    · <?= e(to_fa_digits((string) $slotCount)) ?> ساعت خالی
                  <?php endif; ?>
                </p>
                <?php if ($ymdShowPeople && $names): ?>
                  <p class="appt-ymd-people"><?= e(implode('، ', array_values($names))) ?></p>
                <?php endif; ?>
                <?php
                  if (is_callable($ymdRenderItems)) {
                      $ymdRenderItems($month['items'] ?? [], $month);
                  }
                ?>
              </section>
              <?php foreach ($days as $day): ?>
                <?php
                  if (!is_array($day)) {
                      continue;
                  }
                  $did = (string) ($day['id'] ?? '');
                  $dayOn = $monthOn && $defaultDayId === $did;
                  $dayItems = $day['items'] ?? [];
                  $daySlots = $day['open_slots'] ?? [];
                  $dayCount = (int) ($day['count'] ?? (count($dayItems) + count($daySlots)));
                ?>
                <section class="ymd-day-panel<?= $dayOn ? ' is-active' : '' ?>"
                         data-ymd-day="<?= e($did) ?>"
                         data-ymd-label="<?= e((string) ($day['tab_label'] ?? $day['label'] ?? '')) ?>"
                         data-ymd-count="<?= e((string) $dayCount) ?>"
                         <?= $dayOn ? '' : ' hidden' ?>>
                  <h2 class="binder-sub" style="margin-top:0"><?= e((string) ($day['label'] ?? '')) ?></h2>
                  <p class="appt-ymd-summary muted">
                    <?= e(to_fa_digits((string) count($dayItems))) ?> <?= e($ymdNoun) ?>
                    <?php if ($ymdShowPeople && (int) ($day['people'] ?? 0) > 0): ?>
                      · <?= e(to_fa_digits((string) ($day['people'] ?? 0))) ?> نفر
                    <?php endif; ?>
                    <?php if (count($daySlots) > 0): ?>
                      · <?= e(to_fa_digits((string) count($daySlots))) ?> ساعت خالی
                    <?php endif; ?>
                  </p>
                  <?php
                    if (is_callable($ymdRenderItems)) {
                        $ymdRenderItems($dayItems, $day);
                    }
                  ?>
                </section>
              <?php endforeach; ?>
            </section>
          <?php endforeach; ?>
        <?php endif; ?>
      </section>
    <?php endforeach; ?>
  </div>
</div>
