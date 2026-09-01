<?php
declare(strict_types=1);

$user = require_login(['PATIENT']);
require_once __DIR__ . '/../../includes/patient_panel.php';

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
$booked = isset($_GET['booked']);
$payUrl = url('/dashboard/pay');

ob_start();
?>
<div class="stack">
  <h1>نوبت‌های من</h1>
  <?php if ($booked): ?>
    <div class="panel" style="border-color:var(--success);color:var(--success);font-size:.9rem">
      نوبت با موفقیت ثبت شد. برای پرداخت روی دکمه «پرداخت آنلاین» کلیک کنید.
    </div>
  <?php endif; ?>
  <p id="pay-error" style="color:var(--danger);font-size:.9rem;display:none"></p>
  <?php foreach ($appointments as $a): ?>
    <div class="panel row-between">
      <div>
        <strong><?= e($a['doctor_name']) ?></strong>
        <div class="muted" style="font-size:.85rem"><?= e($a['specialty']) ?></div>
        <div style="margin-top:.5rem;font-size:.9rem"><?= e(format_fa_datetime($a['starts_at'])) ?></div>
      </div>
      <div style="text-align:left;font-size:.85rem">
        <span class="badge"><?= e(appointment_status_label($a['status'])) ?></span>
        <?php if ($a['amount']): ?>
          <div class="muted" style="margin-top:.5rem">
            <?= e(format_price((int)$a['amount'])) ?> — <?= e(payment_status_label((string)$a['pay_status'])) ?>
            <?= $a['ref_id'] ? ' (پیگیری: ' . e((string)$a['ref_id']) . ')' : '' ?>
          </div>
        <?php endif; ?>
        <?php if ($a['status'] === 'PENDING_PAYMENT' && ($a['pay_status'] ?? '') === 'PENDING'): ?>
          <button
            type="button"
            class="btn btn-primary btn-sm pay-btn"
            style="margin-top:.75rem"
            data-id="<?= e($a['id']) ?>"
          >پرداخت آنلاین</button>
        <?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>
  <?php if (!$appointments): ?><p class="muted">نوبتی ثبت نشده است.</p><?php endif; ?>
</div>
<script>
(function(){
  var payUrl = <?= json_encode($payUrl, JSON_UNESCAPED_UNICODE) ?>;
  var errEl = document.getElementById("pay-error");
  document.querySelectorAll(".pay-btn").forEach(function(btn){
    btn.onclick = function(){
      errEl.style.display = "none";
      btn.disabled = true;
      var fd = new FormData();
      fd.append("appointmentId", btn.getAttribute("data-id"));
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
})();
</script>
<?php
render_patient_page('نوبت‌های من', ob_get_clean());
