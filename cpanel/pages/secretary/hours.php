<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/secretary_panel.php';

$user = require_login(['SECRETARY']);
ensure_secretary_day_reports($pdo);
$shift = staff_current_shift($pdo, (string) $user['id']);

$today = $pdo->prepare("
  SELECT * FROM staff_shifts
  WHERE user_id=? AND DATE(started_at)=CURDATE()
  ORDER BY started_at ASC
");
$today->execute([$user['id']]);
$todayRows = $today->fetchAll();

$history = $pdo->prepare("
  SELECT * FROM staff_shifts
  WHERE user_id=?
  ORDER BY started_at DESC
  LIMIT 80
");
$history->execute([$user['id']]);
$historyRows = $history->fetchAll();
$todayDate = date('Y-m-d');
$historyDays = staff_shifts_grouped_by_day($historyRows);
unset($historyDays[$todayDate]);

$todaySeconds = 0;
foreach ($todayRows as $row) {
    $todaySeconds += staff_shift_seconds($row);
}
$report = staff_get_day_report($pdo, (string) $user['id'], $todayDate);
$draft = staff_today_action_draft($pdo, (string) $user['id']);
$reportBody = (string) ($report['body'] ?? '');

ob_start();
?>
<h1>ساعت کاری من</h1>
<p class="muted">هر بار ورود جدا ثبت می‌شود. اگر ۱۰ دقیقه فعال نباشید خارج می‌شوید و ورود بعدی در خط جدا می‌آید.</p>

<div class="grid-2" style="margin-top:1rem">
  <div class="panel stack">
    <strong>شیفت فعلی</strong>
    <?php if ($shift): ?>
      <div>ورود این نوبت: <?= e(format_fa_datetime((string) $shift['started_at'])) ?></div>
      <div>مدت این نوبت: <?= e(staff_format_duration(staff_shift_seconds($shift))) ?></div>
    <?php else: ?>
      <p class="muted">شیفت بازی نیست.</p>
    <?php endif; ?>
  </div>
  <div class="panel stack">
    <strong>جمع امروز</strong>
    <div><?= e(staff_format_duration($todaySeconds)) ?></div>
    <div class="muted" style="font-size:.85rem"><?= to_fa_digits((string) count($todayRows)) ?> بار ورود</div>
  </div>
</div>

<div class="panel stack" style="margin-top:1.25rem">
  <h2 style="margin:0;font-size:1.05rem">ورودهای امروز</h2>
  <?php if (!$todayRows): ?>
    <p class="muted">هنوز ورودی برای امروز نیست.</p>
  <?php else: ?>
    <ol class="staff-shift-lines">
      <?php foreach ($todayRows as $i => $row): ?>
        <li>
          <strong>ورود <?= e(to_fa_digits((string) ($i + 1))) ?></strong>
          از <?= e(format_fa_datetime((string) $row['started_at'])) ?>
          تا <?= !empty($row['ended_at']) ? e(format_fa_datetime((string) $row['ended_at'])) : 'الان' ?>
          · <?= e(staff_format_duration(staff_shift_seconds($row))) ?>
          · <?= e(staff_shift_reason_label($row['end_reason'] ?? null)) ?>
        </li>
      <?php endforeach; ?>
    </ol>
  <?php endif; ?>
</div>

<div class="panel stack" style="margin-top:1.25rem;border-color:var(--primary)">
  <h2 style="margin:0;font-size:1.05rem">گزارش پایان روز</h2>
  <p class="muted" style="margin:0;font-size:.85rem;line-height:1.7">وقتی کارتان تمام شد، کارهای امروز را بنویسید. دکتر همین گزارش را در ساعت کاری می‌بیند.</p>
  <?php if ($draft !== ''): ?>
    <details>
      <summary style="cursor:pointer;color:var(--primary);font-size:.9rem">کارهای ثبت‌شده امروز (برای کپی در گزارش)</summary>
      <pre class="staff-report-draft"><?= e($draft) ?></pre>
    </details>
  <?php endif; ?>
  <form class="form-stack" method="post" action="<?= e(url('/secretary/hours')) ?>">
    <input type="hidden" name="report_date" value="<?= e($todayDate) ?>">
    <div>
      <label class="label" for="day-report-body">گزارش امروز</label>
      <textarea class="input" id="day-report-body" name="body" rows="8" required placeholder="مثلاً: ثبت نوبت برای …، دریافت فیش کارگاه …، هماهنگی با دکتر …"><?= e($reportBody) ?></textarea>
    </div>
    <button class="btn btn-primary" type="submit"><?= $report ? 'به‌روزرسانی گزارش' : 'ثبت گزارش پایان روز' ?></button>
  </form>
</div>

<div class="panel" style="margin-top:1.25rem">
  <h2 style="margin:0 0 .75rem;font-size:1.05rem">سابقه ورود و خروج</h2>
  <?php if (!$historyDays): ?>
    <p class="muted">هنوز سابقه‌ای نیست.</p>
  <?php else: ?>
    <?php foreach ($historyDays as $day): ?>
      <div class="staff-day-block">
        <h3 class="appt-day-title">
          <?= e((string) $day['label']) ?>
          <span class="muted" style="font-weight:400;font-size:.85rem"> · <?= e(to_fa_digits((string) count($day['items']))) ?> بار ورود</span>
        </h3>
        <ol class="staff-shift-lines">
          <?php foreach ($day['items'] as $i => $row): ?>
            <li>
              ورود <?= e(to_fa_digits((string) ($i + 1))) ?>:
              <?= e(format_fa_datetime((string) $row['started_at'])) ?>
              تا <?= !empty($row['ended_at']) ? e(format_fa_datetime((string) $row['ended_at'])) : '— هنوز باز' ?>
              · <?= e(staff_format_duration(staff_shift_seconds($row))) ?>
              · <?= e(staff_shift_reason_label($row['end_reason'] ?? null)) ?>
            </li>
          <?php endforeach; ?>
        </ol>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>
<?php
render_secretary_page('ساعت کاری', ob_get_clean());
