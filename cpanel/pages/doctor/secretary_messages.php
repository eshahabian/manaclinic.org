<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/doctor_panel.php';
require_once __DIR__ . '/../../includes/admin_staff_messages.php';

$ctx = require_doctor_profile($pdo);
$user = $ctx['user'] ?? current_user();
if (!doctor_can_message_secretaries($user)) {
    flash_set('error', 'پیام به منشی‌ها فقط برای دکتر شیوا گرانمایه‌پور و مدیر سایت مجاز است.');
    redirect('/doctor/notifications');
}
$userId = (string) ($user['id'] ?? '');
$secretaries = admin_staff_msg_secretaries($pdo);
$sent = admin_staff_msg_sent_list($pdo, 50, $userId);

ob_start();
?>
<div class="doctor-secretary-msgs">
<h1>پیام به منشی‌ها</h1>
<p class="muted" style="margin-top:.35rem;line-height:1.8">
  برای یک منشی، چند منشی، یا همه پیام بفرستید.
  تا «خواندم» نزنند، کار پنل برایشان قفل می‌ماند. با <strong style="color:#0d7a6a">@</strong> می‌توانید کسی را منشن کنید.
</p>

<form class="panel form-stack" method="post" action="<?= e(url('/doctor/secretary-messages')) ?>" enctype="multipart/form-data" style="margin-top:1rem" id="doctor-msg-form" data-rich-note>
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="send">
  <h2 style="margin:0;font-size:1.05rem">ارسال پیام</h2>
  <div>
    <label class="label" for="doctor-msg-editor">متن پیام</label>
    <?= rich_editor_toolbar_html(['id' => 'doctor-msg-toolbar', 'data_rich_toolbar' => true]) ?>
    <div
      id="doctor-msg-editor"
      class="clinical-editor clinical-editor-sm"
      contenteditable="true"
      role="textbox"
      data-rich-editor
      aria-label="متن پیام"
      data-placeholder="مثلاً هماهنگی نوبت یا درخواست از منشی…"
    ></div>
    <textarea name="body" id="doctor-msg-body" data-rich-hidden hidden></textarea>
  </div>
  <div>
    <label class="label" for="doctor-msg-image">عکس (اختیاری)</label>
    <input class="input" type="file" id="doctor-msg-image" name="image" accept="image/jpeg,image/png,image/webp">
    <p class="muted" style="margin:.35rem 0 0;font-size:.8rem">JPG، PNG یا WEBP · حداکثر ۵ مگابایت</p>
  </div>

  <fieldset style="border:1px solid var(--line);border-radius:.75rem;padding:.85rem 1rem;margin:0">
    <legend style="padding:0 .35rem;font-size:.9rem">گیرنده</legend>
    <div style="display:flex;flex-wrap:wrap;gap:.75rem 1.25rem;margin-bottom:.75rem">
      <label style="display:flex;gap:.45rem;align-items:center;font-size:.95rem">
        <input type="radio" name="send_mode" value="one" id="doctor-msg-mode-one" checked>
        یک منشی
      </label>
      <label style="display:flex;gap:.45rem;align-items:center;font-size:.95rem">
        <input type="radio" name="send_mode" value="multi" id="doctor-msg-mode-multi">
        چند منشی
      </label>
      <label style="display:flex;gap:.45rem;align-items:center;font-size:.95rem">
        <input type="radio" name="send_mode" value="all" id="doctor-msg-mode-all">
        همه منشی‌ها
      </label>
    </div>

    <div id="doctor-msg-one-wrap">
      <label class="label" for="doctor-msg-one">انتخاب منشی</label>
      <select class="input" id="doctor-msg-one" name="to_user_id">
        <option value="">— انتخاب کنید —</option>
        <?php foreach ($secretaries as $s): ?>
          <option value="<?= e((string) $s['id']) ?>"><?= e(staff_actor_label($s)) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div id="doctor-msg-multi-wrap" hidden>
      <p class="muted" style="margin:0 0 .5rem;font-size:.85rem">منشی‌هایی که باید این پیام را بگیرند تیک بزنید.</p>
      <div class="stack" style="gap:.4rem">
        <?php foreach ($secretaries as $s): ?>
          <label style="display:flex;gap:.5rem;align-items:center;font-size:.9rem">
            <input type="checkbox" name="to_user_ids[]" value="<?= e((string) $s['id']) ?>" class="doctor-msg-sec">
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

<h2 style="margin:1.5rem 0 .65rem;font-size:1.05rem">پیام‌های ارسال‌شده شما</h2>
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
      ?>
      <div class="panel" id="sent-<?= e($mid) ?>" style="display:grid;gap:.65rem">
        <div class="muted" style="font-size:.8rem;display:flex;flex-wrap:wrap;gap:.5rem;align-items:center;justify-content:space-between">
          <span>برای: <?= e($recipNames ? implode('، ', $recipNames) : '—') ?></span>
          <form method="post" action="<?= e(url('/doctor/secretary-messages')) ?>" style="margin:0" onsubmit="return confirm('این پیام حذف شود؟');">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="message_id" value="<?= e($mid) ?>">
            <button type="submit" class="btn btn-danger btn-sm">حذف</button>
          </form>
        </div>
        <div style="font-size:.95rem;line-height:1.8" class="rich-msg-body"><?= $bodyHtml ?></div>
        <?php if ($hasImage): ?>
          <a href="<?= e(admin_staff_msg_image_url($mid)) ?>" target="_blank" rel="noopener">
            <img class="admin-staff-msg-thumb" src="<?= e(admin_staff_msg_image_url($mid)) ?>" alt="عکس پیام">
          </a>
        <?php endif; ?>
        <div class="muted" style="font-size:.8rem;display:flex;flex-wrap:wrap;gap:.45rem;align-items:center">
          <span><?= e(format_fa_datetime((string) $m['created_at'])) ?></span>
          <?= function_exists('render_delivery_ticks') ? render_delivery_ticks(true, $allRead) : '' ?>
          <span><?= $allRead ? 'همه خواندند' : (to_fa_digits((string) (int) ($m['unread_count'] ?? 0)) . ' خوانده‌نشده') ?></span>
        </div>
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
  if (window.initMentions) { window.initMentions(document); }
  function plainFromHtml(html){
    return (html || "").replace(/<[^>]+>/g, " ").replace(/&nbsp;/g, " ").trim();
  }
  var form = document.getElementById("doctor-msg-form");
  if (!form) return;
  var editor = form.querySelector("[data-rich-editor]");
  var hidden = form.querySelector("[data-rich-hidden]");
  form.addEventListener("submit", function(e){
    if (editor && hidden) hidden.value = editor.innerHTML;
    var plain = plainFromHtml(hidden && hidden.value ? hidden.value : "");
    if (!plain) {
      e.preventDefault();
      alert("متن پیام را بنویسید.");
      if (editor) editor.focus();
    }
  });
  var oneWrap = document.getElementById("doctor-msg-one-wrap");
  var multiWrap = document.getElementById("doctor-msg-multi-wrap");
  var oneSel = document.getElementById("doctor-msg-one");
  var boxes = form.querySelectorAll(".doctor-msg-sec");
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
render_doctor_page('پیام به منشی‌ها', $inner);
