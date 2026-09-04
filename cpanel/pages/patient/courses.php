<?php
declare(strict_types=1);

$user = require_login(['PATIENT']);
require_once __DIR__ . '/../../includes/patient_panel.php';
require_once __DIR__ . '/../../includes/workshops.php';
require_once __DIR__ . '/../../includes/workshop_media.php';

ensure_workshop_schema($pdo);
ensure_workshop_media_schema($pdo);
$wallet = ensure_wallet($pdo, $user['id']);

$tabParam = trim((string) ($_GET['type'] ?? $_GET['tab'] ?? ''));
if (!in_array($tabParam, ['in-person', 'online', 'offline', 'archive'], true)) {
    $tabParam = 'in-person';
}

$published = $pdo->query("
  SELECT w.*, u.name AS doctor_name,
    (SELECT COUNT(*) FROM workshop_media_items m WHERE m.workshop_id = w.id AND m.kind = 'VIDEO') AS video_count,
    (SELECT COUNT(*) FROM workshop_media_items m WHERE m.workshop_id = w.id AND m.kind = 'AUDIO') AS audio_count
  FROM workshops w
  " . workshop_active_doctor_join('w') . "
  JOIN users u ON u.id = dp.user_id
  WHERE w.is_published = 1
  ORDER BY w.starts_at DESC
")->fetchAll();

$mine = $pdo->prepare("
  SELECT e.*, w.title, w.starts_at, w.ends_at, w.type, w.status AS workshop_status,
         w.meeting_url, w.content_url, w.group_url, w.location,
         w.location_lat, w.location_lng,
         (SELECT COUNT(*) FROM workshop_media_items m WHERE m.workshop_id = w.id AND m.kind = 'VIDEO') AS video_count,
         (SELECT COUNT(*) FROM workshop_media_items m WHERE m.workshop_id = w.id AND m.kind = 'AUDIO') AS audio_count,
         wp.amount, wp.wallet_amount, wp.status AS pay_status, u.name AS doctor_name
  FROM workshop_enrollments e
  JOIN workshops w ON w.id = e.workshop_id
  JOIN doctor_profiles dp ON dp.id = w.doctor_id
  JOIN users u ON u.id = dp.user_id
  LEFT JOIN workshop_payments wp ON wp.enrollment_id = e.id
  WHERE e.patient_id = ?
  ORDER BY e.enrolled_at DESC
");
$mine->execute([$user['id']]);
$myEnrollments = $mine->fetchAll();

$enrollByWorkshop = [];
foreach ($myEnrollments as $row) {
    $wid = (string) ($row['workshop_id'] ?? '');
    if ($wid !== '' && !isset($enrollByWorkshop[$wid])) {
        $enrollByWorkshop[$wid] = $row;
    }
}

$visible = [];
foreach ($published as $w) {
    if ((string) ($w['status'] ?? '') === 'CANCELLED' && empty($enrollByWorkshop[(string) $w['id']])) {
        continue;
    }
    $visible[] = $w;
}
$grouped = workshop_group_for_tabs($visible);

$enrollmentArchived = static function (array $e): bool {
    return workshop_is_archived([
        'status' => (string) ($e['workshop_status'] ?? ''),
        'type' => (string) ($e['type'] ?? ''),
        'ends_at' => (string) ($e['ends_at'] ?? ''),
    ]);
};

$enrollmentsByTab = [
    'in-person' => [],
    'online' => [],
    'offline' => [],
    'archive' => [],
];
foreach ($myEnrollments as $row) {
    if ($enrollmentArchived($row)) {
        $enrollmentsByTab['archive'][] = $row;
        continue;
    }
    $tab = workshop_tab_from_type((string) ($row['type'] ?? ''));
    $enrollmentsByTab[$tab][] = $row;
}

$enrollUrl = url('/enroll-workshop');
$payUrl = url('/pay-workshop');
$cancelUrl = url('/cancel-enrollment');

$binderTabs = [
    'in-person' => ['label' => 'حضوری', 'class' => 'binder-tab-in-person', 'empty' => 'کارگاه حضوری فعالی برای ثبت‌نام نیست.'],
    'online' => ['label' => 'آنلاین', 'class' => 'binder-tab-online', 'empty' => 'کارگاه آنلاین فعالی برای ثبت‌نام نیست.'],
    'offline' => ['label' => 'آفلاین', 'class' => 'binder-tab-offline', 'empty' => 'دوره آفلاین فعالی برای ثبت‌نام نیست.'],
];

ob_start();
?>
<div class="stack">
  <h1>دوره‌های من</h1>
  <p class="muted">کارگاه‌های همه درمانگران را از تب رنگی ببینید و اگر خواستید ثبت‌نام کنید. کارگاه تمام‌شده به آرشیو می‌رود.</p>
  <p id="course-msg" class="course-flash" style="display:none" role="status"></p>

  <div class="binder-tile" data-binder-tabs data-binder-initial="<?= e($tabParam) ?>">
    <div class="binder-tabs" role="tablist" aria-label="دسته‌بندی کارگاه‌ها">
      <?php foreach ($binderTabs as $id => $meta): ?>
        <button type="button" class="binder-tab <?= e($meta['class']) ?><?= $tabParam === $id ? ' is-active' : '' ?>" role="tab" data-binder-tab="<?= e($id) ?>" aria-selected="<?= $tabParam === $id ? 'true' : 'false' ?>">
          <?= e($meta['label']) ?>
          <span class="binder-tab-count"><?= count($grouped[$id]) ?></span>
        </button>
      <?php endforeach; ?>
      <button type="button" class="binder-tab binder-tab-archive<?= $tabParam === 'archive' ? ' is-active' : '' ?>" role="tab" data-binder-tab="archive" aria-selected="<?= $tabParam === 'archive' ? 'true' : 'false' ?>">
        آرشیو
        <span class="binder-tab-count"><?= count($grouped['archive']) ?></span>
      </button>
    </div>
    <div class="binder-body">
      <?php foreach ($binderTabs as $id => $meta): ?>
        <section class="binder-panel<?= $tabParam === $id ? ' is-active' : '' ?>" data-binder-panel="<?= e($id) ?>" role="tabpanel"<?= $tabParam === $id ? '' : ' hidden' ?>>
          <h2 class="binder-sub" style="margin-top:0">کارگاه‌های قابل ثبت‌نام</h2>
          <?php
            $workshopList = $grouped[$id];
            $archiveView = false;
            $emptyAvailable = $meta['empty'];
            require __DIR__ . '/../../includes/patient_workshop_available.php';
          ?>
          <h2 class="binder-sub">ثبت‌نام‌های من</h2>
          <?php
            $enrollmentList = $enrollmentsByTab[$id];
            $emptyEnrollments = 'هنوز در کارگاهی از این دسته ثبت‌نام نکرده‌اید.';
            require __DIR__ . '/../../includes/patient_workshop_enrollments.php';
          ?>
        </section>
      <?php endforeach; ?>

      <section class="binder-panel<?= $tabParam === 'archive' ? ' is-active' : '' ?>" data-binder-panel="archive" role="tabpanel"<?= $tabParam === 'archive' ? '' : ' hidden' ?>>
        <p class="muted" style="margin:0 0 .85rem;font-size:.9rem">کارگاه‌هایی که زمانشان تمام شده اینجا هستند. ثبت‌نام جدید برای آن‌ها ممکن نیست؛ اگر قبلاً ثبت‌نام کرده باشید، محتوا و لینک جلسه را می‌بینید.</p>
        <h2 class="binder-sub" style="margin-top:0">کارگاه‌های آرشیو</h2>
        <?php
          $workshopList = $grouped['archive'];
          $archiveView = true;
          $emptyAvailable = 'هنوز کارگاهی در آرشیو نیست.';
          require __DIR__ . '/../../includes/patient_workshop_available.php';
        ?>
        <h2 class="binder-sub">ثبت‌نام‌های آرشیو من</h2>
        <?php
          $enrollmentList = $enrollmentsByTab['archive'];
          $emptyEnrollments = 'ثبت‌نام آرشیوشده‌ای ندارید.';
          require __DIR__ . '/../../includes/patient_workshop_enrollments.php';
        ?>
      </section>
    </div>
  </div>
</div>
<?php
$coursesContent = ob_get_clean();

$GLOBALS['pageScripts'] = '
<script src="' . e(url('/assets/js/binder-tabs.js')) . '?v=20260904q"></script>
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
