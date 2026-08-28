<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/secretary_panel.php';
$user = require_login(['SECRETARY']);

$upcoming = $pdo->query("
  SELECT a.*, pu.name AS patient_name, du.name AS doctor_name
  FROM appointments a
  JOIN users pu ON pu.id = a.patient_id
  JOIN doctor_profiles dp ON dp.id = a.doctor_id
  JOIN users du ON du.id = dp.user_id
  WHERE a.starts_at >= NOW() AND a.status IN ('CONFIRMED','PENDING_PAYMENT')
  ORDER BY a.starts_at ASC
  LIMIT 8
")->fetchAll();

ob_start();
?>
<h1>سلام <?= e($user['name']) ?></h1>
<p class="muted">از اینجا می‌توانید برای بیماران نوبت ثبت کنید.</p>
<p style="margin-top:1rem"><a class="btn btn-primary" href="<?= e(url('/secretary/book')) ?>">رزرو نوبت برای بیمار</a></p>
<div class="panel stack" style="margin-top:1.5rem">
  <h2 style="margin:0;font-size:1.1rem">نوبت‌های پیش‌رو</h2>
  <?php foreach ($upcoming as $a): ?>
    <div class="row-between" style="border:1px solid var(--line);border-radius:.75rem;padding:.75rem">
      <div>
        <strong><?= e($a['patient_name']) ?></strong>
        <div class="muted" style="font-size:.85rem">دکتر: <?= e($a['doctor_name']) ?></div>
        <div style="font-size:.85rem;margin-top:.25rem"><?= e(format_fa_datetime($a['starts_at'])) ?></div>
      </div>
      <span class="badge"><?= e(appointment_status_label($a['status'])) ?></span>
    </div>
  <?php endforeach; ?>
  <?php if (!$upcoming): ?><p class="muted">نوبت پیش‌رویی نیست.</p><?php endif; ?>
</div>
<?php
render_secretary_page('پنل منشی', ob_get_clean());
