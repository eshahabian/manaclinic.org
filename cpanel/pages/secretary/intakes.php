<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/assistant.php';
require_once __DIR__ . '/../../includes/secretary_panel.php';

$user = require_login(['SECRETARY']);
ensure_assistant_schema($pdo);

$rows = $pdo->query("
  SELECT s.*, u.name AS patient_name, u.phone AS patient_phone, du.name AS doctor_name
  FROM assistant_sessions s
  LEFT JOIN users u ON u.id = s.patient_id
  LEFT JOIN doctor_profiles dp ON dp.id = s.selected_doctor_id
  LEFT JOIN users du ON du.id = dp.user_id
  WHERE s.status = 'SENT'
  ORDER BY s.sent_at DESC, s.created_at DESC
  LIMIT 80
")->fetchAll();

ob_start();
?>
<h1>گفتگوهای دستیار</h1>
<p class="muted">خلاصه‌های ارسال‌شده از «با من حرف بزن» — می‌توانید به درمانگر ارجاع دهید.</p>

<div class="panel stack" style="margin-top:1.25rem">
  <?php if (!$rows): ?>
    <p class="muted">موردی برای ارجاع نیست.</p>
  <?php endif; ?>
  <?php foreach ($rows as $row): ?>
    <?php
      $assigned = !empty($row['assigned_at']);
      $summary = trim((string) ($row['ai_summary'] ?? ''));
      if ($summary === '') {
          $summary = mb_substr((string) ($row['intake_text'] ?? ''), 0, 180);
      }
    ?>
    <div class="row-between" style="border:1px solid var(--line);border-radius:.75rem;padding:.85rem;gap:1rem;align-items:flex-start">
      <div style="flex:1;min-width:0">
        <strong><?= e($row['patient_name'] ?: 'مراجع') ?></strong>
        <?php if (!empty($row['patient_phone'])): ?>
          <span class="muted" style="font-size:.85rem"> · <?= e($row['patient_phone']) ?></span>
        <?php endif; ?>
        <div class="muted" style="font-size:.85rem;margin-top:.25rem">
          <?= e($row['sent_at'] ? format_fa_datetime($row['sent_at']) : '') ?>
          <?php if ($assigned): ?>
            · <span style="color:var(--primary)">ارجاع به <?= e($row['doctor_name'] ?: 'درمانگر') ?></span>
          <?php else: ?>
            · در انتظار ارجاع
            <?php if ($row['doctor_name']): ?>
              · ترجیح مراجع: <?= e($row['doctor_name']) ?>
            <?php endif; ?>
          <?php endif; ?>
        </div>
        <p style="margin:.55rem 0 0;line-height:1.8;font-size:.92rem"><?= e($summary) ?></p>
      </div>
      <a class="btn btn-outline" href="<?= e(url('/secretary/intakes/' . $row['id'])) ?>">مشاهده / ارجاع</a>
    </div>
  <?php endforeach; ?>
</div>
<?php
render_secretary_page('گفتگوهای دستیار', ob_get_clean());
