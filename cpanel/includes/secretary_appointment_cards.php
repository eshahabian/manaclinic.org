<?php
declare(strict_types=1);

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
      <div class="panel stack">
        <div class="row-between">
          <div>
            <strong><?= e($a['patient_name']) ?> → <?= e($a['doctor_name']) ?></strong>
            <div class="muted" style="font-size:.85rem;margin-top:.35rem"><?= e(format_fa_datetime($a['starts_at'])) ?></div>
            <?= staff_sign_html(['name' => $a['actor_name'] ?? '', 'username' => $a['actor_username'] ?? '']) ?>
          </div>
          <div class="appt-status">
            <span class="badge"><?= e(appointment_status_label($a['status'])) ?></span>
            <?php if ($a['amount'] !== null): ?>
              <div class="appt-pay-status muted"><?= e(payment_status_label((string) $a['pay_status'])) ?></div>
            <?php endif; ?>
          </div>
        </div>
        <div style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center">
          <?= staff_receipt_view_html($a['payment_id'] ?? null, $a['receipt_path'] ?? null, true) ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
