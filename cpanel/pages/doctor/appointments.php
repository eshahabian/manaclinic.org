<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/doctor_panel.php';
$ctx = require_doctor_profile($pdo);
$stmt = $pdo->prepare("
  SELECT a.*, u.name AS patient_name, u.phone, u.email,
         p.id AS payment_id, p.amount, p.status AS pay_status, p.receipt_path,
         cu.name AS actor_name, cu.username AS actor_username
  FROM appointments a
  JOIN users u ON u.id=a.patient_id
  LEFT JOIN payments p ON p.appointment_id=a.id
  LEFT JOIN users cu ON cu.id = a.created_by_user_id
  WHERE a.doctor_id=?
  ORDER BY a.starts_at DESC
");
$stmt->execute([$ctx['profile']['id']]);
$rows = $stmt->fetchAll();
ob_start();
?>
<h1>نوبت‌های بیماران</h1>
<div class="stack" style="margin-top:1rem">
<?php foreach ($rows as $a): ?>
  <div class="panel stack">
    <div class="row-between">
      <div>
        <strong><?= e($a['patient_name']) ?></strong>
        <div class="muted" style="font-size:.85rem"><?= e((string)($a['phone'] ?: $a['email'])) ?></div>
        <div style="margin-top:.35rem;font-size:.9rem"><?= e(format_fa_datetime($a['starts_at'])) ?></div>
        <?= staff_sign_html(['name' => $a['actor_name'] ?? '', 'username' => $a['actor_username'] ?? '']) ?>
      </div>
      <div style="font-size:.85rem">
        <span class="badge"><?= e(appointment_status_label($a['status'])) ?></span>
        <?php if ($a['amount']): ?>
          <div class="muted" style="margin-top:.35rem"><?= e(format_price((int)$a['amount'])) ?> — <?= e(payment_status_label((string)$a['pay_status'])) ?></div>
        <?php endif; ?>
      </div>
    </div>
    <div style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center">
      <?= staff_receipt_view_html($a['payment_id'] ?? null, $a['receipt_path'] ?? null, false) ?>
      <a class="btn btn-outline btn-sm" href="<?= e(url('/doctor/patients/' . $a['patient_id'])) ?>">پرونده بیمار</a>
      <?php if ($a['status'] !== 'CANCELLED'): ?>
        <form method="post" action="<?= e(url('/doctor/appointments')) ?>">
          <input type="hidden" name="id" value="<?= e($a['id']) ?>">
          <input type="hidden" name="status" value="CANCELLED">
          <button class="btn btn-danger btn-sm" type="submit">لغو</button>
        </form>
      <?php endif; ?>
      <?php if ($a['status'] === 'CONFIRMED'): ?>
        <form method="post" action="<?= e(url('/doctor/appointments')) ?>">
          <input type="hidden" name="id" value="<?= e($a['id']) ?>">
          <input type="hidden" name="status" value="COMPLETED">
          <button class="btn btn-outline btn-sm" type="submit">انجام شد</button>
        </form>
      <?php endif; ?>
    </div>
  </div>
<?php endforeach; ?>
<?php if (!$rows): ?><p class="muted">نوبتی نیست.</p><?php endif; ?>
</div>
<?php
render_doctor_page('نوبت‌ها', ob_get_clean());
