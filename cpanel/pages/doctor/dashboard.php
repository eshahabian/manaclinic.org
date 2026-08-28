<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/doctor_panel.php';
$ctx = require_doctor_profile($pdo);

$stmt = $pdo->prepare("
  SELECT a.*, u.name AS patient_name
  FROM appointments a
  JOIN users u ON u.id = a.patient_id
  WHERE a.doctor_id=? AND a.status IN ('CONFIRMED','PENDING_PAYMENT') AND a.starts_at >= NOW()
  ORDER BY a.starts_at ASC LIMIT 5
");
$stmt->execute([$ctx['profile']['id']]);
$rows = $stmt->fetchAll();

ob_start();
?>
<h1>سلام دکتر <?= e($ctx['user']['name']) ?></h1>
<p class="muted">نوبت‌ها و برنامه کاری خود را مدیریت کنید. پرونده بیماران فقط برای شماست.</p>
<p style="margin-top:1rem"><a class="btn btn-primary" href="<?= e(url('/doctor/patients')) ?>">پرونده بیماران</a></p>
<div class="panel stack" style="margin-top:1.5rem">
  <h2 style="margin:0;font-size:1.1rem">نوبت‌های پیش‌رو</h2>
  <?php foreach ($rows as $a): ?>
    <div class="row-between" style="border:1px solid var(--line);border-radius:.75rem;padding:.75rem">
      <div>
        <strong><?= e($a['patient_name']) ?></strong>
        <div class="muted" style="font-size:.85rem"><?= e(format_fa_datetime($a['starts_at'])) ?></div>
      </div>
      <div style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap">
        <a class="btn btn-outline btn-sm" href="<?= e(url('/doctor/patients/' . $a['patient_id'])) ?>">پرونده</a>
        <span class="badge"><?= e(appointment_status_label($a['status'])) ?></span>
      </div>
    </div>
  <?php endforeach; ?>
  <?php if (!$rows): ?><p class="muted">نوبت پیش‌رویی نیست.</p><?php endif; ?>
</div>
<?php
render_doctor_page('پنل دکتر', ob_get_clean());
