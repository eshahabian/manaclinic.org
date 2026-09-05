<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/doctor_panel.php';
require_once __DIR__ . '/../../includes/articles.php';
$ctx = require_doctor_profile($pdo);
ensure_articles_schema($pdo);
$stmt = $pdo->prepare("
  SELECT a.*, su.name AS submitter_name
  FROM articles a
  LEFT JOIN users su ON su.id = a.submitted_by_user_id
  WHERE a.author_id=?
  ORDER BY a.created_at DESC
");
$stmt->execute([$ctx['user']['id']]);
$articles = $stmt->fetchAll();
$pending = [];
$others = [];
foreach ($articles as $a) {
    if ((string) ($a['approval_status'] ?? '') === 'PENDING' && !(int) $a['published']) {
        $pending[] = $a;
    } else {
        $others[] = $a;
    }
}
ob_start();
?>
<h1>مقالات من</h1>
<?php if ($pending): ?>
<div class="stack" style="margin-top:1rem">
  <h2 style="margin:0;font-size:1.05rem">در انتظار تأیید شما</h2>
  <p class="muted" style="margin:0;font-size:.85rem">منشی مقاله را آماده کرده؛ فقط پس از تأیید شما در سایت دیده می‌شود.</p>
  <?php foreach ($pending as $a): ?>
    <div class="panel stack">
      <div class="row-between" style="align-items:flex-start;gap:1rem">
        <div>
          <strong><?= e($a['title']) ?></strong>
          <div class="muted" style="font-size:.85rem">ارسال‌شده توسط <?= e($a['submitter_name'] ?: 'منشی') ?></div>
          <?php if (!empty($a['excerpt'])): ?>
            <p style="margin:.5rem 0 0;font-size:.9rem;line-height:1.7"><?= e($a['excerpt']) ?></p>
          <?php endif; ?>
        </div>
        <div style="display:flex;gap:.5rem;flex-wrap:wrap">
          <form method="post" action="<?= e(url('/doctor/articles')) ?>">
            <input type="hidden" name="action" value="approve"><input type="hidden" name="id" value="<?= e($a['id']) ?>">
            <button class="btn btn-primary btn-sm" type="submit">تأیید و انتشار</button>
          </form>
          <form method="post" action="<?= e(url('/doctor/articles')) ?>">
            <input type="hidden" name="action" value="reject"><input type="hidden" name="id" value="<?= e($a['id']) ?>">
            <button class="btn btn-outline btn-sm" type="submit">رد</button>
          </form>
        </div>
      </div>
      <?php if (!empty($a['cover_url'])): ?>
        <img class="article-media-preview" src="<?= e(url($a['cover_url'])) ?>" alt="">
      <?php endif; ?>
      <?php if (!empty($a['video_url'])): ?>
        <video class="article-media-preview" src="<?= e(url($a['video_url'])) ?>" controls></video>
      <?php endif; ?>
      <div class="article-body" style="line-height:1.9;font-size:.95rem"><?= rich_html_for_display($a['content']) ?></div>
    </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>
<form class="panel form-stack" method="post" action="<?= e(url('/doctor/articles')) ?>" id="article-form" style="margin-top:1rem">
  <input type="hidden" name="action" value="create">
  <h2 style="margin:0;font-size:1.05rem">مقاله جدید</h2>
  <p class="muted" style="margin:0;font-size:.85rem">متن را انتخاب کنید، بعد Bold / سایز / هایلایت بزنید.</p>
  <div><label class="label">عنوان</label><input class="input" name="title" required></div>
  <div><label class="label">خلاصه</label><input class="input" name="excerpt"></div>
  <div>
    <label class="label">متن مقاله</label>
    <div class="clinical-toolbar" id="article-toolbar">
      <button type="button" class="tool-btn bold" data-cmd="bold" title="ضخیم">B</button>
      <span class="tool-sep"></span>
      <button type="button" class="tool-btn" data-fontsize="14">۱۴</button>
      <button type="button" class="tool-btn" data-fontsize="16">۱۶</button>
      <button type="button" class="tool-btn" data-fontsize="18">۱۸</button>
      <button type="button" class="tool-btn" data-fontsize="22">۲۲</button>
      <span class="tool-sep"></span>
      <span class="muted" style="font-size:.8rem;margin-inline-end:.25rem">هایلایت</span>
      <button type="button" class="swatch yellow" data-hl="#ffe566" title="زرد"></button>
      <button type="button" class="swatch green" data-hl="#8fd6a8" title="سبز"></button>
      <button type="button" class="swatch pink" data-hl="#f5a3c0" title="صورتی"></button>
      <button type="button" class="swatch blue" data-hl="#8eb7e8" title="آبی"></button>
      <button type="button" class="tool-btn" data-cmd="removeFormat" title="پاک کردن فرمت">پاک‌کردن رنگ</button>
    </div>
    <div
      id="article-editor"
      class="clinical-editor"
      contenteditable="true"
      role="textbox"
      aria-label="متن مقاله"
      data-placeholder="متن مقاله را اینجا بنویسید..."
    ></div>
    <textarea name="content" id="article-content" hidden></textarea>
  </div>
  <label style="display:flex;gap:.5rem;align-items:center;font-size:.9rem"><input type="checkbox" name="published" checked> انتشار فوری</label>
  <button class="btn btn-primary" type="submit">ذخیره مقاله</button>
</form>
<div class="stack" style="margin-top:1.5rem">
<?php foreach ($others as $a): ?>
  <div class="panel row-between">
    <div>
      <strong><?= e($a['title']) ?></strong>
      <div class="muted" style="font-size:.85rem"><?= e(article_approval_label((string) ($a['approval_status'] ?? 'NONE'), (int) $a['published'])) ?><?= !empty($a['submitter_name']) ? ' · منشی: ' . e($a['submitter_name']) : '' ?></div>
      <?php if ($a['published']): ?><a href="<?= e(url('/articles/' . $a['slug'])) ?>" style="color:var(--primary);font-size:.85rem">مشاهده</a><?php endif; ?>
    </div>
    <div style="display:flex;gap:.5rem">
      <form method="post" action="<?= e(url('/doctor/articles')) ?>">
        <input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= e($a['id']) ?>">
        <button class="btn btn-outline btn-sm" type="submit"><?= $a['published'] ? 'لغو انتشار' : 'انتشار' ?></button>
      </form>
      <form method="post" action="<?= e(url('/doctor/articles')) ?>">
        <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= e($a['id']) ?>">
        <button class="btn btn-danger btn-sm" type="submit">حذف</button>
      </form>
    </div>
  </div>
<?php endforeach; ?>
</div>
<?php
$inner = ob_get_clean();
$pageScripts = '
<script src="' . e(url('/assets/js/rich-editor.js')) . '"></script>
<script>
initRichEditor({
  editor: "#article-editor",
  toolbar: "#article-toolbar",
  form: "#article-form",
  hidden: "#article-content"
});
document.getElementById("article-form").addEventListener("submit", function(e){
  var html = (document.getElementById("article-content").value || "").replace(/<br\\s*\\/?>/gi,"").replace(/&nbsp;/g," ").trim();
  var text = html.replace(/<[^>]+>/g,"").trim();
  if (!text) {
    e.preventDefault();
    alert("متن مقاله را بنویسید.");
  }
});
</script>
';
render_doctor_page('مقالات', $inner);
