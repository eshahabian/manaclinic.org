<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/secretary_panel.php';
require_once __DIR__ . '/../../includes/staff_hours_ui.php';

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

$report = staff_get_day_report($pdo, (string) $user['id'], $todayDate);
$draft = staff_today_action_draft($pdo, (string) $user['id']);
$reportBody = (string) ($report['body'] ?? '');

ob_start();
?>
<h1>ساعت کاری من</h1>
<p class="muted">ساعت عادی منشی از ۹ صبح تا ۸ شب است؛ قبل از ۹ و بعد از ۸ اضافه‌کار حساب می‌شود. برای هر روز ساعت ورود، خروج و جمع حضور دیده می‌شود؛ جزئیات هر ورود پشت «بیشتر» است. اگر ۱۰ دقیقه فعال نباشید خارج می‌شوید.</p>

<div class="panel stack" style="margin-top:1rem">
  <strong>شیفت فعلی</strong>
  <?php if ($shift): ?>
    <div>ورود این نوبت: <?= e(format_fa_datetime((string) $shift['started_at'])) ?></div>
    <div>مدت این نوبت: <?= e(staff_format_duration(staff_shift_seconds($shift))) ?></div>
    <div class="muted" style="font-size:.85rem"><?= e(staff_format_split_line(staff_shift_seconds_split($shift), true)) ?></div>
  <?php else: ?>
    <p class="muted">شیفت بازی نیست.</p>
  <?php endif; ?>
</div>

<div class="panel stack" style="margin-top:1.25rem">
  <strong>حضور امروز</strong>
  <?= staff_hours_render_day_presence($todayRows, ['is_today' => true]) ?>
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
        <h3 class="appt-day-title"><?= e((string) $day['label']) ?></h3>
        <?= staff_hours_render_day_presence($day['items'] ?? []) ?>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>
<?php
render_secretary_page('ساعت کاری', ob_get_clean());
