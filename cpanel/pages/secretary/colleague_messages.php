<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/secretary_panel.php';

$user = require_login(['SECRETARY']);

$peers = handover_other_secretaries($pdo, (string) $user['id']);
$inbox = handover_inbox_for($pdo, (string) $user['id'], 40);
$sent = handover_sent_grouped($pdo, (string) $user['id'], 25);
$colleagueUnread = 0;
foreach ($inbox as $note) {
    if (empty($note['read_at'])) {
        $colleagueUnread++;
    }
}

ob_start();
?>
<div class="stack" style="max-width:44rem">
  <h1>پیام همکار</h1>
  <p class="muted" style="margin-top:.35rem;line-height:1.8">
    متن برای همه منشی‌های دیگر می‌رود. با ورود بعدی، کل صفحه را می‌بینند و تا «خواندم» نزنند وارد پورتال نمی‌شوند.
    <?= $colleagueUnread ? ' · ' . to_fa_digits((string) $colleagueUnread) . ' پیام خوانده‌نشده' : '' ?>
  </p>

  <?php if (!$peers): ?>
    <p class="muted" style="margin:0">منشی دیگری برای ارسال نیست.</p>
  <?php else: ?>
    <form method="post" action="<?= e(url('/secretary/handover')) ?>" class="panel form-stack" id="secretary-handover-form" data-rich-note>
      <?= csrf_field() ?>
      <div>
        <label class="label" for="handover-editor">متن پیام</label>
        <?= rich_editor_toolbar_html(['id' => 'handover-toolbar', 'data_rich_toolbar' => true]) ?>
        <div
          id="handover-editor"
          class="clinical-editor clinical-editor-sm"
          contenteditable="true"
          role="textbox"
          data-rich-editor
          aria-label="متن پیام همکار"
          data-placeholder="مثلاً وضعیت نوبت‌ها، کار باقی‌مانده، یا نکته شیفت…"
        ></div>
        <textarea name="body" id="handover-body" data-rich-hidden hidden></textarea>
      </div>
      <button type="submit" class="btn btn-primary">ارسال پیام به همکاران</button>
    </form>
  <?php endif; ?>

  <h2 style="margin:.5rem 0 0;font-size:1.05rem">تاریخچه دریافت‌شده</h2>
  <?php if (!$inbox): ?>
    <p class="muted" style="margin:0">پیام همکاری دریافت نشده است.</p>
  <?php else: ?>
    <div class="stack">
      <?php foreach ($inbox as $note): ?>
        <?php $noteRead = !empty($note['read_at']); ?>
        <div class="row-between" style="border:1px solid var(--line);border-radius:.75rem;padding:.75rem;background:<?= $noteRead ? 'var(--card)' : 'var(--bg-soft)' ?>">
          <div style="flex:1;min-width:0">
            <strong>از <?= e((string) ($note['from_name'] ?? 'منشی')) ?></strong>
            <div style="font-size:.95rem;line-height:1.8;margin-top:.4rem" class="rich-msg-body"><?= rich_html_for_display((string) $note['body']) ?></div>
            <div class="muted" style="font-size:.75rem;margin-top:.4rem;display:flex;flex-wrap:wrap;gap:.45rem;align-items:center">
              <span><?= e(format_fa_datetime((string) $note['created_at'])) ?></span>
              <?= render_delivery_ticks(true, $noteRead) ?>
              <span><?= $noteRead ? 'خوانده شد' : 'رسید' ?></span>
            </div>
          </div>
          <?php if (!$noteRead): ?>
            <form method="post" action="<?= e(url('/secretary/handover/ack')) ?>" style="margin:0">
              <?= csrf_field() ?>
              <input type="hidden" name="note_id" value="<?= e((string) $note['id']) ?>">
              <button type="submit" class="btn btn-primary btn-sm">خواندم</button>
            </form>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <h2 style="margin:.5rem 0 0;font-size:1.05rem">تاریخچه ارسال‌های شما</h2>
  <?php if (!$sent): ?>
    <p class="muted" style="margin:0">هنوز پیامی نفرستاده‌اید.</p>
  <?php else: ?>
    <div class="stack">
      <?php foreach ($sent as $note): ?>
        <?php
          $recips = $note['recipients'] ?? [];
          $allRead = $recips && (int) ($note['unread_count'] ?? 0) === 0;
        ?>
        <div style="border:1px solid var(--line);border-radius:.75rem;padding:.75rem .85rem">
          <div style="font-size:.95rem;line-height:1.8" class="rich-msg-body"><?= rich_html_for_display((string) $note['body']) ?></div>
          <div class="muted" style="font-size:.75rem;margin-top:.45rem;display:flex;flex-wrap:wrap;gap:.45rem;align-items:center">
            <span><?= e(format_fa_datetime((string) $note['created_at'])) ?></span>
            <?= render_delivery_ticks(true, $allRead) ?>
            <span><?= $allRead ? 'همه خواندند' : ((int) $note['recipient_count'] . ' رسید') ?></span>
          </div>
          <?php if ($recips): ?>
            <ul class="msg-tick-list">
              <?php foreach ($recips as $r): ?>
                <?php $rRead = !empty($r['read_at']); ?>
                <li>
                  <?= e((string) ($r['to_name'] ?? 'منشی')) ?>
                  <?= render_delivery_ticks(true, $rRead) ?>
                  <span class="muted"><?= $rRead ? 'خوانده شد' : 'رسید' ?></span>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
<?php
$pageScripts = '<script src="' . e(url('/assets/js/rich-editor.js')) . '?v=20260916e"></script>
<script>
(function(){
  if (window.initRichEditors) { window.initRichEditors(document); }
  var form = document.getElementById("secretary-handover-form");
  if (!form) return;
  var editor = document.getElementById("handover-editor");
  var hidden = document.getElementById("handover-body");
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
render_secretary_page('پیام همکار', ob_get_clean());
