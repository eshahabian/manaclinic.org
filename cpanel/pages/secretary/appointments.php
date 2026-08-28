<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/secretary_panel.php';
require_login(['SECRETARY']);

$rows = $pdo->query("
  SELECT a.*, pu.name AS patient_name, du.name AS doctor_name, p.status AS pay_status, p.amount
  FROM appointments a
  JOIN users pu ON pu.id = a.patient_id
  JOIN doctor_profiles dp ON dp.id = a.doctor_id
  JOIN users du ON du.id = dp.user_id
  LEFT JOIN payments p ON p.appointment_id = a.id
  ORDER BY a.starts_at DESC
  LIMIT 100
")->fetchAll();

ob_start();
?>
<h1>نوبت‌ها</h1>
<div class="stack" style="margin-top:1rem">
<?php foreach ($rows as $a): ?>
  <div class="panel row-between">
    <div>
      <strong><?= e($a['patient_name']) ?> → <?= e($a['doctor_name']) ?></strong>
      <div class="muted" style="font-size:.85rem;margin-top:.35rem"><?= e(format_fa_datetime($a['starts_at'])) ?></div>
    </div>
    <div style="font-size:.85rem">
      <span class="badge"><?= e(appointment_status_label($a['status'])) ?></span>
      <?php if ($a['amount'] !== null): ?>
        <div class="muted" style="margin-top:.35rem"><?= e(payment_status_label((string)$a['pay_status'])) ?></div>
      <?php endif; ?>
    </div>
  </div>
<?php endforeach; ?>
<?php if (!$rows): ?><p class="muted">نوبتی نیست.</p><?php endif; ?>
</div>
<?php
render_secretary_page('نوبت‌ها', ob_get_clean());
