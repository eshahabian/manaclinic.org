<?php
declare(strict_types=1);

function ensure_articles_schema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    $pdo->exec("
      CREATE TABLE IF NOT EXISTS articles (
        id VARCHAR(32) PRIMARY KEY,
        title VARCHAR(255) NOT NULL,
        slug VARCHAR(255) NOT NULL UNIQUE,
        content MEDIUMTEXT NOT NULL,
        excerpt TEXT NOT NULL,
        cover_url VARCHAR(255) NULL,
        video_url VARCHAR(500) NULL,
        published TINYINT(1) NOT NULL DEFAULT 0,
        published_at DATETIME NULL,
        author_id VARCHAR(32) NOT NULL,
        submitted_by_user_id VARCHAR(32) NULL,
        approval_status VARCHAR(20) NOT NULL DEFAULT 'NONE',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        CONSTRAINT fk_article_author FOREIGN KEY (author_id) REFERENCES users(id) ON DELETE CASCADE
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $addColumn = static function (PDO $pdo, string $column, string $ddl): void {
        try {
            $has = $pdo->query("SHOW COLUMNS FROM articles LIKE " . $pdo->quote($column))->fetch();
            if (!$has) {
                $pdo->exec("ALTER TABLE articles ADD COLUMN {$ddl}");
            }
        } catch (Throwable $ignored) {
        }
    };
    $addColumn($pdo, 'cover_url', 'cover_url VARCHAR(255) NULL AFTER excerpt');
    $addColumn($pdo, 'video_url', 'video_url VARCHAR(500) NULL AFTER cover_url');
    $addColumn($pdo, 'submitted_by_user_id', 'submitted_by_user_id VARCHAR(32) NULL AFTER author_id');
    $addColumn($pdo, 'approval_status', "approval_status VARCHAR(20) NOT NULL DEFAULT 'NONE' AFTER submitted_by_user_id");

    article_media_ensure_storage();
    $ready = true;
}

function article_media_storage_root(): string
{
    return dirname(__DIR__) . '/uploads/articles';
}

function article_media_ensure_storage(): void
{
    $root = article_media_storage_root();
    if (!is_dir($root)) {
        mkdir($root, 0755, true);
    }
}

function article_approval_label(string $status, int $published = 0): string
{
    if ($published) {
        return 'منتشر شده';
    }
    return match ($status) {
        'PENDING' => 'در انتظار تأیید دکتر',
        'REJECTED' => 'رد شده توسط دکتر',
        'APPROVED' => 'تأیید شده — پیش‌نویس',
        default => 'پیش‌نویس',
    };
}

function article_unique_slug(PDO $pdo, string $title, ?string $exceptId = null): string
{
    $slug = slugify($title);
    $check = $pdo->prepare('SELECT id FROM articles WHERE slug=? AND id<>? LIMIT 1');
    $n = 0;
    $candidate = $slug;
    while (true) {
        $check->execute([$candidate, $exceptId ?: '']);
        if (!$check->fetch()) {
            return $candidate;
        }
        $candidate = $slug . '-' . (++$n);
        if ($n > 50) {
            return $slug . '-' . time();
        }
    }
}

function article_detect_mime(string $tmpPath): string
{
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = $finfo ? (string) finfo_file($finfo, $tmpPath) : '';
    if ($finfo) {
        finfo_close($finfo);
    }
    return strtolower($mime);
}

function article_save_media(string $articleId, string $kind, array $file): string
{
    article_media_ensure_storage();
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return '';
    }
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException($kind === 'video' ? 'آپلود ویدیو ناموفق بود.' : 'آپلود عکس ناموفق بود.');
    }

    $mime = article_detect_mime((string) $file['tmp_name']);
    if ($kind === 'cover') {
        $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        $max = 5 * 1024 * 1024;
        if (($file['size'] ?? 0) > $max) {
            throw new RuntimeException('حجم عکس حداکثر ۵ مگابایت باشد.');
        }
    } else {
        $allowed = ['video/mp4' => 'mp4', 'video/webm' => 'webm', 'video/quicktime' => 'mov'];
        $max = 80 * 1024 * 1024;
        if (($file['size'] ?? 0) > $max) {
            throw new RuntimeException('حجم ویدیو حداکثر ۸۰ مگابایت باشد.');
        }
    }
    if (!isset($allowed[$mime])) {
        throw new RuntimeException($kind === 'video' ? 'فرمت ویدیو باید mp4 یا webm باشد.' : 'فرمت عکس باید jpg، png یا webp باشد.');
    }

    $name = $articleId . '-' . $kind . '-' . substr(cuid(), 0, 8) . '.' . $allowed[$mime];
    $dest = article_media_storage_root() . '/' . $name;
    if (!move_uploaded_file((string) $file['tmp_name'], $dest)) {
        throw new RuntimeException('ذخیره فایل مقاله ناموفق بود.');
    }
    return '/uploads/articles/' . $name;
}

function article_delete_media_file(?string $publicPath): void
{
    $publicPath = (string) $publicPath;
    if ($publicPath === '' || !str_starts_with($publicPath, '/uploads/articles/')) {
        return;
    }
    $name = basename($publicPath);
    $full = article_media_storage_root() . '/' . $name;
    if (is_file($full)) {
        @unlink($full);
    }
}

function article_delete_files(array $article): void
{
    article_delete_media_file($article['cover_url'] ?? null);
    article_delete_media_file($article['video_url'] ?? null);
}

function article_author_doctors(PDO $pdo): array
{
    return $pdo->query("
      SELECT u.id, u.name, dp.specialty
      FROM doctor_profiles dp
      JOIN users u ON u.id = dp.user_id
      WHERE dp.is_active = 1 AND dp.is_approved = 1
      ORDER BY u.name ASC
    ")->fetchAll();
}

function article_doctor_user_id(PDO $pdo, string $authorId): ?string
{
    $stmt = $pdo->prepare("
      SELECT u.id
      FROM users u
      JOIN doctor_profiles dp ON dp.user_id = u.id
      WHERE u.id = ? AND dp.is_active = 1 AND dp.is_approved = 1
      LIMIT 1
    ");
    $stmt->execute([$authorId]);
    $id = $stmt->fetchColumn();
    return $id ? (string) $id : null;
}
