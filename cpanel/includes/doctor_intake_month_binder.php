<?php
declare(strict_types=1);

/** @var array $intakeMonthGroups */
/** @var string $intakeMonthEmpty */
/** @var array|null $intakeYmdPack */

$intakeMonthEmpty = $intakeMonthEmpty ?? 'هنوز گفتگویی از دستیار نیست.';
$ymdPack = is_array($intakeYmdPack ?? null) ? $intakeYmdPack : [];
if ($ymdPack === [] && !empty($intakeMonthGroups) && is_array($intakeMonthGroups)) {
    $flat = [];
    foreach ($intakeMonthGroups as $bucket) {
        foreach (($bucket['items'] ?? []) as $row) {
            if (is_array($row)) {
                $flat[] = $row;
            }
        }
    }
    $ymdPack = group_appointments_by_jalali_ymd($flat, 'intk', 'latest', ['fill' => 'none']);
}
$ymdEmpty = $intakeMonthEmpty;
$ymdNoun = 'گفتگو';
$ymdAllPrefix = 'گفتگوهای';
$ymdShowPeople = false;
$ymdClass = 'intake-ymd';
$ymdRenderItems = static function (array $list): void {
    if (!$list) {
        echo '<p class="muted" style="margin:0">در این بازه گفتگویی نیست.</p>';
        return;
    }
    echo '<div class="clinical-session-grid intake-day-grid" data-session-note-grid>';
    foreach ($list as $row) {
        if (!is_array($row)) {
            continue;
        }
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
        <?php
    }
    echo '</div>';
};
require __DIR__ . '/appointment_ymd_binder.php';
