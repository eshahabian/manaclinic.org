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
        $canConfirmPay = function_exists('appointment_staff_can_mark_paid')
            ? appointment_staff_can_mark_paid($a)
            : appointment_payment_can_upload_receipt($a);
        $awaitingApproval = appointment_awaiting_secretary_approval($a);
        $openHold = function_exists('appointment_is_open_hold') && appointment_is_open_hold($a);
      ?>
      <div class="panel appt-card">
        <div class="appt-card-top">
          <div>
            <strong><?= e($a['patient_name']) ?> → <?= e($a['doctor_name']) ?></strong>
            <div class="muted" style="font-size:.85rem;margin-top:.35rem;display:flex;flex-wrap:wrap;gap:.4rem;align-items:center">
              <?= e(format_fa_datetime($a['starts_at'])) ?>
              <?= function_exists('appointment_session_mode_badge_html') ? appointment_session_mode_badge_html((string) ($a['session_mode'] ?? 'IN_PERSON')) : '' ?>
              <?= function_exists('appointment_room_badge_html') ? appointment_room_badge_html($a) : '' ?>
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
            <?php if (function_exists('appointment_patient_phase_html')): ?>
              <?= appointment_patient_phase_html($a) ?>
            <?php endif; ?>
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
        <?= function_exists('appointment_time_edit_form_html') ? appointment_time_edit_form_html($a, $deskNext) : '' ?>
        <?php
          $needsRoom = in_array((string) ($a['status'] ?? ''), ['CONFIRMED', 'COMPLETED'], true)
              && function_exists('appointment_is_online_mode')
              && !appointment_is_online_mode($a)
              && (int) ($a['room_no'] ?? 0) < 1;
        ?>
        <?php if ($needsRoom): ?>
          <form method="post" action="<?= e(url('/secretary/appointments')) ?>" class="sec-room-form" style="display:flex;flex-wrap:wrap;gap:.4rem;align-items:end;margin:0 0 .6rem">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="set_room">
            <input type="hidden" name="appointment_id" value="<?= e((string) ($a['id'] ?? '')) ?>">
            <input type="hidden" name="next" value="<?= e($deskNext) ?>">
            <div style="margin:0;min-width:12rem;flex:1">
              <span class="label" style="font-size:.75rem">اتاق این نوبت هنوز مشخص نیست</span>
              <?= function_exists('secretary_room_picker_markup') ? secretary_room_picker_markup() : '' ?>
            </div>
            <button type="submit" class="btn btn-primary btn-sm">ثبت اتاق</button>
          </form>
        <?php endif; ?>
        <div class="appt-card-actions">
          <?php if ($awaitingApproval): ?>
            <?= appointment_approval_actions_html($a, $deskNext) ?>
          <?php elseif ($openHold && function_exists('appointment_hold_reject_form_html')): ?>
            <?= appointment_hold_reject_form_html($a, $deskNext) ?>
          <?php endif; ?>
          <?= staff_receipt_view_html($a['payment_id'] ?? null, $a['receipt_path'] ?? null, true, $deskNext) ?>
          <?php if ($canConfirmPay && function_exists('appointment_staff_mark_paid_form_html')): ?>
            <?= appointment_staff_mark_paid_form_html($a, $deskNext) ?>
          <?php endif; ?>
          <?= secretary_patient_cancel_form((string) ($a['id'] ?? ''), (string) ($a['status'] ?? ''), $deskNext) ?>
          <?= admin_appointment_delete_form((string) ($a['id'] ?? ''), $deskNext) ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
<?php
if (function_exists('secretary_room_picker_script')) {
    echo secretary_room_picker_script();
}
?>
