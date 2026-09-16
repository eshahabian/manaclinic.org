<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/admin_panel.php';
require_once __DIR__ . '/../../includes/admin_staff_messages.php';
require_once __DIR__ . '/../../includes/secretary_to_admin.php';

$user = require_login(['ADMIN']);
$secretaries = admin_staff_msg_secretaries($pdo);
$sent = admin_staff_msg_sent_list($pdo, 50);
$fromSecretaries = secretary_to_admin_inbox($pdo, 60);
$fromUnread = secretary_to_admin_unread_count($pdo);

ob_start();
?>
<div class="admin-secretary-msgs">
<h1>پیام منشی‌ها</h1>
<p class="muted" style="margin-top:.35rem;line-height:1.8">
  ارسال پیام به منشی‌ها و دریافت پیام‌هایی که منشی‌ها برای مدیر می‌فرستند.
  پیام‌های ارسالی شما در بخش «پیام مدیر» منشی بایگانی می‌ماند و تا تیک و «خواندم» نزند کار پنل برایش قفل است.
</p>

<section class="panel stack" id="from-secretaries" style="margin-top:1rem">
  <div class="row-between" style="align-items:center;gap:.75rem;flex-wrap:wrap">
    <div>
      <h2 style="margin:0;font-size:1.05rem">پیام‌های دریافتی از منشی‌ها</h2>
      <p class="muted" style="margin:.3rem 0 0;font-size:.85rem">
        <?= $fromUnread ? to_fa_digits((string) $fromUnread) . ' خوانده‌نشده' : 'همه خوانده شده‌اند' ?>
      </p>
    </div>
    <?php if ($fromUnread > 0): ?>
      <form method="post" action="<?= e(url('/admin/secretary-messages')) ?>" style="margin:0">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="ack_all_from_secretaries">
        <button type="submit" class="btn btn-outline btn-sm">خواندن همه</button>
      </form>
    <?php endif; ?>
  </div>
  <?php if (!$fromSecretaries): ?>
    <p class="muted" style="margin:0">هنوز پیامی از منشی‌ها نیامده است.</p>
  <?php else: ?>
    <div class="stack">
      <?php foreach ($fromSecretaries as $row): ?>
        <?php $read = !empty($row['read_at']); ?>
        <article class="admin-site-msg-card<?= $read ? '' : ' is-unread' ?>">
          <header class="admin-site-msg-head">
            <strong><?= e(staff_actor_label(['name' => $row['from_name'] ?? '', 'username' => $row['from_username'] ?? ''])) ?></strong>
            <span class="muted" style="font-size:.8rem"><?= e(format_fa_datetime((string) ($row['created_at'] ?? ''))) ?></span>
          </header>
          <div class="admin-site-msg-body rich-msg-body"><?= rich_html_for_display((string) ($row['body'] ?? '')) ?></div>
          <footer class="muted" style="font-size:.8rem;margin-top:.45rem;display:flex;flex-wrap:wrap;gap:.45rem;align-items:center">
            <?= function_exists('render_delivery_ticks') ? render_delivery_ticks(true, $read) : '' ?>
            <span><?= $read ? 'خوانده شد' : 'جدید' ?></span>
            <?php if (!$read): ?>
              <form method="post" action="<?= e(url('/admin/secretary-messages')) ?>" style="margin:0">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="ack_from_secretary">
                <input type="hidden" name="message_id" value="<?= e((string) ($row['id'] ?? '')) ?>">
                <button type="submit" class="btn btn-primary btn-sm">خواندم</button>
              </form>
            <?php endif; ?>
            <form method="post" action="<?= e(url('/admin/secretary-messages')) ?>" style="margin:0" onsubmit="return confirm('این پیام حذف شود؟');">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete_from_secretary">
              <input type="hidden" name="message_id" value="<?= e((string) ($row['id'] ?? '')) ?>">
              <button type="submit" class="btn btn-danger btn-sm">حذف</button>
            </form>
          </footer>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>

<form class="panel form-stack" method="post" action="<?= e(url('/admin/secretary-messages')) ?>" enctype="multipart/form-data" style="margin-top:1rem" id="admin-msg-form" data-rich-note>
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="send">
  <h2 style="margin:0;font-size:1.05rem">ارسال پیام به منشی</h2>
  <div>
    <label class="label" for="admin-msg-editor">متن پیام</label>
    <?= rich_editor_toolbar_html(['id' => 'admin-msg-toolbar', 'data_rich_toolbar' => true]) ?>
    <div
      id="admin-msg-editor"
      class="clinical-editor clinical-editor-sm"
      contenteditable="true"
      role="textbox"
      data-rich-editor
      aria-label="متن پیام"
      data-placeholder="مثلاً دستور کاری مخصوص یک منشی…"
    ></div>
    <textarea name="body" id="admin-msg-body" data-rich-hidden hidden></textarea>
  </div>
  <div>
    <label class="label" for="admin-msg-image">عکس (اختیاری)</label>
    <input class="input" type="file" id="admin-msg-image" name="image" accept="image/jpeg,image/png,image/webp">
    <p class="muted" style="margin:.35rem 0 0;font-size:.8rem">JPG، PNG یا WEBP · حداکثر ۵ مگابایت</p>
  </div>

  <fieldset style="border:1px solid var(--line);border-radius:.75rem;padding:.85rem 1rem;margin:0">
    <legend style="padding:0 .35rem;font-size:.9rem">گیرنده</legend>
    <div style="display:flex;flex-wrap:wrap;gap:.75rem 1.25rem;margin-bottom:.75rem">
      <label style="display:flex;gap:.45rem;align-items:center;font-size:.95rem">
        <input type="radio" name="send_mode" value="one" id="admin-msg-mode-one" checked>
        یک منشی (جداگانه)
      </label>
      <label style="display:flex;gap:.45rem;align-items:center;font-size:.95rem">
        <input type="radio" name="send_mode" value="multi" id="admin-msg-mode-multi">
        چند منشی
      </label>
      <label style="display:flex;gap:.45rem;align-items:center;font-size:.95rem">
        <input type="radio" name="send_mode" value="all" id="admin-msg-mode-all">
        همه منشی‌ها
      </label>
    </div>

    <div id="admin-msg-one-wrap">
      <label class="label" for="admin-msg-one">انتخاب منشی</label>
      <select class="input" id="admin-msg-one" name="to_user_id">
        <option value="">— انتخاب کنید —</option>
        <?php foreach ($secretaries as $s): ?>
          <option value="<?= e((string) $s['id']) ?>"><?= e(staff_actor_label($s)) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div id="admin-msg-multi-wrap" hidden>
      <p class="muted" style="margin:0 0 .5rem;font-size:.85rem">منشی‌هایی که باید این پیام را جداگانه بگیرند تیک بزنید.</p>
      <div class="stack" style="gap:.4rem">
        <?php foreach ($secretaries as $s): ?>
          <label style="display:flex;gap:.5rem;align-items:center;font-size:.9rem">
            <input type="checkbox" name="to_user_ids[]" value="<?= e((string) $s['id']) ?>" class="admin-msg-sec">
            <?= e(staff_actor_label($s)) ?>
          </label>
        <?php endforeach; ?>
      </div>
    </div>

    <?php if (!$secretaries): ?>
      <p class="muted" style="margin:.5rem 0 0">منشی فعالی نیست.</p>
    <?php endif; ?>
  </fieldset>

  <button type="submit" class="btn btn-primary"<?= $secretaries ? '' : ' disabled' ?>>ارسال پیام</button>
</form>

<h2 style="margin:1.5rem 0 .65rem;font-size:1.05rem">پیام‌های ارسال‌شده به منشی‌ها</h2>
<p class="muted" style="margin:0 0 .75rem;font-size:.85rem">منشی نمی‌تواند این پیام‌ها را پاک یا ویرایش کند؛ فقط شما می‌توانید متن را عوض یا پیام را حذف کنید.</p>
<div class="stack">
  <?php if (!$sent): ?>
    <p class="muted">هنوز پیامی نفرستاده‌اید.</p>
  <?php else: ?>
    <?php foreach ($sent as $m): ?>
      <?php
        $mid = (string) ($m['id'] ?? '');
        $recipients = $m['recipients'] ?? [];
        $allRead = $recipients && (int) ($m['unread_count'] ?? 0) === 0;
        $hasImage = trim((string) ($m['image_path'] ?? '')) !== '';
        $bodyHtml = rich_html_for_display((string) ($m['body'] ?? ''));
        $recipNames = [];
        foreach ($recipients as $r) {
            $recipNames[] = staff_actor_label(['name' => $r['to_name'] ?? '', 'username' => $r['to_username'] ?? '']);
        }
        $editEditorId = 'admin-msg-edit-editor-' . $mid;
        $editBodyId = 'admin-msg-edit-body-' . $mid;
        $editToolbarId = 'admin-msg-edit-toolbar-' . $mid;
      ?>
      <div class="panel" id="sent-<?= e($mid) ?>" style="display:grid;gap:.65rem">
        <div class="muted" style="font-size:.8rem;display:flex;flex-wrap:wrap;gap:.5rem;align-items:center;justify-content:space-between">
          <span>برای: <?= e($recipNames ? implode('، ', $recipNames) : '—') ?></span>
          <div style="display:flex;flex-wrap:wrap;gap:.4rem;align-items:center">
            <button type="button" class="btn btn-outline btn-sm js-admin-msg-edit-toggle" data-target="admin-msg-edit-<?= e($mid) ?>" aria-expanded="false">ویرایش</button>
            <form method="post" action="<?= e(url('/admin/secretary-messages')) ?>" style="margin:0" onsubmit="return confirm('این پیام از بایگانی منشی‌ها هم حذف می‌شود. ادامه؟');">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete_to_secretary">
              <input type="hidden" name="message_id" value="<?= e($mid) ?>">
              <button type="submit" class="btn btn-danger btn-sm">حذف</button>
            </form>
          </div>
        </div>
        <div style="font-size:.95rem;line-height:1.8" class="rich-msg-body"><?= $bodyHtml ?></div>
        <?php if ($hasImage): ?>
          <a href="<?= e(admin_staff_msg_image_url($mid)) ?>" target="_blank" rel="noopener">
            <img class="admin-staff-msg-thumb" src="<?= e(admin_staff_msg_image_url($mid)) ?>" alt="عکس پیام">
          </a>
        <?php endif; ?>
        <form
          class="form-stack"
          method="post"
          action="<?= e(url('/admin/secretary-messages')) ?>"
          id="admin-msg-edit-<?= e($mid) ?>"
          data-rich-note
          hidden
          style="margin:0;padding-top:.35rem;border-top:1px solid var(--line)"
        >
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="edit_to_secretary">
          <input type="hidden" name="message_id" value="<?= e($mid) ?>">
          <label class="label" for="<?= e($editEditorId) ?>">ویرایش متن پیام</label>
          <?= rich_editor_toolbar_html(['id' => $editToolbarId, 'data_rich_toolbar' => true]) ?>
          <div
            id="<?= e($editEditorId) ?>"
            class="clinical-editor clinical-editor-sm"
            contenteditable="true"
            role="textbox"
            data-rich-editor
            aria-label="ویرایش متن پیام"
          ><?= $bodyHtml ?></div>
          <textarea name="body" id="<?= e($editBodyId) ?>" data-rich-hidden hidden><?= e((string) ($m['body'] ?? '')) ?></textarea>
          <div style="display:flex;flex-wrap:wrap;gap:.5rem">
            <button type="submit" class="btn btn-primary btn-sm">ذخیره تغییرات</button>
            <button type="button" class="btn btn-outline btn-sm js-admin-msg-edit-cancel" data-target="admin-msg-edit-<?= e($mid) ?>">انصراف</button>
          </div>
          <p class="muted" style="margin:0;font-size:.8rem">فقط متن عوض می‌شود؛ عکس پیام دست‌نخورده می‌ماند.</p>
        </form>
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
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>
</div>
<?php
$inner = ob_get_clean();
$pageScripts = '<script src="' . e(url('/assets/js/rich-editor.js')) . '?v=20260916e"></script>
<script>
(function(){
  if (window.initRichEditors) { window.initRichEditors(document); }
  function plainFromHtml(html){
    return (html || "").replace(/<[^>]+>/g, " ").replace(/&nbsp;/g, " ").trim();
  }
  function bindEmptyGuard(form){
    if (!form) return;
    var editor = form.querySelector("[data-rich-editor]");
    var hidden = form.querySelector("[data-rich-hidden]");
    form.addEventListener("submit", function(e){
      if (editor && hidden) {
        hidden.value = editor.innerHTML;
      }
      var plain = plainFromHtml(hidden && hidden.value ? hidden.value : "");
      if (!plain) {
        e.preventDefault();
        alert("متن پیام را بنویسید.");
        if (editor) editor.focus();
      }
    });
  }
  bindEmptyGuard(document.getElementById("admin-msg-form"));
  document.querySelectorAll("form[id^=\\"admin-msg-edit-\\"]").forEach(bindEmptyGuard);

  document.querySelectorAll(".js-admin-msg-edit-toggle, .js-admin-msg-edit-cancel").forEach(function(btn){
    btn.addEventListener("click", function(){
      var id = btn.getAttribute("data-target");
      var form = id ? document.getElementById(id) : null;
      if (!form) return;
      var open = btn.classList.contains("js-admin-msg-edit-toggle") ? form.hidden : false;
      form.hidden = !open;
      var toggle = document.querySelector(".js-admin-msg-edit-toggle[data-target=\\"" + id + "\\"]");
      if (toggle) toggle.setAttribute("aria-expanded", open ? "true" : "false");
      if (open) {
        var ed = form.querySelector("[data-rich-editor]");
        if (ed) ed.focus();
      }
    });
  });

  var form = document.getElementById("admin-msg-form");
  if (!form) return;
  var oneWrap = document.getElementById("admin-msg-one-wrap");
  var multiWrap = document.getElementById("admin-msg-multi-wrap");
  var oneSel = document.getElementById("admin-msg-one");
  var boxes = form.querySelectorAll(".admin-msg-sec");
  function mode(){
    var el = form.querySelector("input[name=\\"send_mode\\"]:checked");
    return el ? el.value : "one";
  }
  function sync(){
    var m = mode();
    if (oneWrap) oneWrap.hidden = m !== "one";
    if (multiWrap) multiWrap.hidden = m !== "multi";
    if (oneSel) oneSel.required = m === "one";
    boxes.forEach(function(b){ b.disabled = m !== "multi"; });
  }
  form.querySelectorAll("input[name=\\"send_mode\\"]").forEach(function(r){
    r.addEventListener("change", sync);
  });
  sync();
})();
</script>';
$GLOBALS['pageScripts'] = $pageScripts;
render_admin_page('پیام منشی‌ها', $inner);
