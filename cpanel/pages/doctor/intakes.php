<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/doctor_panel.php';
require_once __DIR__ . '/../../includes/assistant.php';

$ctx = require_doctor_profile($pdo);
ensure_assistant_schema($pdo);

$rows = $pdo->query("
  SELECT s.*, u.name AS patient_name, u.phone AS patient_phone
  FROM assistant_sessions s
  LEFT JOIN users u ON u.id = s.patient_id
  WHERE s.status = 'SENT'
    AND s.sent_at IS NOT NULL
    AND (s.patient_id IS NULL OR s.patient_id = '')
  ORDER BY s.sent_at DESC
  LIMIT 100
")->fetchAll();

ob_start();
?>
<div class="panel">
  <p class="panel-back">
    <a class="btn btn-outline btn-sm" href="<?= e(url('/doctor')) ?>">بازگشت به پنل</a>
  </p>
  <h1>گفتگوهای دستیار</h1>
  <p class="muted">فقط گفتگوهای مهمان (بدون ورود) اینجا دیده می‌شود. گفتگوی مراجعه‌کننده ثبت‌نام‌شده در پرونده همان فرد است.</p>
  <?php if (!$rows): ?>
    <p class="muted" style="margin-top:1rem">هنوز گفتگویی ارسال نشده است.</p>
  <?php else: ?>
    <div class="intake-list">
      <?php foreach ($rows as $row): ?>
        <?php
          $summary = trim((string) ($row['ai_summary'] ?? ''));
          if ($summary === '') {
              $summary = mb_substr((string) ($row['intake_text'] ?? ''), 0, 180);
          }
        ?>
        <article class="intake-item">
          <div class="intake-item-body">
            <strong>مراجعه‌کننده مهمان</strong>
            <p class="muted intake-item-meta"><?= e(format_fa_datetime((string) ($row['sent_at'] ?? ''))) ?></p>
            <p class="intake-item-summary"><?= e($summary) ?><?= mb_strlen($summary) >= 180 ? '…' : '' ?></p>
          </div>
          <a class="btn btn-outline btn-sm intake-item-btn" href="<?= e(url('/doctor/intakes/' . $row['id'])) ?>">مشاهده کامل</a>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
<?php
render_doctor_page('گفتگوهای دستیار', ob_get_clean());
