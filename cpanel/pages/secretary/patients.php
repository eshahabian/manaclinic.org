<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/secretary_panel.php';
require_once __DIR__ . '/../../includes/secretary_patient.php';
require_once __DIR__ . '/../../includes/user_referral.php';
require_once __DIR__ . '/../../includes/outreach.php';

$user = require_login(['SECRETARY']);

users_backfill_created_by($pdo);

$patients = $pdo->query("
  SELECT u.id, u.name, u.username, u.phone, u.referral_source,
         cu.name AS created_by_name, cu.username AS created_by_username, cu.role AS created_by_role,
         COUNT(a.id) AS visit_count,
         MAX(a.starts_at) AS last_visit
  FROM users u
  LEFT JOIN users cu ON cu.id = u.created_by_user_id
  LEFT JOIN appointments a ON a.patient_id = u.id
    AND a.status IN ('PENDING_APPROVAL','PENDING_PAYMENT','CONFIRMED','COMPLETED')
  WHERE u.role = 'PATIENT'
  GROUP BY u.id, u.name, u.username, u.phone, u.referral_source, cu.name, cu.username, cu.role
  ORDER BY u.name ASC
")->fetchAll();
$doctors = secretary_active_doctors($pdo);
$nameDict = build_name_transliterations_client_map($pdo);
$openAdd = isset($_GET['add']);
$tab = (string) ($_GET['tab'] ?? 'active');
if ($tab !== 'inactive') {
    $tab = 'active';
}
ensure_outreach_schema($pdo);
ensure_user_referral_schema($pdo);
$inactiveContacts = outreach_contacts($pdo);
$smsPending = 0;
try {
    $smsPending = (int) $pdo->query("SELECT COUNT(*) FROM sms_outbox WHERE status='PENDING'")->fetchColumn();
} catch (Throwable $ignored) {
    $smsPending = 0;
}
$accountUsers = $pdo->query("
  SELECT u.id, u.username, u.name, u.role, u.phone, u.created_at, u.last_login_at,
         u.referral_source, u.preferred_doctor_id,
         cu.name AS created_by_name, cu.username AS created_by_username, cu.role AS created_by_role
  FROM users u
  LEFT JOIN users cu ON cu.id = u.created_by_user_id
  WHERE u.is_disabled = 0
    AND (
      u.role = 'PATIENT'
      OR LOWER(u.username) = 'eshahabian'
      OR (u.name LIKE '%عماد%' AND u.name LIKE '%شهابیان%')
    )
  ORDER BY u.created_at DESC
")->fetchAll() ?: [];

ob_start();
?>
<div class="secretary-patients-head">
  <div>
    <h1>مراجعه‌کنندگان</h1>
    <p class="muted" style="margin-top:.35rem;font-size:.9rem">فعال حساب کاربری دارد. غیر فعال فقط نام و شماره است و حساب ساخته نمی‌شود.</p>
  </div>
</div>
<div class="secretary-kind-switch" role="tablist">
  <button type="button" class="btn <?= $tab === 'active' ? 'btn-primary' : 'btn-outline' ?>" data-patient-kind="active" aria-selected="<?= $tab === 'active' ? 'true' : 'false' ?>">فعال</button>
  <button type="button" class="btn <?= $tab === 'inactive' ? 'btn-primary' : 'btn-outline' ?>" data-patient-kind="inactive" aria-selected="<?= $tab === 'inactive' ? 'true' : 'false' ?>">غیر فعال</button>
</div>

<div id="patients-active" <?= $tab === 'inactive' ? 'hidden' : '' ?>>
<div class="secretary-patients-head" style="margin-top:1rem">
  <p class="muted" style="margin:0;font-size:.9rem">روی نام بزنید تا شماره تماس و روزهای نوبت را ببینید.</p>
  <button type="button" class="btn btn-primary" id="toggle-add-patient" aria-expanded="<?= $openAdd ? 'true' : 'false' ?>" aria-controls="secretary-add-patient">
    افزودن مراجعه‌کننده فعال
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
      $creatorLabel = user_created_by_label([
          'name' => $p['created_by_name'] ?? '',
          'username' => $p['created_by_username'] ?? '',
          'role' => $p['created_by_role'] ?? '',
      ], 'ثبت‌کننده نامشخص');
      $search = mb_strtolower(trim((string) $p['name'] . ' ' . (string) $p['username'] . ' ' . $phone . ' ' . $creatorLabel));
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
        <div class="muted" style="font-size:.8rem;margin-top:.35rem">ثبت توسط <?= e($creatorLabel) ?></div>
        <?php $referralLabel = user_referral_label((string) ($p['referral_source'] ?? '')); ?>
        <?php if ($referralLabel !== ''): ?>
          <div class="muted" style="font-size:.8rem;margin-top:.2rem">معرف: <?= e($referralLabel) ?></div>
        <?php endif; ?>
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

<h2 style="margin:1.5rem 0 .35rem;font-size:1.05rem">ویرایش حساب فعال</h2>
<p class="muted" style="margin:0 0 .75rem;font-size:.9rem">درمانگر، معرف و موبایل همین حساب‌ها از اینجا عوض می‌شود.</p>
<div class="panel admin-users-board" style="padding:0;overflow:auto">
  <table class="table">
    <thead>
      <tr>
        <th>نام</th>
        <th>نام کاربری</th>
        <th>درمانگر</th>
        <th>معرف</th>
        <th>موبایل</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($accountUsers as $u): ?>
        <?php
          $uid = (string) ($u['id'] ?? '');
          $formId = 'sec-user-' . preg_replace('/[^a-zA-Z0-9_-]/', '', $uid);
          $compact = 'min-width:9rem;padding:.35rem .5rem;font-size:.85rem';
        ?>
        <tr>
          <td><?= e((string) $u['name']) ?></td>
          <td dir="ltr"><?= e((string) $u['username']) ?></td>
          <td>
            <select class="input" name="preferred_doctor_id" form="<?= e($formId) ?>" required aria-label="درمانگر" style="<?= e($compact) ?>">
              <option value="">انتخاب درمانگر</option>
              <?php foreach ($doctors as $doctor): ?>
                <option value="<?= e((string) $doctor['id']) ?>"<?= ((string) ($u['preferred_doctor_id'] ?? '') === (string) $doctor['id']) ? ' selected' : '' ?>><?= e((string) $doctor['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </td>
          <td>
            <select class="input" name="referral_source" form="<?= e($formId) ?>" aria-label="معرف" style="<?= e($compact) ?>">
              <option value="">انتخاب معرف</option>
              <option value="clinic"<?= (($u['referral_source'] ?? '') === 'clinic') ? ' selected' : '' ?>>ارجاع از سمت کلینیک</option>
              <option value="therapist"<?= (($u['referral_source'] ?? '') === 'therapist') ? ' selected' : '' ?>>ارجاع از سمت درمانگر</option>
            </select>
          </td>
          <td>
            <input class="input" name="phone" form="<?= e($formId) ?>" dir="ltr" inputmode="tel" value="<?= e((string) ($u['phone'] ?? '')) ?>" required aria-label="موبایل" style="min-width:8rem;padding:.35rem .5rem;font-size:.85rem">
          </td>
          <td>
            <form id="<?= e($formId) ?>" method="post" action="<?= e(url('/secretary/users')) ?>" style="margin:0">
              <?= csrf_field() ?>
              <input type="hidden" name="user_id" value="<?= e($uid) ?>">
              <button class="btn btn-primary btn-sm" type="submit">ذخیره</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$accountUsers): ?>
        <tr><td colspan="6" class="muted" style="text-align:center;padding:1.2rem">حساب فعالی نیست.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>
</div>

<div id="patients-inactive" <?= $tab === 'active' ? 'hidden' : '' ?>>
  <p class="muted" style="margin:1rem 0 0;line-height:1.8">
    اسم و شماره بدون ساختن حساب کاربری ذخیره می‌شود.
    اگر بعداً با همین شماره ثبت‌نام کنند، همین ردیف به حسابشان وصل می‌شود.
    پیامک در صف: <?= e(to_fa_digits((string) $smsPending)) ?>
  </p>
  <div class="grid-2" style="margin-top:1rem">
    <form class="panel" method="post" action="<?= e(url('/secretary/patients')) ?>" style="margin:0">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="add_inactive">
      <h2 style="margin:0 0 .75rem;font-size:1rem">یک نفر</h2>
      <label>
        <span class="label">نام</span>
        <input class="input" name="name" required>
      </label>
      <label style="display:block;margin-top:.6rem">
        <span class="label">شماره تماس</span>
        <input class="input" name="phone" required dir="ltr" inputmode="tel">
      </label>
      <label style="display:block;margin-top:.6rem">
        <span class="label">یادداشت</span>
        <input class="input" name="note">
      </label>
      <button class="btn btn-primary" type="submit" style="margin-top:.8rem">ذخیره</button>
    </form>
    <form class="panel" method="post" action="<?= e(url('/secretary/patients')) ?>" style="margin:0">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="import_inactive">
      <h2 style="margin:0 0 .75rem;font-size:1rem">چند نفر با هم</h2>
      <p class="muted" style="margin:0 0 .5rem;font-size:.85rem">هر خط: نام و بعد شماره. مثلاً «مهراد بابایی ۰۹۱۲۱۲۳۴۵۶۷»</p>
      <textarea class="input" name="lines" rows="6" required></textarea>
      <button class="btn btn-primary" type="submit" style="margin-top:.8rem">افزودن فهرست</button>
    </form>
  </div>
  <form class="panel" method="post" action="<?= e(url('/secretary/patients')) ?>" style="margin-top:1rem">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="queue_inactive">
    <h2 style="margin:0 0 .5rem;font-size:1rem">پیامک دستی به همین فهرست</h2>
    <textarea class="input" name="body" rows="3" required placeholder="متن پیامک، مثلاً اعلام یک کارگاه"></textarea>
    <button class="btn btn-primary" type="submit" style="margin-top:.75rem">گذاشتن در صف پیامک</button>
  </form>
  <div class="panel" style="padding:0;overflow:auto;margin-top:1rem">
    <table class="table">
      <thead>
        <tr>
          <th>نام</th>
          <th>شماره</th>
          <th>حساب سایت</th>
          <th>یادداشت</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$inactiveContacts): ?>
          <tr><td colspan="5" class="muted">هنوز شماره‌ای ثبت نشده است.</td></tr>
        <?php endif; ?>
        <?php foreach ($inactiveContacts as $row): ?>
          <tr>
            <td><?= e((string) $row['name']) ?></td>
            <td dir="ltr"><?= e((string) $row['phone']) ?></td>
            <td><?= !empty($row['linked_user_id']) ? 'وصل به ' . e((string) ($row['linked_name'] ?: 'حساب')) : 'هنوز حساب ندارد' ?></td>
            <td><?= e((string) ($row['note'] ?? '')) ?></td>
            <td>
              <form method="post" action="<?= e(url('/secretary/patients')) ?>" onsubmit="return confirm('این شماره از فهرست حذف شود؟');">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete_inactive">
                <input type="hidden" name="id" value="<?= e((string) $row['id']) ?>">
                <button class="btn btn-outline btn-sm" type="submit">حذف</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php
$inner = ob_get_clean();
$pageScripts = '
<script src="' . e(url('/assets/js/search-select.js')) . '?v=20260906p"></script>
<script src="' . e(url('/assets/js/name-transliterate.js')) . '?v=20260906p"></script>
<script src="' . e(url('/assets/js/form-draft.js')) . '?v=20260906p"></script>
<script src="' . e(url('/assets/js/secretary-patient-form.js')) . '?v=20261007ref"></script>
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

  var activePanel = document.getElementById("patients-active");
  var inactivePanel = document.getElementById("patients-inactive");
  var kindButtons = document.querySelectorAll("[data-patient-kind]");
  function showKind(kind){
    if (activePanel) activePanel.hidden = kind !== "active";
    if (inactivePanel) inactivePanel.hidden = kind !== "inactive";
    kindButtons.forEach(function(btn){
      var on = btn.getAttribute("data-patient-kind") === kind;
      btn.classList.toggle("btn-primary", on);
      btn.classList.toggle("btn-outline", !on);
      btn.setAttribute("aria-selected", on ? "true" : "false");
    });
  }
  kindButtons.forEach(function(btn){
    btn.addEventListener("click", function(){
      showKind(btn.getAttribute("data-patient-kind") || "active");
    });
  });

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
      row.classList.toggle("is-search-hidden", !on);
      row.hidden = !on;
      if (on) shown++;
    });
    if (empty) empty.hidden = shown > 0 || rows.length === 0;
  });
})();
</script>
';
render_secretary_page('مراجعه‌کنندگان', $inner);
