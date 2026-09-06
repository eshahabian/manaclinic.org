<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/secretary_panel.php';

$user = require_login(['SECRETARY']);

$patients = $pdo->query("
  SELECT u.id, u.name, u.username, u.phone,
         COUNT(a.id) AS visit_count,
         MAX(a.starts_at) AS last_visit
  FROM users u
  LEFT JOIN appointments a ON a.patient_id = u.id
    AND a.status IN ('PENDING_PAYMENT','CONFIRMED','COMPLETED')
  WHERE u.role = 'PATIENT'
  GROUP BY u.id, u.name, u.username, u.phone
  ORDER BY u.name ASC
")->fetchAll();
$doctors = secretary_active_doctors($pdo);
$nameDict = build_name_transliterations_client_map($pdo);
$openAdd = isset($_GET['add']);

ob_start();
?>
<div class="secretary-patients-head">
  <div>
    <h1>مراجعه‌کنندگان</h1>
    <p class="muted" style="margin-top:.35rem;font-size:.9rem">روی نام بزنید تا شماره تماس و روزهای نوبت را ببینید.</p>
  </div>
  <button type="button" class="btn btn-primary" id="toggle-add-patient" aria-expanded="<?= $openAdd ? 'true' : 'false' ?>" aria-controls="secretary-add-patient">
    افزودن مراجعه‌کننده
  </button>
</div>

<form class="panel form-stack secretary-add-patient" method="post" action="<?= e(url('/secretary/patients')) ?>" id="secretary-patient-form" <?= $openAdd ? '' : 'hidden' ?>>
  <?= csrf_field() ?>
  <p style="margin:0;font-weight:600">مراجعه‌کننده جدید</p>
  <?php
    $secretaryPatientHint = 'بدون گرفتن نوبت هم می‌توانید نفر جدید ثبت کنید. با تایپ نام فارسی، معادل انگلیسی پیشنهاد می‌شود. اگر صفحه بسته شود یا نت قطع شود، اطلاعات همین‌جا می‌ماند.';
    require __DIR__ . '/../../includes/secretary_new_patient_fields.php';
  ?>
  <p id="sec-patient-error" style="color:var(--danger);display:none;font-size:.9rem"></p>
  <button class="btn btn-primary" type="submit">ثبت مراجعه‌کننده</button>
</form>

<div style="margin-top:1rem;max-width:22rem">
  <label class="label" for="patient-filter">جستجو</label>
  <input class="input" type="search" id="patient-filter" placeholder="نام، نام کاربری یا موبایل" autocomplete="off">
</div>
<div class="stack" id="secretary-patient-list" style="margin-top:1rem">
  <?php foreach ($patients as $p): ?>
    <?php
      $phone = trim((string) ($p['phone'] ?? ''));
      $search = mb_strtolower(trim((string) $p['name'] . ' ' . (string) $p['username'] . ' ' . $phone));
    ?>
    <a class="panel row-between secretary-patient-row" href="<?= e(url('/secretary/patients/' . $p['id'])) ?>" style="color:inherit" data-search="<?= e($search) ?>">
      <div>
        <strong><?= e((string) $p['name']) ?></strong>
        <div class="muted" style="font-size:.85rem;margin-top:.3rem">
          <?php if ($phone !== ''): ?>
            <span dir="ltr"><?= e($phone) ?></span>
          <?php else: ?>
            شماره ثبت نشده
          <?php endif; ?>
        </div>
      </div>
      <div style="text-align:left">
        <span class="badge"><?= (int) $p['visit_count'] ?> نوبت</span>
        <?php if (!empty($p['last_visit'])): ?>
          <div class="muted" style="font-size:.75rem;margin-top:.35rem"><?= e(format_fa_datetime((string) $p['last_visit'])) ?></div>
        <?php endif; ?>
      </div>
    </a>
  <?php endforeach; ?>
  <?php if (!$patients): ?><p class="muted">مراجعه‌کننده‌ای ثبت نشده است.</p><?php endif; ?>
  <p class="muted" id="patient-filter-empty" hidden>موردی با این جستجو نیست.</p>
</div>
<?php
$inner = ob_get_clean();
$pageScripts = '
<script src="' . e(url('/assets/js/search-select.js')) . '?v=20260906p"></script>
<script src="' . e(url('/assets/js/name-transliterate.js')) . '?v=20260906p"></script>
<script src="' . e(url('/assets/js/form-draft.js')) . '?v=20260906p"></script>
<script src="' . e(url('/assets/js/secretary-patient-form.js')) . '?v=20260906p"></script>
<script>
(function(){
  var form = document.getElementById("secretary-patient-form");
  var toggle = document.getElementById("toggle-add-patient");
  var errEl = document.getElementById("sec-patient-error");
  var bound = bindSecretaryPatientFields({
    form: form,
    transliterateUrl: ' . json_encode(url('/api/transliterate-name')) . ',
    nameDict: ' . json_encode($nameDict, JSON_UNESCAPED_UNICODE) . ',
    draftKey: "mana.secretary.patient.create",
    clearParams: ["added"],
    onRestore: function(){
      if (form) form.hidden = false;
      if (toggle) toggle.setAttribute("aria-expanded", "true");
    }
  });

  function openForm(){
    if (!form) return;
    form.hidden = false;
    if (toggle) toggle.setAttribute("aria-expanded", "true");
    var first = document.getElementById("new_first_name");
    if (first) first.focus();
  }
  function closeForm(){
    if (!form) return;
    form.hidden = true;
    if (toggle) toggle.setAttribute("aria-expanded", "false");
  }
  if (toggle) {
    toggle.addEventListener("click", function(){
      if (form && form.hidden) openForm();
      else closeForm();
    });
  }
  if (form) {
    form.addEventListener("submit", function(e){
      if (!validateSecretaryNewPatient(errEl)) e.preventDefault();
    });
  }

  var input = document.getElementById("patient-filter");
  var rows = document.querySelectorAll(".secretary-patient-row");
  var empty = document.getElementById("patient-filter-empty");
  if (!input) return;
  input.addEventListener("input", function(){
    var q = (input.value || "").trim().toLowerCase();
    var shown = 0;
    rows.forEach(function(row){
      var hay = (row.getAttribute("data-search") || "");
      var on = !q || hay.indexOf(q) !== -1;
      row.hidden = !on;
      if (on) shown++;
    });
    if (empty) empty.hidden = shown > 0 || rows.length === 0;
  });
})();
</script>
';
render_secretary_page('مراجعه‌کنندگان', $inner);
