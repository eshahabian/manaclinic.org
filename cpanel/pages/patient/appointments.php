<?php
declare(strict_types=1);

$user = require_login(['PATIENT']);
require_once __DIR__ . '/../../includes/patient_panel.php';

$stmt = $pdo->prepare("
  SELECT a.*, u.name AS doctor_name, dp.specialty, p.amount, p.status AS pay_status, p.ref_id
  FROM appointments a
  JOIN doctor_profiles dp ON dp.id = a.doctor_id
  JOIN users u ON u.id = dp.user_id
  LEFT JOIN payments p ON p.appointment_id = a.id
  WHERE a.patient_id = ?
  ORDER BY a.starts_at DESC
");
$stmt->execute([$user['id']]);
$appointments = $stmt->fetchAll();

ob_start();
?>
<div class="stack">
  <h1>نوبت‌های من</h1>
  <?php foreach ($appointments as $a): ?>
    <div class="panel row-between">
      <div>
        <strong><?= e($a['doctor_name']) ?></strong>
        <div class="muted" style="font-size:.85rem"><?= e($a['specialty']) ?></div>
        <div style="margin-top:.5rem;font-size:.9rem"><?= e(format_fa_datetime($a['starts_at'])) ?></div>
      </div>
      <div style="text-align:left;font-size:.85rem">
        <span class="badge"><?= e(appointment_status_label($a['status'])) ?></span>
        <?php if ($a['amount']): ?>
          <div class="muted" style="margin-top:.5rem">
            <?= e(format_price((int)$a['amount'])) ?> — <?= e(payment_status_label((string)$a['pay_status'])) ?>
            <?= $a['ref_id'] ? ' (پیگیری: ' . e((string)$a['ref_id']) . ')' : '' ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>
  <?php if (!$appointments): ?><p class="muted">نوبتی ثبت نشده است.</p><?php endif; ?>
</div>
<?php
render_patient_page('نوبت‌های من', ob_get_clean());
