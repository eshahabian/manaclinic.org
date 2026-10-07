<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/secretary_panel.php';
require_once __DIR__ . '/../../includes/session_complaints.php';

require_login(['SECRETARY']);

try {
    $appointments = session_complaint_appointments($pdo);
    $complaints = session_complaint_list($pdo);
} catch (Throwable $e) {
    $appointments = [];
    $complaints = [];
}

ob_start();
?>
<h1>شکایت از درمانگر</h1>
<p class="muted">اگر مراجع بعد از جلسه از درمانگر شکایت داشت، همان جلسه را انتخاب کنید و حرف مراجع را بنویسید.</p>

<div class="panel stack" style="margin-top:1rem">
  <?php if (!$appointments): ?>
    <p class="muted">جلسه‌ای برای انتخاب نیست.</p>
  <?php else: ?>
    <form class="form-stack" method="post" action="<?= e(url('/secretary/complaints')) ?>">
      <?= csrf_field() ?>
      <div>
        <label class="label" for="complaint-appointment">جلسه</label>
        <select class="input" id="complaint-appointment" name="appointment_id" required>
          <option value="">انتخاب جلسه</option>
          <?php foreach ($appointments as $row): ?>
            <option value="<?= e((string) $row['id']) ?>">
              <?= e((string) $row['patient_name']) ?> — <?= e((string) $row['doctor_name']) ?> — <?= e(format_fa_datetime((string) $row['starts_at'])) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="label" for="complaint-body">متن شکایت</label>
        <textarea class="input" id="complaint-body" name="body" rows="5" required placeholder="مراجع چه گفت؟"></textarea>
      </div>
      <button class="btn btn-primary" type="submit">ثبت شکایت</button>
    </form>
  <?php endif; ?>
</div>

<div class="stack" style="margin-top:1.25rem">
  <h2 style="margin:0;font-size:1.05rem">شکایت‌های ثبت‌شده</h2>
  <?php if (!$complaints): ?>
    <p class="muted">هنوز شکایتی نوشته نشده است.</p>
  <?php else: ?>
    <?php foreach ($complaints as $row): ?>
      <article class="panel" style="margin-top:.75rem">
        <strong><?= e((string) $row['patient_name']) ?></strong>
        <span class="muted"> از <?= e((string) $row['doctor_name']) ?></span>
        <p class="muted" style="margin:.35rem 0 0;font-size:.85rem">
          جلسه <?= e(format_fa_datetime((string) $row['starts_at'])) ?>
          · ثبت <?= e(format_fa_datetime((string) $row['created_at'])) ?>
          · <?= e((string) $row['secretary_name']) ?>
        </p>
        <p style="margin:.6rem 0 0;line-height:1.8;white-space:pre-wrap"><?= e((string) $row['body']) ?></p>
      </article>
    <?php endforeach; ?>
  <?php endif; ?>
</div>
<?php
render_secretary_page('شکایت از درمانگر', ob_get_clean());
