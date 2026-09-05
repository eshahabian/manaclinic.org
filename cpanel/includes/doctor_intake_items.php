<?php
declare(strict_types=1);

/** @var array $intakeList */
/** @var string $intakeEmpty */

$intakeList = $intakeList ?? [];
$intakeEmpty = $intakeEmpty ?? 'هنوز گفتگویی از دستیار نیست.';
?>
<?php if (!$intakeList): ?>
  <p class="muted" style="margin:0"><?= e($intakeEmpty) ?></p>
<?php else: ?>
  <div class="intake-list" style="margin-top:0">
    <?php foreach ($intakeList as $row): ?>
      <?php
        $summary = trim((string) ($row['ai_summary'] ?? ''));
        if ($summary === '') {
            $summary = mb_substr((string) ($row['intake_text'] ?? ''), 0, 180);
        }
        $guest = empty($row['patient_id']);
        $who = $guest ? 'مراجعه‌کننده مهمان' : (string) ($row['patient_name'] ?? 'مراجعه‌کننده');
      ?>
      <article class="intake-item">
        <div class="intake-item-body">
          <strong><?= e($who) ?></strong>
          <?php if (!$guest && !empty($row['patient_phone'])): ?>
            <span class="muted"> · <?= e((string) $row['patient_phone']) ?></span>
          <?php endif; ?>
          <p class="muted intake-item-meta"><?= e(format_fa_datetime((string) ($row['sent_at'] ?? ''))) ?></p>
          <p class="intake-item-summary"><?= e($summary) ?><?= mb_strlen($summary) >= 180 ? '…' : '' ?></p>
        </div>
        <a class="btn btn-outline btn-sm intake-item-btn" href="<?= e(url('/doctor/intakes/' . $row['id'])) ?>">مشاهده کامل</a>
      </article>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
