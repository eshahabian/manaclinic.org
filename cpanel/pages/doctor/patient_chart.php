<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/doctor_panel.php';
require_once __DIR__ . '/../../includes/doctor_clinical.php';

$ctx = require_doctor_profile($pdo);
$patientId = (string) ($_GET['id'] ?? '');
if ($patientId === '') {
    redirect('/doctor/patients');
}

$access = require_doctor_patient_access($pdo, $ctx, $patientId);
$patient = $access['patient'];
$appointments = $access['appointments'];
$doctorId = $ctx['profile']['id'];

$chart = get_or_create_patient_chart($pdo, $doctorId, $patientId);
$historyHtml = history_html_for_editor($chart['history_text'] ?? '');

$notesStmt = $pdo->prepare('SELECT * FROM doctor_session_notes WHERE doctor_id=? AND patient_id=?');
$notesStmt->execute([$doctorId, $patientId]);
$notesByApp = [];
foreach ($notesStmt->fetchAll() as $n) {
    $notesByApp[$n['appointment_id']] = $n;
}

ob_start();
?>
<p style="margin:0 0 .75rem"><a href="<?= e(url('/doctor/patients')) ?>" class="muted" style="font-size:.9rem">← بازگشت به لیست بیماران</a></p>
<div class="row-between" style="align-items:flex-start;margin-bottom:1rem">
  <div>
    <h1 style="margin:0"><?= e($patient['name']) ?></h1>
    <p class="muted" style="margin:.35rem 0 0;font-size:.9rem" dir="ltr">
      <?= e((string)$patient['username']) ?>
      <?= $patient['phone'] ? ' · ' . e((string)$patient['phone']) : '' ?>
    </p>
  </div>
  <span class="badge">محرمانه — فقط شما</span>
</div>

<section class="panel clinical-board">
  <div>
    <h2 style="margin:0;font-size:1.1rem">شرح حال</h2>
    <p class="muted" style="margin:.35rem 0 0;font-size:.85rem">
      متن را انتخاب کنید، بعد Bold / سایز / رنگ هایلایت بزنید. یادداشت جلسات پایین همین کادر هستند.
    </p>
  </div>

  <form method="post" action="<?= e(url('/doctor/patients/' . $patientId . '/history')) ?>" id="history-form">
    <div class="clinical-toolbar" id="clinical-toolbar">
      <button type="button" class="tool-btn bold" data-cmd="bold" title="ضخیم">B</button>
      <span class="tool-sep"></span>
      <button type="button" class="tool-btn" data-fontsize="14">۱۴</button>
      <button type="button" class="tool-btn" data-fontsize="16">۱۶</button>
      <button type="button" class="tool-btn" data-fontsize="18">۱۸</button>
      <button type="button" class="tool-btn" data-fontsize="22">۲۲</button>
      <span class="tool-sep"></span>
      <span class="muted" style="font-size:.8rem;margin-inline-end:.25rem">هایلایت</span>
      <button type="button" class="swatch yellow" data-hl="#ffe566" title="زرد"></button>
      <button type="button" class="swatch green" data-hl="#8fd6a8" title="سبز"></button>
      <button type="button" class="swatch pink" data-hl="#f5a3c0" title="صورتی"></button>
      <button type="button" class="swatch blue" data-hl="#8eb7e8" title="آبی"></button>
      <button type="button" class="tool-btn" data-cmd="removeFormat" title="پاک کردن فرمت">پاک‌کردن رنگ</button>
    </div>

    <div
      id="clinical-editor"
      class="clinical-editor"
      contenteditable="true"
      role="textbox"
      aria-label="شرح حال"
      data-placeholder="شرح حال بیمار را اینجا بنویسید..."
    ><?= $historyHtml ?></div>
    <textarea name="history_text" id="history_text" hidden></textarea>

    <div style="margin-top:.85rem;display:flex;gap:.5rem;flex-wrap:wrap;align-items:center">
      <button class="btn btn-primary" type="submit">ذخیره شرح حال</button>
      <?php if (!empty($chart['updated_at'])): ?>
        <span class="muted" style="font-size:.8rem">آخرین ویرایش: <?= e(format_fa_datetime($chart['updated_at'])) ?></span>
      <?php endif; ?>
    </div>
  </form>

  <div>
    <p class="clinical-sessions-label">یادداشت جلسات</p>
    <p class="muted" style="margin:.25rem 0 .75rem;font-size:.8rem">روی هر تاریخ کلیک کنید تا باکس باز شود و یادداشت را ببینید یا بنویسید.</p>
    <div class="clinical-session-grid" id="session-note-grid">
      <?php foreach ($appointments as $a): ?>
        <?php
          $note = $notesByApp[$a['id']] ?? null;
          $hasNote = $note && trim((string)$note['note_text']) !== '';
        ?>
        <div class="session-note-box<?= $hasNote ? ' has-note' : '' ?>" data-box>
          <button type="button" class="session-note-toggle" data-toggle>
            <span class="sn-date"><?= e(format_fa_datetime($a['starts_at'])) ?></span>
            <span class="sn-meta">
              <?= e(appointment_status_label($a['status'])) ?>
              · <?= $hasNote ? 'دارای یادداشت' : 'بدون یادداشت' ?>
            </span>
          </button>
          <div class="session-note-panel" data-panel>
            <form method="post" action="<?= e(url('/doctor/patients/' . $patientId . '/session-note')) ?>">
              <input type="hidden" name="appointment_id" value="<?= e($a['id']) ?>">
              <label class="label">یادداشت این جلسه</label>
              <textarea class="input" name="note_text" rows="5" placeholder="مشاهدات، مداخلات، تکالیف..."><?= e((string)($note['note_text'] ?? '')) ?></textarea>
              <?php if ($a['notes']): ?>
                <p class="muted" style="font-size:.8rem;margin:.5rem 0 0">یادداشت رزرو: <?= e($a['notes']) ?></p>
              <?php endif; ?>
              <div style="margin-top:.75rem;display:flex;gap:.5rem;flex-wrap:wrap">
                <button class="btn btn-primary btn-sm" type="submit">ذخیره</button>
                <button class="btn btn-outline btn-sm" type="button" data-close>بستن</button>
              </div>
            </form>
          </div>
        </div>
      <?php endforeach; ?>
      <?php if (!$appointments): ?>
        <p class="muted" style="margin:0">هنوز جلسه‌ای ثبت نشده.</p>
      <?php endif; ?>
    </div>
  </div>
</section>
<?php
$inner = ob_get_clean();

$pageScripts = '
<script src="' . e(url('/assets/js/rich-editor.js')) . '"></script>
<script>
initRichEditor({
  editor: "#clinical-editor",
  toolbar: "#clinical-toolbar",
  form: "#history-form",
  hidden: "#history_text"
});
(function(){
  var grid = document.getElementById("session-note-grid");
  if (!grid) return;
  grid.addEventListener("click", function(e){
    var closeBtn = e.target.closest("[data-close]");
    var toggle = e.target.closest("[data-toggle]");
    var box = e.target.closest("[data-box]");
    if (closeBtn && box) {
      box.classList.remove("open");
      return;
    }
    if (toggle && box) {
      var wasOpen = box.classList.contains("open");
      grid.querySelectorAll("[data-box].open").forEach(function(b){ b.classList.remove("open"); });
      if (!wasOpen) box.classList.add("open");
    }
  });
  document.addEventListener("click", function(e){
    if (!e.target.closest("[data-box]")) {
      grid.querySelectorAll("[data-box].open").forEach(function(b){ b.classList.remove("open"); });
    }
  });
})();
</script>
';

render_doctor_page('پرونده ' . $patient['name'], $inner);
