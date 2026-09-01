<?php
declare(strict_types=1);

$user = require_login(['PATIENT']);
require_once __DIR__ . '/../../includes/patient_panel.php';
require_once __DIR__ . '/../../includes/workshops.php';

ensure_workshop_schema($pdo);
ensure_wallet($pdo, $user['id']);

$sections = [
    'in-person' => 'دوره‌های حضوری',
    'online' => 'دوره‌های آنلاین',
    'offline' => 'دوره‌های آفلاین',
];
$active = (string) ($_GET['type'] ?? 'in-person');
if (!isset($sections[$active])) {
    $active = 'in-person';
}
$dbType = workshop_type_from_tab($active);

$tabCounts = $pdo->query("
  SELECT w.type, COUNT(*) AS cnt
  FROM workshops w
  WHERE w.is_published = 1 AND w.status = 'PUBLISHED' AND w.starts_at > NOW()
  GROUP BY w.type
")->fetchAll(PDO::FETCH_KEY_PAIR);
$countForTab = static function (string $tab): int {
    global $tabCounts;
    $type = workshop_type_from_tab($tab);
    return (int) ($tabCounts[$type] ?? 0);
};

$available = $pdo->prepare("
  SELECT w.*, u.name AS doctor_name
  FROM workshops w
  JOIN doctor_profiles dp ON dp.id = w.doctor_id
  JOIN users u ON u.id = dp.user_id
  WHERE w.type = ? AND w.is_published = 1 AND w.status = 'PUBLISHED' AND w.starts_at > NOW()
  ORDER BY w.starts_at ASC
");
$available->execute([$dbType]);
$availableWorkshops = $available->fetchAll();

$mine = $pdo->prepare("
  SELECT e.*, w.title, w.starts_at, w.ends_at, w.type, w.meeting_url, w.content_url, w.location,
         wp.amount, wp.wallet_amount, wp.status AS pay_status, u.name AS doctor_name
  FROM workshop_enrollments e
  JOIN workshops w ON w.id = e.workshop_id
  JOIN doctor_profiles dp ON dp.id = w.doctor_id
  JOIN users u ON u.id = dp.user_id
  LEFT JOIN workshop_payments wp ON wp.enrollment_id = e.id
  WHERE e.patient_id = ? AND w.type = ?
  ORDER BY w.starts_at DESC
");
$mine->execute([$user['id'], $dbType]);
$myEnrollments = $mine->fetchAll();

$wallet = ensure_wallet($pdo, $user['id']);
$enrollUrl = url('/enroll-workshop');
$payUrl = url('/pay-workshop');
$cancelUrl = url('/cancel-enrollment');

ob_start();
?>
<div class="stack">
  <h1>دوره‌های من</h1>
  <p class="muted">کارگاه‌های حضوری، آنلاین و آفلاین — ثبت‌نام و پرداخت از اینجا.</p>

  <nav class="course-tabs" aria-label="دسته‌بندی دوره‌ها">
    <?php foreach ($sections as $key => $label): ?>
      <a href="<?= e(url('/dashboard/courses?type=' . $key)) ?>" class="course-tab<?= $active === $key ? ' active' : '' ?>">
        <?= e($label) ?><?php $n = $countForTab($key); if ($n > 0): ?> <span class="course-tab-count"><?= $n ?></span><?php endif; ?>
      </a>
    <?php endforeach; ?>
  </nav>

  <section class="panel stack">
    <h2 style="margin:0;font-size:1.1rem">کارگاه‌های قابل ثبت‌نام</h2>
    <?php foreach ($availableWorkshops as $w): ?>
      <?php
        $enrolled = false;
        foreach ($myEnrollments as $me) {
            if ($me['workshop_id'] === $w['id'] && in_array($me['status'], ['PENDING_PAYMENT', 'CONFIRMED', 'COMPLETED'], true)) {
                $enrolled = true;
                break;
            }
        }
      ?>
      <div class="row-between" style="border:1px solid var(--line);border-radius:.75rem;padding:.85rem">
        <div>
          <strong><?= e($w['title']) ?></strong>
          <div class="muted" style="font-size:.85rem;margin-top:.25rem"><?= e($w['doctor_name']) ?></div>
          <div style="font-size:.85rem;margin-top:.35rem"><?= e(format_fa_datetime($w['starts_at'])) ?> — <?= e(format_fa_datetime($w['ends_at'])) ?></div>
          <div class="muted" style="font-size:.85rem;margin-top:.25rem"><?= e(format_price((int)$w['price'])) ?></div>
          <?php if ($w['items_to_bring']): ?>
            <div style="font-size:.8rem;margin-top:.35rem"><strong>همراه داشته باشید:</strong> <?= e($w['items_to_bring']) ?></div>
          <?php endif; ?>
          <?php if ($w['description']): ?>
            <div class="muted" style="font-size:.8rem;margin-top:.25rem"><?= e($w['description']) ?></div>
          <?php endif; ?>
        </div>
        <div>
          <?php if (!$enrolled): ?>
            <button type="button" class="btn btn-primary btn-sm enroll-btn" data-id="<?= e($w['id']) ?>">ثبت‌نام</button>
          <?php else: ?>
            <span class="badge">ثبت‌نام شده</span>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
    <?php if (!$availableWorkshops): ?>
      <p class="muted">کارگاه فعالی برای ثبت‌نام در این دسته نیست.</p>
      <ul class="muted" style="font-size:.85rem;margin:.5rem 0 0;padding-right:1.1rem">
        <li>کارگاه <strong>حضوری</strong> را در تب «دوره‌های حضوری»، <strong>آنلاین</strong> و <strong>آفلاین</strong> را در تب مربوطه ببینید.</li>
        <li>پزشک باید کارگاه را <strong>منتشر</strong> کرده باشد و زمان شروع هنوز نگذشته باشد.</li>
      </ul>
    <?php endif; ?>
  </section>

  <section class="panel stack">
    <h2 style="margin:0;font-size:1.1rem">ثبت‌نام‌های من</h2>
    <p id="course-msg" style="font-size:.9rem;display:none"></p>
    <?php foreach ($myEnrollments as $e): ?>
      <div class="row-between" style="border:1px solid var(--line);border-radius:.75rem;padding:.85rem;align-items:flex-start">
        <div>
          <strong><?= e($e['title']) ?></strong>
          <div class="muted" style="font-size:.85rem;margin-top:.25rem"><?= e($e['doctor_name']) ?></div>
          <div style="font-size:.85rem;margin-top:.35rem"><?= e(format_fa_datetime($e['starts_at'])) ?></div>
          <span class="badge" style="margin-top:.5rem;display:inline-block"><?= e(enrollment_status_label($e['status'])) ?></span>
          <?php if ($e['amount']): ?>
            <div class="muted" style="font-size:.85rem;margin-top:.35rem"><?= e(format_price((int)$e['amount'])) ?></div>
          <?php endif; ?>
          <?php if ($e['status'] === 'CONFIRMED'): ?>
            <?php if ($e['type'] === 'ONLINE' && $e['meeting_url']): ?>
              <div style="font-size:.85rem;margin-top:.35rem"><a href="<?= e($e['meeting_url']) ?>" target="_blank" rel="noopener">ورود به جلسه آنلاین</a></div>
            <?php elseif ($e['type'] === 'OFFLINE' && $e['content_url']): ?>
              <div style="font-size:.85rem;margin-top:.35rem"><a href="<?= e($e['content_url']) ?>" target="_blank" rel="noopener">دریافت محتوا</a></div>
            <?php elseif ($e['type'] === 'IN_PERSON' && $e['location']): ?>
              <div style="font-size:.85rem;margin-top:.35rem">محل: <?= e($e['location']) ?></div>
            <?php endif; ?>
          <?php endif; ?>
        </div>
        <div style="text-align:left">
          <?php if ($e['status'] === 'PENDING_PAYMENT'): ?>
            <label style="display:flex;gap:.35rem;align-items:center;font-size:.8rem;margin-bottom:.5rem">
              <input type="checkbox" class="use-wallet" data-id="<?= e($e['id']) ?>" <?= (int)$wallet['balance'] > 0 ? '' : 'disabled' ?>>
              استفاده از کیف پول (<?= e(format_price((int)$wallet['balance'])) ?>)
            </label>
            <button type="button" class="btn btn-primary btn-sm pay-btn" data-id="<?= e($e['id']) ?>">پرداخت آنلاین</button>
          <?php endif; ?>
          <?php if (in_array($e['status'], ['PENDING_PAYMENT', 'CONFIRMED'], true)): ?>
            <button type="button" class="btn btn-outline btn-sm cancel-btn" data-id="<?= e($e['id']) ?>" style="margin-top:.5rem">لغو ثبت‌نام</button>
            <?php if ($e['status'] === 'CONFIRMED' && !workshop_refund_allowed($e['starts_at'])): ?>
              <p class="muted" style="font-size:.75rem;margin-top:.35rem;max-width:12rem">کمتر از ۲۴ ساعت مانده — بازگشت وجه نیست.</p>
            <?php endif; ?>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
    <?php if (!$myEnrollments): ?>
      <p class="muted">هنوز در کارگاهی ثبت‌نام نکرده‌اید.</p>
    <?php endif; ?>
  </section>
</div>
<style>
  .course-tabs { display: flex; flex-wrap: wrap; gap: .5rem; }
  .course-tab { padding: .55rem .9rem; border-radius: .65rem; border: 1px solid var(--line); color: var(--muted); font-size: .9rem; background: #fff; }
  .course-tab:hover { border-color: var(--primary); color: var(--primary); }
  .course-tab.active { background: var(--primary); border-color: var(--primary); color: #fff; }
  .course-tab-count { font-size: .75rem; opacity: .9; }
  .course-tab.active .course-tab-count { opacity: 1; }
  #course-msg.ok { color: var(--success); }
  #course-msg.err { color: var(--danger); }
</style>
<script>
(function(){
  var enrollUrl = <?= json_encode($enrollUrl, JSON_UNESCAPED_UNICODE) ?>;
  var payUrl = <?= json_encode($payUrl, JSON_UNESCAPED_UNICODE) ?>;
  var cancelUrl = <?= json_encode($cancelUrl, JSON_UNESCAPED_UNICODE) ?>;
  var msgEl = document.getElementById("course-msg");
  function showMsg(text, ok){ msgEl.textContent=text; msgEl.className=ok?"ok":"err"; msgEl.style.display="block"; }

  document.querySelectorAll(".enroll-btn").forEach(function(btn){
    btn.onclick=function(){
      var fd=new FormData(); fd.append("workshopId", btn.getAttribute("data-id"));
      fetch(enrollUrl,{method:"POST",body:fd}).then(function(r){return r.json().then(function(j){return{ok:r.ok,j:j};});})
        .then(function(res){ if(!res.ok){showMsg(res.j.error||"خطا",false);return;} location.reload(); })
        .catch(function(){showMsg("خطای شبکه",false);});
    };
  });
  document.querySelectorAll(".pay-btn").forEach(function(btn){
    btn.onclick=function(){
      var id=btn.getAttribute("data-id");
      var useWallet=document.querySelector('.use-wallet[data-id="'+id+'"]');
      var fd=new FormData(); fd.append("enrollmentId", id);
      if(useWallet && useWallet.checked) fd.append("use_wallet","1");
      fetch(payUrl,{method:"POST",body:fd}).then(function(r){return r.json().then(function(j){return{ok:r.ok,j:j};});})
        .then(function(res){
          if(!res.ok){showMsg(res.j.error||"پرداخت ناموفق",false);return;}
          if(res.j.paymentUrl){location.href=res.j.paymentUrl;return;}
          location.reload();
        }).catch(function(){showMsg("خطای شبکه",false);});
    };
  });
  document.querySelectorAll(".cancel-btn").forEach(function(btn){
    btn.onclick=function(){
      if(!confirm("ثبت‌نام لغو شود؟")) return;
      var fd=new FormData(); fd.append("enrollmentId", btn.getAttribute("data-id"));
      fetch(cancelUrl,{method:"POST",body:fd}).then(function(r){return r.json().then(function(j){return{ok:r.ok,j:j};});})
        .then(function(res){ if(!res.ok){showMsg(res.j.error||"خطا",false);return;} location.reload(); })
        .catch(function(){showMsg("خطای شبکه",false);});
    };
  });
})();
</script>
<?php
render_patient_page('دوره‌های من', ob_get_clean());
