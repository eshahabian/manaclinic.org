<?php
declare(strict_types=1);

function ensure_patient_journal_schema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    $pdo->exec("
      CREATE TABLE IF NOT EXISTS patient_journal_entries (
        id VARCHAR(32) PRIMARY KEY,
        patient_id VARCHAR(32) NOT NULL,
        entry_date DATE NOT NULL,
        mood TINYINT NULL,
        mood_label VARCHAR(32) NULL,
        body TEXT NULL,
        photo_path VARCHAR(255) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_patient_journal_day (patient_id, entry_date),
        INDEX idx_journal_patient_date (patient_id, entry_date),
        CONSTRAINT fk_journal_patient FOREIGN KEY (patient_id) REFERENCES users(id) ON DELETE CASCADE
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    patient_journal_ensure_storage();
    $ready = true;
}

function patient_journal_moods(): array
{
    return [
        1 => ['label' => 'مضطرب', 'emoji' => '😟', 'tone' => 'low'],
        2 => ['label' => 'ناآرام', 'emoji' => '😕', 'tone' => 'uneasy'],
        3 => ['label' => 'معمولی', 'emoji' => '😐', 'tone' => 'mid'],
        4 => ['label' => 'آرام', 'emoji' => '🙂', 'tone' => 'calm'],
        5 => ['label' => 'شاد', 'emoji' => '😊', 'tone' => 'good'],
    ];
}

function patient_journal_mood_label(?int $mood): string
{
    if ($mood === null) {
        return '';
    }
    $map = patient_journal_moods();
    return (string) ($map[$mood]['label'] ?? '');
}

function patient_journal_storage_root(): string
{
    return dirname(__DIR__) . '/uploads/journal';
}

function patient_journal_ensure_storage(): void
{
    $root = patient_journal_storage_root();
    if (!is_dir($root)) {
        mkdir($root, 0755, true);
    }
}

function patient_journal_detect_mime(string $tmpPath): string
{
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = $finfo ? (string) finfo_file($finfo, $tmpPath) : '';
    if ($finfo) {
        finfo_close($finfo);
    }
    return strtolower($mime);
}

function patient_journal_save_photo(string $patientId, array $file): string
{
    patient_journal_ensure_storage();
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return '';
    }
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('آپلود عکس ناموفق بود.');
    }
    $mime = patient_journal_detect_mime((string) $file['tmp_name']);
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!isset($allowed[$mime])) {
        throw new RuntimeException('فرمت عکس باید jpg، png یا webp باشد.');
    }
    if (($file['size'] ?? 0) > 5 * 1024 * 1024) {
        throw new RuntimeException('حجم عکس حداکثر ۵ مگابایت باشد.');
    }
    $name = $patientId . '-' . date('Ymd') . '-' . substr(cuid(), 0, 8) . '.' . $allowed[$mime];
    $dest = patient_journal_storage_root() . '/' . $name;
    if (!move_uploaded_file((string) $file['tmp_name'], $dest)) {
        throw new RuntimeException('ذخیره عکس ناموفق بود.');
    }
    return '/uploads/journal/' . $name;
}

function patient_journal_delete_photo(?string $publicPath): void
{
    $publicPath = (string) $publicPath;
    if ($publicPath === '' || !str_starts_with($publicPath, '/uploads/journal/')) {
        return;
    }
    $full = patient_journal_storage_root() . '/' . basename($publicPath);
    if (is_file($full)) {
        @unlink($full);
    }
}

function patient_journal_fetch_range(PDO $pdo, string $patientId, string $fromDate, string $toDate): array
{
    ensure_patient_journal_schema($pdo);
    $stmt = $pdo->prepare('
      SELECT * FROM patient_journal_entries
      WHERE patient_id = ? AND entry_date BETWEEN ? AND ?
      ORDER BY entry_date DESC
    ');
    $stmt->execute([$patientId, $fromDate, $toDate]);
    return $stmt->fetchAll();
}

function patient_journal_fetch_all(PDO $pdo, string $patientId, int $limit = 90): array
{
    ensure_patient_journal_schema($pdo);
    $limit = max(1, min(365, $limit));
    $stmt = $pdo->prepare('
      SELECT * FROM patient_journal_entries
      WHERE patient_id = ?
      ORDER BY entry_date DESC
      LIMIT ' . $limit
    );
    $stmt->execute([$patientId]);
    return $stmt->fetchAll();
}

function patient_journal_for_date(PDO $pdo, string $patientId, string $entryDate): ?array
{
    ensure_patient_journal_schema($pdo);
    $stmt = $pdo->prepare('SELECT * FROM patient_journal_entries WHERE patient_id = ? AND entry_date = ? LIMIT 1');
    $stmt->execute([$patientId, $entryDate]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function patient_journal_upsert(
    PDO $pdo,
    string $patientId,
    string $entryDate,
    ?int $mood,
    ?string $body,
    ?string $photoPath,
    bool $keepExistingPhoto = true
): array {
    ensure_patient_journal_schema($pdo);
    $existing = patient_journal_for_date($pdo, $patientId, $entryDate);
    $moodLabel = $mood !== null ? patient_journal_mood_label($mood) : null;
    $body = $body !== null ? trim($body) : null;
    if ($body === '') {
        $body = null;
    }

    if ($existing) {
        $finalPhoto = $photoPath;
        if ($photoPath === null && $keepExistingPhoto) {
            $finalPhoto = $existing['photo_path'] ?? null;
        } elseif ($photoPath !== null && !empty($existing['photo_path']) && $photoPath !== $existing['photo_path']) {
            patient_journal_delete_photo((string) $existing['photo_path']);
        } elseif ($photoPath === null && !$keepExistingPhoto) {
            patient_journal_delete_photo((string) ($existing['photo_path'] ?? ''));
            $finalPhoto = null;
        }
        $pdo->prepare('
          UPDATE patient_journal_entries
          SET mood = ?, mood_label = ?, body = ?, photo_path = ?
          WHERE id = ?
        ')->execute([
            $mood,
            $moodLabel !== '' ? $moodLabel : null,
            $body,
            $finalPhoto,
            $existing['id'],
        ]);
        return patient_journal_for_date($pdo, $patientId, $entryDate) ?? $existing;
    }

    $id = cuid();
    $pdo->prepare('
      INSERT INTO patient_journal_entries (id, patient_id, entry_date, mood, mood_label, body, photo_path)
      VALUES (?,?,?,?,?,?,?)
    ')->execute([
        $id,
        $patientId,
        $entryDate,
        $mood,
        $moodLabel !== '' ? $moodLabel : null,
        $body,
        $photoPath,
    ]);
    return patient_journal_for_date($pdo, $patientId, $entryDate) ?? [
        'id' => $id,
        'patient_id' => $patientId,
        'entry_date' => $entryDate,
        'mood' => $mood,
        'mood_label' => $moodLabel,
        'body' => $body,
        'photo_path' => $photoPath,
    ];
}

/** تعداد روزهای پیاپی ثبت‌شده تا امروز (شامل امروز اگر نوشته باشد) */
function patient_journal_streak(PDO $pdo, string $patientId): int
{
    ensure_patient_journal_schema($pdo);
    $stmt = $pdo->prepare('
      SELECT entry_date FROM patient_journal_entries
      WHERE patient_id = ?
      ORDER BY entry_date DESC
      LIMIT 60
    ');
    $stmt->execute([$patientId]);
    $dates = [];
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $d) {
        $dates[(string) $d] = true;
    }
    if (!$dates) {
        return 0;
    }
    $cursor = new DateTimeImmutable('today');
    if (empty($dates[$cursor->format('Y-m-d')])) {
        $cursor = $cursor->modify('-1 day');
    }
    $streak = 0;
    while (!empty($dates[$cursor->format('Y-m-d')])) {
        $streak++;
        $cursor = $cursor->modify('-1 day');
    }
    return $streak;
}

function patient_journal_day_strip(int $days = 14): array
{
    $days = max(7, min(31, $days));
    $out = [];
    $today = new DateTimeImmutable('today');
    for ($i = $days - 1; $i >= 0; $i--) {
        $d = $today->modify("-{$i} days");
        $ymd = $d->format('Y-m-d');
        $out[] = [
            'ymd' => $ymd,
            'label' => to_jalali_label($ymd),
            'is_today' => $i === 0,
            'weekday' => (int) $d->format('N'),
        ];
    }
    return $out;
}
