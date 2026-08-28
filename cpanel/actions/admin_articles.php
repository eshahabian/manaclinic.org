<?php
declare(strict_types=1);
require_login(['ADMIN']);
$action = post('action');
$id = post('id');
if ($action === 'toggle') {
    $row = $pdo->prepare('SELECT * FROM articles WHERE id=?');
    $row->execute([$id]);
    $a = $row->fetch();
    if ($a) {
        $published = $a['published'] ? 0 : 1;
        $pdo->prepare('UPDATE articles SET published=?, published_at=COALESCE(published_at, IF(?, NOW(), published_at)) WHERE id=?')
            ->execute([$published, $published, $id]);
    }
} elseif ($action === 'delete') {
    $pdo->prepare('DELETE FROM articles WHERE id=?')->execute([$id]);
    flash_set('success', 'حذف شد.');
}
redirect('/admin/articles');
