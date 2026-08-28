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

$notesStmt = $pdo->prepare('SELECT * FROM doctor_session_notes WHERE doctor_id=? AND patient_id=?');
$notesStmt->execute([$doctorId, $patientId]);
$notesByApp = [];
foreach ($notesStmt->fetchAll() as $n) {
    $notesByApp[$n['appointment_id']] = $n;
}

$hlStmt = $pdo->prepare('SELECT * FROM doctor_highlights WHERE doctor_id=? AND patient_id=? ORDER BY created_at DESC');
$hlStmt->execute([$doctorId, $patientId]);
$highlights = $hlStmt->fetchAll();

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

<section class="panel form-stack" style="margin-bottom:1.25rem">
  <h2 style="margin:0;font-size:1.05rem">شرح حال</h2>
  <p class="muted" style="margin:0;font-size:.85rem">تاریخچه کلی، تشخیص‌های احتمالی، سوابق و هر چیزی که فقط خودتان باید ببینید.</p>
  <form method="post" action="<?= e(url('/doctor/patients/' . $patientId . '/history')) ?>">
    <textarea class="input" name="history_text" rows="8" placeholder="شرح حال بیمار را اینجا بنویسید..."><?= e((string)($chart['history_text'] ?? '')) ?></textarea>
    <div style="margin-top:.75rem;display:flex;gap:.5rem;flex-wrap:wrap;align-items:center">
      <button class="btn btn-primary" type="submit">ذخیره شرح حال</button>
      <?php if (!empty($chart['updated_at'])): ?>
        <span class="muted" style="font-size:.8rem">آخرین ویرایش: <?= e(format_fa_datetime($chart['updated_at'])) ?></span>
      <?php endif; ?>
    </div>
  </form>
</section>

<section class="panel stack" style="margin-bottom:1.25rem">
  <div>
    <h2 style="margin:0;font-size:1.05rem">هایلایت‌ها و نکات مهم</h2>
    <p class="muted" style="margin:.35rem 0 0;font-size:.85rem">متن‌های مهم را اینجا نگه دارید تا سریع پیدا شوند.</p>
  </div>

  <?php if ($highlights): ?>
    <div class="stack">
      <?php foreach ($highlights as $h): ?>
        <div class="clinical-highlight clinical-highlight-<?= e($h['color']) ?>">
          <div class="clinical-highlight-excerpt"><?= e($h['excerpt']) ?></div>
          <?php if ($h['remark']): ?>
            <div class="muted" style="font-size:.85rem;margin-top:.35rem"><?= e($h['remark']) ?></div>
          <?php endif; ?>
          <div class="row-between" style="margin-top:.5rem;align-items:center">
            <span class="muted" style="font-size:.75rem"><?= e(format_fa_datetime($h['created_at'])) ?></span>
            <form method="post" action="<?= e(url('/doctor/patients/' . $patientId . '/highlight')) ?>" onsubmit="return confirm('این هایلایت حذف شود؟');">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= e($h['id']) ?>">
              <button class="btn btn-danger btn-sm" type="submit">حذف</button>
            </form>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php else: ?>
    <p class="muted" style="margin:0;font-size:.9rem">هنوز هایلایتی ثبت نشده.</p>
  <?php endif; ?>

  <form class="form-stack" method="post" action="<?= e(url('/doctor/patients/' . $patientId . '/highlight')) ?>" style="border-top:1px dashed var(--line);padding-top:1rem">
    <input type="hidden" name="action" value="create">
    <div>
      <label class="label">متن هایلایت</label>
      <textarea class="input" name="excerpt" rows="3" required placeholder="متن مهم را اینجا بچسبانید یا بنویسید..."></textarea>
    </div>
    <div>
      <label class="label">یادداشت روی هایلایت (اختیاری)</label>
      <input class="input" name="remark" placeholder="مثلاً: پیگیری در جلسه بعد">
    </div>
    <div>
      <label class="label">رنگ</label>
      <select class="input" name="color">
        <option value="yellow">زرد</option>
        <option value="green">سبز</option>
        <option value="pink">صورتی</option>
        <option value="blue">آبی</option>
      </select>
    </div>
    <button class="btn btn-outline" type="submit">افزودن هایلایت</button>
  </form>
</section>

<section class="stack">
  <h2 style="margin:0;font-size:1.05rem">جلسات و یادداشت‌ها</h2>
  <p class="muted" style="margin:0;font-size:.85rem">برای هر نوبت می‌توانید نظر و کامنت خصوصی بنویسید.</p>

  <?php foreach ($appointments as $a): ?>
    <?php $note = $notesByApp[$a['id']] ?? null; ?>
    <div class="panel form-stack">
      <div class="row-between">
        <div>
          <strong><?= e(format_fa_datetime($a['starts_at'])) ?></strong>
          <div style="margin-top:.35rem"><span class="badge"><?= e(appointment_status_label($a['status'])) ?></span></div>
        </div>
      </div>
      <?php if ($a['notes']): ?>
        <p class="muted" style="margin:0;font-size:.85rem">یادداشت رزرو: <?= e($a['notes']) ?></p>
      <?php endif; ?>
      <form method="post" action="<?= e(url('/doctor/patients/' . $patientId . '/session-note')) ?>">
        <input type="hidden" name="appointment_id" value="<?= e($a['id']) ?>">
        <label class="label">یادداشت / نظر شما درباره این جلسه</label>
        <textarea class="input" name="note_text" rows="4" placeholder="مشاهدات جلسه، مداخلات، تکالیف، برنامه جلسه بعد..."><?= e((string)($note['note_text'] ?? '')) ?></textarea>
        <div style="margin-top:.75rem;display:flex;gap:.5rem;flex-wrap:wrap;align-items:center">
          <button class="btn btn-primary btn-sm" type="submit"><?= $note ? 'به‌روزرسانی یادداشت' : 'ذخیره یادداشت' ?></button>
          <?php if ($note): ?>
            <span class="muted" style="font-size:.8rem">آخرین ویرایش: <?= e(format_fa_datetime($note['updated_at'])) ?></span>
          <?php endif; ?>
        </div>
      </form>
    </div>
  <?php endforeach; ?>
  <?php if (!$appointments): ?>
    <p class="muted">نوبتی ثبت نشده.</p>
  <?php endif; ?>
</section>
<?php
render_doctor_page('پرونده ' . $patient['name'], ob_get_clean());
