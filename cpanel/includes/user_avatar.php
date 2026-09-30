<?php
declare(strict_types=1);

function ensure_users_avatar_schema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    try {
        $has = $pdo->query("SHOW COLUMNS FROM users LIKE 'avatar_url'")->fetch();
        if (!$has) {
            $pdo->exec('ALTER TABLE users ADD COLUMN avatar_url VARCHAR(255) NULL AFTER phone');
        }
    } catch (Throwable $ignored) {
    }
    $ready = true;
}

function user_avatar_storage_root(): string
{
    $root = dirname(__DIR__) . '/uploads/avatars';
    if (!is_dir($root)) {
        @mkdir($root, 0755, true);
    }

    return $root;
}

function user_avatar_src(?string $publicPath): string
{
    $publicPath = trim((string) $publicPath);
    if ($publicPath === '' || !str_starts_with($publicPath, '/uploads/avatars/')) {
        return '';
    }

    return $publicPath;
}

function user_avatar_initial(string $name): string
{
    $name = trim($name);
    if ($name === '') {
        return 'م';
    }
    if (function_exists('mb_substr')) {
        return mb_substr($name, 0, 1);
    }

    return substr($name, 0, 1);
}

function user_save_avatar(string $userId, array $file): string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return '';
    }
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('آپلود عکس ناموفق بود.');
    }
    if (!function_exists('article_detect_mime')) {
        require_once __DIR__ . '/articles.php';
    }
    $mime = article_detect_mime((string) $file['tmp_name']);
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (($file['size'] ?? 0) > 5 * 1024 * 1024) {
        throw new RuntimeException('حجم عکس حداکثر ۵ مگابایت باشد.');
    }
    if (!isset($allowed[$mime])) {
        throw new RuntimeException('فرمت عکس باید jpg، png یا webp باشد.');
    }
    user_avatar_storage_root();
    $name = $userId . '-' . substr(cuid(), 0, 8) . '.' . $allowed[$mime];
    $dest = user_avatar_storage_root() . '/' . $name;
    if (!move_uploaded_file((string) $file['tmp_name'], $dest)) {
        throw new RuntimeException('ذخیره عکس ناموفق بود.');
    }

    return '/uploads/avatars/' . $name;
}

function user_delete_avatar_file(?string $publicPath): void
{
    $publicPath = (string) $publicPath;
    if ($publicPath === '' || !str_starts_with($publicPath, '/uploads/avatars/')) {
        return;
    }
    $full = user_avatar_storage_root() . '/' . basename($publicPath);
    if (is_file($full)) {
        @unlink($full);
    }
}

/** آواتار کاربر لاگین‌شده را از دیتابیس به سشن می‌آورد */
function user_hydrate_session_avatar(PDO $pdo, ?array $user): ?array
{
    if (!$user || empty($user['id'])) {
        return $user;
    }
    ensure_users_avatar_schema($pdo);
    try {
        $stmt = $pdo->prepare('SELECT avatar_url FROM users WHERE id=? LIMIT 1');
        $stmt->execute([(string) $user['id']]);
        $url = user_avatar_src((string) ($stmt->fetchColumn() ?: ''));
        $user['avatar_url'] = $url;
        if (isset($_SESSION['user']) && is_array($_SESSION['user'])) {
            $_SESSION['user']['avatar_url'] = $url;
        }
    } catch (Throwable $ignored) {
        $user['avatar_url'] = (string) ($user['avatar_url'] ?? '');
    }

    return $user;
}
