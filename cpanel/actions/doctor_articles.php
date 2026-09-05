<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/doctor_panel.php';
require_once __DIR__ . '/../includes/articles.php';
require_once __DIR__ . '/../includes/notifications.php';

$ctx = require_doctor_profile($pdo);
ensure_articles_schema($pdo);
$action = post('action');
$authorId = (string) $ctx['user']['id'];

if ($action === 'create') {
    $title = post('title');
    $excerpt = post('excerpt');
    $content = sanitize_rich_html((string) ($_POST['content'] ?? ''));
    $published = isset($_POST['published']) ? 1 : 0;
    if ($title && $content !== '' && trim(strip_tags($content)) !== '') {
        $slug = article_unique_slug($pdo, $title);
        $pdo->prepare('INSERT INTO articles (id,title,slug,content,excerpt,published,published_at,author_id,approval_status) VALUES (?,?,?,?,?,?,?,?,?)')
            ->execute([
                cuid(),
                $title,
                $slug,
                $content,
                $excerpt,
                $published,
                $published ? date('Y-m-d H:i:s') : null,
                $authorId,
                $published ? 'APPROVED' : 'NONE',
            ]);
        flash_set('success', 'مقاله ذخیره شد.');
    } else {
        flash_set('error', 'عنوان و متن مقاله الزامی است.');
    }
} elseif ($action === 'approve' || $action === 'reject') {
    $id = post('id');
    $row = $pdo->prepare('SELECT * FROM articles WHERE id=? AND author_id=? LIMIT 1');
    $row->execute([$id, $authorId]);
    $article = $row->fetch();
    if ($article && (string) $article['approval_status'] === 'PENDING') {
        if ($action === 'approve') {
            $pdo->prepare("UPDATE articles SET approval_status='APPROVED', published=1, published_at=COALESCE(published_at, NOW()) WHERE id=?")
                ->execute([$id]);
            if (!empty($article['submitted_by_user_id'])) {
                notify_user(
                    $pdo,
                    (string) $article['submitted_by_user_id'],
                    'تأیید مقاله',
                    'مقاله «' . $article['title'] . '» تأیید و در سایت منتشر شد.',
                    '/articles/' . $article['slug'],
                    'article'
                );
            }
            flash_set('success', 'مقاله تأیید و منتشر شد.');
        } else {
            $pdo->prepare("UPDATE articles SET approval_status='REJECTED', published=0 WHERE id=?")
                ->execute([$id]);
            if (!empty($article['submitted_by_user_id'])) {
                notify_user(
                    $pdo,
                    (string) $article['submitted_by_user_id'],
                    'رد مقاله',
                    'مقاله «' . $article['title'] . '» تأیید نشد. می‌توانید نسخه جدید بفرستید.',
                    '/secretary/articles',
                    'article'
                );
            }
            flash_set('success', 'مقاله رد شد و منتشر نشد.');
        }
    }
} elseif ($action === 'toggle') {
    $id = post('id');
    $row = $pdo->prepare('SELECT * FROM articles WHERE id=? AND author_id=?');
    $row->execute([$id, $authorId]);
    $article = $row->fetch();
    if ($article) {
        if ((string) $article['approval_status'] === 'PENDING') {
            flash_set('error', 'ابتدا مقاله منشی را تأیید یا رد کنید.');
        } elseif ((string) $article['approval_status'] === 'REJECTED') {
            flash_set('error', 'مقاله ردشده قابل انتشار نیست.');
        } else {
            $published = $article['published'] ? 0 : 1;
            $pdo->prepare('UPDATE articles SET published=?, published_at=COALESCE(published_at, IF(?, NOW(), published_at)), approval_status=? WHERE id=?')
                ->execute([$published, $published, $published ? 'APPROVED' : (string) $article['approval_status'], $id]);
        }
    }
} elseif ($action === 'delete') {
    $row = $pdo->prepare('SELECT * FROM articles WHERE id=? AND author_id=? LIMIT 1');
    $row->execute([post('id'), $authorId]);
    $article = $row->fetch();
    if ($article) {
        article_delete_files($article);
        $pdo->prepare('DELETE FROM articles WHERE id=? AND author_id=?')->execute([(string) $article['id'], $authorId]);
        flash_set('success', 'حذف شد.');
    }
}
redirect('/doctor/articles');
