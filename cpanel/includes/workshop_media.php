<?php
declare(strict_types=1);

function ensure_workshop_media_schema(PDO $pdo): void
{
    $pdo->exec("
      CREATE TABLE IF NOT EXISTS workshop_media_items (
        id VARCHAR(32) PRIMARY KEY,
        workshop_id VARCHAR(32) NOT NULL,
        kind ENUM('VIDEO','AUDIO') NOT NULL,
        title VARCHAR(255) NOT NULL,
        description TEXT NULL,
        file_path VARCHAR(500) NOT NULL,
        original_name VARCHAR(255) NOT NULL,
        mime_type VARCHAR(120) NOT NULL,
        file_size INT NOT NULL DEFAULT 0,
        sort_order INT NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_media_workshop (workshop_id, sort_order),
        CONSTRAINT fk_media_workshop FOREIGN KEY (workshop_id) REFERENCES workshops(id) ON DELETE CASCADE
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    try {
        $col = $pdo->query("SHOW COLUMNS FROM workshop_media_items LIKE 'session_id'")->fetch();
        if (!$col) {
            $pdo->exec("ALTER TABLE workshop_media_items ADD COLUMN session_id VARCHAR(32) NULL AFTER workshop_id");
        }
    } catch (Throwable $ignored) {
    }
    try {
        $pdo->exec("ALTER TABLE workshop_media_items MODIFY kind ENUM('VIDEO','AUDIO','PDF') NOT NULL");
    } catch (Throwable $ignored) {
    }
    workshop_media_ensure_storage();
    require_once __DIR__ . '/workshop_sessions.php';
    ensure_workshop_sessions_schema($pdo);
}

function workshop_media_storage_root(): string
{
    return dirname(__DIR__) . '/storage/workshop_media';
}

function workshop_media_ensure_storage(): void
{
    $root = workshop_media_storage_root();
    if (!is_dir($root)) {
        mkdir($root, 0755, true);
    }
    $htaccess = $root . '/.htaccess';
    if (!is_file($htaccess)) {
        file_put_contents($htaccess, "Require all denied\n");
    }
}

function workshop_media_max_bytes(): int
{
    global $config;
    $mb = (int) ($config['workshop_media_max_mb'] ?? 300);
    return max(10, $mb) * 1024 * 1024;
}

function workshop_media_kind_label(string $kind): string
{
    return match ($kind) {
        'AUDIO' => 'صوت',
        'PDF' => 'پی‌دی‌اف',
        default => 'ویدیو',
    };
}

function workshop_media_allowed_specs(string $kind): array
{
    if ($kind === 'PDF') {
        return [
            'application/pdf' => 'pdf',
            'application/x-pdf' => 'pdf',
        ];
    }
    if ($kind === 'AUDIO') {
        return [
            'audio/mpeg' => 'mp3',
            'audio/mp3' => 'mp3',
            'audio/mp4' => 'm4a',
            'audio/x-m4a' => 'm4a',
            'audio/aac' => 'aac',
            'audio/ogg' => 'ogg',
            'audio/wav' => 'wav',
            'audio/x-wav' => 'wav',
            'audio/webm' => 'webm',
        ];
    }
    return [
        'video/mp4' => 'mp4',
        'video/webm' => 'webm',
        'video/quicktime' => 'mov',
        'video/x-msvideo' => 'avi',
    ];
}

function workshop_media_list(PDO $pdo, string $workshopId): array
{
    ensure_workshop_media_schema($pdo);
    $stmt = $pdo->prepare('SELECT * FROM workshop_media_items WHERE workshop_id=? ORDER BY sort_order ASC, created_at ASC');
    $stmt->execute([$workshopId]);
    return $stmt->fetchAll();
}

function workshop_media_count(PDO $pdo, string $workshopId): int
{
    ensure_workshop_media_schema($pdo);
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM workshop_media_items WHERE workshop_id=?');
    $stmt->execute([$workshopId]);
    return (int) $stmt->fetchColumn();
}

/** تعداد ویدیو و صوت بارگذاری‌شده برای هر کارگاه */
function workshop_media_kind_counts(PDO $pdo, string $workshopId): array
{
    ensure_workshop_media_schema($pdo);
    $stmt = $pdo->prepare("
      SELECT kind, COUNT(*) AS cnt
      FROM workshop_media_items
      WHERE workshop_id = ?
      GROUP BY kind
    ");
    $stmt->execute([$workshopId]);
    $counts = ['video' => 0, 'audio' => 0, 'pdf' => 0, 'total' => 0];
    foreach ($stmt->fetchAll() as $row) {
        if ($row['kind'] === 'VIDEO') {
            $counts['video'] = (int) $row['cnt'];
        } elseif ($row['kind'] === 'AUDIO') {
            $counts['audio'] = (int) $row['cnt'];
        } elseif ($row['kind'] === 'PDF') {
            $counts['pdf'] = (int) $row['cnt'];
        }
    }
    $counts['total'] = $counts['video'] + $counts['audio'] + $counts['pdf'];
    return $counts;
}

function workshop_media_kind_counts_from_list(array $items): array
{
    $counts = ['video' => 0, 'audio' => 0, 'pdf' => 0, 'total' => 0];
    foreach ($items as $item) {
        if (($item['kind'] ?? '') === 'VIDEO') {
            $counts['video']++;
        } elseif (($item['kind'] ?? '') === 'AUDIO') {
            $counts['audio']++;
        } elseif (($item['kind'] ?? '') === 'PDF') {
            $counts['pdf']++;
        }
    }
    $counts['total'] = $counts['video'] + $counts['audio'] + $counts['pdf'];
    return $counts;
}

function workshop_media_counts_from_row(array $row): array
{
    $video = (int) ($row['video_count'] ?? $row['media_video_count'] ?? 0);
    $audio = (int) ($row['audio_count'] ?? $row['media_audio_count'] ?? 0);
    $pdf = (int) ($row['pdf_count'] ?? $row['media_pdf_count'] ?? 0);
    $total = (int) ($row['media_count'] ?? 0);
    if ($total < 1) {
        $total = $video + $audio + $pdf;
    }
    return ['video' => $video, 'audio' => $audio, 'pdf' => $pdf, 'total' => $total];
}

/** نمایشگر تعداد ویدیو/صوت */
function workshop_media_counts_html(array $counts, bool $hideWhenEmpty = true): string
{
    $video = (int) ($counts['video'] ?? 0);
    $audio = (int) ($counts['audio'] ?? 0);
    $pdf = (int) ($counts['pdf'] ?? 0);
    $total = $video + $audio + $pdf;
    if ($hideWhenEmpty && $total < 1) {
        return '';
    }

    $parts = [];
    if ($video > 0) {
        $parts[] = '<span class="media-stat media-stat-video" title="تعداد ویدیو">'
            . '<span class="media-stat-icon" aria-hidden="true">▶</span>'
            . '<span class="media-stat-num">' . $video . '</span>'
            . '<span class="media-stat-label">ویدیو</span></span>';
    }
    if ($audio > 0) {
        $parts[] = '<span class="media-stat media-stat-audio" title="تعداد صوت">'
            . '<span class="media-stat-icon" aria-hidden="true">♫</span>'
            . '<span class="media-stat-num">' . $audio . '</span>'
            . '<span class="media-stat-label">صوت</span></span>';
    }
    if ($pdf > 0) {
        $parts[] = '<span class="media-stat media-stat-pdf" title="تعداد پی‌دی‌اف">'
            . '<span class="media-stat-icon" aria-hidden="true">📄</span>'
            . '<span class="media-stat-num">' . $pdf . '</span>'
            . '<span class="media-stat-label">پی‌دی‌اف</span></span>';
    }
    if (!$parts && !$hideWhenEmpty) {
        $parts[] = '<span class="media-stat media-stat-empty">بدون فایل</span>';
    }
    if (!$parts) {
        return '';
    }

    return '<span class="media-stats" role="group" aria-label="تعداد فایل‌های بارگذاری‌شده">'
        . implode('', $parts)
        . '</span>';
}

function workshop_media_doctor_owns(PDO $pdo, string $workshopId, string $doctorProfileId): bool
{
    $stmt = $pdo->prepare('SELECT id FROM workshops WHERE id=? AND doctor_id=? LIMIT 1');
    $stmt->execute([$workshopId, $doctorProfileId]);
    return (bool) $stmt->fetch();
}

function workshop_media_get(PDO $pdo, string $itemId): ?array
{
    $stmt = $pdo->prepare('SELECT m.*, w.type AS workshop_type, w.doctor_id FROM workshop_media_items m JOIN workshops w ON w.id = m.workshop_id WHERE m.id=? LIMIT 1');
    $stmt->execute([$itemId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function workshop_media_patient_can_access(PDO $pdo, string $patientId, string $itemId): bool
{
    $item = workshop_media_get($pdo, $itemId);
    if (!$item) {
        return false;
    }
    $stmt = $pdo->prepare("
      SELECT e.id FROM workshop_enrollments e
      WHERE e.workshop_id = ? AND e.patient_id = ? AND e.status IN ('CONFIRMED','COMPLETED')
      LIMIT 1
    ");
    $stmt->execute([$item['workshop_id'], $patientId]);
    return (bool) $stmt->fetch();
}

function workshop_media_enrollment_access(PDO $pdo, string $patientId, string $enrollmentId): ?array
{
    $stmt = $pdo->prepare("
      SELECT e.*, w.title, w.type, w.status AS workshop_status, w.ends_at
      FROM workshop_enrollments e
      JOIN workshops w ON w.id = e.workshop_id
      WHERE e.id = ? AND e.patient_id = ?
        AND e.status IN ('CONFIRMED','COMPLETED')
      LIMIT 1
    ");
    $stmt->execute([$enrollmentId, $patientId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function workshop_courses_tab_for_type(string $type): string
{
    return match ($type) {
        'OFFLINE' => 'offline',
        'ONLINE' => 'online',
        default => 'in-person',
    };
}

function workshop_media_course_url(string $enrollmentId): string
{
    return url('/dashboard/courses/media?enrollment=' . rawurlencode($enrollmentId));
}

function workshop_media_watermark_for_user(array $user, ?PDO $pdo = null): string
{
    $label = trim((string) ($user['name'] ?? ''));
    $phone = trim((string) ($user['phone'] ?? ''));
    if (($label === '' || $phone === '') && $pdo !== null && !empty($user['id'])) {
        $stmt = $pdo->prepare('SELECT name, phone FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([(string) $user['id']]);
        $row = $stmt->fetch();
        if ($row) {
            if ($label === '') {
                $label = trim((string) ($row['name'] ?? ''));
            }
            if ($phone === '') {
                $phone = trim((string) ($row['phone'] ?? ''));
            }
        }
    }
    if ($label === '') {
        $label = 'مراجعه‌کننده';
    }
    if ($phone !== '') {
        return $label . ' · ' . $phone;
    }
    return $label;
}

function workshop_media_detect_mime(string $tmpPath): string
{
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = $finfo ? (string) finfo_file($finfo, $tmpPath) : '';
    if ($finfo) {
        finfo_close($finfo);
    }
    return strtolower($mime);
}

function workshop_media_workshop_exists(PDO $pdo, string $workshopId): bool
{
    $stmt = $pdo->prepare('SELECT id FROM workshops WHERE id=? LIMIT 1');
    $stmt->execute([$workshopId]);
    return (bool) $stmt->fetch();
}

function workshop_media_save_upload(PDO $pdo, string $workshopId, ?string $doctorProfileId, string $kind, string $title, ?string $description, array $file, ?string $sessionId = null): string
{
    ensure_workshop_media_schema($pdo);
    if ($doctorProfileId !== null) {
        if (!workshop_media_doctor_owns($pdo, $workshopId, $doctorProfileId)) {
            throw new RuntimeException('کارگاه یافت نشد.');
        }
    } elseif (!workshop_media_workshop_exists($pdo, $workshopId)) {
        throw new RuntimeException('کارگاه یافت نشد.');
    }
    if (!in_array($kind, ['VIDEO', 'AUDIO', 'PDF'], true)) {
        throw new RuntimeException('نوع فایل نامعتبر است.');
    }
    if ($title === '') {
        throw new RuntimeException('عنوان محتوا را بنویسید.');
    }
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('آپلود فایل ناموفق بود.');
    }
    if (($file['size'] ?? 0) > workshop_media_max_bytes()) {
        throw new RuntimeException('حجم فایل بیش از حد مجاز است.');
    }

    $mime = workshop_media_detect_mime((string) $file['tmp_name']);
    $allowed = workshop_media_allowed_specs($kind);
    if (!isset($allowed[$mime])) {
        throw new RuntimeException('فرمت فایل پشتیبانی نمی‌شود.');
    }

    $id = cuid();
    $ext = $allowed[$mime];
    $dir = workshop_media_storage_root() . '/' . $workshopId;
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $relative = $workshopId . '/' . $id . '.' . $ext;
    $dest = workshop_media_storage_root() . '/' . $relative;
    if (!move_uploaded_file((string) $file['tmp_name'], $dest)) {
        throw new RuntimeException('ذخیره فایل ناموفق بود.');
    }

    $sort = workshop_media_count($pdo, $workshopId);
    if ($sessionId) {
        $old = $pdo->prepare('SELECT id FROM workshop_media_items WHERE workshop_id=? AND session_id=? AND kind=? LIMIT 1');
        $old->execute([$workshopId, $sessionId, $kind]);
        $oldId = (string) ($old->fetchColumn() ?: '');
        if ($oldId !== '') {
            workshop_media_delete($pdo, $oldId, $doctorProfileId);
        }
    }
    $pdo->prepare('
      INSERT INTO workshop_media_items
        (id, workshop_id, session_id, kind, title, description, file_path, original_name, mime_type, file_size, sort_order)
      VALUES (?,?,?,?,?,?,?,?,?,?,?)
    ')->execute([
        $id,
        $workshopId,
        $sessionId,
        $kind,
        $title,
        $description ?: null,
        $relative,
        (string) ($file['name'] ?? 'file'),
        $mime,
        (int) ($file['size'] ?? 0),
        $sort,
    ]);

    return $id;
}

function workshop_media_process_session_uploads(PDO $pdo, string $workshopId, ?string $doctorProfileId): int
{
    require_once __DIR__ . '/workshop_sessions.php';
    $sessions = workshop_sessions_list($pdo, $workshopId);
    $byDate = [];
    foreach ($sessions as $session) {
        $byDate[(string) $session['session_date']] = $session;
    }
    $files = $_FILES['session_file'] ?? [];
    if (!is_array($files['name'] ?? null)) {
        return 0;
    }
    $saved = 0;
    foreach ($files['name'] as $date => $kinds) {
        $date = (string) $date;
        if (!isset($byDate[$date]) || !is_array($kinds)) {
            continue;
        }
        $session = $byDate[$date];
        foreach ($kinds as $kind => $unusedName) {
            $kind = strtoupper((string) $kind);
            $file = [
                'name' => (string) ($files['name'][$date][$kind] ?? ''),
                'type' => (string) ($files['type'][$date][$kind] ?? ''),
                'tmp_name' => (string) ($files['tmp_name'][$date][$kind] ?? ''),
                'error' => (int) ($files['error'][$date][$kind] ?? UPLOAD_ERR_NO_FILE),
                'size' => (int) ($files['size'][$date][$kind] ?? 0),
            ];
            if ($file['error'] === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $title = (string) $session['title'] . ' — ' . workshop_media_kind_label($kind);
            workshop_media_save_upload($pdo, $workshopId, $doctorProfileId, $kind, $title, null, $file, (string) $session['id']);
            $saved++;
        }
    }
    return $saved;
}

/** بارگذاری چند فایل از فرم ایجاد/ویرایش کارگاه — doctorProfileId=null یعنی دسترسی منشی */
function workshop_media_process_form_uploads(PDO $pdo, string $workshopId, ?string $doctorProfileId): int
{
    $kinds = $_POST['media_kind'] ?? [];
    $titles = $_POST['media_title'] ?? [];
    $descriptions = $_POST['media_description'] ?? [];
    $files = $_FILES['media_files'] ?? [];

    if (!is_array($kinds) || !is_array($files['name'] ?? null)) {
        return 0;
    }

    $saved = 0;
    foreach ($kinds as $i => $kind) {
        $kind = (string) $kind;
        $title = trim((string) ($titles[$i] ?? ''));
        $description = trim((string) ($descriptions[$i] ?? '')) ?: null;
        $file = [
            'name' => (string) ($files['name'][$i] ?? ''),
            'type' => (string) ($files['type'][$i] ?? ''),
            'tmp_name' => (string) ($files['tmp_name'][$i] ?? ''),
            'error' => (int) ($files['error'][$i] ?? UPLOAD_ERR_NO_FILE),
            'size' => (int) ($files['size'][$i] ?? 0),
        ];

        if ($file['error'] === UPLOAD_ERR_NO_FILE) {
            if ($title !== '') {
                throw new RuntimeException('برای هر ردیف محتوا، فایل را هم انتخاب کنید.');
            }
            continue;
        }

        workshop_media_save_upload($pdo, $workshopId, $doctorProfileId, $kind, $title, $description, $file);
        $saved++;
    }

    return $saved;
}

function workshop_media_delete(PDO $pdo, string $itemId, ?string $doctorProfileId): void
{
    $item = workshop_media_get($pdo, $itemId);
    if (!$item) {
        throw new RuntimeException('فایل یافت نشد.');
    }
    if ($doctorProfileId !== null && !workshop_media_doctor_owns($pdo, (string) $item['workshop_id'], $doctorProfileId)) {
        throw new RuntimeException('فایل یافت نشد.');
    }
    $abs = workshop_media_storage_root() . '/' . $item['file_path'];
    if (is_file($abs)) {
        unlink($abs);
    }
    $pdo->prepare('DELETE FROM workshop_media_items WHERE id=?')->execute([$itemId]);
}

function workshop_media_stream_secret(): string
{
    global $config;
    if (!empty($config['media_stream_secret'])) {
        return (string) $config['media_stream_secret'];
    }
    return hash('sha256', ($config['app_url'] ?? '') . '|' . ($config['session_name'] ?? 'mana_clinic_sess'));
}

function workshop_media_stream_token(string $itemId, string $userId, int $ttl = 14400): array
{
    $exp = time() + $ttl;
    $payload = $itemId . '|' . $userId . '|' . $exp;
    return [
        'exp' => $exp,
        'sig' => hash_hmac('sha256', $payload, workshop_media_stream_secret()),
    ];
}

function workshop_media_verify_stream_token(string $itemId, string $userId, int $exp, string $sig): bool
{
    if ($exp < time()) {
        return false;
    }
    $payload = $itemId . '|' . $userId . '|' . $exp;
    $expected = hash_hmac('sha256', $payload, workshop_media_stream_secret());
    return hash_equals($expected, $sig);
}

function workshop_media_stream_path(array $item): string
{
    $root = realpath(workshop_media_storage_root());
    if ($root === false) {
        return '';
    }
    $relative = str_replace('\\', '/', (string) ($item['file_path'] ?? ''));
    if ($relative === '' || str_contains($relative, '..')) {
        return '';
    }
    $abs = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    $real = realpath($abs);
    if ($real === false || !str_starts_with($real, $root)) {
        return '';
    }
    return $real;
}

function workshop_media_stream_url(string $itemId, ?array $user = null, bool $download = false): string
{
    $user = $user ?? current_user();
    $query = 'id=' . rawurlencode($itemId);
    if ($user && isset($user['id'])) {
        $token = workshop_media_stream_token($itemId, (string) $user['id']);
        $query .= '&exp=' . $token['exp'] . '&sig=' . rawurlencode($token['sig']);
    }
    if ($download) {
        $query .= '&dl=1';
    }
    return url('/workshop-media/stream?' . $query);
}

function workshop_pdf_stamp_temp(string $srcPath, string $watermark): ?string
{
    if (!class_exists('Imagick') || !is_file($srcPath)) {
        return null;
    }
    try {
        $im = new Imagick();
        $im->setResolution(110, 110);
        $im->readImage($srcPath);
        foreach ($im as $page) {
            $w = $page->getImageWidth();
            $h = $page->getImageHeight();
            $draw = new ImagickDraw();
            $draw->setFillColor(new ImagickPixel('rgba(40,40,40,0.22)'));
            $draw->setFontSize(max(16, (int) ($w / 28)));
            $draw->setGravity(Imagick::GRAVITY_CENTER);
            $page->annotateImage($draw, 0, 0, -28, $watermark);
            $draw2 = new ImagickDraw();
            $draw2->setFillColor(new ImagickPixel('rgba(40,40,40,0.18)'));
            $draw2->setFontSize(max(14, (int) ($w / 34)));
            $page->annotateImage($draw2, 0, (int) ($h * 0.28), -28, $watermark);
            $page->annotateImage($draw2, 0, (int) (-$h * 0.28), -28, $watermark);
        }
        $im->setImageFormat('pdf');
        $tmp = sys_get_temp_dir() . '/mana_pdf_' . bin2hex(random_bytes(8)) . '.pdf';
        $im->writeImages($tmp, true);
        $im->clear();
        $im->destroy();
        return is_file($tmp) ? $tmp : null;
    } catch (Throwable $e) {
        return null;
    }
}

function workshop_media_format_size(int $bytes): string
{
    if ($bytes >= 1048576) {
        return round($bytes / 1048576, 1) . ' MB';
    }
    if ($bytes >= 1024) {
        return round($bytes / 1024, 1) . ' KB';
    }
    return $bytes . ' B';
}
