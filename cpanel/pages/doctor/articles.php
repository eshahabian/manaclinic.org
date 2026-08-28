<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/doctor_panel.php';
$ctx = require_doctor_profile($pdo);
$stmt = $pdo->prepare('SELECT * FROM articles WHERE author_id=? ORDER BY created_at DESC');
$stmt->execute([$ctx['user']['id']]);
$articles = $stmt->fetchAll();
ob_start();
?>
<h1>مقالات من</h1>
<form class="panel form-stack" method="post" action="<?= e(url('/doctor/articles')) ?>" style="margin-top:1rem">
  <input type="hidden" name="action" value="create">
  <h2 style="margin:0;font-size:1.05rem">مقاله جدید</h2>
  <div><label class="label">عنوان</label><input class="input" name="title" required></div>
  <div><label class="label">خلاصه</label><input class="input" name="excerpt"></div>
  <div><label class="label">متن</label><textarea class="input" name="content" rows="8" required></textarea></div>
  <label style="display:flex;gap:.5rem;align-items:center;font-size:.9rem"><input type="checkbox" name="published" checked> انتشار فوری</label>
  <button class="btn btn-primary" type="submit">ذخیره مقاله</button>
</form>
<div class="stack" style="margin-top:1.5rem">
<?php foreach ($articles as $a): ?>
  <div class="panel row-between">
    <div>
      <strong><?= e($a['title']) ?></strong>
      <div class="muted" style="font-size:.85rem"><?= $a['published'] ? 'منتشر شده' : 'پیش‌نویس' ?></div>
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
render_doctor_page('مقالات', ob_get_clean());
