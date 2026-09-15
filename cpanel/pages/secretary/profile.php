<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/secretary_panel.php';
require_once __DIR__ . '/../../includes/admin_staff_messages.php';

$user = require_login(['SECRETARY']);
$inbox = admin_staff_msg_inbox_for($pdo, (string) $user['id'], 200);

ob_start();
?>
<div class="stack" style="max-width:44rem">
  <h1>پروفایل منشی</h1>
  <p class="muted" style="margin-top:.35rem;line-height:1.8">
    <?= e(staff_actor_label($user)) ?>
  </p>

  <section class="panel stack" id="admin-site-messages">
    <div>
      <h2 style="margin:0;font-size:1.1rem">پیام‌های مدیر سایت</h2>
      <p class="muted" style="margin:.35rem 0 0;font-size:.9rem;line-height:1.7">
        آرشیو دائمی پیام‌های مدیر. این بخش فقط مشاهده است و قابل حذف نیست.
      </p>
    </div>

    <?php if (!$inbox): ?>
      <p class="muted" style="margin:0">هنوز پیام مدیری ثبت نشده است.</p>
    <?php else: ?>
      <?php foreach ($inbox as $am): ?>
        <?php
          $amRead = !empty($am['read_at']);
          $amHasImg = trim((string) ($am['image_path'] ?? '')) !== '';
          $amMsgId = (string) ($am['message_id'] ?? '');
        ?>
        <article class="admin-site-msg-card<?= $amRead ? '' : ' is-unread' ?>">
          <header class="admin-site-msg-head">
            <strong>پیام مدیر سایت</strong>
            <span class="muted" style="font-size:.8rem"><?= e(format_fa_datetime((string) ($am['created_at'] ?? ''))) ?></span>
          </header>
          <div class="admin-site-msg-body rich-msg-body"><?= rich_html_for_display((string) ($am['body'] ?? '')) ?></div>
          <?php if ($amHasImg && $amMsgId !== ''): ?>
            <a href="<?= e(admin_staff_msg_image_url($amMsgId)) ?>" target="_blank" rel="noopener">
              <img class="admin-staff-msg-thumb" src="<?= e(admin_staff_msg_image_url($amMsgId)) ?>" alt="عکس پیام مدیر سایت">
            </a>
          <?php endif; ?>
          <footer class="muted" style="font-size:.8rem;margin-top:.45rem;display:flex;flex-wrap:wrap;gap:.45rem;align-items:center">
            <span>از <?= e((string) ($am['from_name'] ?? 'مدیر')) ?></span>
            <?= function_exists('render_delivery_ticks') ? render_delivery_ticks(true, $amRead) : '' ?>
            <span><?= $amRead ? 'خوانده شد' . (!empty($am['read_at']) ? ' · ' . e(format_fa_datetime((string) $am['read_at'])) : '') : 'منتظر تأیید' ?></span>
          </footer>
        </article>
      <?php endforeach; ?>
    <?php endif; ?>
  </section>

  <p style="margin:0">
    <a class="btn btn-outline" href="<?= e(url('/change-password')) ?>">تغییر رمز عبور</a>
  </p>
</div>
<?php
render_secretary_page('پروفایل · پیام‌های مدیر سایت', ob_get_clean());
