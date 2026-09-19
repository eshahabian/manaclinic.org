<?php
declare(strict_types=1);

$user = require_login(['PATIENT']);
require_once __DIR__ . '/../../includes/patient_panel.php';
require_once __DIR__ . '/../../includes/appointment_cancel.php';
require_once __DIR__ . '/../../includes/appointment_session.php';

if (function_exists('appointment_restore_auto_cancelled_unpaid')) {
    appointment_restore_auto_cancelled_unpaid($pdo);
}

$stmt = $pdo->prepare("
  SELECT a.*, u.name AS doctor_name, dp.specialty, p.id AS payment_id, p.amount, p.status AS pay_status, p.ref_id, p.receipt_path
  FROM appointments a
  JOIN doctor_profiles dp ON dp.id = a.doctor_id
  JOIN users u ON u.id = dp.user_id
  LEFT JOIN payments p ON p.appointment_id = a.id
  WHERE a.patient_id = ?
  ORDER BY a.starts_at DESC
");
$stmt->execute([$user['id']]);
$appointments = $stmt->fetchAll();
$booked = isset($_GET['booked']);
$payUrl = url('/dashboard/pay');
$receiptUrl = url('/dashboard/upload-receipt');
$cancelUrl = url('/cancel-appointment');
$onlinePayEnabled = online_payment_enabled($config ?? []);
$flashSuccess = flash_get();

ob_start();
?>
<div class="stack">
  <h1>نوبت‌های من</h1>
  <div class="panel row-between" style="font-size:.9rem">
    <div>
      <strong>رزرو نوبت جدید</strong>
      <div class="muted" style="font-size:.85rem;margin-top:.25rem">برای انتخاب درمانگر و زمان جلسه به صفحهٔ متخصصان بروید.</div>
    </div>
    <a class="btn btn-primary btn-sm" href="<?= e(url('/doctors')) ?>">رزرو نوبت جدید</a>
  </div>
  <?php if ($booked): ?>
    <div class="panel" style="border-color:var(--success);color:var(--success);font-size:.9rem">
      <?php if ($onlinePayEnabled): ?>
        نوبت با موفقیت ثبت شد. برای تکمیل، روی «پرداخت آنلاین» کلیک کنید.
      <?php else: ?>
        نوبت با موفقیت ثبت شد. فیش پرداخت را آپلود کنید یا برای منشی بفرستید تا پس از تأیید، نوبت ثبت شود.
      <?php endif; ?>
    </div>
  <?php endif; ?>
  <?php if ($flashSuccess): ?>
    <div class="panel" style="border-color:var(--success);color:var(--success);font-size:.9rem"><?= e($flashSuccess['message']) ?></div>
  <?php endif; ?>
  <p id="pay-error" style="color:var(--danger);font-size:.9rem;display:none"></p>
  <p id="cancel-msg" style="font-size:.9rem;display:none"></p>
  <?php
    $appointmentList = $appointments;
    $appointmentItemMode = 'manage';
    $appointmentOnlinePayEnabled = $onlinePayEnabled;
    require __DIR__ . '/../../includes/patient_appointment_items.php';
  ?>
</div>
<script>
(function(){
  var payUrl = <?= json_encode($payUrl, JSON_UNESCAPED_UNICODE) ?>;
  var receiptUrl = <?= json_encode($receiptUrl, JSON_UNESCAPED_UNICODE) ?>;
  var cancelUrl = <?= json_encode($cancelUrl, JSON_UNESCAPED_UNICODE) ?>;
  var errEl = document.getElementById("pay-error");
  var cancelMsgEl = document.getElementById("cancel-msg");
  document.querySelectorAll(".pay-btn").forEach(function(btn){
    btn.disabled = false;
    btn.onclick = function(){
      errEl.style.display = "none";
      btn.disabled = true;
      var fd = new FormData();
      fd.append("appointmentId", btn.getAttribute("data-id"));
      fd.append("accept_terms", "1");
      fetch(payUrl, { method: "POST", body: fd })
        .then(function(r){ return r.json().then(function(j){ return { ok: r.ok, j: j }; }); })
        .then(function(res){
          btn.disabled = false;
          if (!res.ok) {
            errEl.textContent = res.j.error || "پرداخت ناموفق بود";
            errEl.style.display = "block";
            return;
          }
          if (res.j.paymentUrl) location.href = res.j.paymentUrl;
        })
        .catch(function(){
          btn.disabled = false;
          errEl.textContent = "خطای شبکه";
          errEl.style.display = "block";
        });
    };
  });
  document.querySelectorAll(".receipt-file-input").forEach(function(inp){
    inp.addEventListener("change", function(){
      if (!inp.files || !inp.files.length) return;
      errEl.style.display = "none";
      var id = inp.getAttribute("data-id") || "";
      var label = inp.closest("label");
      if (label) label.classList.add("is-busy");
      var fd = new FormData();
      fd.append("appointmentId", id);
      fd.append("receipt", inp.files[0]);
      fetch(receiptUrl, { method: "POST", body: fd, credentials: "same-origin" })
        .then(function(r){ return r.json().then(function(j){ return { ok: r.ok, j: j }; }); })
        .then(function(res){
          if (!res.ok) {
            errEl.textContent = (res.j && res.j.error) || "آپلود فیش ناموفق بود";
            errEl.style.display = "block";
            inp.value = "";
            if (label) label.classList.remove("is-busy");
            return;
          }
          cancelMsgEl.textContent = (res.j && res.j.message) || "فیش ارسال شد.";
          cancelMsgEl.style.color = "var(--success)";
          cancelMsgEl.style.display = "block";
          setTimeout(function(){ location.reload(); }, 900);
        })
        .catch(function(){
          errEl.textContent = "خطای شبکه";
          errEl.style.display = "block";
          inp.value = "";
          if (label) label.classList.remove("is-busy");
        });
    });
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
<?php
render_patient_page('نوبت‌های من', ob_get_clean());
