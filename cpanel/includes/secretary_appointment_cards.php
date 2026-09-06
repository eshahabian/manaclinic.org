<?php
declare(strict_types=1);
require_once __DIR__ . '/user_cleanup.php';
require_once __DIR__ . '/appointment_cancel.php';

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
            <span class="badge"><?= e(appointment_row_status_label($a)) ?></span>
            <?php if ($a['amount'] !== null): ?>
              <div class="appt-pay-status muted"><?= e(payment_status_label((string) $a['pay_status'])) ?></div>
            <?php endif; ?>
          </div>
        </div>
        <?= appointment_notes_html($a) ?>
        <div class="appt-card-actions">
          <?= staff_receipt_view_html($a['payment_id'] ?? null, $a['receipt_path'] ?? null, true) ?>
          <?= secretary_patient_cancel_form((string) ($a['id'] ?? ''), (string) ($a['status'] ?? ''), $appointmentsDeskNext ?? '/secretary/appointments') ?>
          <?= admin_appointment_delete_form((string) ($a['id'] ?? ''), $appointmentsDeskNext ?? '/secretary/appointments') ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
