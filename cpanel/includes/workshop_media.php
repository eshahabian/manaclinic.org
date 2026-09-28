<?php
declare(strict_types=1);

function ensure_workshop_media_schema(PDO $pdo): void
{
    static $ready = false;
    if ($ready || $pdo->inTransaction()) {
        return;
    }
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
        $pdo->exec("ALTER TABLE workshop_media_items MODIFY kind ENUM('VIDEO','AUDIO','PDF','PPT') NOT NULL");
    } catch (Throwable $ignored) {
    }
    try {
        $col = $pdo->query("SHOW COLUMNS FROM workshop_media_items LIKE 'placement'")->fetch();
        if (!$col) {
            $pdo->exec("ALTER TABLE workshop_media_items ADD COLUMN placement VARCHAR(16) NOT NULL DEFAULT 'SESSION' AFTER session_id");
        }
    } catch (Throwable $ignored) {
    }
    workshop_media_ensure_storage();
    require_once __DIR__ . '/workshop_sessions.php';
    ensure_workshop_sessions_schema($pdo);
    $ready = true;
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
        'PPT' => 'پاورپوینت',
        default => 'ویدیو',
    };
}

function workshop_media_allowed_specs(string $kind): array
{
    if ($kind === 'PDF') {
        return [
            'application/pdf' => 'pdf',
            'application/x-pdf' => 'pdf',
            'application/octet-stream' => 'pdf',
        ];
    }
    if ($kind === 'PPT') {
        return [
            'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
            'application/zip' => 'pptx',
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
      SELECT e.*, w.title, w.type, w.session_interval, w.status AS workshop_status, w.starts_at, w.ends_at
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

function workshop_media_save_upload(PDO $pdo, string $workshopId, ?string $doctorProfileId, string $kind, string $title, ?string $description, array $file, ?string $sessionId = null, string $placement = 'SESSION'): string
{
    ensure_workshop_media_schema($pdo);
    if ($doctorProfileId !== null) {
        if (!workshop_media_doctor_owns($pdo, $workshopId, $doctorProfileId)) {
            throw new RuntimeException('کارگاه یافت نشد.');
        }
    } elseif (!workshop_media_workshop_exists($pdo, $workshopId)) {
        throw new RuntimeException('کارگاه یافت نشد.');
    }
    if (!in_array($kind, ['VIDEO', 'AUDIO', 'PDF', 'PPT'], true)) {
        throw new RuntimeException('نوع فایل نامعتبر است.');
    }
    if (!in_array($placement, ['SESSION', 'COURSE', 'SLIDES'], true)) {
        $placement = 'SESSION';
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
    if ($kind === 'PPT') {
        $original = strtolower((string) ($file['name'] ?? ''));
        if (!str_ends_with($original, '.pptx')) {
            throw new RuntimeException('پاورپوینت را با پسوند pptx بفرستید تا نام مراجع روی اسلایدها بنشیند.');
        }
        if (in_array($mime, ['application/zip', 'application/octet-stream', 'application/vnd.ms-powerpoint'], true)) {
            $mime = 'application/vnd.openxmlformats-officedocument.presentationml.presentation';
        }
    }
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
    $pdo->prepare('
      INSERT INTO workshop_media_items
        (id, workshop_id, session_id, placement, kind, title, description, file_path, original_name, mime_type, file_size, sort_order)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
    ')->execute([
        $id,
        $workshopId,
        $placement === 'SESSION' ? $sessionId : null,
        $placement,
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
            $names = $files['name'][$date][$kind] ?? null;
            $indexes = is_array($names) ? array_keys($names) : [0];
            foreach ($indexes as $index) {
                $file = workshop_media_nested_upload($files, $date, $kind, (int) $index);
                if ($file['error'] === UPLOAD_ERR_NO_FILE || $file['name'] === '') {
                    continue;
                }
                $title = (string) $session['title'] . ' — ' . workshop_media_kind_label($kind);
                workshop_media_save_upload($pdo, $workshopId, $doctorProfileId, $kind, $title, null, $file, (string) $session['id']);
                $saved++;
            }
        }
    }
    return $saved;
}

function workshop_media_nested_upload(array $files, string $date, string $kind, int $index): array
{
    $pick = static function (string $key) use ($files, $date, $kind, $index) {
        $node = $files[$key][$date][$kind] ?? null;
        if (is_array($node)) {
            return $node[$index] ?? null;
        }
        return $index === 0 ? $node : null;
    };

    return [
        'name' => (string) ($pick('name') ?? ''),
        'type' => (string) ($pick('type') ?? ''),
        'tmp_name' => (string) ($pick('tmp_name') ?? ''),
        'error' => (int) ($pick('error') ?? UPLOAD_ERR_NO_FILE),
        'size' => (int) ($pick('size') ?? 0),
    ];
}

function workshop_media_kind_files(mixed $value): array
{
    if (!is_array($value) || $value === []) {
        return [];
    }
    if (isset($value['id'])) {
        return [$value];
    }
    $out = [];
    foreach ($value as $file) {
        if (is_array($file) && isset($file['id'])) {
            $out[] = $file;
        }
    }

    return $out;
}

function workshop_media_process_path_session_files(
    PDO $pdo,
    string $workshopId,
    ?string $doctorProfileId,
    string $sessionId,
    string $sessionTitle,
    string $filesKey = 'path_file'
): int {
    $files = $_FILES[$filesKey] ?? [];
    if (!is_array($files['name'] ?? null) || $sessionId === '') {
        return 0;
    }
    $saved = 0;
    foreach (['PDF', 'AUDIO', 'VIDEO'] as $kind) {
        $names = $files['name'][$kind] ?? null;
        $indexes = is_array($names) ? array_keys($names) : [0];
        foreach ($indexes as $index) {
            $node = static function (string $key) use ($files, $kind, $index) {
                $value = $files[$key][$kind] ?? null;
                if (is_array($value)) {
                    return $value[$index] ?? null;
                }
                return $index === 0 ? $value : null;
            };
            $file = [
                'name' => (string) ($node('name') ?? ''),
                'type' => (string) ($node('type') ?? ''),
                'tmp_name' => (string) ($node('tmp_name') ?? ''),
                'error' => (int) ($node('error') ?? UPLOAD_ERR_NO_FILE),
                'size' => (int) ($node('size') ?? 0),
            ];
            if ($file['error'] === UPLOAD_ERR_NO_FILE || $file['name'] === '') {
                continue;
            }
            $title = trim($sessionTitle) !== ''
                ? $sessionTitle . ' — ' . workshop_media_kind_label($kind)
                : workshop_media_kind_label($kind);
            workshop_media_save_upload($pdo, $workshopId, $doctorProfileId, $kind, $title, null, $file, $sessionId);
            $saved++;
        }
    }

    return $saved;
}

/** فایل کلی دوره (پی‌دی‌اف یا صوت) و پاورپوینت کارگاه — بدون وابستگی به یک جلسه. */
function workshop_media_process_bundle_uploads(PDO $pdo, string $workshopId, ?string $doctorProfileId): int
{
    $saved = 0;
    $course = $_FILES['course_file'] ?? [];
    if (is_array($course['name'] ?? null)) {
        foreach (['PDF', 'AUDIO'] as $kind) {
            $names = $course['name'][$kind] ?? null;
            $indexes = is_array($names) ? array_keys($names) : [];
            foreach ($indexes as $index) {
                $pick = static function (string $key) use ($course, $kind, $index) {
                    $value = $course[$key][$kind] ?? null;
                    return is_array($value) ? ($value[$index] ?? null) : null;
                };
                $file = [
                    'name' => (string) ($pick('name') ?? ''),
                    'type' => (string) ($pick('type') ?? ''),
                    'tmp_name' => (string) ($pick('tmp_name') ?? ''),
                    'error' => (int) ($pick('error') ?? UPLOAD_ERR_NO_FILE),
                    'size' => (int) ($pick('size') ?? 0),
                ];
                if ($file['error'] === UPLOAD_ERR_NO_FILE || $file['name'] === '') {
                    continue;
                }
                $title = 'فایل کلی کارگاه — ' . workshop_media_kind_label($kind);
                workshop_media_save_upload($pdo, $workshopId, $doctorProfileId, $kind, $title, null, $file, null, 'COURSE');
                $saved++;
            }
        }
    }

    $slides = $_FILES['slide_file'] ?? [];
    if (is_array($slides['name'] ?? null)) {
        foreach (array_keys($slides['name']) as $index) {
            $file = [
                'name' => (string) ($slides['name'][$index] ?? ''),
                'type' => (string) ($slides['type'][$index] ?? ''),
                'tmp_name' => (string) ($slides['tmp_name'][$index] ?? ''),
                'error' => (int) ($slides['error'][$index] ?? UPLOAD_ERR_NO_FILE),
                'size' => (int) ($slides['size'][$index] ?? 0),
            ];
            if ($file['error'] === UPLOAD_ERR_NO_FILE || $file['name'] === '') {
                continue;
            }
            workshop_media_save_upload($pdo, $workshopId, $doctorProfileId, 'PPT', 'پاورپوینت کارگاه', null, $file, null, 'SLIDES');
            $saved++;
        }
    }

    return $saved;
}

function workshop_media_placed_list(PDO $pdo, string $workshopId, string $placement): array
{
    $placement = $placement === 'SLIDES' ? 'SLIDES' : 'COURSE';
    $out = [];
    foreach (workshop_media_list($pdo, $workshopId) as $item) {
        if (!is_array($item)) {
            continue;
        }
        if ((string) ($item['placement'] ?? 'SESSION') === $placement) {
            $out[] = $item;
        }
    }

    return $out;
}

function workshop_media_audio_streams_from_items(array $items, array $user): array
{
    $out = [];
    foreach ($items as $item) {
        if (!is_array($item) || (string) ($item['kind'] ?? '') !== 'AUDIO') {
            continue;
        }
        $id = (string) ($item['id'] ?? '');
        if ($id === '') {
            continue;
        }
        $out[$id] = workshop_media_audio_client_pack($id, $user, (string) ($item['mime_type'] ?? ''));
    }

    return $out;
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

function workshop_media_audio_mask_key(string $itemId, string $userId): string
{
    return substr(hash('sha256', workshop_media_stream_secret() . '|audio|' . $itemId . '|' . $userId), 0, 16);
}

/** برای مراجع، صوت با کلید موقت برمی‌گردد تا دانلود مستقیم فایل mp3 قابل پخش نباشد. */
function workshop_media_audio_client_pack(string $itemId, array $user, string $mime = ''): array
{
    $role = (string) ($user['role'] ?? '');
    $mime = strtolower(trim($mime));
    if ($mime === '' || $mime === 'application/octet-stream') {
        $mime = 'audio/mpeg';
    }

    return [
        'url' => workshop_media_stream_url($itemId, $user),
        'mask' => $role === 'PATIENT' ? workshop_media_audio_mask_key($itemId, (string) ($user['id'] ?? '')) : '',
        'mime' => $mime,
    ];
}

function workshop_media_xor_chunk(string $chunk, string $key, int $offset): string
{
    $keyLen = strlen($key);
    if ($keyLen < 1 || $chunk === '') {
        return $chunk;
    }
    $len = strlen($chunk);
    for ($i = 0; $i < $len; $i++) {
        $chunk[$i] = $chunk[$i] ^ $key[($offset + $i) % $keyLen];
    }

    return $chunk;
}

function workshop_media_file_log_note(PDO $pdo, array $item): string
{
    $workshopTitle = '';
    $workshopId = (string) ($item['workshop_id'] ?? '');
    if ($workshopId !== '') {
        $stmt = $pdo->prepare('SELECT title FROM workshops WHERE id=? LIMIT 1');
        $stmt->execute([$workshopId]);
        $workshopTitle = (string) ($stmt->fetchColumn() ?: '');
    }
    $sessionLabel = '';
    $sessionId = (string) ($item['session_id'] ?? '');
    if ($sessionId !== '') {
        $stmt = $pdo->prepare('SELECT title, session_date FROM workshop_sessions WHERE id=? LIMIT 1');
        $stmt->execute([$sessionId]);
        $row = $stmt->fetch();
        if (is_array($row)) {
            $sessionLabel = trim((string) ($row['title'] ?? ''));
            $date = trim((string) ($row['session_date'] ?? ''));
            if ($date !== '') {
                $sessionLabel .= ($sessionLabel !== '' ? ' — ' : '') . $date;
            }
        }
    }
    $kind = workshop_media_kind_label((string) ($item['kind'] ?? ''));
    $name = trim((string) ($item['original_name'] ?? ''));
    if ($name === '') {
        $name = 'فایل';
    }

    return 'فایل: ' . $name
        . ' | نوع: ' . $kind
        . ' | کارگاه: ' . ($workshopTitle !== '' ? $workshopTitle : $workshopId)
        . ' | جلسه: ' . ($sessionLabel !== '' ? $sessionLabel : '—');
}

function workshop_media_log_secretary_action(PDO $pdo, array $user, string $action, array $item): void
{
    if ((string) ($user['role'] ?? '') !== 'SECRETARY') {
        return;
    }
    if (!function_exists('staff_log_action')) {
        require_once __DIR__ . '/staff_desk.php';
    }
    staff_log_action(
        $pdo,
        (string) ($user['id'] ?? ''),
        $action,
        'workshop_media',
        (string) ($item['id'] ?? ''),
        workshop_media_file_log_note($pdo, $item)
    );
}

function workshop_session_file_lines_html(array $sessions, string $deleteAction = '', string $workshopId = ''): string
{
    if ($sessions === []) {
        return '';
    }
    $blocks = '';
    foreach ($sessions as $session) {
        if (!is_array($session) || trim((string) ($session['id'] ?? '')) === '') {
            continue;
        }
        $files = [];
        foreach (['PDF' => 'پی‌دی‌اف', 'AUDIO' => 'صوت', 'VIDEO' => 'ویدیو'] as $kind => $label) {
            foreach (workshop_media_kind_files($session['files'][$kind] ?? null) as $file) {
                $files[] = ['label' => $label, 'file' => $file];
            }
        }
        $title = (string) ($session['title'] ?? 'جلسه');
        $date = (string) ($session['session_date'] ?? '');
        $blocks .= '<div class="workshop-session-file-block"><strong>' . e($title) . '</strong>';
        if ($date !== '') {
            $blocks .= ' <span class="muted">' . e(to_jalali_label($date)) . '</span>';
        }
        if ($files === []) {
            $blocks .= '<p class="muted" style="margin:.25rem 0 0">فایلی بارگذاری نشده است.</p></div>';
            continue;
        }
        $blocks .= '<ul>';
        foreach ($files as $row) {
            $file = $row['file'];
            $name = trim((string) ($file['original_name'] ?? ''));
            if ($name === '') {
                $name = 'فایل';
            }
            $blocks .= '<li><span>' . e($row['label'] . ' — ' . $name) . '</span>';
            $itemId = (string) ($file['id'] ?? '');
            if ($deleteAction !== '' && $workshopId !== '' && $itemId !== '') {
                $blocks .= '<form method="post" action="' . e($deleteAction) . '" onsubmit="return confirm(\'این فایل حذف شود؟\')">'
                    . '<input type="hidden" name="action" value="delete">'
                    . '<input type="hidden" name="back" value="list">'
                    . '<input type="hidden" name="workshop_id" value="' . e($workshopId) . '">'
                    . '<input type="hidden" name="item_id" value="' . e($itemId) . '">'
                    . '<button class="btn btn-outline btn-sm" type="submit">حذف فایل</button>'
                    . '</form>';
            }
            $blocks .= '</li>';
        }
        $blocks .= '</ul></div>';
    }
    if ($blocks === '') {
        return '';
    }

    return '<div class="workshop-session-file-lines"><h3>فایل‌های هر جلسه</h3>' . $blocks . '</div>';
}

function workshop_bundle_manage_lines(PDO $pdo, string $workshopId, string $deleteAction = ''): string
{
    $groups = [
        'فایل کلی کارگاه' => workshop_media_placed_list($pdo, $workshopId, 'COURSE'),
        'پاورپوینت' => workshop_media_placed_list($pdo, $workshopId, 'SLIDES'),
    ];
    $html = '';
    foreach ($groups as $title => $items) {
        if ($items === []) {
            continue;
        }
        $html .= '<div class="workshop-session-file-block"><strong>' . e($title) . '</strong><ul>';
        foreach ($items as $file) {
            $name = trim((string) ($file['original_name'] ?? ''));
            if ($name === '') {
                $name = 'فایل';
            }
            $html .= '<li><span>' . e(workshop_media_kind_label((string) ($file['kind'] ?? '')) . ' — ' . $name) . '</span>';
            $itemId = (string) ($file['id'] ?? '');
            if ($deleteAction !== '' && $itemId !== '') {
                $html .= '<form method="post" action="' . e($deleteAction) . '" onsubmit="return confirm(\'این فایل حذف شود؟\')">'
                    . '<input type="hidden" name="action" value="delete">'
                    . '<input type="hidden" name="back" value="list">'
                    . '<input type="hidden" name="workshop_id" value="' . e($workshopId) . '">'
                    . '<input type="hidden" name="item_id" value="' . e($itemId) . '">'
                    . '<button class="btn btn-outline btn-sm" type="submit">حذف فایل</button></form>';
            }
            $html .= '</li>';
        }
        $html .= '</ul></div>';
    }

    return $html;
}

function workshop_files_badge_html(array $files): string
{
    $bits = [];
    foreach (['PDF' => 'پی‌دی‌اف', 'AUDIO' => 'صوت', 'VIDEO' => 'ویدیو'] as $kind => $label) {
        $count = count(workshop_media_kind_files($files[$kind] ?? null));
        if ($count > 0) {
            $bits[] = $count > 1 ? $label . ' (' . $count . ')' : $label;
        }
    }
    if ($bits === []) {
        return '<span class="badge">بدون فایل</span>';
    }

    return '<span class="badge">فایل: ' . e(implode('، ', $bits)) . '</span>';
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

function workshop_pdf_actions_html(string $itemId, string $fileName, string $watermark, ?array $user = null): string
{
    $user = $user ?? (function_exists('current_user') ? (current_user() ?: []) : []);
    $view = workshop_media_stream_url($itemId, $user);
    $name = trim($fileName) !== '' ? $fileName : 'session.pdf';

    return '<span class="wm-pdf-box wm-pdf-inline" data-pdf-url="' . e($view) . '" data-pdf-name="' . e($name) . '" data-pdf-mark="' . e($watermark) . '">'
        . '<button type="button" class="btn btn-outline btn-sm js-pdf-show">مشاهده</button> '
        . '<button type="button" class="btn btn-outline btn-sm js-pdf-download">دانلود</button>'
        . '<span class="wm-pdf-status" style="display:block;font-size:.75rem"></span>'
        . '<span class="wm-pdf-pages" hidden></span>'
        . '</span>';
}

function workshop_pdf_client_stamp_document(string $fileUrl, string $fileName, string $watermark): string
{
    $script = e(url('/assets/js/workshop-pdf-view.js') . '?v=20260928ink');
    $name = trim($fileName) !== '' ? $fileName : 'session.pdf';

    return '<!DOCTYPE html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="robots" content="noindex"><title>دانلود</title>'
        . '<style>body{margin:0;padding:1.4rem;font-family:Tahoma,sans-serif;background:#f6f4ef;color:#243}</style></head><body>'
        . '<div class="wm-pdf-box" data-pdf-url="' . e($fileUrl) . '" data-pdf-name="' . e($name) . '" data-pdf-mark="' . e($watermark) . '">'
        . '<p>در حال گذاشتن مهر نام روی پی‌دی‌اف...</p>'
        . '<button type="button" class="js-pdf-download" id="wm-go">دانلود</button>'
        . '<p class="wm-pdf-status"></p></div>'
        . '<script src="' . $script . '"></script>'
        . '<script>var go=document.getElementById("wm-go");if(go){go.click();}</script>'
        . '</body></html>';
}

function workshop_pptx_watermark_shapes(string $safeText): string
{
    $spots = [
        [9101, 400000, 700000],
        [9102, 500000, 2800000],
        [9103, 300000, 4800000],
    ];
    $xml = '';
    foreach ($spots as [$id, $x, $y]) {
        $xml .= '<p:sp><p:nvSpPr><p:cNvPr id="' . $id . '" name="ManaMark' . $id . '"/>'
            . '<p:cNvSpPr/><p:nvPr/></p:nvSpPr><p:spPr><a:xfrm rot="-2700000"><a:off x="' . $x . '" y="' . $y . '"/>'
            . '<a:ext cx="9000000" cy="600000"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom><a:noFill/></p:spPr>'
            . '<p:txBody><a:bodyPr wrap="square"/><a:lstStyle/><a:p><a:pPr algn="ctr"/>'
            . '<a:r><a:rPr lang="fa-IR" sz="2200" b="1"><a:solidFill><a:srgbClr val="555555"><a:alpha val="42000"/></a:srgbClr></a:solidFill></a:rPr>'
            . '<a:t>' . $safeText . '</a:t></a:r></a:p></p:txBody></p:sp>';
    }

    return $xml;
}

function workshop_pptx_stamp_temp(string $srcPath, string $watermark): ?string
{
    if (!class_exists('ZipArchive') || !is_file($srcPath) || trim($watermark) === '') {
        return null;
    }
    $tmp = sys_get_temp_dir() . '/mana_pptx_' . bin2hex(random_bytes(8)) . '.pptx';
    if (!copy($srcPath, $tmp)) {
        return null;
    }
    $zip = new ZipArchive();
    if ($zip->open($tmp) !== true) {
        @unlink($tmp);
        return null;
    }
    $safe = htmlspecialchars($watermark, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    $shapes = workshop_pptx_watermark_shapes($safe);
    $stamped = 0;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = (string) $zip->getNameIndex($i);
        if (!preg_match('#^ppt/slides/slide\d+\.xml$#', $name)) {
            continue;
        }
        $xml = $zip->getFromName($name);
        if (!is_string($xml) || !str_contains($xml, '</p:spTree>')) {
            continue;
        }
        $xml = preg_replace('/<\/p:spTree>/', $shapes . '</p:spTree>', $xml, 1);
        if (is_string($xml)) {
            $zip->addFromString($name, $xml);
            $stamped++;
        }
    }
    $zip->close();
    if ($stamped < 1) {
        @unlink($tmp);
        return null;
    }

    return $tmp;
}

function workshop_pptx_preview_document(string $srcPath, string $watermark): string
{
    $slides = [];
    if (class_exists('ZipArchive') && is_file($srcPath)) {
        $zip = new ZipArchive();
        if ($zip->open($srcPath) === true) {
            $names = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string) $zip->getNameIndex($i);
                if (preg_match('#^ppt/slides/slide(\d+)\.xml$#', $name, $m)) {
                    $names[(int) $m[1]] = $name;
                }
            }
            ksort($names);
            foreach ($names as $num => $name) {
                $xml = $zip->getFromName($name);
                $text = '';
                if (is_string($xml) && preg_match_all('#<a:t[^>]*>(.*?)</a:t>#su', $xml, $found)) {
                    $bits = [];
                    foreach ($found[1] as $bit) {
                        $bit = trim(html_entity_decode(strip_tags($bit), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                        if ($bit !== '') {
                            $bits[] = $bit;
                        }
                    }
                    $text = implode("\n", $bits);
                }
                $slides[] = ['n' => $num, 'text' => $text];
            }
            $zip->close();
        }
    }
    $mark = htmlspecialchars($watermark, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $body = '';
    if (!$slides) {
        $body = '<p>پیش‌نمایش این فایل ساخته نشد. فایل دانلودی مهر نام را روی اسلایدها دارد.</p>';
    }
    foreach ($slides as $slide) {
        $text = trim((string) $slide['text']);
        $shown = $text !== ''
            ? nl2br(htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'))
            : 'این اسلاید بیشتر تصویر است. در فایل دانلودی، نام شما روی خود اسلاید نوشته شده است.';
        $body .= '<article class="slide"><h2>اسلاید ' . (int) $slide['n'] . '</h2><p>' . $shown . '</p></article>';
    }

    return '<!DOCTYPE html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="robots" content="noindex">'
        . '<title>پاورپوینت</title><style>'
        . 'body{margin:0;font-family:Tahoma,sans-serif;background:#f6f4ef;color:#243;}'
        . '.mark{position:fixed;inset:0;pointer-events:none;display:grid;grid-template-columns:1fr 1fr;gap:2rem;align-content:space-evenly;justify-items:center;overflow:hidden;}'
        . '.mark span{transform:rotate(-24deg);color:rgba(40,40,40,.28);font-weight:700;font-size:1.05rem;white-space:nowrap;}'
        . '.slide{position:relative;z-index:1;margin:.8rem;padding:1rem 1.1rem;background:#fff;border-radius:.7rem;box-shadow:0 1px 4px rgba(0,0,0,.06);}'
        . 'h2{margin:0 0 .45rem;font-size:.95rem;} p{margin:0;line-height:1.8;white-space:pre-wrap;}'
        . '</style></head><body><div class="mark" aria-hidden="true">'
        . str_repeat('<span>' . $mark . '</span>', 8)
        . '</div>' . $body . '</body></html>';
}

function workshop_pdf_font_path(): string
{
    $path = dirname(__DIR__) . '/assets/fonts/Vazirmatn-Medium.ttf';

    return is_file($path) ? $path : '';
}

function workshop_pdf_stamp_temp(string $srcPath, string $watermark): ?string
{
    if (!class_exists('Imagick') || !is_file($srcPath) || trim($watermark) === '') {
        return null;
    }
    $font = workshop_pdf_font_path();
    if ($font === '') {
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
            $draw->setFont($font);
            if (method_exists($draw, 'setTextEncoding')) {
                $draw->setTextEncoding('UTF-8');
            }
            $draw->setFillColor(new ImagickPixel('rgba(30,30,30,0.38)'));
            $draw->setFontSize(max(18, (int) ($w / 24)));
            $draw->setGravity(Imagick::GRAVITY_CENTER);
            $page->annotateImage($draw, 0, 0, -28, $watermark);
            $page->annotateImage($draw, 0, (int) ($h * 0.28), -28, $watermark);
            $page->annotateImage($draw, 0, (int) (-$h * 0.28), -28, $watermark);
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
