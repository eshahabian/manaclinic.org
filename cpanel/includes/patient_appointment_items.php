<?php
declare(strict_types=1);

/** @var array $appointmentList */
/** @var string $appointmentItemMode */

$appointmentList = $appointmentList ?? [];
$appointmentItemMode = $appointmentItemMode ?? 'simple';
$appointmentOnlinePayEnabled = $appointmentOnlinePayEnabled ?? false;
if (!function_exists('appointment_session_mode_badge_html') && is_file(__DIR__ . '/appointment_session.php')) {
    require_once __DIR__ . '/appointment_session.php';
}
if (!function_exists('appointment_pay_status_display') && is_file(__DIR__ . '/appointment_payment.php')) {
    require_once __DIR__ . '/appointment_payment.php';
}
$callRequestUrl = function_exists('url') ? url('/appointment-call-request') : '/appointment-call-request';
?>
<?php if (!$appointmentList): ?>
  <p class="muted binder-empty">در این بازه نوبت رزروشده‌ای ندارید.</p>
<?php else: ?>
  <div class="stack">
    <?php foreach ($appointmentList as $a): ?>
      <?php
        $modeBadge = function_exists('appointment_session_mode_badge_html')
            ? appointment_session_mode_badge_html((string) ($a['session_mode'] ?? 'IN_PERSON'))
            : '';
        $noteUrl = url('/dashboard/session-note?appointment=' . rawurlencode((string) ($a['id'] ?? '')));
        $showMana = function_exists('appointment_mana_call_should_show') && appointment_mana_call_should_show($a);
        $manaActive = $showMana && function_exists('appointment_mana_call_is_active') && appointment_mana_call_is_active($a);
        $appId = (string) ($a['id'] ?? '');
      ?>
      <?php if ($appointmentItemMode === 'manage'): ?>
        <?php
          $canPayOnline = $appointmentOnlinePayEnabled
              && ($a['status'] ?? '') === 'PENDING_PAYMENT'
              && ($a['pay_status'] ?? '') === 'PENDING';
          $canUploadReceipt = function_exists('appointment_payment_can_upload_receipt')
              && appointment_payment_can_upload_receipt($a);
          $awaitingReview = function_exists('appointment_payment_awaiting_receipt_review')
              && appointment_payment_awaiting_receipt_review($a);
          $canCancel = function_exists('patient_can_cancel_appointment') && patient_can_cancel_appointment((string) $a['status']);
          $refundHint = ($a['status'] ?? '') === 'CONFIRMED' && ($a['pay_status'] ?? '') === 'PAID' && function_exists('appointment_refund_hint')
              ? appointment_refund_hint((string) $a['starts_at'])
              : '';
          $payLabel = function_exists('appointment_pay_status_display')
              ? appointment_pay_status_display($a)
              : payment_status_label((string) ($a['pay_status'] ?? ''));
        ?>
        <div class="patient-appt-row patient-appt-row--manage">
          <div class="patient-appt-row-main">
            <div class="patient-appt-row-info">
              <strong><?= e((string) $a['doctor_name']) ?></strong>
              <?php if (!empty($a['specialty'])): ?>
                <div class="muted" style="font-size:.85rem"><?= e((string) $a['specialty']) ?></div>
              <?php endif; ?>
              <div style="margin-top:.45rem;font-size:.9rem;display:flex;flex-wrap:wrap;gap:.4rem;align-items:center">
                <?= e(format_workshop_datetime_fa((string) $a['starts_at'])) ?>
                <?= $modeBadge ?>
              </div>
            </div>
            <div class="patient-appt-row-meta">
              <span class="badge"><?= e(appointment_status_label((string) $a['status'])) ?></span>
              <?php if (!empty($a['amount'])): ?>
                <div class="muted" style="margin-top:.45rem">
                  <?= e(format_price((int) $a['amount'])) ?> — <?= e($payLabel) ?>
                  <?= !empty($a['ref_id']) ? ' (پیگیری: ' . e((string) $a['ref_id']) . ')' : '' ?>
                </div>
              <?php endif; ?>
            </div>
          </div>
          <div class="patient-appt-row-footer">
            <?php if ($showMana): ?>
              <p class="muted patient-appt-mana-hint">تماس مانا از ۱۵ دقیقه قبل از شروع جلسه فعال می‌شود.</p>
            <?php elseif ($canUploadReceipt): ?>
              <p class="muted patient-appt-mana-hint">فیش را اینجا آپلود کنید یا برای منشی بفرستید؛ بعد از تأیید منشی نوبت ثبت می‌شود.</p>
            <?php endif; ?>
            <div class="patient-appt-row-btns">
              <?php if ($showMana): ?>
                <button
                  type="button"
                  class="btn btn-primary btn-sm mana-call-request-btn"
                  data-id="<?= e($appId) ?>"
                  <?= $manaActive ? '' : ' disabled' ?>
                  title="<?= $manaActive ? 'درخواست تماس برای درمانگر' : 'از ۱۵ دقیقه قبل از شروع جلسه فعال می‌شود' ?>"
                >تماس مانا</button>
              <?php endif; ?>
              <a class="btn btn-outline btn-sm" href="<?= e($noteUrl) ?>">یادداشت جلسه</a>
              <?php if ($canUploadReceipt): ?>
                <label class="btn btn-primary btn-sm staff-receipt-pick">
                  <?= $awaitingReview ? 'تعویض فیش' : 'آپلود فیش پرداخت' ?>
                  <input class="receipt-file-input" type="file" accept="image/jpeg,image/png,image/webp,application/pdf" data-id="<?= e($appId) ?>">
                </label>
              <?php endif; ?>
              <?php if ($canPayOnline): ?>
                <button type="button" class="btn btn-outline btn-sm pay-btn" data-id="<?= e($appId) ?>">پرداخت آنلاین</button>
              <?php endif; ?>
              <?php if ($canCancel): ?>
                <button type="button" class="btn btn-outline btn-sm cancel-app-btn" data-id="<?= e($appId) ?>">لغو نوبت</button>
              <?php endif; ?>
            </div>
            <?php if ($awaitingReview): ?>
              <p class="muted" style="font-size:.75rem;margin:.35rem 0 0">فیش شما دریافت شد و در انتظار تأیید منشی است.</p>
            <?php endif; ?>
            <?php if ($refundHint !== ''): ?>
              <p class="muted" style="font-size:.75rem;margin:.35rem 0 0"><?= e($refundHint) ?></p>
            <?php endif; ?>
          </div>
        </div>
      <?php else: ?>
        <div class="patient-appt-row">
          <div>
            <strong><?= e((string) $a['doctor_name']) ?></strong>
            <div class="muted" style="font-size:.85rem;margin-top:.25rem;display:flex;flex-wrap:wrap;gap:.4rem;align-items:center">
              <?= e(format_workshop_datetime_fa((string) $a['starts_at'])) ?>
              <?= $modeBadge ?>
            </div>
            <?php if ($showMana): ?>
              <p class="muted patient-appt-mana-hint" style="margin-top:.55rem">تماس مانا از ۱۵ دقیقه قبل از شروع جلسه فعال می‌شود.</p>
            <?php endif; ?>
            <div class="patient-appt-row-btns" style="margin-top:.45rem">
              <?php if ($showMana): ?>
                <button
                  type="button"
                  class="btn btn-primary btn-sm mana-call-request-btn"
                  data-id="<?= e($appId) ?>"
                  <?= $manaActive ? '' : ' disabled' ?>
                  title="<?= $manaActive ? 'درخواست تماس برای درمانگر' : 'از ۱۵ دقیقه قبل از شروع جلسه فعال می‌شود' ?>"
                >تماس مانا</button>
              <?php endif; ?>
              <a class="btn btn-outline btn-sm" href="<?= e($noteUrl) ?>">یادداشت جلسه</a>
            </div>
          </div>
          <span class="badge"><?= e(appointment_status_label((string) $a['status'])) ?></span>
        </div>
      <?php endif; ?>
    <?php endforeach; ?>
  </div>
  <?php if (empty($GLOBALS['__mana_call_script'])):
    $GLOBALS['__mana_call_script'] = true;
  ?>
  <script>
  (function(){
    if (window.__manaCallBound) return;
    window.__manaCallBound = true;
    var callUrl = <?= json_encode($callRequestUrl, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    document.addEventListener("click", function(ev){
      var btn = ev.target && ev.target.closest ? ev.target.closest(".mana-call-request-btn") : null;
      if (!btn || btn.disabled) return;
      btn.disabled = true;
      var fd = new FormData();
      fd.append("appointmentId", btn.getAttribute("data-id") || "");
      fetch(callUrl, { method: "POST", body: fd, credentials: "same-origin" })
        .then(function(r){ return r.json().then(function(j){ return { ok: r.ok, j: j }; }); })
        .then(function(res){
          alert((res.j && (res.j.message || res.j.error)) || (res.ok ? "ارسال شد." : "خطا"));
          if (!res.ok) btn.disabled = false;
        })
        .catch(function(){
          alert("خطای شبکه");
          btn.disabled = false;
        });
    });
  })();
  </script>
  <?php endif; ?>
<?php endif; ?>
