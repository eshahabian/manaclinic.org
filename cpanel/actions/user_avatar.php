<?php
declare(strict_types=1);

$user = require_login();
require_once __DIR__ . '/../includes/user_avatar.php';
require_once __DIR__ . '/../includes/articles.php';

csrf_verify();
ensure_users_avatar_schema($pdo);

header('Content-Type: application/json; charset=utf-8');

$action = trim((string) ($_POST['action'] ?? 'upload'));
$current = '';
try {
    $stmt = $pdo->prepare('SELECT avatar_url FROM users WHERE id=? LIMIT 1');
    $stmt->execute([(string) $user['id']]);
    $current = user_avatar_src((string) ($stmt->fetchColumn() ?: ''));
} catch (Throwable $ignored) {
}

try {
    if ($action === 'remove') {
        if ($current !== '') {
            user_delete_avatar_file($current);
            $pdo->prepare('UPDATE users SET avatar_url=NULL WHERE id=?')->execute([(string) $user['id']]);
        }
        $_SESSION['user']['avatar_url'] = '';
        echo json_encode(['ok' => true, 'avatar_url' => '', 'initial' => user_avatar_initial((string) ($user['name'] ?? ''))], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (empty($_FILES['avatar'])) {
        throw new RuntimeException('فایلی ارسال نشده است.');
    }
    $uploaded = user_save_avatar((string) $user['id'], $_FILES['avatar']);
    if ($uploaded === '') {
        throw new RuntimeException('آپلود عکس ناموفق بود.');
    }
    if ($current !== '' && $current !== $uploaded) {
        user_delete_avatar_file($current);
    }
    $pdo->prepare('UPDATE users SET avatar_url=? WHERE id=?')->execute([$uploaded, (string) $user['id']]);
    $_SESSION['user']['avatar_url'] = $uploaded;
    echo json_encode([
        'ok' => true,
        'avatar_url' => url($uploaded),
        'path' => $uploaded,
        'initial' => user_avatar_initial((string) ($user['name'] ?? '')),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
