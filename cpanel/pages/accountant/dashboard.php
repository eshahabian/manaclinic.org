<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/accountant.php';
require_once __DIR__ . '/../../includes/petty_cash.php';

require_login(['ACCOUNTANT']);
$range = accountant_selected_range();
$today = date('Y-m-d');
$sessions = accountant_session_totals($pdo, $range['start'], $range['end']);
$byDoctor = accountant_session_by_doctor($pdo, $range['start'], $range['end']);
$workshops = accountant_workshop_totals($pdo, $range['start'], $range['end']);
$outside = accountant_outside_totals($pdo, $range['start'], $range['end']);
ensure_petty_cash_schema($pdo);
$cash = petty_cash_month_report($pdo, $range['start'], $range['end']);
$todayIn = accountant_received_on($pdo, $today, $today);
$todayOut = accountant_spent_on($pdo, $today, $today);
$receivables = accountant_receivables($pdo);
$monthIn = $sessions['total'] + $workshops['total'] + $outside['in'];
$monthOut = (int) $cash['out'] + $outside['out'];
$monthNet = $monthIn - $sessions['therapist'] - $monthOut;
$trend = [];
$trendMax = 1;
foreach (accountant_recent_months(6) as $month) {
    $pointSessions = accountant_session_totals($pdo, $month['start'], $month['end']);
    $pointWorkshops = accountant_workshop_totals($pdo, $month['start'], $month['end']);
    $pointOutside = accountant_outside_totals($pdo, $month['start'], $month['end']);
    $pointCash = petty_cash_month_report($pdo, $month['start'], $month['end']);
    $income = $pointSessions['total'] + $pointWorkshops['total'] + $pointOutside['in'];
    $expense = (int) $pointCash['out'] + $pointOutside['out'] + $pointSessions['therapist'];
    $trend[] = ['label' => $month['label'], 'income' => $income, 'expense' => $expense];
    $trendMax = max($trendMax, $income, $expense);
}

ob_start();
?>
<h1>خلاصه مالی</h1>
<p class="muted">عددها از نوبت، کارگاه، تنخواه و پرداخت‌های خارج از روال خوانده می‌شوند. پرونده و یادداشت جلسه اینجا نیست.</p>

<div class="grid-2" style="margin-top:1rem">
  <div class="panel" style="margin:0">
    <div class="muted">درآمد امروز</div>
    <strong style="font-size:1.35rem;line-height:1.6"><?= e(format_price($todayIn)) ?></strong>
    <div class="muted">جلسه، کارگاه و دریافت خارج از روال</div>
  </div>
  <div class="panel" style="margin:0">
    <div class="muted">هزینه‌های امروز</div>
    <strong style="font-size:1.35rem;line-height:1.6"><?= e(format_price($todayOut)) ?></strong>
    <div class="muted">تنخواه و پرداخت خارج از روال</div>
  </div>
  <div class="panel" style="margin:0">
    <div class="muted">مطالبات کلینیک</div>
    <strong style="font-size:1.35rem;line-height:1.6"><?= e(format_price($receivables)) ?></strong>
    <div class="muted">جلسه‌ها و کارگاه‌هایی که هنوز پرداخت نشده‌اند</div>
  </div>
  <div class="panel" style="margin:0">
    <div class="muted">سود خالص <?= e($range['label']) ?></div>
    <strong style="font-size:1.35rem;line-height:1.6"><?= e(format_price($monthNet)) ?></strong>
    <div class="muted">دریافتی منهای سهم درمانگر و هزینه‌ها</div>
  </div>
</div>

<div class="panel" style="margin-top:1rem">
  <h2 style="margin:0 0 .75rem;font-size:1rem">روند درآمد و هزینه · ۶ ماه اخیر</h2>
  <div style="display:flex;align-items:flex-end;gap:.6rem;height:9.5rem">
    <?php foreach ($trend as $point): ?>
      <?php
        $inHeight = (int) round(($point['income'] / $trendMax) * 100);
        $outHeight = (int) round(($point['expense'] / $trendMax) * 100);
      ?>
      <div style="flex:1;min-width:0;display:flex;flex-direction:column;align-items:center;height:100%">
        <div style="flex:1;width:100%;display:flex;align-items:flex-end;justify-content:center;gap:3px">
          <div title="درآمد" style="width:42%;height:<?= max(2, $inHeight) ?>%;background:#1f6b45;border-radius:6px 6px 0 0"></div>
          <div title="هزینه و سهم درمانگر" style="width:42%;height:<?= max(2, $outHeight) ?>%;background:#c45c4a;border-radius:6px 6px 0 0"></div>
        </div>
        <div class="muted" style="margin-top:.35rem;font-size:.75rem;text-align:center;line-height:1.4"><?= e($point['label']) ?></div>
      </div>
    <?php endforeach; ?>
  </div>
  <p class="muted" style="margin:.75rem 0 0">ستون سبز درآمد است. ستون قرمز هزینه تنخواه، پرداخت خارج از روال و سهم درمانگر است.</p>
</div>

<?= accountant_month_form('/accountant', $range) ?>

<div class="grid-3" style="margin-top:1rem">
  <div class="panel" style="margin:0">
    <div class="muted">پرداخت جلسه‌ها</div>
    <strong style="font-size:1.25rem;line-height:1.6"><?= e(format_price($sessions['total'])) ?></strong>
    <div class="muted"><?= e(to_fa_digits((string) $sessions['count'])) ?> پرداخت</div>
  </div>
  <div class="panel" style="margin:0">
    <div class="muted">سهم کلینیک</div>
    <strong style="font-size:1.25rem;line-height:1.6"><?= e(format_price($sessions['clinic'])) ?></strong>
    <div class="muted">سهم درمانگر <?= e(format_price($sessions['therapist'])) ?></div>
  </div>
  <div class="panel" style="margin:0">
    <div class="muted">پرداخت کارگاه‌ها</div>
    <strong style="font-size:1.25rem;line-height:1.6"><?= e(format_price($workshops['total'])) ?></strong>
    <div class="muted"><?= e(to_fa_digits((string) $workshops['count'])) ?> پرداخت</div>
  </div>
  <div class="panel" style="margin:0">
    <div class="muted">دریافت خارج از روال</div>
    <strong style="font-size:1.25rem;line-height:1.6"><?= e(format_price($outside['in'])) ?></strong>
  </div>
  <div class="panel" style="margin:0">
    <div class="muted">پرداخت خارج از روال</div>
    <strong style="font-size:1.25rem;line-height:1.6"><?= e(format_price($outside['out'])) ?></strong>
  </div>
  <div class="panel" style="margin:0">
    <div class="muted">هزینه تنخواه</div>
    <strong style="font-size:1.25rem;line-height:1.6"><?= e(format_price((int) $cash['out'])) ?></strong>
    <div class="muted">مانده تنخواه <?= e(format_price((int) $cash['in'] - (int) $cash['out'])) ?></div>
  </div>
</div>
<?php if ($sessions['unknown'] > 0): ?>
  <p class="muted" style="margin:.75rem 0 0">سهم کلینیک برای <?= e(to_fa_digits((string) $sessions['unknown'])) ?> پرداخت این ماه هنوز مشخص نیست، چون معرف مراجع ثبت نشده است.</p>
<?php endif; ?>

<div class="panel" style="margin-top:1rem;overflow:auto">
  <h2 style="margin:0 0 .75rem;font-size:1rem">جلسات به تفکیک درمانگر</h2>
  <p class="muted" style="margin:0 0 .75rem">همه درمانگرهای فعال این ماه این‌جا هستند. اگر پرداختی ثبت نشده باشد، جمع صفر می‌ماند و ستون «بدون پرداخت» تعداد همان جلسه‌ها را نشان می‌دهد.</p>
  <?php if (!$byDoctor): ?>
    <p class="muted" style="margin:0">درمانگر فعالی ثبت نشده است.</p>
  <?php else: ?>
    <table class="table">
      <thead><tr><th>درمانگر</th><th>پرداخت‌شده</th><th>بدون پرداخت</th><th>جمع</th><th>سهم کلینیک</th></tr></thead>
      <tbody>
        <?php foreach ($byDoctor as $row): ?>
          <tr>
            <td><?= e((string) $row['doctor_name']) ?></td>
            <td><?= e(to_fa_digits((string) (int) $row['c'])) ?></td>
            <td><?= e(to_fa_digits((string) (int) ($row['unpaid'] ?? 0))) ?></td>
            <td><?= e(format_price((int) $row['total'])) ?></td>
            <td><?= e(format_price((int) $row['clinic'])) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>
<?php
render_accountant_page('خلاصه مالی', ob_get_clean());
