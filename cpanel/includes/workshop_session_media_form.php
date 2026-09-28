<?php
declare(strict_types=1);

/** @var array $workshopSessions */
/** @var string $workshopMediaPost */
/** @var ?string $editWorkshopId */

$workshopSessions = $workshopSessions ?? [];
$workshopMediaPost = $workshopMediaPost ?? '';
$editWorkshopId = $editWorkshopId ?? null;
?>
<?php
$bundleCourse = [];
$bundleSlides = [];
if (!empty($editWorkshopId)) {
    global $pdo;
    if ($pdo instanceof PDO && function_exists('workshop_media_placed_list')) {
        $bundleCourse = workshop_media_placed_list($pdo, (string) $editWorkshopId, 'COURSE');
        $bundleSlides = workshop_media_placed_list($pdo, (string) $editWorkshopId, 'SLIDES');
    }
}
$bundleDelete = static function (array $file) use ($editWorkshopId, $workshopMediaPost): void {
    if (empty($file['id']) || !$editWorkshopId || $workshopMediaPost === '') {
        return;
    }
    echo ' <button type="button" class="btn btn-outline btn-sm js-media-delete" data-action="' . e(url($workshopMediaPost)) . '" data-workshop="' . e((string) $editWorkshopId) . '" data-item="' . e((string) $file['id']) . '">حذف فایل</button>';
};
$bundleWatermark = function_exists('workshop_media_watermark_for_user') && function_exists('current_user')
    ? workshop_media_watermark_for_user(current_user() ?: [], isset($pdo) && $pdo instanceof PDO ? $pdo : null)
    : '';
?>
<div class="panel workshop-bundle-form" style="padding:1rem;background:var(--bg-soft,#f8fafc)">
  <h3 style="margin:0;font-size:.95rem">فایل کلی کارگاه</h3>
  <p class="muted" style="font-size:.85rem;line-height:1.65;margin:.4rem 0 .7rem">پی‌دی‌اف، صوت یا پاورپوینت برای کل دوره، نه برای یک جلسهٔ خاص. مراجع آن را بالای مسیر دوره می‌بیند. روی پی‌دی‌اف و اسلایدها نام مراجع می‌نشیند.</p>
  <div class="workshop-session-slots">
    <div class="workshop-session-kind">
      <label class="label">پی‌دی‌اف کل دوره</label>
      <input class="input js-more-session-file" type="file" name="course_file[PDF][]" accept=".pdf,application/pdf">
    </div>
    <div class="workshop-session-kind">
      <label class="label">صوت کل دوره</label>
      <input class="input js-more-session-file" type="file" name="course_file[AUDIO][]" accept="audio/*,.mp3,.m4a,.wav,.ogg">
    </div>
    <div class="workshop-session-kind">
      <label class="label">پاورپوینت</label>
      <input class="input js-more-session-file" type="file" name="slide_file[]" accept=".pptx,application/vnd.openxmlformats-officedocument.presentationml.presentation">
    </div>
  </div>
  <?php foreach ($bundleCourse as $file): ?>
    <p class="muted" style="font-size:.8rem;margin:.55rem 0 0"><?= e(workshop_media_kind_label((string) ($file['kind'] ?? ''))) ?> — <?= e((string) ($file['original_name'] ?? 'فایل')) ?>
      <?php if ((string) ($file['kind'] ?? '') === 'PDF' && !empty($file['id']) && function_exists('workshop_pdf_actions_html')): ?>
        <?= workshop_pdf_actions_html((string) $file['id'], (string) ($file['original_name'] ?? 'course.pdf'), $bundleWatermark) ?>
      <?php endif; ?>
      <?php $bundleDelete($file); ?>
    </p>
  <?php endforeach; ?>
  <?php foreach ($bundleSlides as $file): ?>
    <p class="muted" style="font-size:.8rem;margin:.55rem 0 0">پاورپوینت — <?= e((string) ($file['original_name'] ?? 'slides.pptx')) ?><?php $bundleDelete($file); ?></p>
  <?php endforeach; ?>
</div>
<div id="field-session-media" class="panel workshop-session-media" style="padding:1rem;background:var(--bg-soft,#f8fafc);border-style:dashed">
  <h3 style="margin:0;font-size:.95rem">فایل هر جلسه</h3>
  <p class="muted" style="font-size:.85rem;line-height:1.65;margin:.4rem 0 0">
    برای هر جلسه می‌توانید چند پی‌دی‌اف، صوت یا ویدیو بگذارید. بعد از انتخاب هر فایل، دوباره «انتخاب فایل» می‌آید. فقط اعضای تأییدشده این فایل‌ها را می‌بینند.
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
            <?php $existingList = function_exists('workshop_media_kind_files') ? workshop_media_kind_files($session['files'][$kind] ?? null) : []; ?>
            <div class="workshop-session-kind">
              <label class="label"><?= e($meta[0]) ?></label>
              <?php if ($date !== ''): ?>
                <input class="input js-more-session-file" type="file" name="session_file[<?= e($date) ?>][<?= e($kind) ?>][]" accept="<?= e($meta[1]) ?>">
              <?php endif; ?>
              <?php foreach ($existingList as $existing): ?>
                <div class="muted" style="font-size:.78rem;margin:.45rem 0 0">
                  <?= e((string) $existing['original_name']) ?> · <?= e(workshop_media_format_size((int) $existing['file_size'])) ?>
                  <?php if (!empty($existing['id']) && $kind === 'PDF' && function_exists('workshop_pdf_actions_html')): ?>
                    <?= workshop_pdf_actions_html((string) $existing['id'], (string) ($existing['original_name'] ?? 'session.pdf'), $bundleWatermark) ?>
                  <?php elseif (!empty($existing['id'])): ?>
                    <a href="<?= e(workshop_media_stream_url((string) $existing['id'])) ?>" target="_blank" rel="noopener">مشاهده</a>
                    <a href="<?= e(workshop_media_stream_url((string) $existing['id'], null, true)) ?>">دانلود</a>
                  <?php endif; ?>
                  <?php if ($editWorkshopId && $workshopMediaPost !== ''): ?>
                    <button type="button" class="btn btn-outline btn-sm js-media-delete" data-action="<?= e(url($workshopMediaPost)) ?>" data-workshop="<?= e((string) $editWorkshopId) ?>" data-item="<?= e((string) $existing['id']) ?>">حذف فایل</button>
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>
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
