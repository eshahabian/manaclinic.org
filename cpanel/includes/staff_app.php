<?php
declare(strict_types=1);

function staff_app_sections(): array
{
    return [
        'home' => ['href' => '/app', 'label' => 'خانه', 'title' => 'برنامه داخلی'],
        'appointments' => ['href' => '/app/appointments', 'label' => 'وقت‌ها', 'title' => 'وقت‌ها'],
        'rooms' => ['href' => '/app/rooms', 'label' => 'اتاق‌ها', 'title' => 'شرایط اتاق‌ها'],
        'chat' => ['href' => '/app/chat', 'label' => 'چت', 'title' => 'چت کارکنان'],
        'hours' => ['href' => '/app/hours', 'label' => 'ساعت کار', 'title' => 'ساعت کار منشی‌ها'],
        'checklist' => ['href' => '/app/checklist', 'label' => 'چک‌لیست', 'title' => 'چک‌لیست منشی‌ها'],
        'complaints' => ['href' => '/app/complaints', 'label' => 'شکایت', 'title' => 'شکایت‌ها'],
    ];
}

function staff_app_user(): array
{
    $user = current_user();
    if (!$user) {
        $next = (string) ($GLOBALS['path'] ?? '/app');
        if (!str_starts_with($next, '/app')) {
            $next = '/app';
        }
        redirect('/login?next=' . rawurlencode($next));
    }
    $user = require_login();
    $role = (string) ($user['role'] ?? '');
    if ($role !== 'DOCTOR' && $role !== 'SECRETARY') {
        staff_app_render('home', 'برنامه داخلی', 'این برنامه فقط برای درمانگرها و منشی‌های مانا کلینیک است.', '<h1>برنامه داخلی</h1><p>این بخش فقط برای درمانگرها و منشی‌هاست.</p>', true);
        exit;
    }

    return $user;
}

function staff_app_ready(PDO $pdo): bool
{
    try {
        staff_app_ensure_schema($pdo);

        return true;
    } catch (Throwable $e) {
        error_log('staff app schema: ' . $e->getMessage());

        return false;
    }
}

function staff_app_ensure_schema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    $pdo->exec("
      CREATE TABLE IF NOT EXISTS staff_app_rooms (
        id VARCHAR(32) PRIMARY KEY,
        title VARCHAR(120) NOT NULL,
        is_general TINYINT(1) NOT NULL DEFAULT 0,
        created_by VARCHAR(32) NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_staff_app_room_general (is_general)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $pdo->exec("
      CREATE TABLE IF NOT EXISTS staff_app_members (
        room_id VARCHAR(32) NOT NULL,
        user_id VARCHAR(32) NOT NULL,
        PRIMARY KEY (room_id, user_id),
        INDEX idx_staff_app_member_user (user_id)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $pdo->exec("
      CREATE TABLE IF NOT EXISTS staff_app_messages (
        id VARCHAR(32) PRIMARY KEY,
        room_id VARCHAR(32) NOT NULL,
        user_id VARCHAR(32) NOT NULL,
        body TEXT NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_staff_app_msg_room (room_id, created_at)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $pdo->exec("
      CREATE TABLE IF NOT EXISTS staff_app_files (
        id VARCHAR(32) PRIMARY KEY,
        message_id VARCHAR(32) NOT NULL,
        stored_name VARCHAR(80) NOT NULL,
        original_name VARCHAR(180) NOT NULL,
        mime VARCHAR(120) NOT NULL,
        size INT NOT NULL,
        INDEX idx_staff_app_file_msg (message_id)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $pdo->exec("
      CREATE TABLE IF NOT EXISTS staff_app_reads (
        user_id VARCHAR(32) NOT NULL,
        room_id VARCHAR(32) NOT NULL,
        read_at DATETIME NOT NULL,
        PRIMARY KEY (user_id, room_id)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $ready = true;
}

function staff_app_upload_dir(): string
{
    return dirname(__DIR__) . '/uploads/staff-app';
}

function staff_app_role_label(string $role): string
{
    return $role === 'SECRETARY' ? 'منشی' : 'درمانگر';
}

function staff_app_people(PDO $pdo): array
{
    return $pdo->query("
      SELECT id, name, username, role
      FROM users
      WHERE role IN ('DOCTOR','SECRETARY') AND COALESCE(is_disabled,0)=0
      ORDER BY FIELD(role, 'SECRETARY', 'DOCTOR'), name ASC
    ")->fetchAll() ?: [];
}

function staff_app_ensure_general(PDO $pdo): string
{
    staff_app_ensure_schema($pdo);
    $pdo->prepare("
      INSERT INTO staff_app_rooms (id, title, is_general, created_by)
      SELECT ?, 'چت کلی', 1, 'system' FROM DUAL
      WHERE NOT EXISTS (SELECT 1 FROM staff_app_rooms WHERE is_general = 1)
    ")->execute([cuid()]);
    $id = (string) $pdo->query('SELECT id FROM staff_app_rooms WHERE is_general = 1 ORDER BY created_at ASC LIMIT 1')->fetchColumn();
    if ($id === '') {
        throw new RuntimeException('چت کلی ساخته نشد.');
    }
    $pdo->prepare("
      INSERT IGNORE INTO staff_app_members (room_id, user_id)
      SELECT ?, u.id FROM users u
      WHERE u.role IN ('DOCTOR','SECRETARY') AND COALESCE(u.is_disabled,0)=0
    ")->execute([$id]);

    return $id;
}

function staff_app_rooms_for(PDO $pdo, string $userId): array
{
    $general = staff_app_ensure_general($pdo);
    $stmt = $pdo->prepare("
      SELECT r.id, r.title, r.is_general, r.created_at,
             (SELECT COUNT(*) FROM staff_app_messages m WHERE m.room_id = r.id) AS message_count
      FROM staff_app_rooms r
      JOIN staff_app_members mem ON mem.room_id = r.id AND mem.user_id = ?
      ORDER BY r.is_general DESC, r.created_at DESC
    ");
    $stmt->execute([$userId]);
    $rows = $stmt->fetchAll() ?: [];
    if ($rows === []) {
        $stmt->execute([$userId]);
        $rows = $stmt->fetchAll() ?: [];
    }
    foreach ($rows as &$row) {
        if ((string) $row['id'] === $general) {
            $row['is_general'] = 1;
        }
    }
    unset($row);

    return $rows;
}

function staff_app_room_for_member(PDO $pdo, string $roomId, string $userId): ?array
{
    staff_app_ensure_general($pdo);
    $stmt = $pdo->prepare("
      SELECT r.*
      FROM staff_app_rooms r
      JOIN staff_app_members mem ON mem.room_id = r.id AND mem.user_id = ?
      WHERE r.id = ?
      LIMIT 1
    ");
    $stmt->execute([$userId, $roomId]);
    $row = $stmt->fetch();

    return $row ?: null;
}

function staff_app_unread_count(PDO $pdo, string $userId): int
{
    if (!staff_app_ready($pdo)) {
        return 0;
    }
    try {
        staff_app_ensure_general($pdo);
        $stmt = $pdo->prepare("
          SELECT COUNT(*)
          FROM staff_app_messages m
          JOIN staff_app_members mem ON mem.room_id = m.room_id AND mem.user_id = ?
          LEFT JOIN staff_app_reads r ON r.room_id = m.room_id AND r.user_id = ?
          WHERE m.user_id <> ?
            AND m.created_at > COALESCE(r.read_at, '1970-01-01 00:00:00')
        ");
        $stmt->execute([$userId, $userId, $userId]);

        return (int) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

function staff_app_mark_read(PDO $pdo, string $userId, string $roomId): void
{
    $pdo->prepare("
      INSERT INTO staff_app_reads (user_id, room_id, read_at)
      VALUES (?, ?, NOW())
      ON DUPLICATE KEY UPDATE read_at = NOW()
    ")->execute([$userId, $roomId]);
}

/** @return list<array<string, mixed>> */
function staff_app_messages(PDO $pdo, string $roomId): array
{
    $stmt = $pdo->prepare("
      SELECT m.id, m.body, m.created_at, m.user_id, u.name, u.role
      FROM staff_app_messages m
      JOIN users u ON u.id = m.user_id
      WHERE m.room_id = ?
      ORDER BY m.created_at DESC
      LIMIT 150
    ");
    $stmt->execute([$roomId]);
    $rows = array_reverse($stmt->fetchAll() ?: []);
    if ($rows === []) {
        return [];
    }
    $ids = array_map(static fn(array $row): string => (string) $row['id'], $rows);
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $files = $pdo->prepare("SELECT * FROM staff_app_files WHERE message_id IN ($marks)");
    $files->execute($ids);
    $byMessage = [];
    foreach ($files->fetchAll() ?: [] as $file) {
        $byMessage[(string) $file['message_id']][] = $file;
    }
    foreach ($rows as &$row) {
        $row['files'] = $byMessage[(string) $row['id']] ?? [];
    }
    unset($row);

    return $rows;
}

function staff_app_create_room(PDO $pdo, array $user, string $title, array $memberIds): string
{
    $title = trim($title);
    if ($title === '') {
        throw new RuntimeException('برای اتاق یک نام بنویسید.');
    }
    if (mb_strlen($title) > 80) {
        throw new RuntimeException('نام اتاق طولانی است.');
    }
    $people = [];
    foreach (staff_app_people($pdo) as $person) {
        $people[(string) $person['id']] = $person;
    }
    $userId = (string) ($user['id'] ?? '');
    $chosen = [];
    foreach ($memberIds as $memberId) {
        $memberId = trim((string) $memberId);
        if ($memberId !== '' && $memberId !== $userId && isset($people[$memberId])) {
            $chosen[$memberId] = $memberId;
        }
    }
    if ($chosen === []) {
        throw new RuntimeException('حداقل یک درمانگر یا منشی دیگر را برای اتاق انتخاب کنید.');
    }
    $roomId = cuid();
    $pdo->beginTransaction();
    try {
        $pdo->prepare('INSERT INTO staff_app_rooms (id, title, is_general, created_by) VALUES (?,?,0,?)')
            ->execute([$roomId, $title, $userId]);
        $add = $pdo->prepare('INSERT INTO staff_app_members (room_id, user_id) VALUES (?,?)');
        $add->execute([$roomId, $userId]);
        foreach ($chosen as $memberId) {
            $add->execute([$roomId, $memberId]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return $roomId;
}

function staff_app_allowed_upload_types(): array
{
    return [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
        'application/pdf' => 'pdf',
        'text/plain' => 'txt',
        'application/zip' => 'zip',
        'application/x-zip-compressed' => 'zip',
        'audio/mpeg' => 'mp3',
        'audio/mp4' => 'm4a',
        'audio/x-m4a' => 'm4a',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.ms-excel' => 'xls',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
    ];
}

function staff_app_store_upload(array $file): ?array
{
    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($error !== UPLOAD_ERR_OK) {
        throw new RuntimeException('فایل فرستاده نشد.');
    }
    $size = (int) ($file['size'] ?? 0);
    if ($size <= 0 || $size > 12 * 1024 * 1024) {
        throw new RuntimeException('حجم فایل باید کمتر از ۱۲ مگابایت باشد.');
    }
    $tmp = (string) ($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        throw new RuntimeException('فایل معتبر نیست.');
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string) $finfo->file($tmp);
    $ext = staff_app_allowed_upload_types()[$mime] ?? '';
    if ($ext === '') {
        throw new RuntimeException('این نوع فایل مجاز نیست. عکس، پی‌دی‌اف، زیپ، صوت یا فایل آفیس بفرستید.');
    }
    $dir = staff_app_upload_dir();
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new RuntimeException('پوشه فایل ساخته نشد.');
    }
    $stored = bin2hex(random_bytes(16)) . '.' . $ext;
    if (!move_uploaded_file($tmp, $dir . '/' . $stored)) {
        throw new RuntimeException('ذخیره فایل انجام نشد.');
    }
    $original = basename(str_replace(["\0", '/', '\\'], '', (string) ($file['name'] ?? 'file')));
    $original = trim(preg_replace('/[\r\n\t]+/', ' ', $original) ?? '');
    if ($original === '') {
        $original = 'file.' . $ext;
    }
    if (mb_strlen($original) > 160) {
        $original = mb_substr($original, 0, 160);
    }

    return [
        'stored_name' => $stored,
        'original_name' => $original,
        'mime' => $mime,
        'size' => $size,
    ];
}

function staff_app_send_message(PDO $pdo, array $user, string $roomId, string $body, ?array $file): void
{
    $room = staff_app_room_for_member($pdo, $roomId, (string) ($user['id'] ?? ''));
    if (!$room) {
        throw new RuntimeException('به این اتاق دسترسی ندارید.');
    }
    $body = trim($body);
    if (mb_strlen($body) > 4000) {
        throw new RuntimeException('متن پیام طولانی است.');
    }
    $stored = null;
    if (is_array($file)) {
        $stored = staff_app_store_upload($file);
    }
    if ($body === '' && $stored === null) {
        throw new RuntimeException('متن یا فایل را بفرستید.');
    }
    $messageId = cuid();
    $pdo->beginTransaction();
    try {
        $pdo->prepare('INSERT INTO staff_app_messages (id, room_id, user_id, body) VALUES (?,?,?,?)')
            ->execute([$messageId, $roomId, (string) $user['id'], $body]);
        if ($stored) {
            $pdo->prepare('INSERT INTO staff_app_files (id, message_id, stored_name, original_name, mime, size) VALUES (?,?,?,?,?,?)')
                ->execute([cuid(), $messageId, $stored['stored_name'], $stored['original_name'], $stored['mime'], $stored['size']]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($stored) {
            @unlink(staff_app_upload_dir() . '/' . $stored['stored_name']);
        }
        throw $e;
    }
    staff_app_mark_read($pdo, (string) $user['id'], $roomId);
}

function staff_app_output_file(PDO $pdo, array $user, string $fileId): never
{
    $stmt = $pdo->prepare("
      SELECT f.stored_name, f.original_name, f.mime
      FROM staff_app_files f
      JOIN staff_app_messages m ON m.id = f.message_id
      JOIN staff_app_members mem ON mem.room_id = m.room_id AND mem.user_id = ?
      WHERE f.id = ?
      LIMIT 1
    ");
    $stmt->execute([(string) ($user['id'] ?? ''), $fileId]);
    $file = $stmt->fetch();
    $stored = (string) ($file['stored_name'] ?? '');
    $path = staff_app_upload_dir() . '/' . $stored;
    if (!$file || !preg_match('/^[a-f0-9]{32}\.[a-z0-9]{1,5}$/', $stored) || !is_file($path)) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'فایل پیدا نشد.';
        exit;
    }
    $mime = (string) ($file['mime'] ?? 'application/octet-stream');
    $inline = str_starts_with($mime, 'image/') || $mime === 'application/pdf';
    $name = (string) ($file['original_name'] ?? 'file');
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . (string) filesize($path));
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . "; filename*=UTF-8''" . rawurlencode($name));
    readfile($path);
    exit;
}

function staff_app_doctor_profile_id(PDO $pdo, string $userId): string
{
    $stmt = $pdo->prepare('SELECT id FROM doctor_profiles WHERE user_id = ? LIMIT 1');
    $stmt->execute([$userId]);

    return (string) ($stmt->fetchColumn() ?: '');
}

/** @return list<array<string, mixed>> */
function staff_app_appointment_rows(PDO $pdo, array $user): array
{
    if (!function_exists('ensure_clinic_rooms_schema') && is_file(__DIR__ . '/clinic_rooms.php')) {
        require_once __DIR__ . '/clinic_rooms.php';
    }
    if (function_exists('ensure_clinic_rooms_schema')) {
        ensure_clinic_rooms_schema($pdo);
    }
    $from = date('Y-m-d 00:00:00');
    $to = date('Y-m-d 00:00:00', strtotime('+10 days') ?: time());
    $params = [$from, $to];
    $doctorSql = '';
    if ((string) ($user['role'] ?? '') === 'DOCTOR') {
        $profileId = staff_app_doctor_profile_id($pdo, (string) ($user['id'] ?? ''));
        if ($profileId === '') {
            return [];
        }
        $doctorSql = ' AND a.doctor_id = ?';
        $params[] = $profileId;
    }
    $stmt = $pdo->prepare("
      SELECT a.*,
             pu.name AS patient_name, du.name AS doctor_name,
             (SELECT rb.room_no FROM clinic_room_bookings rb
               WHERE rb.appointment_id = a.id
               ORDER BY rb.starts_at DESC LIMIT 1) AS room_no
      FROM appointments a
      JOIN users pu ON pu.id = a.patient_id
      JOIN doctor_profiles dp ON dp.id = a.doctor_id
      JOIN users du ON du.id = dp.user_id
      WHERE a.starts_at >= ? AND a.starts_at < ?
        AND a.status <> 'CANCELLED'
        {$doctorSql}
      ORDER BY a.starts_at ASC
      LIMIT 300
    ");
    $stmt->execute($params);

    return $stmt->fetchAll() ?: [];
}

/** @return list<array<string, mixed>> */
function staff_app_room_board(PDO $pdo): array
{
    if (!function_exists('clinic_rooms_between') && is_file(__DIR__ . '/clinic_rooms.php')) {
        require_once __DIR__ . '/clinic_rooms.php';
    }
    if (!function_exists('clinic_rooms_between')) {
        return [];
    }
    $from = date('Y-m-d 00:00:00');
    $to = date('Y-m-d 00:00:00', strtotime('+7 days') ?: time());

    return clinic_rooms_between($pdo, $from, $to);
}

function staff_app_render(string $active, string $title, string $description, string $html, bool $locked = false): void
{
    global $pdo;
    $user = current_user();
    $path = (string) ($GLOBALS['path'] ?? '/app');
    $GLOBALS['pageTitle'] = $title;
    $GLOBALS['pageDescription'] = $description;
    $GLOBALS['pageCanonical'] = seo_absolute_url($path);
    $GLOBALS['pageRobots'] = 'noindex,nofollow';
    $GLOBALS['pageKeywords'] = 'برنامه داخلی مانا کلینیک, درمانگر, منشی';
    $unread = 0;
    if (!$locked && $user && $pdo instanceof PDO) {
        $unread = staff_app_unread_count($pdo, (string) ($user['id'] ?? ''));
    }
    $flash = function_exists('flash_get') ? flash_get() : null;
    $name = trim((string) ($user['name'] ?? ''));
    $role = (string) ($user['role'] ?? '');
    $isSecretary = $role === 'SECRETARY';
    $avatarSrc = '';
    $avatarInitial = 'م';
    if ($user && !$locked && $pdo instanceof PDO) {
        if (!function_exists('user_hydrate_session_avatar')) {
            require_once __DIR__ . '/user_avatar.php';
        }
        $fresh = user_hydrate_session_avatar($pdo, $user);
        if (is_array($fresh)) {
            $user = $fresh;
            $name = trim((string) ($user['name'] ?? $name));
        }
        $avatarSrc = user_avatar_src((string) ($user['avatar_url'] ?? ''));
        $avatarInitial = user_avatar_initial($name !== '' ? $name : 'م');
    }
    $navKey = $active === 'chat' ? 'chat' : ($active === 'profile' ? 'profile' : 'home');
    header('X-Robots-Tag: noindex, nofollow');
    ?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
  <meta name="mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <link rel="manifest" href="<?= e(url('/assets/staff-app.webmanifest')) ?>">
  <?= seo_render_head() ?>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= e(url('/assets/css/style.css')) ?>?v=20261008staff2">
  <?php if (!empty($GLOBALS['pageHead'])): ?>
    <?= $GLOBALS['pageHead'] ?>
  <?php endif; ?>
  <style>
    body.sapp{margin:0;background:#f3f6f4;color:#1c3d36;font-family:Vazirmatn,Tahoma,sans-serif}
    .sapp-top{position:sticky;top:0;z-index:20;display:flex;align-items:center;justify-content:space-between;gap:12px;padding:14px 16px 8px;background:#f3f6f4;direction:ltr}
    .sapp-grid,.sapp-nav{direction:ltr}
    .sapp-brand{display:flex;align-items:center;gap:8px;color:#1c3d36;text-decoration:none;font-weight:800;font-size:1.05rem}
    .sapp-brand img{width:36px;height:36px;border-radius:12px;background:#128f84;object-fit:cover}
    .sapp-tools{display:flex;align-items:center;gap:8px}
    .sapp-avatar{width:38px;height:38px;border-radius:999px;background:#e7eeeb;display:inline-flex;align-items:center;justify-content:center;overflow:hidden;color:#1c3d36;font-weight:800;border:2px solid #fff;box-shadow:0 2px 8px rgba(20,60,50,.08);text-decoration:none}
    .sapp-avatar img{width:100%;height:100%;object-fit:cover;display:block}
    .sapp-logout{display:inline-flex;align-items:center;justify-content:center;min-height:36px;padding:0 14px;border-radius:999px;background:#1a9a8a;color:#fff;text-decoration:none;font-weight:800;font-size:.92rem}
    .sapp-main{max-width:32rem;margin:0 auto;padding:8px 16px 132px}
    .sapp-hello h1{margin:8px 0 2px;font-size:1.28rem;font-weight:800;line-height:1.45;color:#1c3d36}
    .sapp-hello p{margin:0 0 14px;color:#8aa099;font-size:.92rem}
    .sapp-main h1{margin:0 0 .35rem;font-size:1.28rem;color:#1c3d36}
    .sapp-flash{margin:0 0 12px;padding:10px 12px;border-radius:12px;background:#e7f6f3}
    .sapp-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
    .sapp-tile{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:8px;min-height:148px;padding:16px 10px;border-radius:22px;background:#fff;text-decoration:none;color:#1c3d36;box-shadow:0 10px 28px rgba(28,70,58,.06)}
    .sapp-tile strong{font-size:1.02rem;font-weight:800}
    .sapp-tile em{font-style:normal;color:#9aaba4;font-size:.78rem}
    .sapp-ico{width:52px;height:52px;border-radius:16px;display:flex;align-items:center;justify-content:center}
    .sapp-ico svg{width:28px;height:28px}
    .sapp-ico-blue{background:#e7f3fb;color:#3d8fd4}
    .sapp-ico-green{background:#e7f6ee;color:#3aaa78}
    .sapp-ico-purple{background:#f3eefb;color:#8b72d6}
    .sapp-ico-amber{background:#fff4e8;color:#e0a15a}
    .sapp-ico-pink{background:#fdeef3;color:#e07a9a}
    .sapp-ico-red{background:#fdeeee;color:#e07070}
    .sapp-nav{position:fixed;right:0;left:0;bottom:0;z-index:30;display:grid;grid-template-columns:repeat(3,1fr);padding:6px 8px calc(8px + env(safe-area-inset-bottom));background:#fff;border-top:1px solid #e6eeea}
    .sapp-nav a{display:flex;flex-direction:column;align-items:center;gap:1px;text-decoration:none;color:#8aa099;font-size:.78rem;font-weight:700}
    .sapp-nav a.is-active{color:#159688}
    .sapp-nav-ico{width:46px;height:32px;display:flex;align-items:center;justify-content:center;border-radius:14px}
    .sapp-nav a.is-active .sapp-nav-ico{background:#e7f6f3}
    .sapp-nav svg{width:22px;height:22px}
    .sapp-badge{position:absolute;top:2px;left:50%;transform:translateX(-18px);min-width:1.1rem;height:1.1rem;padding:0 4px;border-radius:999px;background:#e07070;color:#fff;font-size:.68rem;display:inline-flex;align-items:center;justify-content:center}
    .sapp-nav a{position:relative}
    .sapp-fab{position:fixed;z-index:31;right:18px;bottom:calc(76px + env(safe-area-inset-bottom));display:flex;align-items:center;gap:8px;color:#1c3d36;text-decoration:none;font-weight:800;font-size:.92rem}
    .sapp-fab-btn{width:54px;height:54px;border-radius:999px;background:#1a9a8a;color:#fff;display:flex;align-items:center;justify-content:center;box-shadow:0 10px 22px rgba(26,154,138,.35)}
    .sapp-fab-btn svg{width:26px;height:26px}
    .sapp-profile{display:flex;flex-direction:column;align-items:center;gap:8px;margin-top:18px;padding:22px 16px;border-radius:22px;background:#fff;box-shadow:0 10px 28px rgba(28,70,58,.06)}
    .sapp-profile .sapp-avatar{width:72px;height:72px;font-size:1.6rem}
    .sapp-list{display:flex;flex-direction:column;gap:8px;margin-top:12px}
    .sapp-row{padding:12px;border:1px solid var(--line);border-radius:14px;background:var(--card)}
    .sapp-row small{display:block;margin-top:4px;color:var(--muted)}
    .sapp-day{margin:16px 0 0;font-size:1rem}
    .sapp-chat{display:flex;flex-direction:column;gap:8px;margin-top:12px}
    .sapp-msg{padding:10px 12px;border-radius:14px;background:var(--bg-soft)}
    .sapp-msg.is-mine{background:var(--card);border:1px solid var(--primary)}
    .sapp-msg img{display:block;max-width:min(100%,18rem);margin-top:8px;border-radius:10px}
    .sapp-compose{display:flex;flex-direction:column;gap:8px;margin-top:12px}
    body.sapp{padding:0}
  </style>
</head>
<body class="sapp"<?= $user ? ' data-session-guard="1" data-session-ping="' . e(url('/session/ping')) . '" data-logout="' . e(url('/logout')) . '"' : '' ?><?= $isSecretary ? ' data-secretary-desk="1" data-no-idle="1" data-heartbeat="' . e(url('/secretary/heartbeat')) . '"' : '' ?>>
  <header class="sapp-top">
    <a class="sapp-brand" href="<?= e(url('/app')) ?>">
      <img src="<?= e(url('/assets/img/logo.png')) ?>" alt="" width="36" height="36">
      Mana Staff
    </a>
    <?php if ($user): ?>
      <div class="sapp-tools">
        <?php if (!$locked): ?>
          <a class="sapp-avatar" href="<?= e(url('/app/profile')) ?>" aria-label="پروفایل">
            <?php if ($avatarSrc !== ''): ?>
              <img src="<?= e(url($avatarSrc)) ?>" alt="" width="38" height="38">
            <?php else: ?>
              <?= e($avatarInitial) ?>
            <?php endif; ?>
          </a>
        <?php endif; ?>
        <a class="sapp-logout" href="<?= e(url('/logout')) ?>">خروج</a>
      </div>
    <?php endif; ?>
  </header>
  <main class="sapp-main">
    <?php if (is_array($flash) && trim((string) ($flash['message'] ?? '')) !== ''): ?>
      <p class="sapp-flash"><?= e((string) $flash['message']) ?></p>
    <?php endif; ?>
    <?= $html ?>
  </main>
  <?php if (!$locked && $active === 'home'): ?>
    <a class="sapp-fab" href="<?= e(url('/app/chat')) ?>#sapp-new-chat">
      <span class="sapp-fab-btn" aria-hidden="true">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M7 17.5 4 20V6.5A2.5 2.5 0 0 1 6.5 4h11A2.5 2.5 0 0 1 20 6.5v8A2.5 2.5 0 0 1 17.5 17H7z"/><path d="M8 9h8M8 12.5h5"/></svg>
      </span>
      <span>چت جدید</span>
    </a>
  <?php endif; ?>
  <?php if (!$locked): ?>
    <nav class="sapp-nav" aria-label="بخش‌های برنامه">
      <a class="<?= $navKey === 'home' ? 'is-active' : '' ?>" href="<?= e(url('/app')) ?>">
        <span class="sapp-nav-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 10.5 12 4l8 6.5V20a1 1 0 0 1-1 1h-5v-6H10v6H5a1 1 0 0 1-1-1v-9.5z"/></svg></span>
        خانه
      </a>
      <a class="<?= $navKey === 'chat' ? 'is-active' : '' ?>" href="<?= e(url('/app/chat')) ?>">
        <span class="sapp-nav-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M7 17.5 4 20V6.5A2.5 2.5 0 0 1 6.5 4h11A2.5 2.5 0 0 1 20 6.5v8A2.5 2.5 0 0 1 17.5 17H7z"/></svg></span>
        گفتگو
        <?php if ($unread > 0): ?><span class="sapp-badge"><?= e(to_fa_digits((string) $unread)) ?></span><?php endif; ?>
      </a>
      <a class="<?= $navKey === 'profile' ? 'is-active' : '' ?>" href="<?= e(url('/app/profile')) ?>">
        <span class="sapp-nav-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="3.2"/><path d="M5.5 19.2a6.5 6.5 0 0 1 13 0"/></svg></span>
        پروفایل
      </a>
    </nav>
  <?php endif; ?>
  <?php if ($user): ?>
    <script src="<?= e(url('/assets/js/session-guard.js')) ?>?v=20261001idle"></script>
  <?php endif; ?>
  <?php if ($isSecretary): ?>
    <script src="<?= e(url('/assets/js/secretary-idle.js')) ?>?v=20261001idle"></script>
  <?php endif; ?>
  <?php if (!empty($GLOBALS['pageScripts'])): ?>
    <?= $GLOBALS['pageScripts'] ?>
  <?php endif; ?>
</body>
</html>
    <?php
}
