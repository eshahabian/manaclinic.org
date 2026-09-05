<?php
declare(strict_types=1);

/** @var array $intakeMonthGroups */
/** @var string $intakeMonthDefault */
/** @var string $intakeMonthEmpty */
/** @var string $intakeMonthAria */

$intakeMonthGroups = $intakeMonthGroups ?? [];
$intakeMonthDefault = (string) ($intakeMonthDefault ?? '');
$intakeMonthEmpty = $intakeMonthEmpty ?? 'هنوز گفتگویی از دستیار نیست.';
$intakeMonthAria = $intakeMonthAria ?? 'ماه گفتگوهای دستیار';

if ($intakeMonthDefault === '' && $intakeMonthGroups) {
    $intakeMonthDefault = (string) array_key_first($intakeMonthGroups);
}
$defaultTone = (string) (($intakeMonthGroups[$intakeMonthDefault]['tone'] ?? 'workshops'));
?>
<?php if (!$intakeMonthGroups): ?>
  <p class="muted" style="margin:0"><?= e($intakeMonthEmpty) ?></p>
<?php else: ?>
  <div class="binder-tile binder-tile--nested" data-binder-tabs data-binder-hash="0" data-binder-initial="<?= e($intakeMonthDefault) ?>" data-binder-tone="<?= e($defaultTone) ?>">
    <div class="binder-tabs" role="tablist" aria-label="<?= e($intakeMonthAria) ?>">
      <?php foreach ($intakeMonthGroups as $id => $bucket): ?>
        <button type="button"
          class="binder-tab <?= e((string) ($bucket['class'] ?? 'binder-tab-workshops')) ?><?= $intakeMonthDefault === $id ? ' is-active' : '' ?>"
          role="tab"
          data-binder-tab="<?= e((string) $id) ?>"
          data-binder-tone="<?= e((string) ($bucket['tone'] ?? 'workshops')) ?>"
          aria-selected="<?= $intakeMonthDefault === $id ? 'true' : 'false' ?>">
          <?= e((string) ($bucket['tab_label'] ?? $bucket['short'] ?? $id)) ?>
          <span class="binder-tab-count"><?= count($bucket['items'] ?? []) ?></span>
        </button>
      <?php endforeach; ?>
    </div>
    <div class="binder-body">
      <?php foreach ($intakeMonthGroups as $id => $bucket): ?>
        <section class="binder-panel<?= $intakeMonthDefault === $id ? ' is-active' : '' ?>" data-binder-panel="<?= e((string) $id) ?>" role="tabpanel"<?= $intakeMonthDefault === $id ? '' : ' hidden' ?>>
          <h2 class="binder-sub" style="margin-top:0"><?= e((string) ($bucket['label'] ?? '')) ?></h2>
          <div class="clinical-session-grid intake-day-grid" data-session-note-grid>
            <?php foreach (($bucket['items'] ?? []) as $row): ?>
              <?php
                $when = (string) (($row['sent_at'] ?? '') ?: ($row['created_at'] ?? ''));
                $day = jalali_day_parts($when);
                $guest = empty($row['patient_id']);
                $who = $guest ? 'مراجعه‌کننده مهمان' : (string) ($row['patient_name'] ?? 'مراجعه‌کننده');
                $title = $who . ' با دستیار گفتگو کرده';
                $summary = trim((string) ($row['ai_summary'] ?? ''));
                if ($summary === '') {
                    $summary = mb_substr((string) ($row['intake_text'] ?? ''), 0, 220);
                }
              ?>
              <div class="session-note-box intake-day-box" data-box>
                <button type="button" class="session-note-toggle" data-toggle>
                  <span class="sn-date"><?= e($day['label'] ?? format_fa_datetime($when)) ?></span>
                  <span class="sn-title"><?= e($title) ?></span>
                  <span class="sn-meta"><?= $day ? 'ساعت ' . e($day['time_fa']) : e(format_fa_datetime($when)) ?></span>
                </button>
                <div class="session-note-panel" data-panel>
                  <p style="margin:0 0 .65rem;font-weight:600"><?= e($title) ?></p>
                  <p class="muted" style="margin:0 0 .75rem;font-size:.85rem"><?= e(format_fa_datetime($when)) ?></p>
                  <?php if ($summary !== ''): ?>
                    <p style="margin:0 0 .85rem;line-height:1.75;font-size:.9rem"><?= e($summary) ?><?= mb_strlen($summary) >= 220 ? '…' : '' ?></p>
                  <?php endif; ?>
                  <div style="display:flex;gap:.5rem;flex-wrap:wrap">
                    <a class="btn btn-primary btn-sm" href="<?= e(url('/doctor/intakes/' . $row['id'])) ?>">مشاهده کامل گفتگو</a>
                    <button class="btn btn-outline btn-sm" type="button" data-close>بستن</button>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </section>
      <?php endforeach; ?>
    </div>
  </div>
<?php endif; ?>
