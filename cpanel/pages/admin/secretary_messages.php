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
<p class="muted" style="margin-top:.35rem;line-height:1.8;max-width:42rem">
  می‌توانید برای <strong>یک منشی</strong> یا چند نفر جداگانه پیام بفرستید (با یا بدون عکس).
  هر پیام در پروفایل همان منشی به‌عنوان «پیام‌های مدیر سایت» برای همیشه ثبت می‌شود و منشی نمی‌تواند پاکش کند.
  تا تیک و «خواندم» نزند، کار پنل برایش قفل است.
</p>

<form class="panel form-stack" method="post" action="<?= e(url('/admin/secretary-messages')) ?>" enctype="multipart/form-data" style="margin-top:1rem;max-width:42rem" id="admin-msg-form" data-rich-note>
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="send">
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

<h2 style="margin:1.5rem 0 .65rem;font-size:1.05rem">پیام‌های ارسال‌شده</h2>
<p class="muted" style="margin:0 0 .75rem;font-size:.85rem">این پیام‌ها در پروفایل منشی‌ها بایگانی می‌مانند و منشی نمی‌تواند حذفشان کند.</p>
<div class="stack">
  <?php if (!$sent): ?>
    <p class="muted">هنوز پیامی نفرستاده‌اید.</p>
  <?php else: ?>
    <?php foreach ($sent as $m): ?>
      <?php
        $recipients = $m['recipients'] ?? [];
        $allRead = $recipients && (int) ($m['unread_count'] ?? 0) === 0;
        $hasImage = trim((string) ($m['image_path'] ?? '')) !== '';
        $recipNames = [];
        foreach ($recipients as $r) {
            $recipNames[] = staff_actor_label(['name' => $r['to_name'] ?? '', 'username' => $r['to_username'] ?? '']);
        }
      ?>
      <div class="panel" style="display:grid;gap:.65rem">
        <div class="muted" style="font-size:.8rem">
          برای: <?= e($recipNames ? implode('، ', $recipNames) : '—') ?>
        </div>
        <div style="font-size:.95rem;line-height:1.8" class="rich-msg-body"><?= rich_html_for_display((string) $m['body']) ?></div>
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
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>
<?php
$inner = ob_get_clean();
$pageScripts = '<script src="' . e(url('/assets/js/rich-editor.js')) . '?v=20260916e"></script>
<script>
(function(){
  if (window.initRichEditors) { window.initRichEditors(document); }
  var form = document.getElementById("admin-msg-form");
  if (!form) return;
  var oneWrap = document.getElementById("admin-msg-one-wrap");
  var multiWrap = document.getElementById("admin-msg-multi-wrap");
  var oneSel = document.getElementById("admin-msg-one");
  var boxes = form.querySelectorAll(".admin-msg-sec");
  var editor = document.getElementById("admin-msg-editor");
  var hidden = document.getElementById("admin-msg-body");
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
  form.addEventListener("submit", function(e){
    if (editor && hidden) {
      hidden.value = editor.innerHTML;
    }
    var plain = (hidden && hidden.value ? hidden.value.replace(/<[^>]+>/g, " ").replace(/&nbsp;/g, " ").trim() : "");
    if (!plain) {
      e.preventDefault();
      alert("متن پیام را بنویسید.");
      if (editor) editor.focus();
    }
  });
  sync();
})();
</script>';
$GLOBALS['pageScripts'] = $pageScripts;
render_admin_page('پیام به منشی‌ها', $inner);
