<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/secretary_panel.php';
require_once __DIR__ . '/../../includes/admin_staff_messages.php';
require_once __DIR__ . '/../../includes/secretary_to_admin.php';

$user = require_login(['SECRETARY']);
$inbox = admin_staff_msg_inbox_for($pdo, (string) $user['id'], 200);
$sentToAdmin = secretary_to_admin_sent_for($pdo, (string) $user['id'], 50);

ob_start();
?>
<div class="stack">
  <h1>پیام مدیر</h1>
  <p class="muted" style="margin-top:.35rem;line-height:1.8">
    پیام‌های مدیر و درمانگر را اینجا می‌بینید و می‌توانید برای مدیر پیام بفرستید.
    <?= e(staff_actor_label($user)) ?>
  </p>

  <section class="panel stack" id="compose-to-admin">
    <div>
      <h2 style="margin:0;font-size:1.1rem">ارسال پیام به مدیر</h2>
      <p class="muted" style="margin:.35rem 0 0;font-size:.9rem;line-height:1.7">
        متن برای مدیر سایت می‌رود و در پنل ادمین دیده می‌شود.
      </p>
    </div>
    <form method="post" action="<?= e(url('/secretary/to-admin')) ?>" class="form-stack" id="secretary-to-admin-form" data-rich-note>
      <?= csrf_field() ?>
      <div>
        <label class="label" for="to-admin-editor">متن پیام</label>
        <?= rich_editor_toolbar_html(['id' => 'to-admin-toolbar', 'data_rich_toolbar' => true]) ?>
        <div
          id="to-admin-editor"
          class="clinical-editor clinical-editor-sm"
          contenteditable="true"
          role="textbox"
          data-rich-editor
          aria-label="پیام به مدیر"
          data-placeholder="مثلاً سؤال، گزارش، یا درخواست هماهنگی…"
        ></div>
        <textarea name="body" id="to-admin-body" data-rich-hidden hidden></textarea>
      </div>
      <button type="submit" class="btn btn-primary">ارسال به مدیر</button>
    </form>
  </section>

  <section class="panel stack" id="admin-site-messages">
    <div>
      <h2 style="margin:0;font-size:1.1rem">پیام‌های دریافتی</h2>
      <p class="muted" style="margin:.35rem 0 0;font-size:.9rem;line-height:1.7">
        آرشیو دائمی از پیام‌های مدیر و درمانگر. این بخش قابل حذف نیست.
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
            <strong><?= e((string) (($am['from_name'] ?? '') !== '' ? $am['from_name'] : 'فرستنده')) ?></strong>
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

  <section class="panel stack" id="sent-to-admin">
    <div>
      <h2 style="margin:0;font-size:1.1rem">پیام‌های ارسالی شما به مدیر</h2>
    </div>
    <?php if (!$sentToAdmin): ?>
      <p class="muted" style="margin:0">هنوز به مدیر پیامی نفرستاده‌اید.</p>
    <?php else: ?>
      <?php foreach ($sentToAdmin as $row): ?>
        <?php $read = !empty($row['read_at']); ?>
        <article class="admin-site-msg-card">
          <header class="admin-site-msg-head">
            <strong>به مدیر سایت</strong>
            <span class="muted" style="font-size:.8rem"><?= e(format_fa_datetime((string) ($row['created_at'] ?? ''))) ?></span>
          </header>
          <div class="admin-site-msg-body rich-msg-body"><?= rich_html_for_display((string) ($row['body'] ?? '')) ?></div>
          <footer class="muted" style="font-size:.8rem;margin-top:.45rem;display:flex;flex-wrap:wrap;gap:.45rem;align-items:center">
            <?= function_exists('render_delivery_ticks') ? render_delivery_ticks(true, $read) : '' ?>
            <span><?= $read ? 'مدیر خواند' . (!empty($row['read_at']) ? ' · ' . e(format_fa_datetime((string) $row['read_at'])) : '') : 'منتظر خواندن مدیر' ?></span>
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
$pageScripts = '<script src="' . e(url('/assets/js/rich-editor.js')) . '?v=20260916e"></script>
<script>
(function(){
  if (window.initRichEditors) { window.initRichEditors(document); }
  var form = document.getElementById("secretary-to-admin-form");
  if (!form) return;
  var editor = document.getElementById("to-admin-editor");
  var hidden = document.getElementById("to-admin-body");
  form.addEventListener("submit", function(e){
    if (editor && hidden) { hidden.value = editor.innerHTML; }
    var plain = (hidden && hidden.value ? hidden.value.replace(/<[^>]+>/g, " ").replace(/&nbsp;/g, " ").trim() : "");
    if (!plain) {
      e.preventDefault();
      alert("متن پیام را بنویسید.");
      if (editor) editor.focus();
    }
  });
})();
</script>';
$GLOBALS['pageScripts'] = $pageScripts;
render_secretary_page('پیام مدیر', ob_get_clean());
