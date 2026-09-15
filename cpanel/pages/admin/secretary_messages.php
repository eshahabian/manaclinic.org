<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/admin_panel.php';
require_once __DIR__ . '/../../includes/admin_staff_messages.php';

$user = require_login(['ADMIN']);
$secretaries = admin_staff_msg_secretaries($pdo);
$sent = admin_staff_msg_sent_list($pdo, 50);

ob_start();
?>
<h1>پیام به منشی‌ها</h1>
<p class="muted" style="margin-top:.35rem;line-height:1.8;max-width:40rem">
  پیام و در صورت نیاز عکس بفرستید. هر منشی باید تیک بزند و «خواندم» را بزند؛ تا آن لحظه نمی‌تواند در پنل کار کند.
</p>

<form class="panel form-stack" method="post" action="<?= e(url('/admin/secretary-messages')) ?>" enctype="multipart/form-data" style="margin-top:1rem;max-width:40rem">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="send">
  <div>
    <label class="label" for="admin-msg-body">متن پیام</label>
    <textarea class="input" id="admin-msg-body" name="body" rows="5" required placeholder="مثلاً دستور کاری، نکته مهم شیفت، یا یادآوری…"></textarea>
  </div>
  <div>
    <label class="label" for="admin-msg-image">عکس (اختیاری)</label>
    <input class="input" type="file" id="admin-msg-image" name="image" accept="image/jpeg,image/png,image/webp">
    <p class="muted" style="margin:.35rem 0 0;font-size:.8rem">JPG، PNG یا WEBP · حداکثر ۵ مگابایت</p>
  </div>
  <fieldset style="border:1px solid var(--line);border-radius:.75rem;padding:.85rem 1rem;margin:0">
    <legend style="padding:0 .35rem;font-size:.9rem">گیرندگان</legend>
    <label style="display:flex;gap:.5rem;align-items:center;font-size:.95rem;margin-bottom:.55rem">
      <input type="checkbox" name="to_all" value="1" id="admin-msg-to-all" checked>
      همه منشی‌ها
    </label>
    <div id="admin-msg-secretaries" class="stack" style="gap:.4rem">
      <?php foreach ($secretaries as $s): ?>
        <label style="display:flex;gap:.5rem;align-items:center;font-size:.9rem">
          <input type="checkbox" name="to_user_ids[]" value="<?= e((string) $s['id']) ?>" class="admin-msg-sec" disabled>
          <?= e(staff_actor_label($s)) ?>
        </label>
      <?php endforeach; ?>
      <?php if (!$secretaries): ?>
        <p class="muted" style="margin:0">منشی فعالی نیست.</p>
      <?php endif; ?>
    </div>
  </fieldset>
  <button type="submit" class="btn btn-primary"<?= $secretaries ? '' : ' disabled' ?>>ارسال پیام</button>
</form>

<h2 style="margin:1.5rem 0 .65rem;font-size:1.05rem">پیام‌های ارسال‌شده</h2>
<div class="stack">
  <?php if (!$sent): ?>
    <p class="muted">هنوز پیامی نفرستاده‌اید.</p>
  <?php else: ?>
    <?php foreach ($sent as $m): ?>
      <?php
        $recipients = $m['recipients'] ?? [];
        $allRead = $recipients && (int) ($m['unread_count'] ?? 0) === 0;
        $hasImage = trim((string) ($m['image_path'] ?? '')) !== '';
      ?>
      <div class="panel" style="display:grid;gap:.65rem">
        <div style="font-size:.95rem;line-height:1.8;white-space:pre-wrap"><?= e((string) $m['body']) ?></div>
        <?php if ($hasImage): ?>
          <a href="<?= e(admin_staff_msg_image_url((string) $m['id'])) ?>" target="_blank" rel="noopener">
            <img class="admin-staff-msg-thumb" src="<?= e(admin_staff_msg_image_url((string) $m['id'])) ?>" alt="عکس پیام">
          </a>
        <?php endif; ?>
        <div class="muted" style="font-size:.8rem;display:flex;flex-wrap:wrap;gap:.45rem;align-items:center">
          <span><?= e(format_fa_datetime((string) $m['created_at'])) ?></span>
          <?= function_exists('render_delivery_ticks') ? render_delivery_ticks(true, $allRead) : '' ?>
          <span><?= $allRead ? 'همه خواندند' : (to_fa_digits((string) (int) ($m['unread_count'] ?? 0)) . ' خوانده‌نشده') ?></span>
        </div>
        <?php if ($recipients): ?>
          <ul class="msg-tick-list">
            <?php foreach ($recipients as $r): ?>
              <?php $rRead = !empty($r['read_at']); ?>
              <li>
                <?= e(staff_actor_label(['name' => $r['to_name'] ?? '', 'username' => $r['to_username'] ?? ''])) ?>
                <?= function_exists('render_delivery_ticks') ? render_delivery_ticks(true, $rRead) : '' ?>
                <span class="muted"><?= $rRead ? 'خوانده شد' : 'منتظر تأیید' ?></span>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
        <form method="post" action="<?= e(url('/admin/secretary-messages')) ?>" onsubmit="return confirm('این پیام حذف شود؟');" style="margin:0">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="delete">
          <input type="hidden" name="message_id" value="<?= e((string) $m['id']) ?>">
          <button type="submit" class="btn btn-outline btn-sm" style="color:var(--danger)">حذف</button>
        </form>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>
<?php
$inner = ob_get_clean();
$pageScripts = <<<'JS'
<script>
(function(){
  var all = document.getElementById('admin-msg-to-all');
  var boxes = document.querySelectorAll('.admin-msg-sec');
  function sync(){
    var on = !!(all && all.checked);
    boxes.forEach(function(b){
      b.disabled = on;
      if (on) b.checked = false;
    });
  }
  if (all) all.addEventListener('change', sync);
  sync();
})();
</script>
JS;
render_admin_page('پیام به منشی‌ها', $inner);
