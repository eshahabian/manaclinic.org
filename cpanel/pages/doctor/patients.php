<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/doctor_panel.php';
require_once __DIR__ . '/../../includes/doctor_clinical.php';
require_once __DIR__ . '/../../includes/assistant.php';

$ctx = require_doctor_profile($pdo);
ensure_doctor_clinical_tables($pdo);
ensure_assistant_schema($pdo);
$doctorId = $ctx['profile']['id'];
if (!function_exists('doctor_is_clinic_lead')) {
    require_once __DIR__ . '/../../includes/doctor_profile_fields.php';
}
$seesAllPatients = doctor_is_clinic_lead($ctx['user'] ?? null);
$leadIds = clinic_lead_profile_ids($pdo);

if ($seesAllPatients) {
    $stmt = $pdo->query("
      SELECT
        u.id,
        u.name,
        u.username,
        u.phone,
        COUNT(a.id) AS visit_count,
        MAX(a.starts_at) AS last_visit,
        COALESCE(pdu.name, '') AS therapist_name
      FROM users u
      LEFT JOIN appointments a ON a.patient_id = u.id
      LEFT JOIN doctor_profiles pdp ON pdp.id = u.preferred_doctor_id
      LEFT JOIN users pdu ON pdu.id = pdp.user_id
      WHERE (
          u.role = 'PATIENT'
          OR LOWER(u.username) = 'eshahabian'
          OR (u.name LIKE '%عماد%' AND u.name LIKE '%شهابیان%')
          OR EXISTS (
            SELECT 1 FROM appointments axrole
            WHERE axrole.patient_id = u.id AND axrole.status IN ('CONFIRMED','COMPLETED')
          )
        )
        AND (
          (u.preferred_doctor_id IS NOT NULL AND u.preferred_doctor_id <> '')
          OR EXISTS (SELECT 1 FROM appointments ax WHERE ax.patient_id = u.id)
          OR EXISTS (
            SELECT 1 FROM workshop_enrollments e
            JOIN workshops w ON w.id = e.workshop_id
            WHERE e.patient_id = u.id
          )
        )
      GROUP BY u.id, u.name, u.username, u.phone, pdu.name
      ORDER BY last_visit IS NULL ASC, last_visit DESC, u.name ASC
    ");
    $patients = $stmt->fetchAll();
} else {
    $excludeSql = '';
    $params = [$doctorId, $doctorId, $doctorId, $doctorId];
    if ($leadIds !== []) {
        $marks = implode(',', array_fill(0, count($leadIds), '?'));
        $excludeSql = " AND (u.preferred_doctor_id IS NULL OR u.preferred_doctor_id NOT IN ({$marks}))";
        $params = array_merge($params, $leadIds);
    }
    $params[] = $doctorId;
    $stmt = $pdo->prepare("
      SELECT
        u.id,
        u.name,
        u.username,
        u.phone,
        COUNT(a.id) AS visit_count,
        MAX(a.starts_at) AS last_visit,
        '' AS therapist_name
      FROM users u
      LEFT JOIN appointments a ON a.patient_id = u.id AND a.doctor_id = ?
      WHERE (
          (
            (
              u.role = 'PATIENT'
              OR LOWER(u.username) = 'eshahabian'
              OR (u.name LIKE '%عماد%' AND u.name LIKE '%شهابیان%')
            )
            AND (
              u.preferred_doctor_id = ?
              OR EXISTS (
                SELECT 1 FROM appointments ax
                WHERE ax.patient_id = u.id AND ax.doctor_id = ?
              )
              OR EXISTS (
                SELECT 1 FROM workshop_enrollments e
                JOIN workshops w ON w.id = e.workshop_id
                " . doctor_workshop_host_join('w') . "
                WHERE e.patient_id = u.id
              )
            )
            {$excludeSql}
          )
          OR EXISTS (
            SELECT 1 FROM appointments axshow
            WHERE axshow.patient_id = u.id
              AND axshow.doctor_id = ?
              AND axshow.status IN ('CONFIRMED','COMPLETED')
          )
        )
      GROUP BY u.id, u.name, u.username, u.phone
      ORDER BY last_visit IS NULL ASC, last_visit DESC, u.name ASC
    ");
    $stmt->execute($params);
    $patients = $stmt->fetchAll();
}

$noteCounts = [];
if ($seesAllPatients) {
    $nc = $pdo->query('SELECT patient_id, COUNT(*) AS c FROM doctor_session_notes GROUP BY patient_id');
} else {
    $nc = $pdo->prepare('SELECT patient_id, COUNT(*) AS c FROM doctor_session_notes WHERE doctor_id=? GROUP BY patient_id');
    $nc->execute([$doctorId]);
}
foreach ($nc->fetchAll() as $row) {
    $noteCounts[$row['patient_id']] = (int) $row['c'];
}

$intakeRows = $pdo->query("
  SELECT s.*, u.name AS patient_name, u.phone AS patient_phone
  FROM assistant_sessions s
  LEFT JOIN users u ON u.id = s.patient_id
  WHERE s.status = 'SENT'
    AND s.sent_at IS NOT NULL
    AND (s.patient_id IS NULL OR s.patient_id = '')
  ORDER BY s.sent_at DESC
  LIMIT 100
")->fetchAll();

$tabParam = trim((string) ($_GET['tab'] ?? ''));
$binderInitial = in_array($tabParam, ['chart', 'intakes'], true) ? $tabParam : 'chart';

ob_start();
?>
<h1>پرونده مراجعه‌کنندگان</h1>
<p class="muted"><?= $seesAllPatients
    ? 'مراجعه‌کنندگان همه درمانگرها اینجاست. این دید فقط برای دکتر شیوا گرانمایه‌پور و دکتر عطیه گارسچی است و درمانگرهای دیگر مراجع این دو را نمی‌بینند.'
    : 'فقط مراجعه‌کنندگان خودتان. مراجعه‌کنندگانی که درمانگرشان دکتر شیوا گرانمایه‌پور یا دکتر عطیه گارسچی است در این فهرست نیستند.' ?></p>

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
      <div style="margin:0 0 1rem;max-width:22rem">
        <label class="label" for="doctor-patient-filter">جستجو</label>
        <input class="input" type="search" id="doctor-patient-filter" placeholder="نام، نام کاربری یا موبایل" autocomplete="off">
      </div>
      <div class="stack" id="doctor-patient-list">
        <?php foreach ($patients as $p): ?>
          <?php
            $phone = trim((string) ($p['phone'] ?? ''));
            $phoneDigits = preg_replace('/\D+/', '', $phone) ?? '';
            $search = mb_strtolower(trim(
                (string) $p['name'] . ' ' .
                (string) $p['username'] . ' ' .
                $phone . ' ' .
                $phoneDigits
            ));
          ?>
          <a class="panel row-between doctor-patient-row" href="<?= e(url('/doctor/patients/' . $p['id'])) ?>" style="color:inherit" data-search="<?= e($search) ?>">
            <div>
              <strong><?= e($p['name']) ?></strong>
              <?php if ($seesAllPatients && trim((string) ($p['therapist_name'] ?? '')) !== ''): ?>
                <div style="font-size:.85rem;margin-top:.15rem">درمانگر: <?= e((string) $p['therapist_name']) ?></div>
              <?php endif; ?>
              <div class="muted" style="font-size:.85rem" dir="ltr">
                <?= e((string)$p['username']) ?>
                <?php if (can_view_patient_phone() && $phone !== ''): ?>
                  · <?= e($phone) ?>
                <?php endif; ?>
              </div>
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
        <p class="muted" id="doctor-patient-filter-empty" hidden>موردی با این جستجو نیست.</p>
      </div>
    </section>
    <section class="binder-panel<?= $binderInitial === 'intakes' ? ' is-active' : '' ?>" data-binder-panel="intakes" role="tabpanel"<?= $binderInitial === 'intakes' ? '' : ' hidden' ?>>
      <p class="muted" style="margin:0 0 .85rem;font-size:.9rem">اینجا فقط گفتگوهای مهمان است. گفتگوی مراجعه‌کننده ثبت‌نام‌شده را از پرونده همان فرد ببینید.</p>
      <?php
        $intakeMapped = [];
        foreach ($intakeRows as $session) {
            $session['starts_at'] = (string) (($session['sent_at'] ?? '') ?: ($session['created_at'] ?? ''));
            $intakeMapped[] = $session;
        }
        $intakeYmdPack = group_appointments_by_jalali_ymd($intakeMapped, 'gintk', 'latest', ['fill' => 'none']);
        $intakeMonthEmpty = 'هنوز گفتگویی ارسال نشده است.';
        require __DIR__ . '/../../includes/doctor_intake_month_binder.php';
      ?>
    </section>
  </div>
</div>
<?php
$inner = ob_get_clean();
$pageScripts = '<script src="' . e(url('/assets/js/binder-tabs.js')) . '?v=20260905c"></script>
<script src="' . e(url('/assets/js/ymd-cascade.js')) . '?v=20260910r"></script>
<script>
(function(){
  function faToEn(str){
    return String(str || "").replace(/[۰-۹]/g, function(d){ return String("۰۱۲۳۴۵۶۷۸۹".indexOf(d)); })
      .replace(/[٠-٩]/g, function(d){ return String("٠١٢٣٤٥٦٧٨٩".indexOf(d)); });
  }
  function norm(str){
    return faToEn(str).trim().toLowerCase().replace(/ي/g, "ی").replace(/ك/g, "ک");
  }
  var input = document.getElementById("doctor-patient-filter");
  var list = document.getElementById("doctor-patient-list");
  var rows = list ? list.querySelectorAll(".doctor-patient-row") : [];
  var empty = document.getElementById("doctor-patient-filter-empty");
  if (!input) return;
  function applyFilter(){
    var raw = norm(input.value || "");
    var q = raw;
    var qDigits = raw.replace(/\D+/g, "");
    var shown = 0;
    rows.forEach(function(row){
      var hay = norm(row.getAttribute("data-search") || "");
      var on = !q || hay.indexOf(q) !== -1 || (qDigits.length >= 3 && hay.indexOf(qDigits) !== -1);
      row.classList.toggle("is-search-hidden", !on);
      row.hidden = !on;
      if (on) shown++;
    });
    if (empty) empty.hidden = shown > 0 || rows.length === 0;
  }
  input.addEventListener("input", applyFilter);
  input.addEventListener("search", applyFilter);
  applyFilter();

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
$GLOBALS['pageScripts'] = $pageScripts;
render_doctor_page('پرونده مراجعه‌کنندگان', $inner);
