<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/doctor_panel.php';
require_once __DIR__ . '/../../includes/doctor_clinical.php';
require_once __DIR__ . '/../../includes/assistant.php';

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
$historyExtraIds = extract_assistant_session_ids_from_history((string) ($chart['history_text'] ?? ''));
$historyClean = doctor_chart_detach_assistant_history($pdo, $chart, $patientId);
$historyHtml = history_html_for_editor($historyClean);

$notesStmt = $pdo->prepare('SELECT * FROM doctor_session_notes WHERE doctor_id=? AND patient_id=?');
$notesStmt->execute([$doctorId, $patientId]);
$notesByApp = [];
foreach ($notesStmt->fetchAll() as $n) {
    $notesByApp[$n['appointment_id']] = $n;
}

$monthPack = doctor_session_month_groups($appointments);
$monthGroups = $monthPack['months'];
$defaultMonthId = $monthPack['default_id'];
$intakes = doctor_patient_assistant_sessions($pdo, $patientId, $historyExtraIds);
$intakeMonthPack = doctor_intake_month_groups($intakes);
$intakeMonthGroups = $intakeMonthPack['months'];
$intakeMonthDefault = $intakeMonthPack['default_id'];

$tabParam = trim((string) ($_GET['tab'] ?? ''));
$binderInitial = in_array($tabParam, ['chart', 'intakes'], true) ? $tabParam : 'chart';

ob_start();
?>
<p style="margin:0 0 .75rem"><a href="<?= e(url('/doctor/patients')) ?>" class="muted" style="font-size:.9rem">← بازگشت به لیست مراجعه‌کنندگان</a></p>
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

<div class="binder-tile" data-binder-tabs data-binder-initial="<?= e($binderInitial) ?>" data-binder-tone="<?= e($binderInitial === 'intakes' ? 'workshops' : 'appts') ?>">
  <div class="binder-tabs" role="tablist" aria-label="بخش‌های پرونده">
    <button type="button" class="binder-tab binder-tab-appts<?= $binderInitial === 'chart' ? ' is-active' : '' ?>" role="tab" data-binder-tab="chart" data-binder-tone="appts" aria-selected="<?= $binderInitial === 'chart' ? 'true' : 'false' ?>">
      پرونده مراجعه‌کننده
    </button>
    <button type="button" class="binder-tab binder-tab-workshops<?= $binderInitial === 'intakes' ? ' is-active' : '' ?>" role="tab" data-binder-tab="intakes" data-binder-tone="workshops" aria-selected="<?= $binderInitial === 'intakes' ? 'true' : 'false' ?>">
      گفتگوهای دستیار <span class="binder-tab-count"><?= count($intakes) ?></span>
    </button>
  </div>
  <div class="binder-body">
    <section class="binder-panel<?= $binderInitial === 'chart' ? ' is-active' : '' ?>" data-binder-panel="chart" role="tabpanel"<?= $binderInitial === 'chart' ? '' : ' hidden' ?>>
      <div class="clinical-board">
        <div>
          <h2 style="margin:0;font-size:1.1rem">شرح حال</h2>
          <p class="muted" style="margin:.35rem 0 0;font-size:.85rem">
            متن را انتخاب کنید، بعد Bold / سایز / رنگ هایلایت بزنید. یادداشت جلسات را از تب ماه انتخاب کنید.
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
            data-placeholder="شرح حال مراجعه‌کننده را اینجا بنویسید..."
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
          <p class="muted" style="margin:.25rem 0 .75rem;font-size:.8rem">ماه را انتخاب کنید؛ بعد روی روز مراجعه کلیک کنید تا یادداشت را ببینید یا بنویسید.</p>
          <?php if (!$monthGroups): ?>
            <p class="muted" style="margin:0">هنوز جلسه‌ای ثبت نشده.</p>
          <?php else: ?>
            <div class="binder-tile binder-tile--nested" data-binder-tabs data-binder-hash="0" data-binder-initial="<?= e($defaultMonthId) ?>" data-binder-tone="<?= e((string) ($monthGroups[$defaultMonthId]['tone'] ?? 'in-person')) ?>">
              <div class="binder-tabs" role="tablist" aria-label="ماه جلسات">
                <?php foreach ($monthGroups as $id => $bucket): ?>
                  <button type="button"
                    class="binder-tab <?= e((string) ($bucket['class'] ?? 'binder-tab-in-person')) ?><?= $defaultMonthId === $id ? ' is-active' : '' ?>"
                    role="tab"
                    data-binder-tab="<?= e((string) $id) ?>"
                    data-binder-tone="<?= e((string) ($bucket['tone'] ?? 'in-person')) ?>"
                    aria-selected="<?= $defaultMonthId === $id ? 'true' : 'false' ?>">
                    <?= e((string) ($bucket['tab_label'] ?? $bucket['short'] ?? $id)) ?>
                    <span class="binder-tab-count"><?= count($bucket['items'] ?? []) ?></span>
                  </button>
                <?php endforeach; ?>
              </div>
              <div class="binder-body">
                <?php foreach ($monthGroups as $id => $bucket): ?>
                  <section class="binder-panel<?= $defaultMonthId === $id ? ' is-active' : '' ?>" data-binder-panel="<?= e((string) $id) ?>" role="tabpanel"<?= $defaultMonthId === $id ? '' : ' hidden' ?>>
                    <h2 class="binder-sub" style="margin-top:0"><?= e((string) ($bucket['label'] ?? '')) ?></h2>
                    <div class="clinical-session-grid" data-session-note-grid>
                      <?php foreach (($bucket['items'] ?? []) as $a): ?>
                        <?php
                          $note = $notesByApp[$a['id']] ?? null;
                          $hasNote = $note && trim((string) $note['note_text']) !== '';
                          $day = jalali_day_parts((string) $a['starts_at']);
                        ?>
                        <div class="session-note-box<?= $hasNote ? ' has-note' : '' ?>" data-box>
                          <button type="button" class="session-note-toggle" data-toggle>
                            <span class="sn-date"><?= e($day['label'] ?? format_fa_datetime((string) $a['starts_at'])) ?></span>
                            <span class="sn-meta">
                              <?= $day ? 'ساعت ' . e($day['time_fa']) . ' · ' : '' ?>
                              <?= e(appointment_status_label($a['status'])) ?>
                              · <?= $hasNote ? 'دارای یادداشت' : 'بدون یادداشت' ?>
                            </span>
                          </button>
                          <div class="session-note-panel" data-panel>
                            <form method="post" action="<?= e(url('/doctor/patients/' . $patientId . '/session-note')) ?>">
                              <input type="hidden" name="appointment_id" value="<?= e($a['id']) ?>">
                              <label class="label">یادداشت این جلسه</label>
                              <textarea class="input" name="note_text" rows="5" placeholder="مشاهدات، مداخلات، تکالیف..."><?= e((string) ($note['note_text'] ?? '')) ?></textarea>
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
                      <?php if (empty($bucket['items'])): ?>
                        <p class="muted" style="margin:0">در این ماه مراجعه‌ای ثبت نشده.</p>
                      <?php endif; ?>
                    </div>
                  </section>
                <?php endforeach; ?>
              </div>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </section>
    <section class="binder-panel<?= $binderInitial === 'intakes' ? ' is-active' : '' ?>" data-binder-panel="intakes" role="tabpanel"<?= $binderInitial === 'intakes' ? '' : ' hidden' ?>>
      <p class="muted" style="margin:0 0 .85rem;font-size:.9rem">گفتگوها ماه‌به‌ماه جدا شده‌اند. روی تاریخ بزنید تا ببینید <?= e($patient['name']) ?> کی با دستیار حرف زده است.</p>
      <?php
        $intakeMonthEmpty = 'هنوز گفتگویی از دستیار برای این مراجعه‌کننده ارسال نشده است.';
        require __DIR__ . '/../../includes/doctor_intake_month_binder.php';
      ?>
    </section>
  </div>
</div>
<?php
$inner = ob_get_clean();

$pageScripts = '<script src="' . e(url('/assets/js/binder-tabs.js')) . '?v=20260905c"></script>
<script src="' . e(url('/assets/js/rich-editor.js')) . '"></script>
<script>
initRichEditor({
  editor: "#clinical-editor",
  toolbar: "#clinical-toolbar",
  form: "#history-form",
  hidden: "#history_text"
});
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
</script>
';

render_doctor_page('پرونده ' . $patient['name'], $inner);
