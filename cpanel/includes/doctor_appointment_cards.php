<?php
declare(strict_types=1);

require_once __DIR__ . '/user_cleanup.php';
require_once __DIR__ . '/appointment_cancel.php';

/** @var array $appointmentList */
/** @var string $appointmentEmpty */

$appointmentList = $appointmentList ?? [];
$appointmentEmpty = $appointmentEmpty ?? 'نوبتی نیست.';

if (!$appointmentList) {
    echo '<p class="muted binder-empty">' . e($appointmentEmpty) . '</p>';
    return;
}
?>
<div class="stack">
  <?php foreach ($appointmentList as $a): ?>
    <?php $time = jalali_day_parts((string) ($a['starts_at'] ?? '')); ?>
    <div class="panel appt-card">
      <div class="appt-card-top">
        <div>
          <strong><?= e((string) ($a['patient_name'] ?? '')) ?></strong>
          <div class="muted" style="font-size:.85rem"><?= e((string) (($a['phone'] ?? '') ?: ($a['email'] ?? ''))) ?></div>
          <div style="margin-top:.35rem;font-size:.9rem">
            <?= e(format_fa_datetime((string) ($a['starts_at'] ?? ''))) ?>
            <?php if (!empty($time['time_fa'])): ?>
              · ساعت <?= e((string) $time['time_fa']) ?>
            <?php endif; ?>
          </div>
          <?= staff_sign_html(['name' => $a['actor_name'] ?? '', 'username' => $a['actor_username'] ?? ''], 'ثبت نوبت') ?>
        </div>
        <div class="appt-card-meta">
          <span class="badge"><?= e(appointment_row_status_label($a)) ?></span>
          <?php if (!empty($a['amount'])): ?>
            <div class="muted"><?= e(format_price((int) $a['amount'])) ?> — <?= e(payment_status_label((string) ($a['pay_status'] ?? ''))) ?></div>
          <?php endif; ?>
        </div>
      </div>
      <?= appointment_notes_html($a) ?>
      <div class="appt-card-actions">
        <?= staff_receipt_view_html($a['payment_id'] ?? null, $a['receipt_path'] ?? null, false) ?>
        <?php if (!empty($a['patient_id'])): ?>
          <a class="btn btn-outline btn-sm" href="<?= e(url('/doctor/patients/' . $a['patient_id'])) ?>">پرونده مراجعه‌کننده</a>
        <?php endif; ?>
        <?= appointment_cancel_form((string) ($a['id'] ?? ''), (string) ($a['status'] ?? ''), '/doctor/appointments', '/doctor/appointments') ?>
        <?= admin_appointment_delete_form((string) ($a['id'] ?? ''), '/doctor/appointments') ?>
        <?php if (($a['status'] ?? '') === 'CONFIRMED'): ?>
          <form method="post" action="<?= e(url('/doctor/appointments')) ?>">
            <input type="hidden" name="id" value="<?= e((string) $a['id']) ?>">
            <input type="hidden" name="status" value="COMPLETED">
            <input type="hidden" name="next" value="/doctor/appointments">
            <button class="btn btn-outline btn-sm" type="submit">انجام شد</button>
          </form>
        <?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>
</div>
