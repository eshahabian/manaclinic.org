<?php
declare(strict_types=1);

$user = require_login(['PATIENT']);
require_once __DIR__ . '/../../includes/patient_panel.php';
require_once __DIR__ . '/../../includes/workshops.php';
require_once __DIR__ . '/../../includes/workshop_media.php';

ensure_workshop_schema($pdo);
ensure_workshop_media_schema($pdo);
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
  " . workshop_active_doctor_join('w') . "
  WHERE " . workshop_patient_enrollable_sql('w') . "
  GROUP BY w.type
")->fetchAll(PDO::FETCH_KEY_PAIR);
$countForTab = static function (string $tab): int {
    global $tabCounts;
    $type = workshop_type_from_tab($tab);
    return (int) ($tabCounts[$type] ?? 0);
};

$available = $pdo->prepare("
  SELECT w.*, u.name AS doctor_name,
    (SELECT COUNT(*) FROM workshop_media_items m WHERE m.workshop_id = w.id AND m.kind = 'VIDEO') AS video_count,
    (SELECT COUNT(*) FROM workshop_media_items m WHERE m.workshop_id = w.id AND m.kind = 'AUDIO') AS audio_count
  FROM workshops w
  " . workshop_active_doctor_join('w') . "
  JOIN users u ON u.id = dp.user_id
  WHERE w.type = ? AND " . workshop_patient_list_sql('w') . "
  ORDER BY " . ($dbType === 'OFFLINE' ? 'w.created_at DESC' : 'w.starts_at ASC') . "
");
$available->execute([$dbType]);
$availableWorkshops = $available->fetchAll();

$mine = $pdo->prepare("
  SELECT e.*, w.title, w.starts_at, w.ends_at, w.type, w.meeting_url, w.content_url, w.location,
         w.location_lat, w.location_lng,
         (SELECT COUNT(*) FROM workshop_media_items m WHERE m.workshop_id = w.id AND m.kind = 'VIDEO') AS video_count,
         (SELECT COUNT(*) FROM workshop_media_items m WHERE m.workshop_id = w.id AND m.kind = 'AUDIO') AS audio_count,
         wp.amount, wp.wallet_amount, wp.status AS pay_status, u.name AS doctor_name
  FROM workshop_enrollments e
  JOIN workshops w ON w.id = e.workshop_id
  JOIN doctor_profiles dp ON dp.id = w.doctor_id
  JOIN users u ON u.id = dp.user_id
  LEFT JOIN workshop_payments wp ON wp.enrollment_id = e.id
  WHERE e.patient_id = ? AND w.type = ?
  ORDER BY " . ($dbType === 'OFFLINE' ? 'e.enrolled_at DESC' : 'w.starts_at DESC') . "
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
  <p class="muted">کارگاه‌های حضوری، آنلاین و آفلاین همه درمانگران — ثبت‌نام و پرداخت از اینجا.</p>
  <p id="course-msg" class="course-flash" style="display:none" role="status"></p>

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
          <?php $availableMediaStats = workshop_media_counts_html(workshop_media_counts_from_row($w)); if ($availableMediaStats): ?>
            <div style="margin-top:.35rem"><?= $availableMediaStats ?></div>
          <?php endif; ?>
          <div class="muted" style="font-size:.85rem;margin-top:.25rem"><?= e($w['doctor_name']) ?></div>
          <div style="font-size:.85rem;margin-top:.35rem">
            <?php if ($w['type'] === 'OFFLINE'): ?>
              <span class="muted">دوره آفلاین — دسترسی به ویدیوها پس از ثبت‌نام</span>
            <?php else: ?>
              <?= e(format_fa_datetime($w['starts_at'])) ?> — <?= e(format_fa_datetime($w['ends_at'])) ?>
            <?php endif; ?>
          </div>
          <div class="muted" style="font-size:.85rem;margin-top:.25rem"><?= e(format_price((int)$w['price'])) ?></div>
          <?php if ($w['items_to_bring']): ?>
            <div style="font-size:.8rem;margin-top:.35rem"><strong>همراه داشته باشید:</strong> <?= e($w['items_to_bring']) ?></div>
          <?php endif; ?>
          <?php if ($w['description']): ?>
            <div class="muted" style="font-size:.8rem;margin-top:.25rem"><?= e($w['description']) ?></div>
          <?php endif; ?>
        </div>
        <div>
          <?php if (!$enrolled && workshop_can_enroll($w)): ?>
            <button type="button" class="btn btn-primary btn-sm enroll-btn" data-id="<?= e($w['id']) ?>">ثبت‌نام</button>
          <?php elseif (!$enrolled): ?>
            <span class="badge">ثبت‌نام بسته</span>
          <?php else: ?>
            <span class="badge">ثبت‌نام شده</span>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
    <?php if (!$availableWorkshops): ?>
      <p class="muted">کارگاه فعالی برای ثبت‌نام در این دسته نیست.</p>
      <ul class="muted" style="font-size:.85rem;margin:.5rem 0 0;padding-right:1.1rem">
        <li>کارگاه همه <strong>درمانگران</strong> اینجا نمایش داده می‌شود — نوع را در تب مناسب انتخاب کنید.</li>
        <li>ثبت‌نام تا زمانی که درمانگر آن را <strong>باز</strong> نگه دارد فعال است<?= $dbType === 'OFFLINE' ? '' : ' و کارگاه تمام نشده باشد' ?>.</li>
      </ul>
    <?php endif; ?>
  </section>

  <section class="panel stack">
    <h2 style="margin:0;font-size:1.1rem">ثبت‌نام‌های من</h2>
    <?php foreach ($myEnrollments as $e): ?>
      <div class="enrollment-card">
        <div class="enrollment-card-main">
          <strong><?= e($e['title']) ?></strong>
          <?php $enrollmentStats = workshop_media_counts_html(workshop_media_counts_from_row($e)); if ($enrollmentStats): ?>
            <div style="margin-top:.35rem"><?= $enrollmentStats ?></div>
          <?php endif; ?>
          <div class="muted" style="font-size:.85rem;margin-top:.25rem"><?= e($e['doctor_name']) ?></div>
          <?php if ($e['type'] !== 'OFFLINE'): ?>
            <div style="font-size:.85rem;margin-top:.35rem"><?= e(format_fa_datetime($e['starts_at'])) ?></div>
          <?php endif; ?>
          <span class="badge" style="margin-top:.5rem;display:inline-block"><?= e(enrollment_status_label($e['status'])) ?></span>
          <?php if ($e['amount']): ?>
            <div class="muted" style="font-size:.85rem;margin-top:.35rem"><?= e(format_price((int)$e['amount'])) ?></div>
          <?php endif; ?>
          <?php if ($e['status'] === 'CONFIRMED' || $e['status'] === 'COMPLETED'): ?>
            <?php if ($e['type'] === 'ONLINE' && $e['meeting_url']): ?>
              <div style="font-size:.85rem;margin-top:.35rem"><a href="<?= e($e['meeting_url']) ?>" target="_blank" rel="noopener">ورود به جلسه آنلاین</a></div>
            <?php endif; ?>
            <?php if ($e['type'] === 'IN_PERSON' && ($e['location'] || workshop_navigation_uri_from_row($e))): ?>
              <div class="enrollment-location">
                <?php if ($e['location']): ?>
                  <div class="enrollment-address"><span class="enrollment-label">محل:</span> <?= e($e['location']) ?></div>
                <?php endif; ?>
                <?php $navUri = workshop_navigation_uri_from_row($e); ?>
                <?php if ($navUri): ?>
                  <a href="<?= e($navUri) ?>" class="btn btn-outline btn-sm enrollment-nav-btn">مسیر‌یابی</a>
                <?php endif; ?>
              </div>
            <?php endif; ?>
            <?php
              $enrollmentMedia = workshop_media_counts_from_row($e);
              $hasMedia = $enrollmentMedia['total'] > 0;
            ?>
            <?php if ($hasMedia || $e['type'] === 'OFFLINE'): ?>
              <div style="font-size:.85rem;margin-top:.35rem">
                <a class="btn btn-outline btn-sm" href="<?= e(workshop_media_course_url((string) $e['id'])) ?>">
                  <?= $e['type'] === 'OFFLINE' ? 'مشاهده محتوای آفلاین' : 'مشاهده ضبط جلسات' ?>
                </a>
              </div>
            <?php endif; ?>
          <?php elseif ($e['status'] === 'PENDING_PAYMENT' && $e['type'] === 'OFFLINE'): ?>
            <p class="muted" style="font-size:.8rem;margin-top:.35rem">پس از پرداخت، محتوای آفلاین فعال می‌شود.</p>
          <?php endif; ?>
        </div>
        <div class="enrollment-card-actions">
          <?php if ($e['status'] === 'PENDING_PAYMENT'): ?>
            <label class="enrollment-wallet-label">
              <input type="checkbox" class="use-wallet" data-id="<?= e($e['id']) ?>" <?= (int)$wallet['balance'] > 0 ? '' : 'disabled' ?>>
              استفاده از کیف پول (<?= e(format_price((int)$wallet['balance'])) ?>)
            </label>
            <button type="button" class="btn btn-primary btn-sm pay-btn" data-id="<?= e($e['id']) ?>">پرداخت آنلاین</button>
          <?php endif; ?>
          <?php if (in_array($e['status'], ['PENDING_PAYMENT', 'CONFIRMED'], true)): ?>
            <button type="button" class="btn btn-outline btn-sm cancel-btn" data-id="<?= e($e['id']) ?>">لغو ثبت‌نام</button>
            <?php if ($e['status'] === 'CONFIRMED' && $e['type'] !== 'OFFLINE' && !workshop_refund_allowed($e['starts_at'])): ?>
              <p class="muted enrollment-refund-note">کمتر از ۲۴ ساعت مانده — بازگشت وجه نیست.</p>
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
  .course-flash { font-size: .9rem; padding: .65rem .85rem; border-radius: .65rem; border: 1px solid var(--line); margin-top: .75rem; }
  .course-flash.ok { color: var(--success); border-color: var(--success); background: #f0fdf4; }
  .course-flash.err { color: var(--danger); border-color: var(--danger); background: #fef2f2; }
  .enrollment-card {
    border: 1px solid var(--line);
    border-radius: .75rem;
    padding: .85rem;
    display: grid;
    gap: .75rem;
    grid-template-columns: 1fr;
  }
  .enrollment-card-main { min-width: 0; width: 100%; grid-row: 2; }
  .enrollment-card-actions {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: .5rem;
    width: 100%;
    grid-row: 1;
    padding-bottom: .75rem;
    border-bottom: 1px solid var(--line);
  }
  @media (min-width: 640px) {
    .enrollment-card {
      grid-template-columns: 1fr minmax(9rem, 12rem);
      align-items: start;
      gap: 1rem;
    }
    .enrollment-card-main { grid-row: auto; }
    .enrollment-card-actions {
      grid-row: auto;
      border-bottom: none;
      padding-bottom: 0;
      padding-top: 0;
      border-top: none;
    }
  }
  .enrollment-wallet-label {
    display: flex;
    gap: .35rem;
    align-items: center;
    font-size: .8rem;
    flex-wrap: wrap;
  }
  .enrollment-refund-note {
    font-size: .75rem;
    margin: 0;
    line-height: 1.4;
  }
  .enrollment-location {
    margin-top: .5rem;
    font-size: .85rem;
    display: flex;
    flex-direction: column;
    gap: .5rem;
    align-items: flex-start;
  }
  .enrollment-address {
    line-height: 1.65;
    word-break: break-word;
  }
  .enrollment-label {
    font-weight: 600;
    margin-right: .25rem;
  }
  .enrollment-nav-btn {
    width: auto;
    max-width: 100%;
  }
</style>
<?php
$coursesContent = ob_get_clean();

$GLOBALS['pageScripts'] = '
<script>
(function(){
  var enrollUrl = ' . json_encode($enrollUrl, JSON_UNESCAPED_UNICODE) . ';
  var payUrl = ' . json_encode($payUrl, JSON_UNESCAPED_UNICODE) . ';
  var cancelUrl = ' . json_encode($cancelUrl, JSON_UNESCAPED_UNICODE) . ';
  var msgEl = document.getElementById("course-msg");

  function showMsg(text, ok) {
    if (!msgEl) return;
    msgEl.textContent = text;
    msgEl.className = "course-flash " + (ok ? "ok" : "err");
    msgEl.style.display = "block";
    msgEl.scrollIntoView({ behavior: "smooth", block: "nearest" });
  }

  function readJsonResponse(r) {
    return r.text().then(function(text) {
      var j = {};
      try { j = text ? JSON.parse(text) : {}; } catch (e) {
        j = { error: "پاسخ نامعتبر از سرور" };
      }
      return { ok: r.ok, j: j };
    });
  }

  document.querySelectorAll(".enroll-btn").forEach(function(btn) {
    btn.addEventListener("click", function() {
      var workshopId = btn.getAttribute("data-id");
      if (!workshopId) return;
      btn.disabled = true;
      var oldLabel = btn.textContent;
      btn.textContent = "در حال ثبت‌نام...";
      var fd = new FormData();
      fd.append("workshopId", workshopId);
      fetch(enrollUrl, { method: "POST", body: fd, credentials: "same-origin" })
        .then(readJsonResponse)
        .then(function(res) {
          if (!res.ok) {
            btn.disabled = false;
            btn.textContent = oldLabel;
            showMsg(res.j.error || "ثبت‌نام ناموفق بود", false);
            return;
          }
          if (res.j.message) showMsg(res.j.message, true);
          setTimeout(function() { location.reload(); }, res.j.message ? 800 : 0);
        })
        .catch(function() {
          btn.disabled = false;
          btn.textContent = oldLabel;
          showMsg("خطای شبکه — دوباره تلاش کنید", false);
        });
    });
  });

  document.querySelectorAll(".pay-btn").forEach(function(btn) {
    btn.addEventListener("click", function() {
      var id = btn.getAttribute("data-id");
      var useWallet = document.querySelector(".use-wallet[data-id=\"" + id + "\"]");
      var fd = new FormData();
      fd.append("enrollmentId", id);
      if (useWallet && useWallet.checked) fd.append("use_wallet", "1");
      btn.disabled = true;
      fetch(payUrl, { method: "POST", body: fd, credentials: "same-origin" })
        .then(readJsonResponse)
        .then(function(res) {
          if (!res.ok) {
            btn.disabled = false;
            showMsg(res.j.error || "پرداخت ناموفق", false);
            return;
          }
          if (res.j.paymentUrl) { location.href = res.j.paymentUrl; return; }
          location.reload();
        })
        .catch(function() {
          btn.disabled = false;
          showMsg("خطای شبکه", false);
        });
    });
  });

  document.querySelectorAll(".cancel-btn").forEach(function(btn) {
    btn.addEventListener("click", function() {
      if (!confirm("ثبت‌نام لغو شود؟")) return;
      var fd = new FormData();
      fd.append("enrollmentId", btn.getAttribute("data-id"));
      fetch(cancelUrl, { method: "POST", body: fd, credentials: "same-origin" })
        .then(readJsonResponse)
        .then(function(res) {
          if (!res.ok) { showMsg(res.j.error || "خطا", false); return; }
          location.reload();
        })
        .catch(function() { showMsg("خطای شبکه", false); });
    });
  });
})();
</script>';

render_patient_page('دوره‌های من', $coursesContent);
