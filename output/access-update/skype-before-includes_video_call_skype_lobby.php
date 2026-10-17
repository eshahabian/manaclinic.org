<?php
declare(strict_types=1);

/** لابی اسکایپ‌مانند تماس مانا — فقط درمانگر */
/** @var array $user */
/** @var array $patients */
/** @var array $savedRooms */
/** @var array $workshops */
/** @var bool $callActive */

$meName = trim((string) ($user['name'] ?? 'درمانگر'));
$hostId = (string) ($user['id'] ?? '');
$groupRooms = [];
foreach ($savedRooms as $sr) {
    if ((string) ($sr['kind'] ?? '') === 'group') {
        $groupRooms[] = $sr;
    }
}
?>
<aside class="vc-side">
  <div class="vc-side-user">
    <img class="vc-side-logo" src="<?= e(url('/assets/img/mana-call.png')) ?>?v=20260910e" width="22" height="22" alt="">
    <strong><?= e($meName) ?></strong>
  </div>
  <div class="vc-chrome" role="tablist" aria-label="بخش‌های تماس مانا">
    <button type="button" class="vc-chrome-btn is-active" data-vc-tab="home" title="خانه" aria-label="خانه">
      <svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor" aria-hidden="true"><path d="M12 3 4 10v10h6v-6h4v6h6V10z"/></svg>
      <span>خانه</span>
    </button>
    <button type="button" class="vc-chrome-btn" data-vc-tab="calls" title="تماس‌ها" aria-label="تماس‌ها">
      <svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor" aria-hidden="true"><path d="M6.6 10.8c1.4 2.8 3.8 5.2 6.6 6.6l2.2-2.2c.3-.3.7-.4 1-.2 1.1.4 2.3.6 3.6.6.6 0 1 .4 1 1V20c0 .6-.4 1-1 1C10.6 21 3 13.4 3 4c0-.6.4-1 1-1h3.5c.6 0 1 .4 1 1 0 1.2.2 2.5.6 3.6.1.3 0 .7-.3 1l-2.2 2.2z"/></svg>
      <span>تماس‌ها</span>
    </button>
    <button type="button" class="vc-chrome-btn" data-vc-tab="groups" title="گروه" aria-label="گروه">
      <svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor" aria-hidden="true"><path d="M16 11c1.7 0 3-1.3 3-3s-1.3-3-3-3-3 1.3-3 3 1.3 3 3 3zM8 11c1.7 0 3-1.3 3-3S9.7 5 8 5 5 6.3 5 8s1.3 3 3 3zm0 2c-2.3 0-7 1.2-7 3.5V19h14v-2.5C15 14.2 10.3 13 8 13zm8 0c-.3 0-.6 0-1 .1 1.2.9 2 2.1 2 3.4V19h6v-2.5c0-2.3-4.7-3.5-7-3.5z"/></svg>
      <span>گروه</span>
    </button>
  </div>
  <div class="vc-search-wrap" data-vc-search>
    <input class="input" type="search" data-vc-search-input placeholder="جستجو…" autocomplete="off" spellcheck="false" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="vc-search-drop">
    <ul class="vc-search-drop" data-vc-search-drop id="vc-search-drop" role="listbox"></ul>
  </div>

  <div class="vc-pane is-active" data-vc-pane="home">
    <div class="vc-side-tabs">
      <span class="is-on">مخاطبین</span>
    </div>
    <ul class="vc-people" data-vc-home-list>
      <?php if (!$patients): ?>
        <li class="muted vc-empty">مراجعه‌کننده‌ای در فهرست نیست.</li>
      <?php else: ?>
        <?php foreach ($patients as $c): ?>
          <li><?= video_call_person_row_html($c, true, false, 'select') ?></li>
        <?php endforeach; ?>
      <?php endif; ?>
    </ul>
  </div>

  <div class="vc-pane" data-vc-pane="calls" hidden>
    <p class="vc-list-title">جلسه‌های ذخیره‌شده</p>
    <?php if (!$savedRooms): ?>
      <p class="muted vc-empty">هنوز جلسه‌ای ذخیره نشده.</p>
    <?php else: ?>
      <ul class="vc-people">
        <?php foreach ($savedRooms as $sr): ?>
          <li>
            <div class="vc-person vc-room-row">
              <a class="vc-person-meta" href="<?= e(video_call_room_url($sr)) ?>" data-vc-room="<?= e((string) ($sr['room_key'] ?? '')) ?>">
                <strong><?= e((string) $sr['title']) ?></strong>
                <span class="muted"><?= (string) ($sr['kind'] ?? '') === 'workshop' ? 'کارگاه' : 'گروه' ?></span>
              </a>
              <?php if ((string) ($sr['kind'] ?? '') === 'group' && ((string) ($sr['host_user_id'] ?? '') === $hostId || ($user['role'] ?? '') === 'ADMIN')): ?>
                <button type="button" class="vc-room-del" data-vc-delete-room="<?= e((string) ($sr['room_key'] ?? '')) ?>" title="حذف گروه">حذف</button>
              <?php endif; ?>
            </div>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>

  <div class="vc-pane" data-vc-pane="groups" hidden>
    <p class="vc-list-title">گروه‌ها</p>
    <?php if (!$groupRooms): ?>
      <p class="muted vc-empty">گروهی نیست. روی + بزنید و افراد را انتخاب کنید.</p>
    <?php else: ?>
      <ul class="vc-people">
        <?php foreach ($groupRooms as $sr): ?>
          <li>
            <div class="vc-person vc-room-row">
              <a class="vc-person-meta" href="<?= e(video_call_room_url($sr)) ?>" data-vc-room="<?= e((string) ($sr['room_key'] ?? '')) ?>">
                <strong><?= e((string) $sr['title']) ?></strong>
                <span class="muted">گروه ذخیره‌شده</span>
              </a>
              <?php if ((string) ($sr['host_user_id'] ?? '') === $hostId || ($user['role'] ?? '') === 'ADMIN'): ?>
                <button type="button" class="vc-room-del" data-vc-delete-room="<?= e((string) ($sr['room_key'] ?? '')) ?>" title="حذف گروه">حذف</button>
              <?php endif; ?>
            </div>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>
</aside>
