<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/articles.php';
require_once __DIR__ . '/../includes/notifications.php';

$user = require_login(['SECRETARY']);
ensure_articles_schema($pdo);
$action = post('action');

if ($action === 'create') {
    $authorId = article_doctor_user_id($pdo, post('author_id'));
    $title = trim(post('title'));
    $excerpt = trim(post('excerpt'));
    $content = sanitize_rich_html((string) ($_POST['content'] ?? ''));
    $text = trim(strip_tags($content));

    if (!$authorId || $title === '' || $text === '') {
        flash_set('error', 'دکتر، عنوان و متن مقاله الزامی است.');
        redirect('/secretary/articles');
    }
    if ($excerpt === '') {
        $excerpt = mb_substr($text, 0, 170);
    }

    $id = cuid();
    $coverUrl = null;
    $videoUrl = null;
    try {
        $cover = article_save_media($id, 'cover', $_FILES['cover'] ?? []);
        $video = article_save_media($id, 'video', $_FILES['video'] ?? []);
        $coverUrl = $cover !== '' ? $cover : null;
        $videoUrl = $video !== '' ? $video : null;
    } catch (RuntimeException $e) {
        article_delete_media_file($coverUrl);
        article_delete_media_file($videoUrl);
        flash_set('error', $e->getMessage());
        redirect('/secretary/articles');
    }

    $slug = article_unique_slug($pdo, $title);
    $pdo->prepare('
      INSERT INTO articles
        (id, title, slug, content, excerpt, cover_url, video_url, published, published_at, author_id, submitted_by_user_id, approval_status)
      VALUES (?,?,?,?,?,?,?,0,NULL,?,?,?)
    ')->execute([
        $id,
        $title,
        $slug,
        $content,
        $excerpt,
        $coverUrl,
        $videoUrl,
        $authorId,
        (string) $user['id'],
        'PENDING',
    ]);

    $docProfile = $pdo->prepare('SELECT id FROM doctor_profiles WHERE user_id=? LIMIT 1');
    $docProfile->execute([$authorId]);
    $profileId = $docProfile->fetchColumn();
    if ($profileId) {
        notify_doctor_profile(
            $pdo,
            (string) $profileId,
            'مقاله آماده تأیید',
            staff_actor_label($user) . " مقاله «{$title}» را برای شما آماده کرده است. پس از تأیید شما منتشر می‌شود.",
            '/doctor/articles',
            'article'
        );
    }

    flash_set('success', 'مقاله برای تأیید دکتر ارسال شد و هنوز منتشر نشده است.');
    redirect('/secretary/articles');
}

if ($action === 'delete') {
    $id = post('id');
    $row = $pdo->prepare('SELECT * FROM articles WHERE id=? AND submitted_by_user_id=? LIMIT 1');
    $row->execute([$id, (string) $user['id']]);
    $article = $row->fetch();
    if ($article && (string) $article['approval_status'] === 'PENDING' && !(int) $article['published']) {
        article_delete_files($article);
        $pdo->prepare('DELETE FROM articles WHERE id=?')->execute([$id]);
        flash_set('success', 'پیش‌نویس حذف شد.');
    } else {
        flash_set('error', 'فقط پیش‌نویس در انتظار تأیید قابل حذف است.');
    }
}

redirect('/secretary/articles');
