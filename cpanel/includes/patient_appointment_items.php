<?php
declare(strict_types=1);

/** @var array $appointmentList */
/** @var string $appointmentItemMode */

$appointmentList = $appointmentList ?? [];
$appointmentItemMode = $appointmentItemMode ?? 'simple';
?>
<?php if (!$appointmentList): ?>
  <p class="muted binder-empty">در این ماه نوبت رزروشده‌ای ندارید.</p>
<?php else: ?>
  <div class="stack">
    <?php foreach ($appointmentList as $a): ?>
      <?php if ($appointmentItemMode === 'manage'): ?>
        <div class="patient-appt-row patient-appt-row--manage">
          <div>
            <strong><?= e((string) $a['doctor_name']) ?></strong>
            <?php if (!empty($a['specialty'])): ?>
              <div class="muted" style="font-size:.85rem"><?= e((string) $a['specialty']) ?></div>
            <?php endif; ?>
            <div style="margin-top:.45rem;font-size:.9rem"><?= e(format_workshop_datetime_fa((string) $a['starts_at'])) ?></div>
          </div>
          <div class="patient-appt-row-actions">
            <span class="badge"><?= e(appointment_status_label((string) $a['status'])) ?></span>
            <?php if (!empty($a['amount'])): ?>
              <div class="muted" style="margin-top:.45rem">
                <?= e(format_price((int) $a['amount'])) ?> — <?= e(payment_status_label((string) ($a['pay_status'] ?? ''))) ?>
                <?= !empty($a['ref_id']) ? ' (پیگیری: ' . e((string) $a['ref_id']) . ')' : '' ?>
              </div>
            <?php endif; ?>
            <?php if (($a['status'] ?? '') === 'PENDING_PAYMENT' && ($a['pay_status'] ?? '') === 'PENDING'): ?>
              <button
                type="button"
                class="btn btn-primary btn-sm pay-btn"
                style="margin-top:.75rem"
                data-id="<?= e((string) $a['id']) ?>"
                disabled
              >پرداخت آنلاین</button>
            <?php endif; ?>
            <?php if (function_exists('patient_can_cancel_appointment') && patient_can_cancel_appointment((string) $a['status'])): ?>
              <button
                type="button"
                class="btn btn-outline btn-sm cancel-app-btn"
                style="margin-top:.5rem"
                data-id="<?= e((string) $a['id']) ?>"
              >لغو نوبت</button>
              <?php if (($a['status'] ?? '') === 'CONFIRMED' && ($a['pay_status'] ?? '') === 'PAID' && function_exists('appointment_refund_hint')): ?>
                <p class="muted" style="font-size:.75rem;margin-top:.35rem;max-width:14rem"><?= e(appointment_refund_hint((string) $a['starts_at'])) ?></p>
              <?php endif; ?>
            <?php endif; ?>
          </div>
        </div>
      <?php else: ?>
        <div class="patient-appt-row">
          <div>
            <strong><?= e((string) $a['doctor_name']) ?></strong>
            <div class="muted" style="font-size:.85rem;margin-top:.25rem"><?= e(format_workshop_datetime_fa((string) $a['starts_at'])) ?></div>
          </div>
          <span class="badge"><?= e(appointment_status_label((string) $a['status'])) ?></span>
        </div>
      <?php endif; ?>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
