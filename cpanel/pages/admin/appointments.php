<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/admin_panel.php';
require_once __DIR__ . '/../../includes/user_cleanup.php';
require_login(['ADMIN']);
$rows = $pdo->query("
  SELECT a.id, a.starts_at, a.status,
         pu.name AS patient_name, du.name AS doctor_name,
         p.id AS payment_id, p.amount, p.status AS pay_status, p.ref_id, p.receipt_path
  FROM appointments a
  JOIN users pu ON pu.id=a.patient_id
  JOIN doctor_profiles dp ON dp.id=a.doctor_id
  JOIN users du ON du.id=dp.user_id
  LEFT JOIN payments p ON p.appointment_id=a.id
  ORDER BY a.starts_at DESC
")->fetchAll();
ob_start();
?>
<h1>نوبت‌ها و پرداخت‌ها</h1>
<p class="muted" style="margin-top:.35rem;font-size:.9rem">همه نوبت‌های کلینیک. حذف فقط برای ادمین است.</p>
<?php if ($rows): ?>
  <div class="appt-toolbar">
    <form method="post" action="<?= e(url('/admin/appointments')) ?>" onsubmit="return confirm('همه نوبت‌ها برای همیشه حذف شوند؟');">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="delete_all">
      <button type="submit" class="btn btn-outline btn-sm" style="color:var(--danger)">حذف همه نوبت‌ها</button>
    </form>
  </div>
<?php endif; ?>
<div class="stack" style="margin-top:1rem">
<?php foreach ($rows as $a): ?>
  <div class="panel appt-card">
    <div class="appt-card-top">
      <div>
        <strong><?= e((string) $a['patient_name']) ?> → <?= e((string) $a['doctor_name']) ?></strong>
        <div class="muted" style="font-size:.85rem;margin-top:.35rem"><?= e(format_fa_datetime((string) $a['starts_at'])) ?></div>
      </div>
      <div class="appt-card-meta">
        <span class="badge"><?= e(appointment_status_label((string) $a['status'])) ?></span>
        <?php if ($a['amount'] !== null && $a['amount'] !== ''): ?>
          <div class="muted">
            <?= e(format_price((int) $a['amount'])) ?> — <?= e(payment_status_label((string) $a['pay_status'])) ?>
            <?= !empty($a['ref_id']) ? ' / ' . e((string) $a['ref_id']) : '' ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
    <div class="appt-card-actions">
      <?= staff_receipt_view_html($a['payment_id'] ?? null, $a['receipt_path'] ?? null, false) ?>
      <?= admin_appointment_delete_form((string) $a['id'], '/admin/appointments') ?>
    </div>
  </div>
<?php endforeach; ?>
<?php if (!$rows): ?><p class="muted">نوبتی نیست.</p><?php endif; ?>
</div>
<?php
render_admin_page('نوبت‌ها', ob_get_clean());
