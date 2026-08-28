<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/doctor_panel.php';
$ctx = require_doctor_profile($pdo);
$action = post('action');

if ($action === 'create') {
    $title = post('title');
    $excerpt = post('excerpt');
    $content = post('content');
    $published = isset($_POST['published']) ? 1 : 0;
    if ($title && $content) {
        $slug = slugify($title);
        $check = $pdo->prepare('SELECT id FROM articles WHERE slug=?');
        $check->execute([$slug]);
        if ($check->fetch()) $slug .= '-' . time();
        $pdo->prepare('INSERT INTO articles (id,title,slug,content,excerpt,published,published_at,author_id) VALUES (?,?,?,?,?,?,?,?)')
            ->execute([cuid(), $title, $slug, $content, $excerpt, $published, $published ? date('Y-m-d H:i:s') : null, $ctx['user']['id']]);
        flash_set('success', 'مقاله ذخیره شد.');
    }
} elseif ($action === 'toggle') {
    $id = post('id');
    $row = $pdo->prepare('SELECT * FROM articles WHERE id=? AND author_id=?');
    $row->execute([$id, $ctx['user']['id']]);
    $article = $row->fetch();
    if ($article) {
        $published = $article['published'] ? 0 : 1;
        $pdo->prepare('UPDATE articles SET published=?, published_at=COALESCE(published_at, IF(?, NOW(), published_at)) WHERE id=?')
            ->execute([$published, $published, $id]);
    }
} elseif ($action === 'delete') {
    $pdo->prepare('DELETE FROM articles WHERE id=? AND author_id=?')->execute([post('id'), $ctx['user']['id']]);
    flash_set('success', 'حذف شد.');
}
redirect('/doctor/articles');
