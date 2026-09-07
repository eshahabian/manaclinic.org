<?php
declare(strict_types=1);

function staff_hours_scripts(): string
{
    return '<script src="' . e(url('/assets/js/binder-tabs.js')) . '?v=20260906o"></script>';
}

function staff_hours_person_word(array $block): string
{
    return (($block['kind'] ?? '') === 'doctor') ? 'درمانگر' : 'منشی';
}

function staff_hours_render(array $slots, array $opts = []): string
{
    $people = array_values($slots);
    $canRename = !empty($opts['can_rename']);
    $renameAction = (string) ($opts['rename_action'] ?? '/doctor/staff-hours');
    $exportBase = (string) ($opts['export_base'] ?? '/doctor/staff-hours-export');
    $today = date('Y-m-d');
    $secLabels = [1 => staff_slot_default_label(1), 2 => staff_slot_default_label(2)];
    foreach ($people as $block) {
        if (($block['kind'] ?? '') === 'secretary' && isset($block['slot'])) {
            $secLabels[(int) $block['slot']] = (string) ($block['label'] ?? $secLabels[(int) $block['slot']]);
        }
    }
    $defaultSlotId = (string) (($people[0]['tab_id'] ?? 'sec-1'));
    $viewer = current_user();
    if ($viewer && ($viewer['role'] ?? '') === 'DOCTOR') {
        foreach ($people as $block) {
            if (($block['kind'] ?? '') === 'doctor' && (string) (($block['user']['id'] ?? '')) === (string) ($viewer['id'] ?? '')) {
                $defaultSlotId = (string) ($block['tab_id'] ?? $defaultSlotId);
                break;
            }
        }
    }

    ob_start();
    ?>
<h1>ساعت کاری</h1>
<p class="muted">ساعت عادی از ۹ صبح تا ۸ شب است؛ خارج از این بازه اضافه‌کار حساب می‌شود. تب هر منشی و هر درمانگر جداست. ماه را انتخاب کنید تا جمع کل همان ماه را ببینید، یا یک روز را جدا باز کنید. جزئیات ورود و خروج‌ها پشت «بیشتر» است.</p>

<div class="staff-hours-toolbar">
  <div class="staff-hours-export">
    <?php foreach ($people as $block): ?>
      <a class="btn btn-outline btn-sm" href="<?= e(url($exportBase . '?who=' . rawurlencode((string) ($block['tab_id'] ?? '')))) ?>">خروجی <?= e((string) ($block['label'] ?? '')) ?></a>
    <?php endforeach; ?>
    <a class="btn btn-outline btn-sm" href="<?= e(url($exportBase)) ?>">خروجی همه</a>
  </div>
  <?php if ($canRename): ?>
    <form method="post" action="<?= e(url($renameAction)) ?>" class="staff-hours-alias">
      <?= csrf_field() ?>
      <p class="muted" style="margin:0 0 .35rem;font-size:.8rem">نام موقت برای تب منشی‌ها. اگر کسی عوض شد همین‌جا عوض کنید.</p>
      <div class="staff-hours-alias-row">
        <label>
          منشی ۱
          <input type="text" name="label_1" maxlength="80" value="<?= e((string) ($secLabels[1] ?? 'منشی ۱')) ?>">
        </label>
        <label>
          منشی ۲
          <input type="text" name="label_2" maxlength="80" value="<?= e((string) ($secLabels[2] ?? 'منشی ۲')) ?>">
        </label>
        <button type="submit" class="btn btn-sm">ذخیره نام</button>
      </div>
    </form>
  <?php endif; ?>
</div>

<div class="binder-tile" data-binder-tabs data-binder-hash="0" data-binder-initial="<?= e($defaultSlotId) ?>" data-binder-tone="appts" style="margin-top:1.25rem">
  <div class="binder-tabs" role="tablist" aria-label="افراد">
    <?php foreach ($people as $block): ?>
      <?php $sid = (string) ($block['tab_id'] ?? ''); ?>
      <button type="button"
        class="binder-tab <?= e((string) ($block['tab_class'] ?? 'binder-tab-in-person')) ?><?= $defaultSlotId === $sid ? ' is-active' : '' ?>"
        role="tab"
        data-binder-tab="<?= e($sid) ?>"
        data-binder-tone="<?= e((string) ($block['tab_tone'] ?? 'in-person')) ?>"
        aria-selected="<?= $defaultSlotId === $sid ? 'true' : 'false' ?>">
        <?= e((string) ($block['label'] ?? '')) ?>
      </button>
    <?php endforeach; ?>
  </div>
  <div class="binder-body">
    <?php foreach ($people as $block): ?>
      <?php
        $sid = (string) ($block['tab_id'] ?? '');
        $personWord = staff_hours_person_word($block);
        $cal = staff_hours_calendar($block);
        $halves = $cal['halves'];
        $defaultHalfId = (string) $cal['default_half_id'];
      ?>
      <section class="binder-panel<?= $defaultSlotId === $sid ? ' is-active' : '' ?>" data-binder-panel="<?= e($sid) ?>" role="tabpanel"<?= $defaultSlotId === $sid ? '' : ' hidden' ?>>
        <?php if (!$halves): ?>
          <p class="muted">برای این <?= e($personWord) ?> سابقه‌ای نیست.</p>
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
                        <?php $days = $month['days'] ?? []; ?>
                        <section class="binder-panel<?= $defaultMonthId === $monthId ? ' is-active' : '' ?>" data-binder-panel="<?= e((string) $monthId) ?>" role="tabpanel"<?= $defaultMonthId === $monthId ? '' : ' hidden' ?>>
                          <h2 class="binder-sub" style="margin-top:0">
                            <?= e((string) ($month['label'] ?? '')) ?>
                            <span class="muted" style="font-weight:400;font-size:.85rem">
                              · <?= e(to_fa_digits((string) ($month['length'] ?? 0))) ?> روزه
                            </span>
                          </h2>
                          <?php if (!$days): ?>
                            <p class="muted">در این ماه حضوری برای این <?= e($personWord) ?> ثبت نشده.</p>
                          <?php else: ?>
                            <?php
                              $monthAllId = (string) $monthId . '-all';
                              $defaultDayId = $monthAllId;
                              $monthShort = (string) ($month['short'] ?? $month['tab_label'] ?? 'ماه');
                            ?>
                            <div class="binder-tile binder-tile--nested" data-binder-tabs data-binder-hash="0" data-binder-initial="<?= e($defaultDayId) ?>" data-binder-tone="appts">
                              <div class="binder-tabs" role="tablist" aria-label="بازه حضور">
                                <button type="button"
                                  class="binder-tab binder-tab-appts is-active"
                                  role="tab"
                                  data-binder-tab="<?= e($monthAllId) ?>"
                                  data-binder-tone="appts"
                                  aria-selected="true">
                                  کل <?= e($monthShort) ?>
                                </button>
                                <?php foreach ($days as $day): ?>
                                  <button type="button"
                                    class="binder-tab <?= e((string) ($month['class'] ?? 'binder-tab-in-person')) ?>"
                                    role="tab"
                                    data-binder-tab="<?= e((string) ($day['id'] ?? '')) ?>"
                                    data-binder-tone="<?= e((string) ($month['tone'] ?? 'in-person')) ?>"
                                    aria-selected="false">
                                    <?= e((string) ($day['tab_label'] ?? '')) ?>
                                  </button>
                                <?php endforeach; ?>
                              </div>
                              <div class="binder-body">
                                <section class="binder-panel is-active" data-binder-panel="<?= e($monthAllId) ?>" role="tabpanel">
                                  <?= staff_hours_render_month_tile($block, $month, $today) ?>
                                </section>
                                <?php foreach ($days as $day): ?>
                                  <section class="binder-panel" data-binder-panel="<?= e((string) ($day['id'] ?? '')) ?>" role="tabpanel" hidden>
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

function staff_hours_format_clock(?string $datetime, string $empty = '—'): string
{
    if ($datetime === null || trim($datetime) === '') {
        return $empty;
    }

    return format_fa_time($datetime);
}

function staff_hours_render_shift_lines(array $rows, bool $isToday = false): string
{
    if ($rows === []) {
        return '';
    }

    ob_start();
    ?>
    <ol class="staff-shift-lines">
      <?php foreach ($rows as $i => $row): ?>
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
    <?php
    return (string) ob_get_clean();
}

function staff_hours_render_day_presence(array $rows, array $opts = []): string
{
    $isToday = !empty($opts['is_today']);
    if ($rows === []) {
        $empty = (string) ($opts['empty'] ?? ($isToday ? 'هنوز ورودی برای امروز نیست.' : 'در این روز حضوری ثبت نشده.'));

        return '<p class="muted">' . e($empty) . '</p>';
    }

    $split = staff_rows_seconds_split($rows);
    $meta = staff_day_presence_meta($rows);
    $outClock = !empty($meta['open'])
        ? ($isToday ? 'الان' : '— هنوز باز')
        : staff_hours_format_clock($meta['last_out'] ?? null);
    $count = (int) ($meta['count'] ?? 0);
    $moreLabel = $count > 1
        ? 'بیشتر · ' . to_fa_digits((string) $count) . ' ورود و خروج'
        : 'بیشتر';

    ob_start();
    ?>
    <div class="staff-presence">
      <div class="staff-presence-summary">
        <div class="staff-presence-stat">
          <span class="staff-presence-label">ساعت ورود</span>
          <span class="staff-presence-value"><?= e(staff_hours_format_clock($meta['first_in'] ?? null)) ?></span>
        </div>
        <div class="staff-presence-stat">
          <span class="staff-presence-label">ساعت خروج</span>
          <span class="staff-presence-value"><?= e($outClock) ?></span>
        </div>
        <div class="staff-presence-stat">
          <span class="staff-presence-label">زمان حضور</span>
          <span class="staff-presence-value"><?= e(staff_format_duration((int) ($split['total'] ?? 0))) ?></span>
        </div>
      </div>
      <p class="staff-presence-split muted"><?= e(staff_format_split_line($split, true)) ?></p>
      <details class="staff-presence-more">
        <summary><?= e($moreLabel) ?></summary>
        <?= staff_hours_render_shift_lines($rows, $isToday) ?>
      </details>
    </div>
    <?php
    return (string) ob_get_clean();
}

function staff_hours_day_rows(array $block, string $dayDate, string $today): array
{
    $isToday = $dayDate === $today;
    $day = $block['days'][$dayDate] ?? null;
    $dayRows = $isToday ? ($block['today_rows'] ?? []) : (($day['items'] ?? []) ?: []);
    if ($isToday && !$dayRows && !empty($day['items'])) {
        $dayRows = $day['items'];
    }

    return is_array($dayRows) ? $dayRows : [];
}

function staff_hours_render_tile(array $block, string $dayDate, string $today): string
{
    $sec = $block['user'] ?? [];
    $label = (string) ($block['label'] ?? staff_actor_label($sec));
    $isToday = $dayDate === $today;
    $dayRows = staff_hours_day_rows($block, $dayDate, $today);
    $dayReport = $block['reports_by_date'][$dayDate] ?? null;
    $parts = jalali_day_parts($dayDate . ' 12:00:00');

    ob_start();
    ?>
  <div class="panel stack" style="margin-top:1.25rem">
    <div class="row-between">
      <div>
        <strong><?= e($label) ?></strong>
        <div class="muted" style="font-size:.85rem;margin-top:.25rem">
          <?= e((string) ($parts['label'] ?? $dayDate)) ?>
        </div>
      </div>
      <?php if ($isToday && $block['open']): ?>
        <span class="badge">آنلاین از <?= e(format_fa_datetime((string) $block['open']['started_at'])) ?></span>
      <?php elseif ($isToday): ?>
        <span class="badge">آفلاین</span>
      <?php endif; ?>
    </div>

    <?= staff_hours_render_day_presence($dayRows, ['is_today' => $isToday]) ?>

    <?php if ($dayReport): ?>
      <div class="staff-day-report">
        <strong><?= $isToday ? 'گزارش پایان امروز' : 'گزارش کار' ?></strong>
        <p><?= nl2br(e((string) $dayReport['body'])) ?></p>
      </div>
    <?php endif; ?>
  </div>
    <?php
    return (string) ob_get_clean();
}

function staff_hours_render_month_tile(array $block, array $month, string $today): string
{
    $sec = $block['user'] ?? [];
    $label = (string) ($block['label'] ?? staff_actor_label($sec));
    $monthLabel = (string) ($month['label'] ?? $month['short'] ?? 'این ماه');
    $monthShort = (string) ($month['short'] ?? 'ماه');
    $monthLen = (int) ($month['length'] ?? 0);
    $days = $month['days'] ?? [];
    $allRows = [];
    $dayPacks = [];
    foreach ($days as $gdate => $day) {
        $date = (string) ($day['date'] ?? $gdate);
        $rows = staff_hours_day_rows($block, $date, $today);
        $allRows = array_merge($allRows, $rows);
        $dayPacks[] = [
            'date' => $date,
            'label' => (string) ($day['label'] ?? $date),
            'rows' => $rows,
            'is_today' => $date === $today,
            'report' => $block['reports_by_date'][$date] ?? null,
        ];
    }
    usort($dayPacks, static fn(array $a, array $b): int => strcmp((string) $b['date'], (string) $a['date']));

    $split = staff_rows_seconds_split($allRows);
    $presentDays = 0;
    foreach ($dayPacks as $pack) {
        if (($pack['rows'] ?? []) !== [] || !empty($pack['report'])) {
            $presentDays++;
        }
    }
    $monthHasToday = $today !== '' && isset($days[$today]);

    ob_start();
    ?>
  <div class="panel stack" style="margin-top:1.25rem">
    <div class="row-between">
      <div>
        <strong><?= e($label) ?></strong>
        <div class="muted" style="font-size:.85rem;margin-top:.25rem">
          بازه: کل <?= e($monthLabel) ?>
          <?php if ($monthLen > 0): ?>
            · از <?= e(to_fa_digits('1')) ?> تا <?= e(to_fa_digits((string) $monthLen)) ?> <?= e($monthShort) ?>
          <?php endif; ?>
        </div>
      </div>
      <?php if ($monthHasToday && $block['open']): ?>
        <span class="badge">آنلاین از <?= e(format_fa_datetime((string) $block['open']['started_at'])) ?></span>
      <?php elseif ($monthHasToday): ?>
        <span class="badge">آفلاین</span>
      <?php endif; ?>
    </div>

    <?php if ($allRows === [] && $presentDays === 0): ?>
      <p class="muted">در این ماه حضوری برای این <?= e(staff_hours_person_word($block)) ?> ثبت نشده.</p>
    <?php else: ?>
      <div class="staff-presence">
        <div class="staff-presence-summary">
          <div class="staff-presence-stat">
            <span class="staff-presence-label">بازه</span>
            <span class="staff-presence-value">کل <?= e($monthShort) ?></span>
          </div>
          <div class="staff-presence-stat">
            <span class="staff-presence-label">روزهای حضور</span>
            <span class="staff-presence-value"><?= e(to_fa_digits((string) $presentDays)) ?> روز</span>
          </div>
          <div class="staff-presence-stat">
            <span class="staff-presence-label">زمان حضور</span>
            <span class="staff-presence-value"><?= e(staff_format_duration((int) ($split['total'] ?? 0))) ?></span>
          </div>
        </div>
        <p class="staff-presence-split muted"><?= e(staff_format_split_line($split, true)) ?> · <?= e(to_fa_digits((string) count($allRows))) ?> بار ورود</p>
        <details class="staff-presence-more">
          <summary>بیشتر · <?= e(to_fa_digits((string) $presentDays)) ?> روز</summary>
          <div class="staff-month-days">
            <?php foreach ($dayPacks as $pack): ?>
              <div class="staff-month-day">
                <h3 class="appt-day-title" style="margin:0 0 .4rem">
                  <?= e((string) $pack['label']) ?>
                  <?php if (!empty($pack['is_today'])): ?>
                    <span class="muted" style="font-weight:400;font-size:.85rem"> · امروز</span>
                  <?php endif; ?>
                </h3>
                <?= staff_hours_render_day_presence($pack['rows'], ['is_today' => !empty($pack['is_today'])]) ?>
                <?php if (!empty($pack['report'])): ?>
                  <div class="staff-day-report" style="margin-top:.55rem">
                    <strong><?= !empty($pack['is_today']) ? 'گزارش پایان امروز' : 'گزارش کار' ?></strong>
                    <p><?= nl2br(e((string) $pack['report']['body'])) ?></p>
                  </div>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
        </details>
      </div>
    <?php endif; ?>
  </div>
    <?php
    return (string) ob_get_clean();
}

function staff_hours_send_export(PDO $pdo, int|string|null $who = null): void
{
    if (is_int($who)) {
        $who = ($who === 1 || $who === 2) ? ('sec-' . $who) : null;
    }
    $who = is_string($who) && $who !== '' ? $who : null;
    $rows = staff_hours_export_rows($pdo, $who);
    $fileWho = $who ? (string) preg_replace('/[^a-zA-Z0-9_-]/', '', $who) : 'all';
    if ($fileWho === '') {
        $fileWho = 'all';
    }
    $filename = 'staff-hours-' . $fileWho . '-' . date('Ymd') . '.csv';

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store');

    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, [
        'نقش',
        'نام',
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
            (string) ($row['role'] ?? ''),
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
