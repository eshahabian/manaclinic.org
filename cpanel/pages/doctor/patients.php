<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/doctor_panel.php';
require_once __DIR__ . '/../../includes/doctor_clinical.php';
require_once __DIR__ . '/../../includes/assistant.php';

$ctx = require_doctor_profile($pdo);
ensure_doctor_clinical_tables($pdo);
ensure_assistant_schema($pdo);
$doctorId = $ctx['profile']['id'];

$stmt = $pdo->prepare("
  SELECT
    u.id,
    u.name,
    u.username,
    u.phone,
    COUNT(a.id) AS visit_count,
    MAX(a.starts_at) AS last_visit
  FROM users u
  LEFT JOIN appointments a ON a.patient_id = u.id AND a.doctor_id = ?
  WHERE u.role = 'PATIENT'
    AND (
      u.preferred_doctor_id = ?
      OR EXISTS (
        SELECT 1 FROM appointments ax
        WHERE ax.patient_id = u.id AND ax.doctor_id = ?
      )
    )
  GROUP BY u.id, u.name, u.username, u.phone
  ORDER BY last_visit IS NULL ASC, last_visit DESC, u.name ASC
");
$stmt->execute([$doctorId, $doctorId, $doctorId]);
$patients = $stmt->fetchAll();

$noteCounts = [];
$nc = $pdo->prepare('SELECT patient_id, COUNT(*) AS c FROM doctor_session_notes WHERE doctor_id=? GROUP BY patient_id');
$nc->execute([$doctorId]);
foreach ($nc->fetchAll() as $row) {
    $noteCounts[$row['patient_id']] = (int) $row['c'];
}

$intakeRows = $pdo->query("
  SELECT s.*, u.name AS patient_name, u.phone AS patient_phone
  FROM assistant_sessions s
  LEFT JOIN users u ON u.id = s.patient_id
  WHERE s.status = 'SENT' AND s.sent_at IS NOT NULL
  ORDER BY s.sent_at DESC
  LIMIT 100
")->fetchAll();

$tabParam = trim((string) ($_GET['tab'] ?? ''));
$binderInitial = in_array($tabParam, ['chart', 'intakes'], true) ? $tabParam : 'chart';

ob_start();
?>
<h1>پرونده مراجعه‌کنندگان</h1>
<p class="muted">این بخش کاملاً خصوصی است؛ فقط شما می‌توانید شرح حال، یادداشت جلسات و گفتگوها را ببینید.</p>

<div class="binder-tile" data-binder-tabs data-binder-initial="<?= e($binderInitial) ?>" data-binder-tone="<?= e($binderInitial === 'intakes' ? 'workshops' : 'appts') ?>" style="margin-top:1.25rem">
  <div class="binder-tabs" role="tablist" aria-label="بخش‌های پرونده">
    <button type="button" class="binder-tab binder-tab-appts<?= $binderInitial === 'chart' ? ' is-active' : '' ?>" role="tab" data-binder-tab="chart" data-binder-tone="appts" aria-selected="<?= $binderInitial === 'chart' ? 'true' : 'false' ?>">
      پرونده مراجعه‌کننده <span class="binder-tab-count"><?= count($patients) ?></span>
    </button>
    <button type="button" class="binder-tab binder-tab-workshops<?= $binderInitial === 'intakes' ? ' is-active' : '' ?>" role="tab" data-binder-tab="intakes" data-binder-tone="workshops" aria-selected="<?= $binderInitial === 'intakes' ? 'true' : 'false' ?>">
      گفتگوهای دستیار <span class="binder-tab-count"><?= count($intakeRows) ?></span>
    </button>
  </div>
  <div class="binder-body">
    <section class="binder-panel<?= $binderInitial === 'chart' ? ' is-active' : '' ?>" data-binder-panel="chart" role="tabpanel"<?= $binderInitial === 'chart' ? '' : ' hidden' ?>>
      <div class="stack">
        <?php foreach ($patients as $p): ?>
          <a class="panel row-between" href="<?= e(url('/doctor/patients/' . $p['id'])) ?>" style="color:inherit">
            <div>
              <strong><?= e($p['name']) ?></strong>
              <div class="muted" style="font-size:.85rem" dir="ltr"><?= e((string)$p['username']) ?><?= $p['phone'] ? ' · ' . e((string)$p['phone']) : '' ?></div>
              <div style="font-size:.85rem;margin-top:.35rem">
                <?= (int)$p['visit_count'] ?> نوبت
                <?php if ($p['last_visit']): ?>
                  · آخرین: <?= e(format_fa_datetime($p['last_visit'])) ?>
                <?php endif; ?>
                <?php if (!empty($noteCounts[$p['id']])): ?>
                  · <?= (int)$noteCounts[$p['id']] ?> یادداشت جلسه
                <?php endif; ?>
              </div>
            </div>
            <span class="btn btn-outline btn-sm">مشاهده پرونده</span>
          </a>
        <?php endforeach; ?>
        <?php if (!$patients): ?>
          <p class="muted" style="margin:0">هنوز مراجعه‌کننده اختصاص‌یافته یا نوبت‌داری برای شما ثبت نشده است.</p>
        <?php endif; ?>
      </div>
    </section>
    <section class="binder-panel<?= $binderInitial === 'intakes' ? ' is-active' : '' ?>" data-binder-panel="intakes" role="tabpanel"<?= $binderInitial === 'intakes' ? '' : ' hidden' ?>>
      <p class="muted" style="margin:0 0 .85rem;font-size:.9rem">گفتگوها بر اساس ماه و تاریخ جدا شده‌اند. روی روز بزنید تا ببینید چه کسی با دستیار حرف زده است.</p>
      <?php
        $intakeMonthPack = doctor_intake_month_groups($intakeRows);
        $intakeMonthGroups = $intakeMonthPack['months'];
        $intakeMonthDefault = $intakeMonthPack['default_id'];
        $intakeMonthEmpty = 'هنوز گفتگویی ارسال نشده است.';
        require __DIR__ . '/../../includes/doctor_intake_month_binder.php';
      ?>
    </section>
  </div>
</div>
<?php
$pageScripts = '<script src="' . e(url('/assets/js/binder-tabs.js')) . '?v=20260905c"></script>
<script>
(function(){
  document.addEventListener("click", function(e){
    var closeBtn = e.target.closest("[data-close]");
    var toggle = e.target.closest("[data-toggle]");
    var box = e.target.closest("[data-box]");
    var grids = document.querySelectorAll("[data-session-note-grid]");
    if (closeBtn && box) {
      box.classList.remove("open");
      return;
    }
    if (toggle && box) {
      var wasOpen = box.classList.contains("open");
      grids.forEach(function(grid){
        grid.querySelectorAll("[data-box].open").forEach(function(b){ b.classList.remove("open"); });
      });
      if (!wasOpen) box.classList.add("open");
      return;
    }
    if (!box) {
      grids.forEach(function(grid){
        grid.querySelectorAll("[data-box].open").forEach(function(b){ b.classList.remove("open"); });
      });
    }
  });
})();
</script>';
render_doctor_page('پرونده مراجعه‌کنندگان', ob_get_clean());
