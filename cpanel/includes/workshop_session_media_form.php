<?php
declare(strict_types=1);

/** @var array $workshopSessions */
/** @var string $workshopMediaPost */
/** @var ?string $editWorkshopId */

$workshopSessions = $workshopSessions ?? [];
$workshopMediaPost = $workshopMediaPost ?? '';
$editWorkshopId = $editWorkshopId ?? null;
?>
<div id="field-session-media" class="panel workshop-session-media" style="padding:1rem;background:var(--bg-soft,#f8fafc);border-style:dashed">
  <h3 style="margin:0;font-size:.95rem">فایل هر جلسه</h3>
  <p class="muted" style="font-size:.85rem;line-height:1.65;margin:.4rem 0 0">
    برای هر جلسه (روزانه، هفتگی یا ماهانه)، پی‌دی‌اف، صوت و ویدیو جداگانه بارگذاری می‌شود. فقط اعضای تأییدشده این فایل‌ها را می‌بینند.
  </p>
  <p class="muted" style="font-size:.8rem;margin:.35rem 0 0">حداکثر <?= (int) ($mediaMaxMb ?? 300) ?> مگابایت برای هر فایل</p>

  <div id="workshop-session-media-list" class="stack" style="margin-top:1rem">
    <?php foreach ($workshopSessions as $session): ?>
      <?php $date = (string) ($session['session_date'] ?? ''); ?>
      <div class="panel workshop-session-slot" data-session-date="<?= e($date) ?>" style="padding:.85rem;background:#fff">
        <strong><?= e((string) ($session['title'] ?? '')) ?></strong>
        <?php if ($date !== ''): ?>
          <input type="hidden" name="extra_session_dates[]" value="<?= e($date) ?>">
        <?php endif; ?>
        <div class="workshop-session-slots">
          <?php foreach (['PDF' => ['پی‌دی‌اف', '.pdf,application/pdf'], 'AUDIO' => ['صوت', 'audio/*,.mp3,.m4a,.wav,.ogg'], 'VIDEO' => ['ویدیو', 'video/*,.mp4,.webm,.mov']] as $kind => $meta): ?>
            <?php $existing = $session['files'][$kind] ?? null; ?>
            <div class="workshop-session-kind">
              <label class="label"><?= e($meta[0]) ?></label>
              <?php if ($existing): ?>
                <div class="muted" style="font-size:.78rem;margin-bottom:.35rem">
                  <?= e((string) $existing['original_name']) ?> · <?= e(workshop_media_format_size((int) $existing['file_size'])) ?>
                  <?php if ($editWorkshopId && $workshopMediaPost !== ''): ?>
                    <button type="button" class="btn btn-outline btn-sm js-media-delete" data-action="<?= e(url($workshopMediaPost)) ?>" data-workshop="<?= e((string) $editWorkshopId) ?>" data-item="<?= e((string) $existing['id']) ?>">حذف</button>
                  <?php endif; ?>
                </div>
              <?php endif; ?>
              <?php if ($date !== ''): ?>
                <input class="input" type="file" name="session_file[<?= e($date) ?>][<?= e($kind) ?>]" accept="<?= e($meta[1]) ?>">
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <div id="offline-extra-days" class="workshop-type-block" hidden style="margin-top:.85rem">
    <label class="label">افزودن روز جلسه (آفلاین)</label>
    <div style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:flex-end">
      <input class="input workshop-date-view" type="text" id="extra-session-date-view" data-jdp data-jdp-only-date autocomplete="off" readonly placeholder="تاریخ شمسی">
      <input type="hidden" id="extra-session-date">
      <button type="button" class="btn btn-outline btn-sm" id="add-extra-session-day">افزودن روز</button>
    </div>
  </div>
</div>
