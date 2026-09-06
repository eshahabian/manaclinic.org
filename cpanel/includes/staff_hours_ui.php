<?php
declare(strict_types=1);

function staff_hours_scripts(): string
{
    return '<script src="' . e(url('/assets/js/binder-tabs.js')) . '?v=20260906o"></script>';
}

function staff_hours_render(array $slots, array $opts = []): string
{
    $canRename = !empty($opts['can_rename']);
    $renameAction = (string) ($opts['rename_action'] ?? '/doctor/staff-hours');
    $exportBase = (string) ($opts['export_base'] ?? '/doctor/staff-hours-export');
    $today = date('Y-m-d');
    $defaultSlotId = 'sec-1';

    ob_start();
    ?>
<h1>ساعت کاری منشی‌ها</h1>
<p class="muted">ساعت عادی منشی از ۹ صبح تا ۸ شب است؛ خارج از این بازه اضافه‌کار حساب می‌شود. روزهایی که منشی حاضر نبوده در تب‌ها دیده نمی‌شود.</p>

<div class="staff-hours-toolbar">
  <div class="staff-hours-export">
    <a class="btn btn-outline btn-sm" href="<?= e(url($exportBase . '?slot=1')) ?>">خروجی منشی ۱</a>
    <a class="btn btn-outline btn-sm" href="<?= e(url($exportBase . '?slot=2')) ?>">خروجی منشی ۲</a>
    <a class="btn btn-outline btn-sm" href="<?= e(url($exportBase)) ?>">خروجی هر دو</a>
  </div>
  <?php if ($canRename): ?>
    <form method="post" action="<?= e(url($renameAction)) ?>" class="staff-hours-alias">
      <?= csrf_field() ?>
      <p class="muted" style="margin:0 0 .35rem;font-size:.8rem">نام موقت برای تب منشی‌ها. اگر کسی عوض شد همین‌جا عوض کنید.</p>
      <div class="staff-hours-alias-row">
        <label>
          منشی ۱
          <input type="text" name="label_1" maxlength="80" value="<?= e((string) ($slots[1]['label'] ?? 'منشی ۱')) ?>">
        </label>
        <label>
          منشی ۲
          <input type="text" name="label_2" maxlength="80" value="<?= e((string) ($slots[2]['label'] ?? 'منشی ۲')) ?>">
        </label>
        <button type="submit" class="btn btn-sm">ذخیره نام</button>
      </div>
    </form>
  <?php endif; ?>
</div>

<div class="binder-tile" data-binder-tabs data-binder-hash="0" data-binder-initial="<?= e($defaultSlotId) ?>" data-binder-tone="appts" style="margin-top:1.25rem">
  <div class="binder-tabs" role="tablist" aria-label="منشی">
    <?php foreach ([1, 2] as $slot): ?>
      <?php
        $block = $slots[$slot] ?? [];
        $sid = 'sec-' . $slot;
      ?>
      <button type="button"
        class="binder-tab <?= $slot === 1 ? 'binder-tab-appts' : 'binder-tab-workshops' ?><?= $defaultSlotId === $sid ? ' is-active' : '' ?>"
        role="tab"
        data-binder-tab="<?= e($sid) ?>"
        data-binder-tone="<?= $slot === 1 ? 'appts' : 'workshops' ?>"
        aria-selected="<?= $defaultSlotId === $sid ? 'true' : 'false' ?>">
        <?= e((string) ($block['label'] ?? staff_slot_default_label($slot))) ?>
      </button>
    <?php endforeach; ?>
  </div>
  <div class="binder-body">
    <?php foreach ([1, 2] as $slot): ?>
      <?php
        $block = $slots[$slot] ?? ['slot' => $slot, 'days' => [], 'reports' => [], 'label' => staff_slot_default_label($slot)];
        $sid = 'sec-' . $slot;
        $cal = staff_hours_calendar($block);
        $halves = $cal['halves'];
        $defaultHalfId = (string) $cal['default_half_id'];
      ?>
      <section class="binder-panel<?= $defaultSlotId === $sid ? ' is-active' : '' ?>" data-binder-panel="<?= e($sid) ?>" role="tabpanel"<?= $defaultSlotId === $sid ? '' : ' hidden' ?>>
        <?php if (!$halves): ?>
          <p class="muted">برای این منشی سابقه‌ای نیست.</p>
        <?php else: ?>
          <div class="binder-tile binder-tile--nested" data-binder-tabs data-binder-hash="0" data-binder-initial="<?= e($defaultHalfId) ?>" data-binder-tone="<?= e((string) ($halves[$defaultHalfId]['tone'] ?? 'online')) ?>">
            <div class="binder-tabs" role="tablist" aria-label="نیم‌سال">
              <?php foreach ($halves as $halfId => $half): ?>
                <button type="button"
                  class="binder-tab <?= e((string) ($half['class'] ?? '')) ?><?= $defaultHalfId === $halfId ? ' is-active' : '' ?>"
                  role="tab"
                  data-binder-tab="<?= e((string) $halfId) ?>"
                  data-binder-tone="<?= e((string) ($half['tone'] ?? 'online')) ?>"
                  aria-selected="<?= $defaultHalfId === $halfId ? 'true' : 'false' ?>">
                  <?= e((string) ($half['label'] ?? '')) ?>
                </button>
              <?php endforeach; ?>
            </div>
            <div class="binder-body">
              <?php foreach ($halves as $halfId => $half): ?>
                <?php
                  $months = $half['months'] ?? [];
                  $defaultMonthId = (string) $cal['default_month_id'];
                  if (!isset($months[$defaultMonthId])) {
                      $defaultMonthId = (string) (array_key_first($months) ?? '');
                  }
                ?>
                <section class="binder-panel<?= $defaultHalfId === $halfId ? ' is-active' : '' ?>" data-binder-panel="<?= e((string) $halfId) ?>" role="tabpanel"<?= $defaultHalfId === $halfId ? '' : ' hidden' ?>>
                  <div class="binder-tile binder-tile--nested" data-binder-tabs data-binder-hash="0" data-binder-initial="<?= e($defaultMonthId) ?>" data-binder-tone="<?= e((string) (($months[$defaultMonthId]['tone'] ?? $half['tone'] ?? 'in-person'))) ?>">
                    <div class="binder-tabs" role="tablist" aria-label="ماه">
                      <?php foreach ($months as $monthId => $month): ?>
                        <button type="button"
                          class="binder-tab <?= e((string) ($month['class'] ?? 'binder-tab-in-person')) ?><?= $defaultMonthId === $monthId ? ' is-active' : '' ?>"
                          role="tab"
                          data-binder-tab="<?= e((string) $monthId) ?>"
                          data-binder-tone="<?= e((string) ($month['tone'] ?? 'in-person')) ?>"
                          aria-selected="<?= $defaultMonthId === $monthId ? 'true' : 'false' ?>">
                          <?= e((string) ($month['tab_label'] ?? $month['short'] ?? '')) ?>
                          <?php if ((int) ($month['present_count'] ?? 0) > 0): ?>
                            <span class="binder-tab-count"><?= e(to_fa_digits((string) $month['present_count'])) ?></span>
                          <?php endif; ?>
                        </button>
                      <?php endforeach; ?>
                    </div>
                    <div class="binder-body">
                      <?php foreach ($months as $monthId => $month): ?>
                        <?php
                          $days = $month['days'] ?? [];
                          $wanted = (string) $cal['today'];
                          if (isset($days[$wanted])) {
                              $defaultDayId = (string) $days[$wanted]['id'];
                          } elseif ($days) {
                              $defaultDayId = (string) ($days[array_key_first($days)]['id'] ?? '');
                          } else {
                              $defaultDayId = '';
                          }
                        ?>
                        <section class="binder-panel<?= $defaultMonthId === $monthId ? ' is-active' : '' ?>" data-binder-panel="<?= e((string) $monthId) ?>" role="tabpanel"<?= $defaultMonthId === $monthId ? '' : ' hidden' ?>>
                          <h2 class="binder-sub" style="margin-top:0">
                            <?= e((string) ($month['label'] ?? '')) ?>
                            <span class="muted" style="font-weight:400;font-size:.85rem">
                              · <?= e(to_fa_digits((string) ($month['length'] ?? 0))) ?> روزه
                            </span>
                          </h2>
                          <?php if (!$days): ?>
                            <p class="muted">در این ماه حضوری برای این منشی ثبت نشده.</p>
                          <?php else: ?>
                            <div class="binder-tile binder-tile--nested" data-binder-tabs data-binder-hash="0" data-binder-initial="<?= e($defaultDayId) ?>" data-binder-tone="<?= e((string) ($month['tone'] ?? 'in-person')) ?>">
                              <div class="binder-tabs" role="tablist" aria-label="روزهای حضور">
                                <?php foreach ($days as $day): ?>
                                  <button type="button"
                                    class="binder-tab <?= e((string) ($month['class'] ?? 'binder-tab-in-person')) ?><?= $defaultDayId === ($day['id'] ?? '') ? ' is-active' : '' ?>"
                                    role="tab"
                                    data-binder-tab="<?= e((string) ($day['id'] ?? '')) ?>"
                                    data-binder-tone="<?= e((string) ($month['tone'] ?? 'in-person')) ?>"
                                    aria-selected="<?= $defaultDayId === ($day['id'] ?? '') ? 'true' : 'false' ?>">
                                    <?= e((string) ($day['tab_label'] ?? '')) ?>
                                  </button>
                                <?php endforeach; ?>
                              </div>
                              <div class="binder-body">
                                <?php foreach ($days as $day): ?>
                                  <section class="binder-panel<?= $defaultDayId === ($day['id'] ?? '') ? ' is-active' : '' ?>" data-binder-panel="<?= e((string) ($day['id'] ?? '')) ?>" role="tabpanel"<?= $defaultDayId === ($day['id'] ?? '') ? '' : ' hidden' ?>>
                                    <?= staff_hours_render_tile($block, (string) ($day['date'] ?? ''), $today) ?>
                                  </section>
                                <?php endforeach; ?>
                              </div>
                            </div>
                          <?php endif; ?>
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
    <?php
    return (string) ob_get_clean();
}

function staff_hours_render_tile(array $block, string $dayDate, string $today): string
{
    $sec = $block['user'] ?? [];
    $label = (string) ($block['label'] ?? staff_actor_label($sec));
    $isToday = $dayDate === $today;
    $day = $block['days'][$dayDate] ?? null;
    $dayRows = $isToday ? ($block['today_rows'] ?? []) : (($day['items'] ?? []) ?: []);
    if ($isToday && !$dayRows && !empty($day['items'])) {
        $dayRows = $day['items'];
    }
    $dayReport = $block['reports_by_date'][$dayDate] ?? null;
    $daySplit = staff_rows_seconds_split($dayRows);
    $daySeconds = (int) $daySplit['total'];
    $parts = jalali_day_parts($dayDate . ' 12:00:00');

    ob_start();
    ?>
  <div class="panel stack" style="margin-top:1.25rem">
    <div class="row-between">
      <div>
        <strong><?= e($label) ?></strong>
        <div class="muted" style="font-size:.85rem;margin-top:.25rem">
          <?= e((string) ($parts['label'] ?? $dayDate)) ?>
          · حضور: <?= e(staff_format_duration($daySeconds)) ?>
          · <?= e(staff_format_split_line($daySplit, true)) ?>
          · <?= e(to_fa_digits((string) count($dayRows))) ?> بار ورود
        </div>
      </div>
      <?php if ($isToday && $block['open']): ?>
        <span class="badge">آنلاین از <?= e(format_fa_datetime((string) $block['open']['started_at'])) ?></span>
      <?php elseif ($isToday): ?>
        <span class="badge">آفلاین</span>
      <?php endif; ?>
    </div>

    <?php if ($dayRows): ?>
      <div>
        <h3 class="appt-day-title" style="margin-bottom:.45rem"><?= $isToday ? 'ورودهای امروز' : 'ساعت حضور' ?></h3>
        <ol class="staff-shift-lines">
          <?php foreach ($dayRows as $i => $row): ?>
            <li>
              <strong>ورود <?= e(to_fa_digits((string) ($i + 1))) ?></strong>
              از <?= e(format_fa_datetime((string) $row['started_at'])) ?>
              تا <?= !empty($row['ended_at']) ? e(format_fa_datetime((string) $row['ended_at'])) : ($isToday ? 'الان' : '— هنوز باز') ?>
              · <?= e(staff_format_duration(staff_shift_seconds($row))) ?>
              · <?= e(staff_format_split_line(staff_shift_seconds_split($row))) ?>
              · <?= e(staff_shift_reason_label($row['end_reason'] ?? null)) ?>
            </li>
          <?php endforeach; ?>
        </ol>
      </div>
    <?php endif; ?>

    <?php if ($dayReport): ?>
      <div class="staff-day-report">
        <strong><?= $isToday ? 'گزارش پایان امروز' : 'گزارش کار' ?></strong>
        <p><?= nl2br(e((string) $dayReport['body'])) ?></p>
      </div>
    <?php endif; ?>

    <?php if (!$dayRows && !$dayReport): ?>
      <p class="muted">در این روز حضوری ثبت نشده.</p>
    <?php endif; ?>
  </div>
    <?php
    return (string) ob_get_clean();
}

function staff_hours_send_export(PDO $pdo, ?int $slot = null): void
{
    $slot = $slot === 1 || $slot === 2 ? $slot : null;
    $rows = staff_hours_export_rows($pdo, $slot);
    $who = $slot ? ('secretary-' . $slot) : 'all';
    $filename = 'staff-hours-' . $who . '-' . date('Ymd') . '.csv';

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store');

    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, [
        'منشی',
        'نام کاربری',
        'تاریخ شمسی',
        'شماره ورود',
        'ورود',
        'خروج',
        'مدت',
        'ساعت عادی',
        'اضافه‌کاری',
        'توضیح',
    ]);
    foreach ($rows as $row) {
        fputcsv($out, [
            (string) ($row['label'] ?? ''),
            (string) ($row['username'] ?? ''),
            (string) ($row['date'] ?? ''),
            $row['entry'] !== '' ? to_fa_digits((string) $row['entry']) : '',
            (string) ($row['started_at'] ?? ''),
            (string) ($row['ended_at'] ?? ''),
            (string) ($row['duration'] ?? ''),
            (string) ($row['regular'] ?? ''),
            (string) ($row['overtime'] ?? ''),
            (string) ($row['reason'] ?? ''),
        ]);
    }
    fclose($out);
    exit;
}
