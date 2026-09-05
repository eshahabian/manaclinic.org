<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/secretary_panel.php';

$user = require_login(['SECRETARY']);
ensure_workshop_schema($pdo);

$msgTab = trim((string) ($_GET['msg'] ?? 'appointment'));
if (!in_array($msgTab, ['appointment', 'workshop'], true)) {
    $msgTab = 'appointment';
}

$notifications = fetch_notifications($pdo, (string) $user['id'], 80);
$recentAppointments = secretary_recent_shared_appointments($pdo, 40);
$recentEnrollments = secretary_recent_shared_enrollments($pdo, 40);
$unreadCount = secretary_unread_desk_count($notifications);
$handoverPeers = handover_other_secretaries($pdo, (string) $user['id']);
$handoverSent = handover_sent_recent($pdo, (string) $user['id'], 8);

ob_start();
?>
<h1>پیام‌ها</h1>
<p class="muted" style="margin-top:.35rem;font-size:.9rem">
  نوبت‌ها و ثبت‌نام کارگاه‌ها جدا هستند.<?= $unreadCount ? ' · ' . $unreadCount . ' پیام خوانده‌نشده' : '' ?>
</p>

<div class="panel" style="margin-top:1rem;border-color:var(--primary)">
  <h2 style="margin:0 0 .4rem;font-size:1.05rem">پیام برای منشی بعدی</h2>
  <p class="muted" style="margin:0 0 .85rem;font-size:.88rem;line-height:1.8">
    متن را برای منشی دیگر بفرستید. با ورود بعدی، کل صفحه را می‌بیند و تا «خواندم» نزند وارد پورتال نمی‌شود.
    یک کپی هم برای دکتر و ادمین می‌رود.
  </p>
  <?php if (!$handoverPeers): ?>
    <p class="muted" style="margin:0">منشی دیگری برای ارسال نیست.</p>
  <?php else: ?>
    <form method="post" action="<?= e(url('/secretary/handover')) ?>" class="form-stack">
      <div>
        <label class="label">منشی گیرنده</label>
        <select class="input" name="to_user_id" required>
          <option value="">انتخاب کنید</option>
          <?php foreach ($handoverPeers as $peer): ?>
            <option value="<?= e((string) $peer['id']) ?>">
              <?= e((string) $peer['name']) ?>
              <?php if (!empty($peer['username'])): ?> — <span dir="ltr"><?= e((string) $peer['username']) ?></span><?php endif; ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="label">متن پیام</label>
        <textarea class="input" name="body" rows="5" required placeholder="مثلاً وضعیت نوبت‌ها، کار باقی‌مانده، یا نکته شیفت…"></textarea>
      </div>
      <button type="submit" class="btn btn-primary">ارسال پیام تحویل شیفت</button>
    </form>
  <?php endif; ?>
  <?php if ($handoverSent): ?>
    <h3 style="margin:1.1rem 0 0;font-size:.95rem">ارسال‌های اخیر شما</h3>
    <div class="stack" style="margin-top:.6rem">
      <?php foreach ($handoverSent as $note): ?>
        <div style="border:1px solid var(--line);border-radius:.7rem;padding:.7rem .8rem">
          <strong>برای <?= e((string) $note['to_name']) ?></strong>
          <div class="muted" style="font-size:.78rem;margin-top:.2rem">
            <?= e(format_fa_datetime((string) $note['created_at'])) ?>
            · <?= !empty($note['read_at']) ? 'خوانده شد' : 'هنوز نخوانده' ?>
          </div>
          <div style="font-size:.9rem;line-height:1.7;margin-top:.35rem;white-space:pre-wrap"><?= e((string) $note['body']) ?></div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
<?= render_secretary_messages_panel(
    $notifications,
    url('/secretary/notifications/read'),
    $recentAppointments,
    $recentEnrollments,
    $msgTab,
    '/secretary/messages'
) ?>
<?php
render_secretary_page($msgTab === 'workshop' ? 'پیام‌های کارگاه' : 'پیام‌های نوبت', ob_get_clean());
