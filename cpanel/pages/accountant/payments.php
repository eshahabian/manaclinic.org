<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/accountant.php';
require_once __DIR__ . '/../../includes/user_referral.php';

require_login(['ACCOUNTANT']);
$range = accountant_selected_range();
$sessions = accountant_session_rows($pdo, $range['start'], $range['end']);
$workshops = accountant_workshop_rows($pdo, $range['start'], $range['end']);

if (($_GET['export'] ?? '') === 'sessions') {
    $csv = [['درمانگر', 'مراجع', 'زمان جلسه', 'مبلغ', 'سهم کلینیک', 'سهم درمانگر', 'معرف']];
    foreach ($sessions as $row) {
        $amount = (int) ($row['amount'] ?? 0);
        $clinic = $row['clinic_share_amount'];
        $clinicText = $clinic === null || $clinic === '' ? '' : (string) (int) $clinic;
        $therapistText = $clinicText === '' ? '' : (string) ($amount - (int) $clinic);
        $csv[] = [
            (string) $row['doctor_name'],
            (string) $row['patient_name'],
            (string) $row['starts_at'],
            (string) $amount,
            $clinicText,
            $therapistText,
            user_referral_label((string) ($row['referral_source'] ?? '')),
        ];
    }
    accountant_send_csv($csv, 'sessions-' . $range['start'] . '.csv');
}
if (($_GET['export'] ?? '') === 'workshops') {
    $csv = [['کارگاه', 'مراجع', 'زمان ثبت', 'مبلغ']];
    foreach ($workshops as $row) {
        $csv[] = [
            (string) $row['title'],
            (string) $row['patient_name'],
            (string) $row['created_at'],
            (string) (int) $row['amount'],
        ];
    }
    accountant_send_csv($csv, 'workshops-' . $range['start'] . '.csv');
}

$query = 'jy=' . (int) $range['year'] . '&jm=' . (int) $range['month'];
ob_start();
?>
<h1>پرداخت‌ها</h1>
<p class="muted">پرداخت جلسه‌ها و کارگاه‌های <?= e($range['label']) ?>. پرونده بالینی اینجا نیست.</p>
<?= accountant_month_form('/accountant/payments', $range) ?>

<div class="panel" style="margin-top:1rem;overflow:auto">
  <h2 style="margin:0 0 .75rem;font-size:1rem">جلسات</h2>
  <?php if (!$sessions): ?>
    <p class="muted" style="margin:0">پرداخت جلسه‌ای در این ماه نیست.</p>
  <?php else: ?>
    <p style="margin:0 0 .75rem"><a class="btn btn-outline btn-sm" href="<?= e(url('/accountant/payments?' . $query . '&export=sessions')) ?>">خروجی جلسات</a></p>
    <table class="table">
      <thead><tr><th>درمانگر</th><th>مراجع</th><th>زمان جلسه</th><th>مبلغ</th><th>سهم کلینیک</th><th>معرف</th></tr></thead>
      <tbody>
        <?php foreach ($sessions as $row): ?>
          <?php
            $amount = (int) ($row['amount'] ?? 0);
            $clinic = $row['clinic_share_amount'];
            $clinicLabel = ($clinic === null || $clinic === '') ? '—' : format_price((int) $clinic);
            $source = user_referral_label((string) ($row['referral_source'] ?? ''));
          ?>
          <tr>
            <td><?= e((string) $row['doctor_name']) ?></td>
            <td><?= e((string) $row['patient_name']) ?></td>
            <td><?= e(format_fa_datetime((string) $row['starts_at'])) ?></td>
            <td><?= e(format_price($amount)) ?></td>
            <td><?= e($clinicLabel) ?></td>
            <td><?= e($source !== '' ? $source : '—') ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<div class="panel" style="margin-top:1rem;overflow:auto">
  <h2 style="margin:0 0 .75rem;font-size:1rem">کارگاه‌ها</h2>
  <?php if (!$workshops): ?>
    <p class="muted" style="margin:0">پرداخت کارگاهی در این ماه نیست.</p>
  <?php else: ?>
    <p style="margin:0 0 .75rem"><a class="btn btn-outline btn-sm" href="<?= e(url('/accountant/payments?' . $query . '&export=workshops')) ?>">خروجی کارگاه‌ها</a></p>
    <table class="table">
      <thead><tr><th>کارگاه</th><th>مراجع</th><th>زمان ثبت</th><th>مبلغ</th></tr></thead>
      <tbody>
        <?php foreach ($workshops as $row): ?>
          <tr>
            <td><?= e((string) $row['title']) ?></td>
            <td><?= e((string) $row['patient_name']) ?></td>
            <td><?= e(format_fa_datetime((string) $row['created_at'])) ?></td>
            <td><?= e(format_price((int) $row['amount'])) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>
<?php
render_accountant_page('پرداخت‌ها', ob_get_clean());
