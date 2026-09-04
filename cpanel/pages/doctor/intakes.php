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
  WHERE s.status = 'SENT' AND s.sent_at IS NOT NULL
  ORDER BY s.sent_at DESC
  LIMIT 100
")->fetchAll();

ob_start();
?>
<div class="panel">
  <h1>گفتگوهای دستیار</h1>
  <p class="muted">هر گفتگوی تکمیل‌شده به‌صورت خودکار برای همه درمانگران ارسال می‌شود.</p>
  <?php if (!$rows): ?>
    <p class="muted" style="margin-top:1rem">هنوز گفتگویی ارسال نشده است.</p>
  <?php else: ?>
    <div class="stack" style="margin-top:1rem;display:grid;gap:.75rem">
      <?php foreach ($rows as $row): ?>
        <?php
          $summary = trim((string) ($row['ai_summary'] ?? ''));
          if ($summary === '') {
              $summary = mb_substr((string) ($row['intake_text'] ?? ''), 0, 180);
          }
          $guest = empty($row['patient_id']);
        ?>
        <div class="panel" style="padding:1rem">
          <div style="display:flex;flex-wrap:wrap;gap:.5rem;justify-content:space-between;align-items:start">
            <div>
              <strong><?= e($guest ? 'مراجع مهمان' : ((string) ($row['patient_name'] ?? 'مراجع'))) ?></strong>
              <?php if (!$guest && !empty($row['patient_phone'])): ?>
                <span class="muted"> · <?= e((string) $row['patient_phone']) ?></span>
              <?php endif; ?>
              <p class="muted" style="margin:.35rem 0 0;font-size:.85rem"><?= e((string) ($row['sent_at'] ?? '')) ?></p>
              <p style="margin:.5rem 0 0;line-height:1.7"><?= e($summary) ?><?= mb_strlen($summary) >= 180 ? '…' : '' ?></p>
            </div>
            <a class="btn btn-outline" href="<?= e(url('/doctor/intakes/' . $row['id'])) ?>">مشاهده کامل</a>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
<?php
render_doctor_page('گفتگوهای دستیار', ob_get_clean());
