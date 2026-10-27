<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/doctor_panel.php';
require_once __DIR__ . '/../../includes/appointment_cancel.php';
require_once __DIR__ . '/../../includes/appointment_session.php';
require_once __DIR__ . '/../../includes/booking_terms.php';

$user = require_login(['DOCTOR']);

if (function_exists('appointment_restore_auto_cancelled_unpaid')) {
    appointment_restore_auto_cancelled_unpaid($pdo);
}
if (function_exists('appointment_expire_unpaid_holds')) {
    appointment_expire_unpaid_holds($pdo);
}
if (function_exists('appointment_ensure_room_schema')) {
    appointment_ensure_room_schema($pdo);
}

$roomSql = function_exists('appointment_room_no_sql') ? appointment_room_no_sql('a') : 'NULL AS room_no';
$stmt = $pdo->prepare("
  SELECT a.*, u.name AS doctor_name, dp.specialty, p.id AS payment_id, p.amount, p.status AS pay_status, p.ref_id, p.receipt_path,
         {$roomSql}
  FROM appointments a
  JOIN doctor_profiles dp ON dp.id = a.doctor_id
  JOIN users u ON u.id = dp.user_id
  LEFT JOIN payments p ON p.appointment_id = a.id
  WHERE a.patient_id = ?
  ORDER BY a.starts_at DESC
");
$stmt->execute([$user['id']]);
$appointments = $stmt->fetchAll();
$fixedAppointments = [];
$requestAppointments = [];
$cancelledAppointments = [];
foreach ($appointments as $row) {
    $status = (string) ($row['status'] ?? '');
    if ($status === 'CANCELLED') {
        $cancelledAppointments[] = $row;
    } elseif (function_exists('appointment_is_panel_fixed') && appointment_is_panel_fixed($row)) {
        $fixedAppointments[] = $row;
    } elseif (in_array($status, ['PENDING_APPROVAL', 'PENDING_PAYMENT'], true)) {
        $requestAppointments[] = $row;
    } else {
        $fixedAppointments[] = $row;
    }
}
$booked = isset($_GET['booked']);
$payUrl = url('/dashboard/pay');
$receiptUrl = url('/dashboard/upload-receipt');
$cancelUrl = url('/cancel-appointment');
$onlinePayEnabled = online_payment_enabled($config ?? []);
$flashSuccess = flash_get();
$appointmentClientNotePath = '/doctor/therapy-note';

ob_start();
?>
<div class="stack">
  <h1>نوبت تراپی من</h1>
  <p class="muted" style="margin-top:-.35rem">نوبت‌هایی که خودتان به‌عنوان مراجع با درمانگر دیگر گرفته‌اید.</p>
  <div class="panel row-between" style="font-size:.9rem">
    <div>
      <strong>رزرو نوبت جدید</strong>
      <div class="muted" style="font-size:.85rem;margin-top:.25rem">از فهرست متخصصان، درمانگر دیگری را انتخاب کنید.</div>
    </div>
    <a class="btn btn-primary btn-sm" href="<?= e(url('/doctors')) ?>">رزرو نوبت تراپی</a>
  </div>
  <?php if ($booked): ?>
    <div class="panel" style="border-color:var(--success);color:var(--success);font-size:.9rem">
      درخواست شما ارسال شد و در حال بررسی است. تا یک ساعت این وقت برای شما می‌ماند. وقتی منشی پرداخت را تأیید کند، نوبت رزرو می‌شود.
    </div>
  <?php endif; ?>
  <?php if ($flashSuccess): ?>
    <div class="panel" style="border-color:var(--success);color:var(--success);font-size:.9rem"><?= e($flashSuccess['message']) ?></div>
  <?php endif; ?>
  <p id="pay-error" style="color:var(--danger);font-size:.9rem;display:none"></p>
  <p id="cancel-msg" style="font-size:.9rem;display:none"></p>
  <div class="binder-tile" data-binder-tabs data-binder-initial="appts" data-binder-tone="appts">
    <div class="binder-tabs" role="tablist" aria-label="دسته‌بندی نوبت‌ها">
      <button type="button" id="appointments-tab" class="binder-tab is-active" role="tab" data-binder-tab="appts" aria-controls="appointments-panel" aria-selected="true">
        نوبت‌ها <span class="binder-tab-count"><?= e(to_fa_digits((string) (count($fixedAppointments) + count($requestAppointments)))) ?></span>
      </button>
      <button type="button" id="cancelled-tab" class="binder-tab" role="tab" data-binder-tab="cancelled" aria-controls="cancelled-panel" aria-selected="false">
        لغوشده‌ها <span class="binder-tab-count"><?= e(to_fa_digits((string) count($cancelledAppointments))) ?></span>
      </button>
    </div>
    <div class="binder-body">
      <section id="appointments-panel" class="binder-panel is-active" data-binder-panel="appts" role="tabpanel" aria-labelledby="appointments-tab">
  <?php if (!$fixedAppointments && !$requestAppointments): ?>
    <p class="muted binder-empty">نوبت تراپی ثبت‌شده‌ای ندارید. برای رزرو به صفحهٔ متخصصان بروید.</p>
  <?php endif; ?>
  <?php if ($requestAppointments): ?>
    <h2 class="binder-sub">درخواست‌های در انتظار منشی</h2>
    <p class="muted" style="margin:-.35rem 0 .75rem;font-size:.85rem">این وقت‌ها هنوز قطعی نیستند. بعد از تأیید پرداخت منشی، در فهرست شما و درمانگر دیده می‌شوند.</p>
    <?php
      $appointmentList = $requestAppointments;
      $appointmentItemMode = 'manage';
      $appointmentOnlinePayEnabled = $onlinePayEnabled;
      require __DIR__ . '/../../includes/patient_appointment_items.php';
    ?>
  <?php endif; ?>
  <?php if ($fixedAppointments): ?>
  <h2 class="binder-sub">نوبت‌های ثبت‌شده</h2>
  <?php
    $appointmentList = $fixedAppointments;
    $appointmentItemMode = 'manage';
    $appointmentOnlinePayEnabled = $onlinePayEnabled;
    require __DIR__ . '/../../includes/patient_appointment_items.php';
  ?>
  <?php endif; ?>
      </section>
      <section id="cancelled-panel" class="binder-panel" data-binder-panel="cancelled" role="tabpanel" aria-labelledby="cancelled-tab" hidden>
        <?php if (!$cancelledAppointments): ?>
          <p class="muted binder-empty">نوبت لغوشده‌ای ندارید.</p>
        <?php else: ?>
          <?php
            $appointmentList = $cancelledAppointments;
            $appointmentItemMode = 'manage';
            $appointmentOnlinePayEnabled = $onlinePayEnabled;
            require __DIR__ . '/../../includes/patient_appointment_items.php';
          ?>
        <?php endif; ?>
      </section>
    </div>
  </div>
</div>
<script src="<?= e(url('/assets/js/binder-tabs.js')) ?>?v=20260904u"></script>
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
render_doctor_page('نوبت تراپی من', ob_get_clean());
