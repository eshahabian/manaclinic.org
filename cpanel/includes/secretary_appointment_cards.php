<?php
declare(strict_types=1);
require_once __DIR__ . '/user_cleanup.php';

/** @var array $appointmentList */
/** @var string $appointmentEmpty */

$appointmentList = $appointmentList ?? [];
$appointmentEmpty = $appointmentEmpty ?? 'نوبتی نیست.';
?>
<?php if (!$appointmentList): ?>
  <p class="muted binder-empty"><?= e($appointmentEmpty) ?></p>
<?php else: ?>
  <div class="stack">
    <?php foreach ($appointmentList as $a): ?>
      <div class="panel appt-card">
        <div class="appt-card-top">
          <div>
            <strong><?= e($a['patient_name']) ?> → <?= e($a['doctor_name']) ?></strong>
            <div class="muted" style="font-size:.85rem;margin-top:.35rem"><?= e(format_fa_datetime($a['starts_at'])) ?></div>
            <?= staff_sign_html(['name' => $a['actor_name'] ?? '', 'username' => $a['actor_username'] ?? '']) ?>
          </div>
          <div class="appt-card-meta">
            <span class="badge"><?= e(appointment_status_label($a['status'])) ?></span>
            <?php if ($a['amount'] !== null): ?>
              <div class="appt-pay-status muted"><?= e(payment_status_label((string) $a['pay_status'])) ?></div>
            <?php endif; ?>
          </div>
        </div>
        <div class="appt-card-actions">
          <?= staff_receipt_view_html($a['payment_id'] ?? null, $a['receipt_path'] ?? null, true) ?>
          <?= admin_appointment_delete_form((string) ($a['id'] ?? ''), '/secretary/appointments') ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
