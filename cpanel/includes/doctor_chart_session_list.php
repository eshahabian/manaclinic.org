<?php
declare(strict_types=1);

/**
 * لیست جلسات با شرح حال SOAP تفکیک‌شده بر اساس تاریخ/ساعت نوبت.
 *
 * @var list<array<string,mixed>> $sessionList
 * @var array<string, array<string,mixed>> $notesByApp
 * @var string $patientId
 * @var string $sessionListNextTab chart|sessions
 * @var string $sessionListEmpty
 */

$sessionList = $sessionList ?? [];
$notesByApp = $notesByApp ?? [];
$patientId = (string) ($patientId ?? '');
$sessionListNextTab = in_array(($sessionListNextTab ?? 'chart'), ['chart', 'sessions'], true)
    ? (string) $sessionListNextTab
    : 'chart';
$sessionListEmpty = (string) ($sessionListEmpty ?? 'هنوز نوبتی برای این مراجع ثبت نشده است.');

if ($sessionList === []) {
    echo '<p class="muted" style="margin:0">' . e($sessionListEmpty) . '</p>';
    return;
}

$today = date('Y-m-d');
?>
<div class="chart-session-list" data-session-note-grid>
  <?php foreach ($sessionList as $a): ?>
    <?php
      if (!is_array($a)) {
          continue;
      }
      $appId = (string) ($a['id'] ?? '');
      if ($appId === '') {
          continue;
      }
      $note = $notesByApp[$appId] ?? null;
      $hasNote = session_note_has_content(is_array($note) ? $note : null);
      $day = jalali_day_parts((string) ($a['starts_at'] ?? ''));
      $ymd = substr(str_replace('T', ' ', (string) ($a['starts_at'] ?? '')), 0, 10);
      $isToday = $ymd === $today;
      $dateLabel = (string) ($day['label'] ?? format_fa_datetime((string) ($a['starts_at'] ?? '')));
      $timeLabel = !empty($day['time_fa']) ? ('ساعت ' . (string) $day['time_fa']) : '';
    ?>
    <article class="chart-session-card<?= $hasNote ? ' has-note' : '' ?><?= $isToday ? ' is-today' : '' ?>" id="session-<?= e($appId) ?>" data-box>
      <button type="button" class="chart-session-toggle" data-toggle>
        <span class="chart-session-when">
          <strong><?= e($dateLabel) ?></strong>
          <?php if ($timeLabel !== ''): ?>
            <span class="muted"> · <?= e($timeLabel) ?></span>
          <?php endif; ?>
          <?php if ($isToday): ?>
            <span class="chart-session-today">امروز</span>
          <?php endif; ?>
        </span>
        <span class="chart-session-meta muted">
          <?= e(appointment_row_status_label($a)) ?>
          · <?= $hasNote ? 'دارای شرح حال' : 'بدون شرح حال' ?>
        </span>
      </button>
      <div class="chart-session-panel" data-panel>
        <form method="post" action="<?= e(url('/doctor/patients/' . $patientId . '/session-note')) ?>" class="form-stack" style="gap:.75rem">
          <input type="hidden" name="appointment_id" value="<?= e($appId) ?>">
          <input type="hidden" name="next_tab" value="<?= e($sessionListNextTab) ?>">
          <?= chart_soap_rich_editor_html(
              is_array($note) ? $note : [],
              [
                  'id_prefix' => 'session-soap-' . $appId,
                  'fields' => session_soap_fields(),
              ]
          ) ?>
          <div>
            <label class="label" for="pn-<?= e($appId) ?>">نوت کوتاه برای مراجع (در پروفایلش می‌بیند)</label>
            <textarea class="input" id="pn-<?= e($appId) ?>" name="note_text" rows="2" placeholder="اختیاری…" data-emoji-field><?= e((string) ($note['note_text'] ?? '')) ?></textarea>
          </div>
          <div style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center">
            <button class="btn btn-primary btn-sm" type="submit">ذخیره شرح حال این جلسه</button>
            <button class="btn btn-outline btn-sm" type="button" data-close>بستن</button>
          </div>
        </form>
      </div>
    </article>
  <?php endforeach; ?>
</div>
