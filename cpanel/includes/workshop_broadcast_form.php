<?php
declare(strict_types=1);
if(!workshop_can_add_member(current_user())||!isset($broadcastWorkshop))return;
?>
<form class="stack" data-workshop-broadcast action="<?= e(url('/staff/workshops/broadcast')) ?>" method="post" style="max-width:42rem">
  <?= csrf_field() ?>
  <input type="hidden" name="workshop_id" value="<?= e((string)$broadcastWorkshop['id']) ?>">
  <label class="label" for="broadcast-<?= e((string)$broadcastWorkshop['id']) ?>">پیامک برای اعضای «<?= e((string)$broadcastWorkshop['title']) ?>»</label>
  <textarea class="input" name="body" id="broadcast-<?= e((string)$broadcastWorkshop['id']) ?>" rows="3" maxlength="500" required placeholder="متن پیامک را بنویسید"></textarea>
  <p class="muted" data-broadcast-length>۰ کاراکتر</p>
  <p class="muted">فقط اعضای تأییدشده و دوره‌گذرانده؛ هر شماره یک پیام. اعضای لغوشده و حساب‌های غیرفعال پیام نمی‌گیرند.</p>
  <div style="display:flex;gap:.5rem;flex-wrap:wrap">
    <button class="btn btn-outline btn-sm" type="submit">پیش‌نمایش گیرندگان</button>
    <button class="btn btn-primary btn-sm" type="button" data-broadcast-send hidden>تأیید و ارسال پیامک</button>
  </div>
  <p class="muted" data-broadcast-status role="status" aria-live="polite"></p>
</form>
<?php if(empty($GLOBALS['workshopBroadcastLoaded'])): $GLOBALS['workshopBroadcastLoaded']=true; ?>
<script src="<?= e(url('/assets/js/workshop-broadcast.js')) ?>?v=20261020a" defer></script>
<?php endif; ?>
