<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/secretary_panel.php';

$user = require_login(['SECRETARY']);
$peers = handover_other_secretaries($pdo, (string) $user['id']);
$sent = handover_sent_recent($pdo, (string) $user['id'], 12);

ob_start();
?>
<h1>پیام به همکار</h1>
<p class="muted" style="margin-top:.35rem;font-size:.9rem;line-height:1.8">
  متن برای همه منشی‌های دیگر می‌رود. با ورود بعدی، کل صفحه را می‌بینند و تا «خواندم» نزنند وارد پورتال نمی‌شوند.
  یک کپی هم برای دکتر و ادمین می‌رود.
</p>

<div class="panel" style="margin-top:1rem;border-color:var(--primary)">
  <?php if (!$peers): ?>
    <p class="muted" style="margin:0">منشی دیگری برای ارسال نیست.</p>
  <?php else: ?>
    <form method="post" action="<?= e(url('/secretary/handover')) ?>" class="form-stack">
      <?= csrf_field() ?>
      <div>
        <label class="label">متن پیام</label>
        <textarea class="input" name="body" rows="7" required placeholder="مثلاً وضعیت نوبت‌ها، کار باقی‌مانده، یا نکته شیفت…"></textarea>
      </div>
      <button type="submit" class="btn btn-primary">ارسال پیام به همکاران</button>
    </form>
  <?php endif; ?>
</div>

<?php if ($sent): ?>
  <div class="panel stack" style="margin-top:1rem">
    <h2 style="margin:0;font-size:1.05rem">ارسال‌های اخیر شما</h2>
    <?php foreach ($sent as $note): ?>
      <div style="border:1px solid var(--line);border-radius:.7rem;padding:.75rem .85rem">
        <div class="muted" style="font-size:.78rem">
          <?= e(format_fa_datetime((string) $note['created_at'])) ?>
          · <?= (int) $note['recipient_count'] ?> همکار
          · <?= (int) $note['unread_count'] > 0 ? ((int) $note['unread_count'] . ' نفر هنوز نخوانده') : 'همه خواندند' ?>
        </div>
        <div style="font-size:.95rem;line-height:1.8;margin-top:.4rem;white-space:pre-wrap"><?= e((string) $note['body']) ?></div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
<?php
render_secretary_page('پیام به همکار', ob_get_clean());
