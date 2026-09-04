<?php
declare(strict_types=1);

$user = require_login(['PATIENT']);
require_once __DIR__ . '/../../includes/patient_panel.php';
require_once __DIR__ . '/../../includes/booking_terms.php';
require_once __DIR__ . '/../../includes/appointment_cancel.php';

$stmt = $pdo->prepare("
  SELECT a.*, u.name AS doctor_name, dp.specialty, p.amount, p.status AS pay_status, p.ref_id
  FROM appointments a
  JOIN doctor_profiles dp ON dp.id = a.doctor_id
  JOIN users u ON u.id = dp.user_id
  LEFT JOIN payments p ON p.appointment_id = a.id
  WHERE a.patient_id = ?
  ORDER BY a.starts_at DESC
");
$stmt->execute([$user['id']]);
$appointments = $stmt->fetchAll();
$monthPack = group_appointments_by_jalali_month($appointments);
$monthGroups = $monthPack['months'];
$defaultMonthId = $monthPack['default_id'];
$booked = isset($_GET['booked']);
$payUrl = url('/dashboard/pay');
$cancelUrl = url('/cancel-appointment');
$flashSuccess = flash_get();
$hasPendingPay = false;
foreach ($appointments as $a) {
    if ($a['status'] === 'PENDING_PAYMENT' && ($a['pay_status'] ?? '') === 'PENDING') {
        $hasPendingPay = true;
        break;
    }
}

ob_start();
?>
<div class="stack">
  <h1>نوبت‌های من</h1>
  <div class="panel row-between" style="font-size:.9rem">
    <div>
      <strong>رزرو نوبت جدید</strong>
      <div class="muted" style="font-size:.85rem;margin-top:.25rem">انتخاب درمانگر و زمان جلسه</div>
    </div>
    <a class="btn btn-primary btn-sm" href="<?= e(url('/doctors')) ?>">رزرو نوبت جدید</a>
  </div>
  <?php if ($booked): ?>
    <div class="panel" style="border-color:var(--success);color:var(--success);font-size:.9rem">
      نوبت با موفقیت ثبت شد. برای پرداخت، شرایط را بپذیرید و روی «پرداخت آنلاین» کلیک کنید.
    </div>
  <?php endif; ?>
  <?php if ($hasPendingPay): ?>
    <?= booking_terms_acceptance_html('terms-accept-pay') ?>
  <?php endif; ?>
  <?php if ($flashSuccess): ?>
    <div class="panel" style="border-color:var(--success);color:var(--success);font-size:.9rem"><?= e($flashSuccess['message']) ?></div>
  <?php endif; ?>
  <p id="pay-error" style="color:var(--danger);font-size:.9rem;display:none"></p>
  <p id="cancel-msg" style="font-size:.9rem;display:none"></p>
  <?php
    $monthBinderNested = false;
    $appointmentItemMode = 'manage';
    $monthBinderAria = 'انتخاب ماه نوبت';
    require __DIR__ . '/../../includes/patient_appointments_month_binder.php';
  ?>
</div>
<?php if ($hasPendingPay): ?>
<?= booking_terms_modal_html('terms-modal') ?>
<?= booking_terms_styles() ?>
<?php endif; ?>
<script src="<?= e(url('/assets/js/binder-tabs.js')) ?>?v=20260904u"></script>
<script>
(function(){
  var payUrl = <?= json_encode($payUrl, JSON_UNESCAPED_UNICODE) ?>;
  var cancelUrl = <?= json_encode($cancelUrl, JSON_UNESCAPED_UNICODE) ?>;
  var errEl = document.getElementById("pay-error");
  var cancelMsgEl = document.getElementById("cancel-msg");
  var termsCb = document.getElementById("terms-accept-pay");
  document.querySelectorAll(".pay-btn").forEach(function(btn){
    btn.onclick = function(){
      errEl.style.display = "none";
      if (termsCb && !termsCb.checked) {
        errEl.textContent = "لطفاً شرایط رزرو و پرداخت را بپذیرید.";
        errEl.style.display = "block";
        return;
      }
      btn.disabled = true;
      var fd = new FormData();
      fd.append("appointmentId", btn.getAttribute("data-id"));
      fd.append("accept_terms", "1");
      fetch(payUrl, { method: "POST", body: fd })
        .then(function(r){ return r.json().then(function(j){ return { ok: r.ok, j: j }; }); })
        .then(function(res){
          btn.disabled = termsCb ? !termsCb.checked : false;
          if (!res.ok) {
            errEl.textContent = res.j.error || "پرداخت ناموفق بود";
            errEl.style.display = "block";
            return;
          }
          if (res.j.paymentUrl) location.href = res.j.paymentUrl;
        })
        .catch(function(){
          btn.disabled = termsCb ? !termsCb.checked : false;
          errEl.textContent = "خطای شبکه";
          errEl.style.display = "block";
        });
    };
  });
  document.querySelectorAll(".cancel-app-btn").forEach(function(btn){
    btn.onclick = function(){
      if (!confirm("نوبت لغو شود؟")) return;
      cancelMsgEl.style.display = "none";
      errEl.style.display = "none";
      btn.disabled = true;
      var fd = new FormData();
      fd.append("appointmentId", btn.getAttribute("data-id"));
      fetch(cancelUrl, { method: "POST", body: fd })
        .then(function(r){ return r.json().then(function(j){ return { ok: r.ok, j: j }; }); })
        .then(function(res){
          if (!res.ok) {
            btn.disabled = false;
            errEl.textContent = res.j.error || "لغو ناموفق بود";
            errEl.style.display = "block";
            return;
          }
          cancelMsgEl.textContent = res.j.message || "نوبت لغو شد.";
          cancelMsgEl.style.color = "var(--success)";
          cancelMsgEl.style.display = "block";
          setTimeout(function(){ location.reload(); }, 1200);
        })
        .catch(function(){
          btn.disabled = false;
          errEl.textContent = "خطای شبکه";
          errEl.style.display = "block";
        });
    };
  });
})();
</script>
<?php if ($hasPendingPay): ?>
<?= booking_terms_script('terms-accept-pay', '.pay-btn') ?>
<?php endif; ?>
<?php
render_patient_page('نوبت‌های من', ob_get_clean());
