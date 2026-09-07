<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/secretary_panel.php';

$user = require_login(['SECRETARY']);
ensure_secretary_day_reports($pdo);
try {
    require_once __DIR__ . '/../../includes/staff_hours_ui.php';
} catch (Throwable $e) {
    error_log('staff-hours secretary ui: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    render_secretary_page('ساعت کاری', '<h1>ساعت کاری من</h1><p class="muted">بارگذاری ساعت کاری الان ممکن نیست.</p>');
    exit;
}
try {
    $block = staff_hours_block_for_user($pdo, $user);
} catch (Throwable $ignored) {
    $block = staff_hours_build_block($pdo, is_array($user) ? $user : null, [
        'kind' => 'secretary',
        'key' => 'self',
        'tab_id' => 'self',
        'label' => staff_actor_label(is_array($user) ? $user : null),
        'with_reports' => true,
    ]);
}
if (!is_array($block)) {
    $block = [];
}
$shift = is_array($block['open'] ?? null) ? $block['open'] : null;
$todayDate = date('Y-m-d');
try {
    $todayRows = staff_hours_day_rows($block, $todayDate, $todayDate);
} catch (Throwable $ignored) {
    $todayRows = [];
}

$report = staff_get_day_report($pdo, (string) ($user['id'] ?? ''), $todayDate);
$draft = staff_today_action_draft($pdo, (string) ($user['id'] ?? ''));
$reportBody = is_array($report) ? (string) ($report['body'] ?? '') : '';
$pageScripts = function_exists('staff_hours_scripts') ? staff_hours_scripts() : '';

ob_start();
?>
<h1>ساعت کاری من</h1>
<p class="muted">ساعت عادی منشی از ۹ صبح تا ۸ شب است؛ قبل از ۹ و بعد از ۸ اضافه‌کار حساب می‌شود. برای هر روز ساعت ورود، خروج و جمع حضور دیده می‌شود؛ جزئیات هر ورود پشت «بیشتر» است. با زدن «کل شهریور» یا «کل مهر» جمع کل همان ماه را می‌بینید. اگر ۱۰ دقیقه فعال نباشید خارج می‌شوید.</p>

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
  <?php
    try {
        echo staff_hours_render_day_presence($todayRows, ['is_today' => true]);
    } catch (Throwable $ignored) {
        echo '<p class="muted">حضور امروز الان در دسترس نیست.</p>';
    }
  ?>
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

<h2 class="binder-sub" style="margin-top:1.5rem">سابقه ماهانه</h2>
<p class="muted" style="margin:.2rem 0 .85rem">ماه را انتخاب کنید تا جمع کل همان ماه دیده شود؛ تب روزها فقط جزئیات یک روز است.</p>
<?php
try {
    echo staff_hours_render_calendar($block, ['today' => $todayDate]);
} catch (Throwable $ignored) {
    echo '<p class="muted">نمایش سابقه ممکن نشد.</p>';
}
?>
<?php
render_secretary_page('ساعت کاری', ob_get_clean());
