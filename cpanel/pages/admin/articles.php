<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/admin_panel.php';
require_login(['ADMIN']);
$articles = $pdo->query("
  SELECT a.*, u.name AS author_name FROM articles a
  JOIN users u ON u.id=a.author_id ORDER BY a.created_at DESC
")->fetchAll();
ob_start();
?>
<h1>مدیریت مقالات</h1>
<div class="stack" style="margin-top:1rem">
<?php foreach ($articles as $a): ?>
  <div class="panel row-between">
    <div>
      <strong><?= e($a['title']) ?></strong>
      <div class="muted" style="font-size:.85rem">نویسنده: <?= e($a['author_name']) ?> — <?= $a['published'] ? 'منتشر شده' : 'پیش‌نویس' ?></div>
      <?php if ($a['published']): ?><a href="<?= e(url('/articles/' . $a['slug'])) ?>" style="color:var(--primary);font-size:.85rem">مشاهده عمومی</a><?php endif; ?>
    </div>
    <div style="display:flex;gap:.5rem">
      <form method="post" action="<?= e(url('/admin/articles')) ?>">
        <input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= e($a['id']) ?>">
        <button class="btn btn-outline btn-sm" type="submit"><?= $a['published'] ? 'لغو انتشار' : 'انتشار' ?></button>
      </form>
      <form method="post" action="<?= e(url('/admin/articles')) ?>">
        <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= e($a['id']) ?>">
        <button class="btn btn-danger btn-sm" type="submit">حذف</button>
      </form>
    </div>
  </div>
<?php endforeach; ?>
</div>
<?php
render_admin_page('مقالات', ob_get_clean());
