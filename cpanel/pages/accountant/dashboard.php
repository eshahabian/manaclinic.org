<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/accountant.php';
require_once __DIR__ . '/../../includes/petty_cash.php';

require_login(['ACCOUNTANT']);
$range = accountant_selected_range();
$sessions = accountant_session_totals($pdo, $range['start'], $range['end']);
$byDoctor = accountant_session_by_doctor($pdo, $range['start'], $range['end']);
$workshops = accountant_workshop_totals($pdo, $range['start'], $range['end']);
$payouts = accountant_payout_totals($pdo, $range['start'], $range['end']);
ensure_petty_cash_schema($pdo);
$cash = petty_cash_month_report($pdo, $range['start'], $range['end']);

ob_start();
?>
<h1>خلاصه مالی</h1>
<p class="muted">پرداخت جلسه‌ها، سهم کلینیک، کارگاه‌ها، تسویه درمانگرها و تنخواه <?= e($range['label']) ?>.</p>
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
    <div class="muted">تسویه درمانگرها</div>
    <strong style="font-size:1.25rem;line-height:1.6"><?= e(format_price($payouts['total'])) ?></strong>
    <div class="muted"><?= e(to_fa_digits((string) $payouts['count'])) ?> تسویه</div>
  </div>
  <div class="panel" style="margin:0">
    <div class="muted">ورودی تنخواه</div>
    <strong style="font-size:1.25rem;line-height:1.6"><?= e(format_price((int) $cash['in'])) ?></strong>
  </div>
  <div class="panel" style="margin:0">
    <div class="muted">هزینه تنخواه</div>
    <strong style="font-size:1.25rem;line-height:1.6"><?= e(format_price((int) $cash['out'])) ?></strong>
    <div class="muted">مانده <?= e(format_price((int) $cash['in'] - (int) $cash['out'])) ?></div>
  </div>
</div>
<?php if ($sessions['unknown'] > 0): ?>
  <p class="muted" style="margin:.75rem 0 0">سهم کلینیک برای <?= e(to_fa_digits((string) $sessions['unknown'])) ?> پرداخت این ماه هنوز مشخص نیست، چون معرف مراجع ثبت نشده است.</p>
<?php endif; ?>

<div class="panel" style="margin-top:1rem;overflow:auto">
  <h2 style="margin:0 0 .75rem;font-size:1rem">جلسات به تفکیک درمانگر</h2>
  <?php if (!$byDoctor): ?>
    <p class="muted" style="margin:0">در این ماه پرداخت جلسه‌ای ثبت نشده است.</p>
  <?php else: ?>
    <table class="table">
      <thead><tr><th>درمانگر</th><th>تعداد</th><th>جمع</th><th>سهم کلینیک</th></tr></thead>
      <tbody>
        <?php foreach ($byDoctor as $row): ?>
          <tr>
            <td><?= e((string) $row['doctor_name']) ?></td>
            <td><?= e(to_fa_digits((string) (int) $row['c'])) ?></td>
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
