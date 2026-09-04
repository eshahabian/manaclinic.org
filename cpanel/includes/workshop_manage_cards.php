<?php
declare(strict_types=1);

/** @var array $workshopList */
/** @var string $workshopEmpty */
/** @var string $workshopRole doctor|secretary */

$workshopList = $workshopList ?? [];
$workshopEmpty = $workshopEmpty ?? 'کارگاهی در این بخش نیست.';
$workshopRole = $workshopRole ?? 'doctor';
$workshopEditBase = $workshopRole === 'secretary' ? '/secretary/workshops' : '/doctor/workshops';
$workshopPostBase = $workshopEditBase;
$workshopMediaPost = $workshopRole === 'secretary' ? '/secretary/workshop-media' : '/doctor/workshop-media';
?>
<?php if (!$workshopList): ?>
  <p class="muted binder-empty"><?= e($workshopEmpty) ?></p>
<?php else: ?>
  <div class="stack">
    <?php foreach ($workshopList as $workshop): ?>
      <?php $archived = workshop_is_archived($workshop); ?>
      <article class="workshop-binder-card<?= $archived ? ' is-archived' : '' ?>" id="workshop-<?= e($workshop['id']) ?>">
        <div class="workshop-card-row">
          <div class="workshop-card-main">
            <strong><?= e($workshop['title']) ?></strong>
            <span class="badge" style="margin-right:.5rem"><?= e(workshop_type_label($workshop['type'])) ?></span>
            <?php if ($workshopRole === 'secretary' && !empty($workshop['doctor_name'])): ?>
              <div class="muted" style="font-size:.85rem;margin-top:.35rem">درمانگر: <?= e($workshop['doctor_name']) ?></div>
            <?php endif; ?>
            <?php $workshopMediaStats = workshop_media_counts_html(workshop_media_counts_from_row($workshop)); if ($workshopMediaStats): ?>
              <div style="margin-top:.4rem"><?= $workshopMediaStats ?></div>
            <?php endif; ?>
            <div class="muted" style="font-size:.85rem;margin-top:.35rem">
              <?php if ($workshop['type'] === 'OFFLINE'): ?>
                دوره آفلاین
              <?php else: ?>
                <?= e(format_workshop_datetime_fa($workshop['starts_at'])) ?> — <?= e(format_workshop_datetime_fa($workshop['ends_at'])) ?>
              <?php endif; ?>
            </div>
            <div class="muted" style="font-size:.85rem;margin-top:.25rem">
              <?= e(format_price((int)$workshop['price'])) ?>
              · ثبت‌نام: <?= (int)$workshop['enrolled_count'] ?><?= !empty($workshop['capacity']) ? ' / ' . (int)$workshop['capacity'] : '' ?>
              · <?= $workshop['is_published'] ? 'منتشر شده' : 'پیش‌نویس' ?>
              · <?= !empty($workshop['enrollment_open']) ? 'ثبت‌نام باز' : 'ثبت‌نام بسته' ?>
              · <?= $workshop['status'] === 'COMPLETED' ? 'آرشیو / برگزار شده' : ($workshop['status'] === 'CANCELLED' ? 'لغو شده' : 'فعال') ?>
            </div>
            <?php if ($workshop['type'] === 'IN_PERSON' && !empty($workshop['location'])): ?>
              <div class="muted" style="font-size:.8rem;margin-top:.35rem">محل: <?= e($workshop['location']) ?></div>
            <?php endif; ?>
            <?php if ($workshopRole === 'secretary' && !empty($workshop['group_url'])): ?>
              <div class="muted" style="font-size:.8rem;margin-top:.35rem">
                گروه: <a href="<?= e($workshop['group_url']) ?>" target="_blank" rel="noopener" dir="ltr"><?= e($workshop['group_url']) ?></a>
              </div>
            <?php endif; ?>
            <?php if ($archived): ?>
              <p class="muted" style="font-size:.8rem;margin-top:.5rem">این کارگاه در آرشیو است — زمانش تمام شده یا پایان داده شده.</p>
            <?php elseif (!$workshop['is_published'] || $workshop['status'] !== 'PUBLISHED'): ?>
              <p style="color:var(--danger);font-size:.8rem;margin-top:.5rem">مراجعان این کارگاه را نمی‌بینند — دکمه «انتشار» را بزنید.</p>
            <?php elseif (empty($workshop['enrollment_open'])): ?>
              <p style="color:var(--warning,#b45309);font-size:.8rem;margin-top:.5rem">ثبت‌نام بسته — مراجعان می‌بینند اما نمی‌توانند ثبت‌نام کنند.</p>
            <?php elseif ($workshopRole === 'doctor'): ?>
              <p style="color:var(--success);font-size:.8rem;margin-top:.5rem">برای همه مراجعان در «دوره‌های من» → تب <?= e(workshop_type_label($workshop['type'])) ?> قابل مشاهده و ثبت‌نام است.</p>
            <?php endif; ?>
          </div>
          <div class="workshop-card-actions">
            <?php if ($workshopRole === 'doctor'): ?>
              <a class="btn btn-outline btn-sm" href="<?= e(url('/doctor/workshop-export?id=' . $workshop['id'])) ?>">خروجی ثبت‌نام‌ها</a>
              <?php if ($workshop['status'] !== 'CANCELLED'): ?>
                <a class="btn btn-outline btn-sm" href="<?= e(url($workshopEditBase . '?edit=' . $workshop['id'])) ?>#session-notes">یادداشت جلسات</a>
              <?php endif; ?>
            <?php endif; ?>
            <?php if (!$archived && $workshop['status'] !== 'COMPLETED' && $workshop['status'] !== 'CANCELLED'): ?>
              <a class="btn btn-outline btn-sm" href="<?= e(url($workshopEditBase . '?edit=' . $workshop['id'])) ?>#workshop-form"><?= $workshopRole === 'secretary' ? 'ویرایش / فایل' : 'ویرایش' ?></a>
              <form method="post" action="<?= e(url($workshopPostBase)) ?>">
                <input type="hidden" name="action" value="toggle_enrollment">
                <input type="hidden" name="id" value="<?= e($workshop['id']) ?>">
                <button class="btn btn-outline btn-sm" type="submit"><?= !empty($workshop['enrollment_open']) ? 'بستن ثبت‌نام' : 'باز کردن ثبت‌نام' ?></button>
              </form>
            <?php endif; ?>
            <?php if ($workshopRole === 'doctor' && ($workshop['status'] === 'PUBLISHED' || ($workshop['is_published'] && !$archived))): ?>
              <form method="post" action="<?= e(url($workshopPostBase)) ?>">
                <input type="hidden" name="action" value="complete">
                <input type="hidden" name="id" value="<?= e($workshop['id']) ?>">
                <button class="btn btn-outline btn-sm" type="submit">تسویه و پایان</button>
              </form>
            <?php endif; ?>
            <form method="post" action="<?= e(url($workshopPostBase)) ?>">
              <input type="hidden" name="action" value="toggle">
              <input type="hidden" name="id" value="<?= e($workshop['id']) ?>">
              <button class="btn btn-outline btn-sm" type="submit"><?= $workshop['is_published'] ? 'لغو انتشار' : 'انتشار' ?></button>
            </form>
            <form method="post" action="<?= e(url($workshopPostBase)) ?>" onsubmit="return confirm('حذف شود؟')">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= e($workshop['id']) ?>">
              <button class="btn btn-danger btn-sm" type="submit">حذف</button>
            </form>
          </div>
        </div>
        <?php if ($workshopRole === 'doctor' && !empty($workshop['items_to_bring'])): ?>
          <p style="font-size:.85rem;margin:.75rem 0 0"><strong>موارد همراه:</strong> <?= e($workshop['items_to_bring']) ?></p>
        <?php endif; ?>
        <?php if ($workshopRole === 'doctor' && !empty($workshop['notes'])): ?>
          <p class="muted" style="font-size:.85rem;margin:.5rem 0 0"><strong>یادداشت:</strong> <?= e($workshop['notes']) ?></p>
        <?php endif; ?>
      </article>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
