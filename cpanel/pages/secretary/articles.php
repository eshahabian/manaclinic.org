<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/secretary_panel.php';
require_once __DIR__ . '/../../includes/articles.php';

$user = require_login(['SECRETARY']);
ensure_articles_schema($pdo);

$doctors = article_author_doctors($pdo);
$stmt = $pdo->prepare("
  SELECT a.*, u.name AS author_name
  FROM articles a
  JOIN users u ON u.id = a.author_id
  WHERE a.submitted_by_user_id = ?
  ORDER BY a.created_at DESC
");
$stmt->execute([(string) $user['id']]);
$articles = $stmt->fetchAll();

ob_start();
?>
<h1>مقالات دکتر</h1>
<p class="muted" style="margin-top:.35rem;font-size:.9rem;line-height:1.7">متن، عکس و ویدیو را وارد کنید. مقاله فقط پس از تأیید همان دکتر در سایت منتشر می‌شود.</p>

<form class="panel form-stack" method="post" action="<?= e(url('/secretary/articles')) ?>" id="article-form" style="margin-top:1.25rem" enctype="multipart/form-data">
  <input type="hidden" name="action" value="create">
  <h2 style="margin:0;font-size:1.05rem">مقاله جدید</h2>
  <div>
    <label class="label" for="author_id">دکتر نویسنده</label>
    <select class="input" name="author_id" id="author_id" required>
      <option value="">انتخاب دکتر</option>
      <?php foreach ($doctors as $d): ?>
        <option value="<?= e($d['id']) ?>"><?= e($d['name']) ?><?= !empty($d['specialty']) ? ' — ' . e($d['specialty']) : '' ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div><label class="label">عنوان</label><input class="input" name="title" required></div>
  <div><label class="label">خلاصه</label><input class="input" name="excerpt" maxlength="220" placeholder="حدود ۱۴۰ تا ۱۸۰ حرف برای نمایش در فهرست مقالات"></div>
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
  <div>
    <label class="label" for="cover">عکس مقاله</label>
    <input class="input" type="file" name="cover" id="cover" accept="image/jpeg,image/png,image/webp">
    <p class="muted" style="margin:.4rem 0 0;font-size:.8rem">jpg، png یا webp تا ۵ مگابایت.</p>
  </div>
  <div>
    <label class="label" for="video">ویدیو مقاله</label>
    <input class="input" type="file" name="video" id="video" accept="video/mp4,video/webm,video/quicktime">
    <p class="muted" style="margin:.4rem 0 0;font-size:.8rem">mp4 یا webm تا ۸۰ مگابایت.</p>
  </div>
  <button class="btn btn-primary" type="submit">ارسال برای تأیید دکتر</button>
</form>

<div class="stack" style="margin-top:1.5rem">
<?php foreach ($articles as $a): ?>
  <div class="panel row-between">
    <div>
      <strong><?= e($a['title']) ?></strong>
      <div class="muted" style="font-size:.85rem">دکتر <?= e($a['author_name']) ?> — <?= e(article_approval_label((string) $a['approval_status'], (int) $a['published'])) ?></div>
      <?php if ((int) $a['published']): ?>
        <a href="<?= e(url('/articles/' . $a['slug'])) ?>" style="color:var(--primary);font-size:.85rem">مشاهده عمومی</a>
      <?php endif; ?>
    </div>
    <?php if ((string) $a['approval_status'] === 'PENDING' && !(int) $a['published']): ?>
      <form method="post" action="<?= e(url('/secretary/articles')) ?>" onsubmit="return confirm('این پیش‌نویس حذف شود؟')">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="id" value="<?= e($a['id']) ?>">
        <button class="btn btn-danger btn-sm" type="submit">حذف</button>
      </form>
    <?php endif; ?>
  </div>
<?php endforeach; ?>
<?php if (!$articles): ?><p class="muted">هنوز مقاله‌ای از طرف شما ثبت نشده است.</p><?php endif; ?>
</div>
<?php
$inner = ob_get_clean();
$pageScripts = '
<script src="' . e(url('/assets/js/search-select.js')) . '?v=20260905a"></script>
<script src="' . e(url('/assets/js/rich-editor.js')) . '"></script>
<script>
if (window.enhanceSearchSelect) {
  enhanceSearchSelect(document.getElementById("author_id"), { placeholder: "جستجو یا انتخاب دکتر" });
}
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
render_secretary_page('مقالات دکتر', $inner);
