<?php
declare(strict_types=1);

$user = require_login(['PATIENT']);
require_once __DIR__ . '/../../includes/patient_panel.php';

$stmt = $pdo->prepare("
  SELECT a.*, u.name AS doctor_name
  FROM appointments a
  JOIN doctor_profiles dp ON dp.id = a.doctor_id
  JOIN users u ON u.id = dp.user_id
  WHERE a.patient_id = ?
  ORDER BY a.starts_at DESC
  LIMIT 5
");
$stmt->execute([$user['id']]);
$appointments = $stmt->fetchAll();

ob_start();
?>
<div>
  <h1>سلام <?= e($user['name']) ?></h1>
  <p class="muted">نوبت‌ها و وضعیت پرداخت‌های خود را اینجا ببینید.</p>
  <div class="panel" style="margin-top:1.5rem">
    <div class="row-between" style="margin-bottom:1rem">
      <h2 style="margin:0;font-size:1.1rem">آخرین نوبت‌ها</h2>
      <a class="btn btn-primary btn-sm" href="<?= e(url('/doctors')) ?>">رزرو جدید</a>
    </div>
    <div class="stack">
      <?php foreach ($appointments as $a): ?>
        <div class="row-between" style="border:1px solid var(--line);border-radius:.75rem;padding:.75rem">
          <div>
            <strong><?= e($a['doctor_name']) ?></strong>
            <div class="muted" style="font-size:.85rem"><?= e(format_fa_datetime($a['starts_at'])) ?></div>
          </div>
          <span class="badge"><?= e(appointment_status_label($a['status'])) ?></span>
        </div>
      <?php endforeach; ?>
      <?php if (!$appointments): ?><p class="muted">هنوز نوبتی ندارید.</p><?php endif; ?>
    </div>
  </div>
</div>
<?php
render_patient_page('پنل مراجع', ob_get_clean());
