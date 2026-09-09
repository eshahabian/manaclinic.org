<?php
declare(strict_types=1);

/** @var array $ymdPack */
/** @var string $ymdEmpty */
/** @var callable|null $ymdRenderItems */

$ymdPack = is_array($ymdPack ?? null) ? $ymdPack : [];
$ymdEmpty = (string) ($ymdEmpty ?? 'نوبتی در این بخش نیست.');
$ymdRenderItems = $ymdRenderItems ?? null;
$years = is_array($ymdPack['years'] ?? null) ? $ymdPack['years'] : [];
$defaultYearId = (string) ($ymdPack['default_year_id'] ?? '');
if ($defaultYearId === '' || !isset($years[$defaultYearId])) {
    $defaultYearId = (string) (array_key_first($years) ?? '');
}

if (!$years) {
    echo '<p class="muted binder-empty">' . e($ymdEmpty) . '</p>';
    return;
}

$defaultYear = is_array($years[$defaultYearId] ?? null) ? $years[$defaultYearId] : [];
$defaultYearTone = (string) ($defaultYear['tone'] ?? 'in-person');
?>
<div class="binder-tile binder-tile--nested appt-ymd" data-binder-tabs data-binder-hash="0" data-binder-initial="<?= e($defaultYearId) ?>" data-binder-tone="<?= e($defaultYearTone) ?>">
  <div class="binder-tabs" role="tablist" aria-label="سال">
    <?php foreach ($years as $yearId => $year): ?>
      <?php
        if (!is_array($year)) {
            continue;
        }
        $yearId = (string) $yearId;
      ?>
      <button type="button"
        class="binder-tab <?= e((string) ($year['class'] ?? 'binder-tab-in-person')) ?><?= $defaultYearId === $yearId ? ' is-active' : '' ?>"
        role="tab"
        data-binder-tab="<?= e($yearId) ?>"
        data-binder-tone="<?= e((string) ($year['tone'] ?? 'in-person')) ?>"
        aria-selected="<?= $defaultYearId === $yearId ? 'true' : 'false' ?>">
        <?= e((string) ($year['label'] ?? $yearId)) ?>
        <span class="binder-tab-count"><?= e(to_fa_digits((string) ($year['count'] ?? 0))) ?></span>
      </button>
    <?php endforeach; ?>
  </div>
  <div class="binder-body">
    <?php foreach ($years as $yearId => $year): ?>
      <?php
        if (!is_array($year)) {
            continue;
        }
        $yearId = (string) $yearId;
        $months = is_array($year['months'] ?? null) ? $year['months'] : [];
        $defaultMonthId = (string) ($ymdPack['default_month_id'] ?? '');
        if ($defaultMonthId === '' || !isset($months[$defaultMonthId])) {
            $defaultMonthId = (string) (array_key_first($months) ?? '');
        }
        $defaultMonth = is_array($months[$defaultMonthId] ?? null) ? $months[$defaultMonthId] : [];
        $monthTone = (string) ($defaultMonth['tone'] ?? $year['tone'] ?? 'in-person');
      ?>
      <section class="binder-panel<?= $defaultYearId === $yearId ? ' is-active' : '' ?>" data-binder-panel="<?= e($yearId) ?>" role="tabpanel"<?= $defaultYearId === $yearId ? '' : ' hidden' ?>>
        <?php if (!$months): ?>
          <p class="muted">ماهی در این سال ثبت نشده است.</p>
        <?php else: ?>
          <div class="binder-tile binder-tile--nested" data-binder-tabs data-binder-hash="0" data-binder-initial="<?= e($defaultMonthId) ?>" data-binder-tone="<?= e($monthTone) ?>">
            <div class="binder-tabs" role="tablist" aria-label="ماه">
              <?php foreach ($months as $monthId => $month): ?>
                <?php
                  if (!is_array($month)) {
                      continue;
                  }
                  $monthId = (string) $monthId;
                ?>
                <button type="button"
                  class="binder-tab <?= e((string) ($month['class'] ?? 'binder-tab-in-person')) ?><?= $defaultMonthId === $monthId ? ' is-active' : '' ?>"
                  role="tab"
                  data-binder-tab="<?= e($monthId) ?>"
                  data-binder-tone="<?= e((string) ($month['tone'] ?? 'in-person')) ?>"
                  aria-selected="<?= $defaultMonthId === $monthId ? 'true' : 'false' ?>">
                  <?= e((string) ($month['tab_label'] ?? $month['short'] ?? $monthId)) ?>
                  <span class="binder-tab-count"><?= e(to_fa_digits((string) ($month['count'] ?? 0))) ?></span>
                </button>
              <?php endforeach; ?>
            </div>
            <div class="binder-body">
              <?php foreach ($months as $monthId => $month): ?>
                <?php
                  if (!is_array($month)) {
                      continue;
                  }
                  $monthId = (string) $monthId;
                  $days = is_array($month['days'] ?? null) ? $month['days'] : [];
                  $allId = (string) ($month['all_id'] ?? ($monthId . '-all'));
                  $people = (int) ($month['people'] ?? 0);
                  $count = (int) ($month['count'] ?? 0);
                  $names = is_array($month['people_names'] ?? null) ? $month['people_names'] : [];
                ?>
                <section class="binder-panel<?= $defaultMonthId === $monthId ? ' is-active' : '' ?>" data-binder-panel="<?= e($monthId) ?>" role="tabpanel"<?= $defaultMonthId === $monthId ? '' : ' hidden' ?>>
                  <div class="binder-tile binder-tile--nested" data-binder-tabs data-binder-hash="0" data-binder-initial="<?= e($allId) ?>" data-binder-tone="<?= e((string) ($month['tone'] ?? 'in-person')) ?>">
                    <div class="binder-tabs" role="tablist" aria-label="روز">
                      <button type="button"
                        class="binder-tab binder-tab-appts is-active"
                        role="tab"
                        data-binder-tab="<?= e($allId) ?>"
                        data-binder-tone="appts"
                        aria-selected="true">
                        کل <?= e((string) ($month['short'] ?? 'ماه')) ?>
                        <span class="binder-tab-count"><?= e(to_fa_digits((string) $count)) ?></span>
                      </button>
                      <?php foreach ($days as $day): ?>
                        <?php
                          if (!is_array($day)) {
                              continue;
                          }
                          $did = (string) ($day['id'] ?? '');
                        ?>
                        <button type="button"
                          class="binder-tab <?= e((string) ($month['class'] ?? 'binder-tab-in-person')) ?>"
                          role="tab"
                          data-binder-tab="<?= e($did) ?>"
                          data-binder-tone="<?= e((string) ($month['tone'] ?? 'in-person')) ?>"
                          aria-selected="false">
                          <?= e((string) ($day['tab_label'] ?? '')) ?>
                          <span class="binder-tab-count"><?= e(to_fa_digits((string) ($day['count'] ?? 0))) ?></span>
                        </button>
                      <?php endforeach; ?>
                    </div>
                    <div class="binder-body">
                      <section class="binder-panel is-active" data-binder-panel="<?= e($allId) ?>" role="tabpanel">
                        <h2 class="binder-sub" style="margin-top:0">نوبت‌های <?= e((string) ($month['label'] ?? '')) ?></h2>
                        <p class="appt-ymd-summary muted">
                          <?= e(to_fa_digits((string) $count)) ?> نوبت
                          <?php if ($people > 0): ?>
                            · <?= e(to_fa_digits((string) $people)) ?> نفر
                          <?php endif; ?>
                        </p>
                        <?php if ($names): ?>
                          <p class="appt-ymd-people"><?= e(implode('، ', array_values($names))) ?></p>
                        <?php endif; ?>
                        <?php
                          if (is_callable($ymdRenderItems)) {
                              $ymdRenderItems($month['items'] ?? []);
                          }
                        ?>
                      </section>
                      <?php foreach ($days as $day): ?>
                        <?php
                          if (!is_array($day)) {
                              continue;
                          }
                          $did = (string) ($day['id'] ?? '');
                        ?>
                        <section class="binder-panel" data-binder-panel="<?= e($did) ?>" role="tabpanel" hidden>
                          <h2 class="binder-sub" style="margin-top:0"><?= e((string) ($day['label'] ?? '')) ?></h2>
                          <p class="appt-ymd-summary muted">
                            <?= e(to_fa_digits((string) ($day['count'] ?? 0))) ?> نوبت
                            <?php if ((int) ($day['people'] ?? 0) > 0): ?>
                              · <?= e(to_fa_digits((string) ($day['people'] ?? 0))) ?> نفر
                            <?php endif; ?>
                          </p>
                          <?php
                            if (is_callable($ymdRenderItems)) {
                                $ymdRenderItems($day['items'] ?? []);
                            }
                          ?>
                        </section>
                      <?php endforeach; ?>
                    </div>
                  </div>
                </section>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endif; ?>
      </section>
    <?php endforeach; ?>
  </div>
</div>
