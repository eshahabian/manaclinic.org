<?php
declare(strict_types=1);

function staff_hours_scripts(): string
{
    return '<script src="' . e(url('/assets/js/binder-tabs.js')) . '?v=20260906k"></script>';
}

function staff_hours_render(array $byUser): string
{
    $pack = staff_hours_month_groups($byUser);
    $months = $pack['months'];
    $defaultMonthId = (string) $pack['default_month_id'];
    $defaultDayId = (string) $pack['default_day_id'];
    $today = (string) $pack['today'];

    ob_start();
    ?>
<h1>ساعت کاری منشی‌ها</h1>
<p class="muted">هر ورود در خط جداست. اگر منشی ۱۰ دقیقه فعال نباشد خارج می‌شود و ورود بعدی جدا دیده می‌شود.</p>

<?php if (!$byUser): ?>
  <p class="muted" style="margin-top:1rem">حساب منشی‌ای پیدا نشد.</p>
<?php else: ?>
  <div class="binder-tile" data-binder-tabs data-binder-initial="<?= e($defaultMonthId) ?>" data-binder-tone="<?= e((string) ($months[$defaultMonthId]['tone'] ?? 'in-person')) ?>" style="margin-top:1.25rem">
    <div class="binder-tabs" role="tablist" aria-label="ماه ساعت کاری">
      <?php foreach ($months as $monthId => $month): ?>
        <button type="button"
          class="binder-tab <?= e((string) ($month['class'] ?? 'binder-tab-in-person')) ?><?= $defaultMonthId === $monthId ? ' is-active' : '' ?>"
          role="tab"
          data-binder-tab="<?= e((string) $monthId) ?>"
          data-binder-tone="<?= e((string) ($month['tone'] ?? 'in-person')) ?>"
          aria-selected="<?= $defaultMonthId === $monthId ? 'true' : 'false' ?>">
          <?= e((string) ($month['tab_label'] ?? $month['short'] ?? $monthId)) ?>
          <span class="binder-tab-count"><?= to_fa_digits((string) count($month['days'] ?? [])) ?></span>
        </button>
      <?php endforeach; ?>
    </div>
    <div class="binder-body">
      <?php foreach ($months as $monthId => $month): ?>
        <?php $days = $month['days'] ?? []; ?>
        <section class="binder-panel<?= $defaultMonthId === $monthId ? ' is-active' : '' ?>" data-binder-panel="<?= e((string) $monthId) ?>" role="tabpanel"<?= $defaultMonthId === $monthId ? '' : ' hidden' ?>>
          <h2 class="binder-sub" style="margin-top:0"><?= e((string) ($month['label'] ?? '')) ?></h2>
          <?php if (!$days): ?>
            <p class="muted">در این ماه سابقه‌ای نیست.</p>
          <?php else: ?>
            <?php
              $firstDayKey = array_key_first($days);
              $monthDefaultDay = isset($days[$today])
                  ? ('d-' . $today)
                  : (string) (($firstDayKey !== null ? ($days[$firstDayKey]['id'] ?? '') : '') ?: $defaultDayId);
            ?>
            <div class="binder-tile binder-tile--nested" data-binder-tabs data-binder-hash="0" data-binder-initial="<?= e($monthDefaultDay) ?>" data-binder-tone="<?= e((string) ($month['tone'] ?? 'in-person')) ?>">
              <div class="binder-tabs" role="tablist" aria-label="روز ساعت کاری">
                <?php foreach ($days as $day): ?>
                  <button type="button"
                    class="binder-tab <?= e((string) ($month['class'] ?? 'binder-tab-in-person')) ?><?= $monthDefaultDay === ($day['id'] ?? '') ? ' is-active' : '' ?>"
                    role="tab"
                    data-binder-tab="<?= e((string) ($day['id'] ?? '')) ?>"
                    data-binder-tone="<?= e((string) ($month['tone'] ?? 'in-person')) ?>"
                    aria-selected="<?= $monthDefaultDay === ($day['id'] ?? '') ? 'true' : 'false' ?>">
                    <?= e((string) ($day['tab_label'] ?? $day['label'] ?? '')) ?>
                  </button>
                <?php endforeach; ?>
              </div>
              <div class="binder-body">
                <?php foreach ($days as $day): ?>
                  <?php $dayDate = (string) ($day['date'] ?? ''); ?>
                  <section class="binder-panel<?= $monthDefaultDay === ($day['id'] ?? '') ? ' is-active' : '' ?>" data-binder-panel="<?= e((string) ($day['id'] ?? '')) ?>" role="tabpanel"<?= $monthDefaultDay === ($day['id'] ?? '') ? '' : ' hidden' ?>>
                    <?php foreach ($byUser as $block): ?>
                      <?= staff_hours_render_tile($block, $dayDate, $today) ?>
                    <?php endforeach; ?>
                  </section>
                <?php endforeach; ?>
              </div>
            </div>
          <?php endif; ?>
        </section>
      <?php endforeach; ?>
    </div>
  </div>
<?php endif; ?>
    <?php
    return (string) ob_get_clean();
}

function staff_hours_render_tile(array $block, string $dayDate, string $today): string
{
    $sec = $block['user'] ?? [];
    $isToday = $dayDate === $today;
    $day = $block['days'][$dayDate] ?? null;
    $dayRows = $isToday ? ($block['today_rows'] ?? []) : (($day['items'] ?? []) ?: []);
    $dayReport = $block['reports_by_date'][$dayDate] ?? null;

    ob_start();
    ?>
  <div class="panel stack" style="margin-top:1.25rem">
    <div class="row-between">
      <div>
        <strong><?= e(staff_actor_label($sec)) ?></strong>
        <div class="muted" style="font-size:.85rem;margin-top:.25rem">
          امروز: <?= e(staff_format_duration((int) $block['today_seconds'])) ?>
          · <?= e(to_fa_digits((string) count($block['today_rows'] ?? []))) ?> بار ورود
        </div>
      </div>
      <?php if ($block['open']): ?>
        <span class="badge">آنلاین از <?= e(format_fa_datetime((string) $block['open']['started_at'])) ?></span>
      <?php else: ?>
        <span class="badge">آفلاین</span>
      <?php endif; ?>
    </div>

    <?php if ($isToday): ?>
      <?php if ($dayRows): ?>
        <div>
          <h3 class="appt-day-title" style="margin-bottom:.45rem">ورودهای امروز</h3>
          <ol class="staff-shift-lines">
            <?php foreach ($dayRows as $i => $row): ?>
              <li>
                <strong>ورود <?= e(to_fa_digits((string) ($i + 1))) ?></strong>
                از <?= e(format_fa_datetime((string) $row['started_at'])) ?>
                تا <?= !empty($row['ended_at']) ? e(format_fa_datetime((string) $row['ended_at'])) : 'الان' ?>
                · <?= e(staff_format_duration(staff_shift_seconds($row))) ?>
                · <?= e(staff_shift_reason_label($row['end_reason'] ?? null)) ?>
              </li>
            <?php endforeach; ?>
          </ol>
        </div>
      <?php endif; ?>

      <?php if ($dayReport): ?>
        <div class="staff-day-report">
          <strong>گزارش پایان امروز</strong>
          <p><?= nl2br(e((string) $dayReport['body'])) ?></p>
        </div>
      <?php endif; ?>

      <?php if (!$dayRows && !$dayReport): ?>
        <p class="muted">هنوز سابقه‌ای برای این منشی نیست.</p>
      <?php endif; ?>
    <?php else: ?>
      <?php if ($dayRows): ?>
        <div class="staff-day-block">
          <h3 class="appt-day-title">
            <?= e((string) ($day['label'] ?? '')) ?>
            <span class="muted" style="font-weight:400;font-size:.85rem"> · <?= e(to_fa_digits((string) count($dayRows))) ?> بار ورود</span>
          </h3>
          <ol class="staff-shift-lines">
            <?php foreach ($dayRows as $i => $row): ?>
              <li>
                ورود <?= e(to_fa_digits((string) ($i + 1))) ?>:
                <?= e(format_fa_datetime((string) $row['started_at'])) ?>
                تا <?= !empty($row['ended_at']) ? e(format_fa_datetime((string) $row['ended_at'])) : '— هنوز باز' ?>
                · <?= e(staff_format_duration(staff_shift_seconds($row))) ?>
                · <?= e(staff_shift_reason_label($row['end_reason'] ?? null)) ?>
              </li>
            <?php endforeach; ?>
          </ol>
          <?php if ($dayReport): ?>
            <div class="staff-day-report">
              <strong>گزارش کار</strong>
              <p><?= nl2br(e((string) $dayReport['body'])) ?></p>
            </div>
          <?php endif; ?>
        </div>
      <?php elseif ($dayReport): ?>
        <div class="staff-day-block">
          <div class="staff-day-report">
            <strong>گزارش کار</strong>
            <p><?= nl2br(e((string) $dayReport['body'])) ?></p>
          </div>
        </div>
      <?php else: ?>
        <p class="muted">در این روز سابقه‌ای برای این منشی نیست.</p>
      <?php endif; ?>
    <?php endif; ?>
  </div>
    <?php
    return (string) ob_get_clean();
}
