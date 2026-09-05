<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/secretary_panel.php';

$user = require_login(['SECRETARY']);
$id = trim((string) ($_GET['id'] ?? ''));

$stmt = $pdo->prepare("SELECT id, name, username, phone FROM users WHERE id=? AND role='PATIENT' LIMIT 1");
$stmt->execute([$id]);
$patient = $stmt->fetch();
if (!$patient) {
    flash_set('error', 'مراجعه‌کننده یافت نشد.');
    redirect('/secretary/patients');
}

$apps = $pdo->prepare("
  SELECT a.id, a.starts_at, a.ends_at, a.status, a.notes,
         du.name AS doctor_name,
         cu.name AS actor_name, cu.username AS actor_username
  FROM appointments a
  JOIN doctor_profiles dp ON dp.id = a.doctor_id
  JOIN users du ON du.id = dp.user_id
  LEFT JOIN users cu ON cu.id = a.created_by_user_id
  WHERE a.patient_id = ?
  ORDER BY a.starts_at DESC
");
$apps->execute([$id]);
$appointments = $apps->fetchAll();

$phone = trim((string) ($patient['phone'] ?? ''));

ob_start();
?>
<p class="panel-back">
  <a class="btn btn-outline btn-sm" href="<?= e(url('/secretary/patients')) ?>">بازگشت به فهرست</a>
</p>
<h1><?= e((string) $patient['name']) ?></h1>
<div class="panel stack" style="margin-top:1rem;max-width:36rem">
  <div>
    <div class="muted" style="font-size:.8rem">شماره تماس</div>
    <?php if ($phone !== ''): ?>
      <a href="tel:<?= e($phone) ?>" dir="ltr" style="font-size:1.2rem;font-weight:700;color:inherit"><?= e($phone) ?></a>
    <?php else: ?>
      <div>شماره ثبت نشده</div>
    <?php endif; ?>
  </div>
  <div class="muted" style="font-size:.85rem">نام کاربری: <span dir="ltr"><?= e((string) $patient['username']) ?></span></div>
</div>

<h2 style="margin:1.5rem 0 0;font-size:1.1rem">روزهای نوبت</h2>
<div class="stack" style="margin-top:.75rem">
  <?php foreach ($appointments as $a): ?>
    <div class="panel row-between">
      <div>
        <strong><?= e(format_fa_datetime((string) $a['starts_at'])) ?></strong>
        <div class="muted" style="font-size:.85rem;margin-top:.3rem">دکتر: <?= e((string) $a['doctor_name']) ?></div>
        <?php if (!empty($a['actor_name']) || !empty($a['actor_username'])): ?>
          <?= staff_sign_html(['name' => $a['actor_name'] ?? '', 'username' => $a['actor_username'] ?? ''], 'ثبت توسط') ?>
        <?php endif; ?>
      </div>
      <span class="badge"><?= e(appointment_status_label((string) $a['status'])) ?></span>
    </div>
  <?php endforeach; ?>
  <?php if (!$appointments): ?>
    <p class="muted">هنوز نوبتی برای این مراجعه‌کننده ثبت نشده است.</p>
  <?php endif; ?>
</div>
<p style="margin-top:1.25rem">
  <a class="btn btn-primary" href="<?= e(url('/secretary/appointments?tab=new')) ?>">ثبت نوبت جدید</a>
</p>
<?php
render_secretary_page((string) $patient['name'], ob_get_clean());
