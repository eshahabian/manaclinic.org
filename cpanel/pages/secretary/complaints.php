<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/secretary_panel.php';
require_once __DIR__ . '/../../includes/secretary_patient.php';
require_once __DIR__ . '/../../includes/session_complaints.php';

require_login(['SECRETARY']);

$patients = [];
$doctors = [];
$complaints = [];
try {
    $patients = secretary_bookable_patients($pdo);
} catch (Throwable $e) {
    $patients = [];
}
try {
    $doctors = secretary_active_doctors($pdo);
} catch (Throwable $e) {
    $doctors = [];
}
try {
    $complaints = session_complaint_list($pdo);
} catch (Throwable $e) {
    $complaints = [];
}

ob_start();
?>
<h1>شکایت از درمانگر</h1>
<p class="muted">اگر مراجع بعد از جلسه از درمانگر شکایت داشت، این فرم را پر کنید.</p>

<div class="panel stack" style="margin-top:1rem">
  <form class="form-stack" method="post" action="<?= e(url('/secretary/complaints')) ?>">
    <?= csrf_field() ?>
    <div>
      <label class="label" for="complaint-patient">اسم مراجعه‌کننده</label>
      <select class="input" id="complaint-patient" name="patient_id" required>
        <option value="">انتخاب مراجعه‌کننده</option>
        <?php foreach ($patients as $row): ?>
          <?php
            $pname = trim((string) ($row['name'] ?? ''));
            $uname = trim((string) ($row['username'] ?? ''));
            $phone = trim((string) ($row['phone'] ?? ''));
            $label = $pname !== '' ? $pname : $uname;
            if ($uname !== '' && $uname !== $label) {
                $label .= ' (' . $uname . ')';
            }
            $search = trim($pname . ' ' . $uname . ' ' . $phone);
          ?>
          <option value="<?= e((string) $row['id']) ?>" data-search="<?= e($search) ?>"><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label class="label" for="complaint-doctor">درمانگر</label>
      <select class="input" id="complaint-doctor" name="doctor_id" required>
        <option value="">انتخاب درمانگر</option>
        <?php foreach ($doctors as $row): ?>
          <?php
            $dname = trim((string) ($row['name'] ?? ''));
            $spec = trim((string) ($row['specialty'] ?? ''));
            $dlabel = $dname !== '' ? $dname : 'درمانگر';
            if ($spec !== '') {
                $dlabel .= ' — ' . $spec;
            }
          ?>
          <option value="<?= e((string) $row['id']) ?>" data-search="<?= e(trim($dname . ' ' . $spec)) ?>"><?= e($dlabel) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label class="label" for="complaint-session-date">زمان انجام جلسه</label>
      <div style="display:grid;grid-template-columns:minmax(0,1fr) 8.5rem;gap:.5rem">
        <input class="input" id="complaint-session-date" name="session_date" type="text" data-jdp data-jdp-only-date autocomplete="off" readonly required placeholder="تاریخ شمسی" style="cursor:pointer">
        <input class="input" id="complaint-session-time" name="session_time" type="time" required dir="ltr" aria-label="ساعت جلسه">
      </div>
    </div>
    <div>
      <label class="label" for="complaint-reason">علت</label>
      <select class="input" id="complaint-reason" name="reason" required>
        <option value="">انتخاب علت</option>
        <?php foreach (session_complaint_reasons() as $reason): ?>
          <option value="<?= e($reason) ?>"><?= e($reason) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label class="label" for="complaint-body">توضیحات بیشتر</label>
      <textarea class="input" id="complaint-body" name="body" rows="5" required placeholder="جزئیات را بنویسید"></textarea>
    </div>
    <button class="btn btn-primary" type="submit">ثبت شکایت</button>
  </form>
</div>

<div class="stack" style="margin-top:1.25rem">
  <h2 style="margin:0;font-size:1.05rem">شکایت‌های ثبت‌شده</h2>
  <?php if (!$complaints): ?>
    <p class="muted">هنوز شکایتی نوشته نشده است.</p>
  <?php else: ?>
    <?php foreach ($complaints as $row): ?>
      <article class="panel" style="margin-top:.75rem">
        <strong><?= e((string) ($row['patient_name'] ?? '')) ?></strong>
        <span class="muted"> · <?= e((string) ($row['doctor_name'] ?? '')) ?></span>
        <p class="muted" style="margin:.35rem 0 0;font-size:.85rem">
          <?php if (!empty($row['session_at'])): ?>
            جلسه <?= e(format_fa_datetime((string) $row['session_at'])) ?> ·
          <?php endif; ?>
          <?php if (trim((string) ($row['reason'] ?? '')) !== ''): ?>
            <?= e((string) $row['reason']) ?> ·
          <?php endif; ?>
          ثبت <?= e(format_fa_datetime((string) $row['created_at'])) ?>
        </p>
        <p style="margin:.6rem 0 0;line-height:1.8;white-space:pre-wrap"><?= e((string) $row['body']) ?></p>
      </article>
    <?php endforeach; ?>
  <?php endif; ?>
</div>
<?php
$GLOBALS['pageHead'] = '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@majidh1/jalalidatepicker/dist/jalalidatepicker.min.css">';
$GLOBALS['pageScripts'] = '<script src="' . e(url('/assets/js/search-select.js')) . '?v=20261007cmp"></script>'
    . '<script src="https://cdn.jsdelivr.net/npm/@majidh1/jalalidatepicker/dist/jalalidatepicker.min.js"></script>'
    . '<script>
(function(){
  ["complaint-patient","complaint-doctor","complaint-reason"].forEach(function(id){
    var el = document.getElementById(id);
    if (el && window.enhanceSearchSelect) enhanceSearchSelect(el);
  });
  if (!window.jalaliDatepicker) return;
  jalaliDatepicker.startWatch({
    selector: "#complaint-session-date",
    time: false,
    hideAfterChange: true,
    showTodayBtn: true,
    showEmptyBtn: true,
    autoReadOnlyInput: true,
    persianDigits: true,
    zIndex: 100000,
    container: "body"
  });
})();
</script>';
render_secretary_page('شکایت از درمانگر', ob_get_clean());
