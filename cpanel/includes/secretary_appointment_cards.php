<?php
declare(strict_types=1);
require_once __DIR__ . '/user_cleanup.php';
require_once __DIR__ . '/appointment_cancel.php';
require_once __DIR__ . '/appointment_payment.php';
if (!function_exists('appointment_session_mode_badge_html') && is_file(__DIR__ . '/appointment_session.php')) {
    require_once __DIR__ . '/appointment_session.php';
}

/** @var array $appointmentList */
/** @var string $appointmentEmpty */

$appointmentList = $appointmentList ?? [];
$appointmentEmpty = $appointmentEmpty ?? 'نوبتی نیست.';
$deskNext = $appointmentsDeskNext ?? '/secretary/appointments';
?>
<?php if (!$appointmentList): ?>
  <p class="muted binder-empty"><?= e($appointmentEmpty) ?></p>
<?php else: ?>
  <div class="stack">
    <?php foreach ($appointmentList as $a): ?>
      <?php
        $canConfirmPay = appointment_payment_can_upload_receipt($a);
        $hasReceipt = trim((string) ($a['receipt_path'] ?? '')) !== '';
      ?>
      <div class="panel appt-card">
        <div class="appt-card-top">
          <div>
            <strong><?= e($a['patient_name']) ?> → <?= e($a['doctor_name']) ?></strong>
            <div class="muted" style="font-size:.85rem;margin-top:.35rem;display:flex;flex-wrap:wrap;gap:.4rem;align-items:center">
              <?= e(format_fa_datetime($a['starts_at'])) ?>
              <?= function_exists('appointment_session_mode_badge_html') ? appointment_session_mode_badge_html((string) ($a['session_mode'] ?? 'IN_PERSON')) : '' ?>
            </div>
            <?= appointment_booked_by_html($a) ?>
          </div>
          <div class="appt-card-meta">
            <span class="badge"><?= e(appointment_row_status_label($a)) ?></span>
            <?php if ($a['amount'] !== null): ?>
              <div class="appt-pay-status muted"><?= e(appointment_pay_status_display($a)) ?></div>
            <?php endif; ?>
          </div>
        </div>
        <?= appointment_notes_html($a) ?>
        <form class="appt-mode-form" method="post" action="<?= e(url('/secretary/appointments')) ?>" style="display:flex;flex-wrap:wrap;gap:.4rem;align-items:center">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="set_session_mode">
          <input type="hidden" name="appointment_id" value="<?= e((string) ($a['id'] ?? '')) ?>">
          <input type="hidden" name="next" value="<?= e($deskNext) ?>">
          <label class="muted" style="font-size:.8rem" for="mode-<?= e((string) ($a['id'] ?? '')) ?>">تغییر نوع جلسه</label>
          <select class="input" style="width:auto;min-width:7rem;padding:.35rem .55rem;font-size:.85rem" name="session_mode" id="mode-<?= e((string) ($a['id'] ?? '')) ?>">
            <option value="IN_PERSON"<?= appointment_normalize_session_mode((string) ($a['session_mode'] ?? '')) === 'IN_PERSON' ? ' selected' : '' ?>>حضوری</option>
            <option value="ONLINE"<?= appointment_normalize_session_mode((string) ($a['session_mode'] ?? '')) === 'ONLINE' ? ' selected' : '' ?>>آنلاین</option>
          </select>
          <button type="submit" class="btn btn-outline btn-sm">ذخیره</button>
        </form>
        <div class="appt-card-actions">
          <?= staff_receipt_view_html($a['payment_id'] ?? null, $a['receipt_path'] ?? null, true, $deskNext) ?>
          <?php if ($canConfirmPay): ?>
            <form method="post" action="<?= e(url('/secretary/appointments')) ?>" enctype="multipart/form-data" class="appt-confirm-pay-form" style="display:flex;flex-wrap:wrap;gap:.4rem;align-items:center;margin:0">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="confirm_payment">
              <input type="hidden" name="appointment_id" value="<?= e((string) ($a['id'] ?? '')) ?>">
              <input type="hidden" name="next" value="<?= e($deskNext) ?>">
              <label class="btn btn-outline btn-sm staff-receipt-pick" title="اختیاری — اگر فیش روی موبایل آمده، خالی بگذارید">
                فیش (اختیاری)
                <input type="file" name="receipt" accept="image/jpeg,image/png,image/webp,application/pdf">
              </label>
              <button type="submit" class="btn btn-primary btn-sm" onclick="return confirm('پرداخت تأیید و نوبت ثبت شود؟');">
                <?= $hasReceipt ? 'تأیید فیش و ثبت نوبت' : 'تأیید پرداخت و ثبت نوبت' ?>
              </button>
            </form>
            <?php if (!$hasReceipt): ?>
              <p class="muted" style="font-size:.75rem;margin:0;flex-basis:100%">اگر فیش مستقیم به موبایل منشی ارسال شده، بدون آپلود هم می‌توانید تأیید کنید.</p>
            <?php endif; ?>
          <?php endif; ?>
          <?= secretary_patient_cancel_form((string) ($a['id'] ?? ''), (string) ($a['status'] ?? ''), $deskNext) ?>
          <?= admin_appointment_delete_form((string) ($a['id'] ?? ''), $deskNext) ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
