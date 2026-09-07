<?php
declare(strict_types=1);

function staff_hours_scripts(): string
{
    return '<script src="' . e(url('/assets/js/binder-tabs.js')) . '?v=20260907h"></script>';
}

function staff_hours_person_word(array $block): string
{
    return (($block['kind'] ?? '') === 'doctor') ? 'درمانگر' : 'منشی';
}

function staff_hours_month_tab_label(array $month): string
{
    $base = trim((string) ($month['range_tab_label'] ?? ''));
    if ($base !== '') {
        return $base;
    }
    $name = trim((string) ($month['tab_label'] ?? $month['short'] ?? 'ماه'));
    if ($name === '') {
        $name = 'ماه';
    }
    return str_starts_with($name, 'کل ') ? $name : ('کل ' . $name);
}

function staff_hours_people(array $slots): array
{
    if ($slots === []) {
        return [];
    }
    if (isset($slots['tab_id']) || isset($slots['kind']) || isset($slots['days']) || array_key_exists('user', $slots)) {
        return [$slots];
    }
    $people = [];
    foreach (array_values($slots) as $block) {
        if (is_array($block)) {
            $people[] = $block;
        }
    }
    return $people;
}

function staff_hours_render(array $slots, array $opts = []): string
{
    $obLevel = ob_get_level();
    try {
    $people = staff_hours_people($slots);
    $canRename = !empty($opts['can_rename']);
    $renameAction = (string) ($opts['rename_action'] ?? '/doctor/staff-hours');
    $exportBase = (string) ($opts['export_base'] ?? '/doctor/staff-hours-export');
    $today = date('Y-m-d');
    $secLabels = [1 => staff_slot_default_label(1), 2 => staff_slot_default_label(2)];
    foreach ($people as $block) {
        if (($block['kind'] ?? '') === 'secretary' && isset($block['slot']) && $block['slot'] !== null && $block['slot'] !== '') {
            $slotNo = (int) $block['slot'];
            if ($slotNo === 1 || $slotNo === 2) {
                $secLabels[$slotNo] = (string) ($block['label'] ?? $secLabels[$slotNo]);
            }
        }
    }
    $defaultSlotId = (string) (($people[0]['tab_id'] ?? 'sec-1'));
    if ($defaultSlotId === '') {
        $defaultSlotId = 'sec-1';
    }
    $viewer = function_exists('current_user') ? current_user() : null;
    $viewerId = is_array($viewer) ? (string) ($viewer['id'] ?? '') : '';
    if (is_array($viewer) && ($viewer['role'] ?? '') === 'DOCTOR' && $viewerId !== '') {
        foreach ($people as $block) {
            $blockUser = is_array($block['user'] ?? null) ? $block['user'] : [];
            if (($block['kind'] ?? '') === 'doctor' && (string) ($blockUser['id'] ?? '') === $viewerId) {
                $tabId = (string) ($block['tab_id'] ?? '');
                if ($tabId !== '') {
                    $defaultSlotId = $tabId;
                }
                break;
            }
        }
    }

    ob_start();
    ?>
<h1>ساعت کاری منشی‌ها</h1>
<p class="muted">ساعت عادی از ۹ صبح تا ۸ شب است؛ خارج از این بازه اضافه‌کار حساب می‌شود. تب هر منشی و هر درمانگر جداست. جزئیات ورود و خروج پشت «بیشتر» است.</p>

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
      <?php $sid = (string) ($block['tab_id'] ?? ''); ?>
      <section class="binder-panel<?= $defaultSlotId === $sid ? ' is-active' : '' ?>" data-binder-panel="<?= e($sid) ?>" role="tabpanel"<?= $defaultSlotId === $sid ? '' : ' hidden' ?>>
        <?= staff_hours_render_calendar($block, ['today' => $today]) ?>
      </section>
    <?php endforeach; ?>
  </div>
</div>
    <?php
    return (string) ob_get_clean();
    } catch (Throwable $ignored) {
        while (ob_get_level() > $obLevel) {
            ob_end_clean();
        }
        return '<h1>ساعت کاری منشی‌ها</h1><p class="muted">بارگذاری ساعت کاری الان ممکن نیست. یک‌بار دیگر صفحه را باز کنید.</p>';
    }
}

function staff_hours_render_self(array $block, array $opts = []): string
{
    $obLevel = ob_get_level();
    try {
    $today = (string) ($opts['today'] ?? date('Y-m-d'));
    $title = (string) ($opts['title'] ?? 'ساعت کاری من');
    $intro = (string) ($opts['intro'] ?? '');
    if ($intro === '') {
        $intro = 'ساعت عادی از ۹ صبح تا ۸ شب است؛ خارج از این بازه اضافه‌کار حساب می‌شود. جزئیات ورود و خروج پشت «بیشتر» است.';
    }
    $todayRows = staff_hours_day_rows($block, $today, $today);
    $open = is_array($block['open'] ?? null) ? $block['open'] : null;

    ob_start();
    ?>
<h1><?= e($title) ?></h1>
<p class="muted"><?= e($intro) ?></p>

<div class="panel stack" style="margin-top:1rem">
  <div class="row-between">
    <strong>حضور امروز</strong>
    <?php if ($open): ?>
      <span class="badge">آنلاین از <?= e(format_fa_datetime((string) ($open['started_at'] ?? ''))) ?></span>
    <?php else: ?>
      <span class="badge">آفلاین</span>
    <?php endif; ?>
  </div>
  <?= staff_hours_render_day_presence($todayRows, ['is_today' => true]) ?>
</div>

<h2 class="binder-sub" style="margin-top:1.5rem">انتخاب ماه</h2>
<p class="muted" style="margin:.2rem 0 .85rem">با زدن «کل شهریور» یا «کل مهر» بازه، روزهای حضور، زمان حضور و عادی/اضافه‌کار همان ماه را می‌بینید.</p>
<?= staff_hours_render_calendar($block, ['today' => $today]) ?>
    <?php
    return (string) ob_get_clean();
    } catch (Throwable $ignored) {
        while (ob_get_level() > $obLevel) {
            ob_end_clean();
        }
        return '<h1>ساعت کاری من</h1><p class="muted">بارگذاری ساعت کاری الان ممکن نیست.</p>';
    }
}

function staff_hours_render_calendar(array $block, array $opts = []): string
{
    $obLevel = ob_get_level();
    try {
    $today = (string) ($opts['today'] ?? date('Y-m-d'));
    $personWord = staff_hours_person_word($block);
    $cal = staff_hours_calendar($block);
    if (!is_array($cal)) {
        $cal = [];
    }
    $halves = is_array($cal['halves'] ?? null) ? $cal['halves'] : [];
    $defaultHalfId = (string) ($cal['default_half_id'] ?? '');
    if ($halves === []) {
        return '<p class="muted">برای این ' . e($personWord) . ' سابقه‌ای نیست.</p>';
    }
    if ($defaultHalfId === '' || !isset($halves[$defaultHalfId])) {
        $defaultHalfId = (string) (array_key_first($halves) ?? '');
    }
    $defaultHalf = is_array($halves[$defaultHalfId] ?? null) ? $halves[$defaultHalfId] : [];
    $defaultHalfTone = (string) ($defaultHalf['tone'] ?? 'online');

    ob_start();
    ?>
<div class="binder-tile binder-tile--nested staff-hours-calendar" data-binder-tabs data-binder-hash="0" data-binder-initial="<?= e($defaultHalfId) ?>" data-binder-tone="<?= e($defaultHalfTone) ?>">
  <div class="binder-tabs" role="tablist" aria-label="نیم‌سال">
    <?php foreach ($halves as $halfId => $half): ?>
      <?php
        if (!is_array($half)) {
            continue;
        }
        $halfId = (string) $halfId;
      ?>
      <button type="button"
        class="binder-tab <?= e((string) ($half['class'] ?? '')) ?><?= $defaultHalfId === $halfId ? ' is-active' : '' ?>"
        role="tab"
        data-binder-tab="<?= e($halfId) ?>"
        data-binder-tone="<?= e((string) ($half['tone'] ?? 'online')) ?>"
        aria-selected="<?= $defaultHalfId === $halfId ? 'true' : 'false' ?>">
        <?= e((string) ($half['label'] ?? '')) ?>
      </button>
    <?php endforeach; ?>
  </div>
  <div class="binder-body">
    <?php foreach ($halves as $halfId => $half): ?>
      <?php
        if (!is_array($half)) {
            continue;
        }
        $halfId = (string) $halfId;
        $months = is_array($half['months'] ?? null) ? $half['months'] : [];
        $defaultMonthId = (string) ($cal['default_month_id'] ?? '');
        if ($defaultMonthId === '' || !isset($months[$defaultMonthId])) {
            $defaultMonthId = (string) (array_key_first($months) ?? '');
        }
        $monthTone = (string) ($half['tone'] ?? 'in-person');
        $defaultMonth = is_array($months[$defaultMonthId] ?? null) ? $months[$defaultMonthId] : [];
        if ($defaultMonth !== []) {
            $monthTone = (string) ($defaultMonth['tone'] ?? $monthTone);
        }
      ?>
      <section class="binder-panel<?= $defaultHalfId === $halfId ? ' is-active' : '' ?>" data-binder-panel="<?= e($halfId) ?>" role="tabpanel"<?= $defaultHalfId === $halfId ? '' : ' hidden' ?>>
        <?php if (!$months): ?>
          <p class="muted">ماهی در این نیم‌سال نیست.</p>
        <?php else: ?>
          <div class="binder-tile binder-tile--nested staff-hours-month-tabs" data-binder-tabs data-binder-hash="0" data-binder-initial="<?= e($defaultMonthId) ?>" data-binder-tone="<?= e($monthTone) ?>">
            <div class="binder-tabs" role="tablist" aria-label="ماه">
              <?php foreach ($months as $monthId => $month): ?>
                <?php
                  if (!is_array($month)) {
                      continue;
                  }
                  $monthId = (string) $monthId;
                ?>
                <button type="button"
                  class="binder-tab staff-hours-range-tab <?= e((string) ($month['class'] ?? 'binder-tab-in-person')) ?><?= $defaultMonthId === $monthId ? ' is-active' : '' ?>"
                  role="tab"
                  data-binder-tab="<?= e($monthId) ?>"
                  data-binder-tone="<?= e((string) ($month['tone'] ?? 'in-person')) ?>"
                  aria-selected="<?= $defaultMonthId === $monthId ? 'true' : 'false' ?>">
                  <?= e(staff_hours_month_tab_label($month)) ?>
                  <?php if ((int) ($month['present_count'] ?? 0) > 0): ?>
                    <span class="binder-tab-count"><?= e(to_fa_digits((string) $month['present_count'])) ?></span>
                  <?php endif; ?>
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
                ?>
                <section class="binder-panel<?= $defaultMonthId === $monthId ? ' is-active' : '' ?>" data-binder-panel="<?= e($monthId) ?>" role="tabpanel"<?= $defaultMonthId === $monthId ? '' : ' hidden' ?>>
                  <?= staff_hours_render_month_panel($block, $month, $today) ?>
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
    } catch (Throwable $ignored) {
        while (ob_get_level() > $obLevel) {
            ob_end_clean();
        }
        return '<p class="muted">نمایش سابقه ممکن نشد.</p>';
    }
}

function staff_hours_render_month_panel(array $block, array $month, string $today): string
{
    $days = is_array($month['days'] ?? null) ? $month['days'] : [];
    $defaultDayId = '';
    if (isset($days[$today]) && is_array($days[$today])) {
        $defaultDayId = (string) ($days[$today]['id'] ?? '');
    } elseif ($days) {
        $first = reset($days);
        $defaultDayId = is_array($first) ? (string) ($first['id'] ?? '') : '';
    }

    ob_start();
    ?>
<div class="staff-hours-month-main">
  <h2 class="binder-sub staff-hours-month-heading" style="margin-top:0">
    کل <?= e((string) ($month['label'] ?? $month['short'] ?? 'ماه')) ?>
    <span class="muted" style="font-weight:400;font-size:.85rem">
      · <?= e(to_fa_digits((string) ($month['length'] ?? 0))) ?> روزه
    </span>
  </h2>
  <?= staff_hours_render_month_tile($block, $month, $today) ?>
  <?php if ($days): ?>
    <div class="staff-hours-day-drill">
      <h3 class="staff-hours-day-drill-title">جزئیات روز</h3>
      <p class="muted staff-hours-day-drill-hint">جمع ماه همین بالاست. برای ورود و خروج یک روز، تب آن را بزنید.</p>
      <div class="binder-tile binder-tile--nested" data-binder-tabs data-binder-hash="0" data-binder-initial="<?= e($defaultDayId) ?>" data-binder-tone="<?= e((string) ($month['tone'] ?? 'in-person')) ?>">
        <div class="binder-tabs" role="tablist" aria-label="روزهای حضور">
          <?php foreach ($days as $day): ?>
            <?php
              if (!is_array($day)) {
                  continue;
              }
              $did = (string) ($day['id'] ?? '');
            ?>
            <button type="button"
              class="binder-tab <?= e((string) ($month['class'] ?? 'binder-tab-in-person')) ?><?= $defaultDayId === $did ? ' is-active' : '' ?>"
              role="tab"
              data-binder-tab="<?= e($did) ?>"
              data-binder-tone="<?= e((string) ($month['tone'] ?? 'in-person')) ?>"
              aria-selected="<?= $defaultDayId === $did ? 'true' : 'false' ?>">
              <?= e((string) ($day['tab_label'] ?? '')) ?>
            </button>
          <?php endforeach; ?>
        </div>
        <div class="binder-body">
          <?php foreach ($days as $day): ?>
            <?php
              if (!is_array($day)) {
                  continue;
              }
              $did = (string) ($day['id'] ?? '');
            ?>
            <section class="binder-panel<?= $defaultDayId === $did ? ' is-active' : '' ?>" data-binder-panel="<?= e($did) ?>" role="tabpanel"<?= $defaultDayId === $did ? '' : ' hidden' ?>>
              <?= staff_hours_render_tile($block, (string) ($day['date'] ?? ''), $today) ?>
            </section>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  <?php endif; ?>
</div>
    <?php
    return (string) ob_get_clean();
}

function staff_hours_format_clock(mixed $datetime, string $empty = '—'): string
{
    if ($datetime === null || is_array($datetime) || is_object($datetime)) {
        return $empty;
    }
    $datetime = trim((string) $datetime);
    if ($datetime === '') {
        return $empty;
    }

    return format_fa_time($datetime);
}

function staff_hours_render_shift_lines(array $rows, bool $isToday = false): string
{
    $rows = function_exists('staff_hours_shift_rows') ? staff_hours_shift_rows($rows) : $rows;
    if ($rows === []) {
        return '';
    }

    ob_start();
    ?>
    <ol class="staff-shift-lines">
      <?php foreach ($rows as $i => $row): ?>
        <?php if (!is_array($row)) { continue; } ?>
        <li>
          <strong>ورود <?= e(to_fa_digits((string) ((int) $i + 1))) ?></strong>
          از <?= e(format_fa_datetime((string) ($row['started_at'] ?? ''))) ?>
          تا <?= !empty($row['ended_at']) ? e(format_fa_datetime((string) $row['ended_at'])) : ($isToday ? 'الان' : '— هنوز باز') ?>
          · <?= e(staff_format_duration(staff_shift_seconds($row))) ?>
          · <?= e(staff_format_split_line(staff_shift_seconds_split($row))) ?>
          · <?= e(staff_shift_reason_label(isset($row['end_reason']) && is_scalar($row['end_reason']) ? (string) $row['end_reason'] : null)) ?>
        </li>
      <?php endforeach; ?>
    </ol>
    <?php
    return (string) ob_get_clean();
}

function staff_hours_render_day_presence(array $rows, array $opts = []): string
{
    $isToday = !empty($opts['is_today']);
    $rows = function_exists('staff_hours_shift_rows') ? staff_hours_shift_rows($rows) : $rows;
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
    $daysMap = is_array($block['days'] ?? null) ? $block['days'] : [];
    $day = is_array($daysMap[$dayDate] ?? null) ? $daysMap[$dayDate] : [];
    $todayRows = function_exists('staff_hours_shift_rows')
        ? staff_hours_shift_rows($block['today_rows'] ?? [])
        : (is_array($block['today_rows'] ?? null) ? $block['today_rows'] : []);
    $itemRows = function_exists('staff_hours_shift_rows')
        ? staff_hours_shift_rows($day['items'] ?? [])
        : (is_array($day['items'] ?? null) ? $day['items'] : []);
    $dayRows = $isToday ? $todayRows : $itemRows;
    if ($isToday && $dayRows === [] && $itemRows !== []) {
        $dayRows = $itemRows;
    }

    return function_exists('staff_hours_shift_rows') ? staff_hours_shift_rows($dayRows) : (is_array($dayRows) ? $dayRows : []);
}

function staff_hours_render_tile(array $block, string $dayDate, string $today): string
{
    $sec = is_array($block['user'] ?? null) ? $block['user'] : [];
    $label = (string) ($block['label'] ?? staff_actor_label($sec));
    $isToday = $dayDate === $today;
    $dayRows = staff_hours_day_rows($block, $dayDate, $today);
    $reportsByDate = is_array($block['reports_by_date'] ?? null) ? $block['reports_by_date'] : [];
    $dayReport = is_array($reportsByDate[$dayDate] ?? null) ? $reportsByDate[$dayDate] : null;
    $parts = jalali_day_parts($dayDate . ' 12:00:00') ?? [];
    $openShift = is_array($block['open'] ?? null) ? $block['open'] : null;

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
      <?php if ($isToday && $openShift): ?>
        <span class="badge">آنلاین از <?= e(format_fa_datetime((string) ($openShift['started_at'] ?? ''))) ?></span>
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
    $sec = is_array($block['user'] ?? null) ? $block['user'] : [];
    $label = (string) ($block['label'] ?? staff_actor_label($sec));
    $monthLabel = (string) ($month['label'] ?? $month['short'] ?? 'این ماه');
    $monthShort = (string) ($month['short'] ?? 'ماه');
    $monthLen = (int) ($month['length'] ?? 0);
    $days = is_array($month['days'] ?? null) ? $month['days'] : [];
    $reportsByDate = is_array($block['reports_by_date'] ?? null) ? $block['reports_by_date'] : [];
    $openShift = is_array($block['open'] ?? null) ? $block['open'] : null;
    $allRows = [];
    $dayPacks = [];
    foreach ($days as $gdate => $day) {
        if (!is_array($day)) {
            $day = [];
        }
        $date = (string) ($day['date'] ?? $gdate);
        $rows = staff_hours_day_rows($block, $date, $today);
        $allRows = array_merge($allRows, $rows);
        $dayPacks[] = [
            'date' => $date,
            'label' => (string) ($day['label'] ?? $date),
            'rows' => $rows,
            'is_today' => $date === $today,
            'report' => is_array($reportsByDate[$date] ?? null) ? $reportsByDate[$date] : null,
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
      <?php if ($monthHasToday && $openShift): ?>
        <span class="badge">آنلاین از <?= e(format_fa_datetime((string) ($openShift['started_at'] ?? ''))) ?></span>
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
    try {
        $rows = staff_hours_export_rows($pdo, $who);
    } catch (Throwable $ignored) {
        $rows = [];
    }
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
