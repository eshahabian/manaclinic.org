<?php
declare(strict_types=1);

require_once __DIR__ . '/wallet.php';

function ensure_workshop_schema(PDO $pdo): void
{
    ensure_wallet_schema($pdo);
    $pdo->exec("
      CREATE TABLE IF NOT EXISTS workshops (
        id VARCHAR(32) PRIMARY KEY,
        doctor_id VARCHAR(32) NOT NULL,
        title VARCHAR(255) NOT NULL,
        type ENUM('IN_PERSON','ONLINE','OFFLINE') NOT NULL,
        starts_at DATETIME NOT NULL,
        ends_at DATETIME NOT NULL,
        items_to_bring TEXT NULL,
        notes TEXT NULL,
        description TEXT NULL,
        price INT NOT NULL DEFAULT 0,
        capacity INT NULL,
        location TEXT NULL,
        location_lat DECIMAL(10, 7) NULL,
        location_lng DECIMAL(10, 7) NULL,
        meeting_url VARCHAR(500) NULL,
        content_url VARCHAR(500) NULL,
        is_published TINYINT(1) NOT NULL DEFAULT 0,
        enrollment_open TINYINT(1) NOT NULL DEFAULT 1,
        status ENUM('DRAFT','PUBLISHED','CANCELLED','COMPLETED') NOT NULL DEFAULT 'DRAFT',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_workshop_doctor (doctor_id),
        INDEX idx_workshop_type (type, is_published, starts_at),
        CONSTRAINT fk_workshop_doctor FOREIGN KEY (doctor_id) REFERENCES doctor_profiles(id) ON DELETE CASCADE
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

      CREATE TABLE IF NOT EXISTS workshop_enrollments (
        id VARCHAR(32) PRIMARY KEY,
        workshop_id VARCHAR(32) NOT NULL,
        patient_id VARCHAR(32) NOT NULL,
        status ENUM('PENDING_PAYMENT','CONFIRMED','CANCELLED','REFUNDED','COMPLETED') NOT NULL DEFAULT 'PENDING_PAYMENT',
        enrolled_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_workshop_patient (workshop_id, patient_id),
        INDEX idx_enrollment_patient (patient_id),
        CONSTRAINT fk_enroll_workshop FOREIGN KEY (workshop_id) REFERENCES workshops(id) ON DELETE CASCADE,
        CONSTRAINT fk_enroll_patient FOREIGN KEY (patient_id) REFERENCES users(id) ON DELETE CASCADE
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

      CREATE TABLE IF NOT EXISTS workshop_payments (
        id VARCHAR(32) PRIMARY KEY,
        enrollment_id VARCHAR(32) NOT NULL UNIQUE,
        amount INT NOT NULL,
        wallet_amount INT NOT NULL DEFAULT 0,
        authority VARCHAR(100) NULL,
        ref_id VARCHAR(100) NULL,
        status ENUM('PENDING','PAID','FAILED','REFUNDED') NOT NULL DEFAULT 'PENDING',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        CONSTRAINT fk_wpay_enrollment FOREIGN KEY (enrollment_id) REFERENCES workshop_enrollments(id) ON DELETE CASCADE
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    workshop_ensure_columns($pdo);
    workshop_sync_publish_flags($pdo);
    ensure_workshop_session_notes_schema($pdo);
    workshop_archive_expired($pdo);
}

function ensure_workshop_session_notes_schema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    $pdo->exec("
      CREATE TABLE IF NOT EXISTS workshop_session_notes (
        id VARCHAR(32) PRIMARY KEY,
        workshop_id VARCHAR(32) NOT NULL,
        doctor_id VARCHAR(32) NOT NULL,
        session_title VARCHAR(255) NOT NULL,
        session_at DATETIME NULL,
        note_text MEDIUMTEXT NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_wsn_workshop (workshop_id, session_at),
        CONSTRAINT fk_wsn_workshop FOREIGN KEY (workshop_id) REFERENCES workshops(id) ON DELETE CASCADE,
        CONSTRAINT fk_wsn_doctor FOREIGN KEY (doctor_id) REFERENCES doctor_profiles(id) ON DELETE CASCADE
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $ready = true;
}

function workshop_ensure_columns(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    $hasEnrollmentOpen = $pdo->query("SHOW COLUMNS FROM workshops LIKE 'enrollment_open'")->fetch();
    if (!$hasEnrollmentOpen) {
        $pdo->exec('ALTER TABLE workshops ADD COLUMN enrollment_open TINYINT(1) NOT NULL DEFAULT 1 AFTER is_published');
    }
    $hasLocationLat = $pdo->query("SHOW COLUMNS FROM workshops LIKE 'location_lat'")->fetch();
    if (!$hasLocationLat) {
        $pdo->exec('ALTER TABLE workshops ADD COLUMN location_lat DECIMAL(10, 7) NULL AFTER location');
        $pdo->exec('ALTER TABLE workshops ADD COLUMN location_lng DECIMAL(10, 7) NULL AFTER location_lat');
    }
    $locationCol = $pdo->query("SHOW COLUMNS FROM workshops LIKE 'location'")->fetch();
    if ($locationCol && stripos((string) $locationCol['Type'], 'varchar') !== false) {
        $pdo->exec('ALTER TABLE workshops MODIFY location TEXT NULL');
    }
    $hasGroupUrl = $pdo->query("SHOW COLUMNS FROM workshops LIKE 'group_url'")->fetch();
    if (!$hasGroupUrl) {
        $pdo->exec('ALTER TABLE workshops ADD COLUMN group_url VARCHAR(500) NULL AFTER content_url');
    }
    $hasCreatedBy = $pdo->query("SHOW COLUMNS FROM workshops LIKE 'created_by_user_id'")->fetch();
    if (!$hasCreatedBy) {
        $pdo->exec('ALTER TABLE workshops ADD COLUMN created_by_user_id VARCHAR(32) NULL AFTER status');
    }
    $hasUpdatedBy = $pdo->query("SHOW COLUMNS FROM workshops LIKE 'updated_by_user_id'")->fetch();
    if (!$hasUpdatedBy) {
        $pdo->exec('ALTER TABLE workshops ADD COLUMN updated_by_user_id VARCHAR(32) NULL AFTER created_by_user_id');
    }
    $hasEnrollCreatedBy = $pdo->query("SHOW COLUMNS FROM workshop_enrollments LIKE 'created_by_user_id'")->fetch();
    if (!$hasEnrollCreatedBy) {
        $pdo->exec('ALTER TABLE workshop_enrollments ADD COLUMN created_by_user_id VARCHAR(32) NULL AFTER patient_id');
    }
    $hasPayReceipt = $pdo->query("SHOW COLUMNS FROM workshop_payments LIKE 'receipt_path'")->fetch();
    if (!$hasPayReceipt) {
        $pdo->exec('ALTER TABLE workshop_payments ADD COLUMN receipt_path VARCHAR(255) NULL AFTER ref_id');
    }
    $hasPayRecorder = $pdo->query("SHOW COLUMNS FROM workshop_payments LIKE 'recorded_by_user_id'")->fetch();
    if (!$hasPayRecorder) {
        $pdo->exec('ALTER TABLE workshop_payments ADD COLUMN recorded_by_user_id VARCHAR(32) NULL AFTER receipt_path');
    }
    $ready = true;
}

/** هم‌خوان‌سازی وضعیت انتشار (رفع ناسازگاری is_published و status) */
function workshop_sync_publish_flags(PDO $pdo): void
{
    $pdo->exec("UPDATE workshops SET status = 'PUBLISHED' WHERE is_published = 1 AND status = 'DRAFT'");
}

/** JOIN درمانگران تأییدشده و فعال */
function workshop_active_doctor_join(string $workshopAlias = 'w'): string
{
    $a = $workshopAlias;
    return "JOIN doctor_profiles dp ON dp.id = {$a}.doctor_id AND dp.is_approved = 1 AND dp.is_active = 1";
}

/** تاریخ‌های ثابت برای دوره آفلاین (بدون زمان‌بندی واقعی) */
function workshop_offline_datetimes(): array
{
    return [
        'starts_at' => '2000-01-01 00:00:00',
        'ends_at' => '2099-12-31 23:59:59',
    ];
}

function workshop_is_offline(string $type): bool
{
    return $type === 'OFFLINE';
}

/** کارگاه‌هایی که مراجعه‌کننده در لیست «دوره‌های من» می‌بیند (هنوز تمام نشده) */
function workshop_patient_list_sql(string $alias = 'w'): string
{
    $a = $alias;
    return "{$a}.is_published = 1 AND {$a}.status NOT IN ('CANCELLED', 'COMPLETED') AND ({$a}.type = 'OFFLINE' OR {$a}.ends_at > NOW())";
}

/** کارگاه‌هایی که مراجعه‌کننده هنوز می‌تواند ثبت‌نام کند (تا دکتر ببندد) */
function workshop_patient_enrollable_sql(string $alias = 'w'): string
{
    $a = $alias;
    return workshop_patient_list_sql($a) . " AND {$a}.enrollment_open = 1";
}

function workshop_can_enroll(array $workshop): bool
{
    return (bool) ($workshop['enrollment_open'] ?? 1);
}

/** لینک geo برای انتخاب اپ مسیریابی روی موبایل */
function workshop_navigation_geo_uri(?float $lat, ?float $lng, ?string $address = null): ?string
{
    if ($lat !== null && $lng !== null && $lat >= -90 && $lat <= 90 && $lng >= -180 && $lng <= 180) {
        return 'geo:' . rtrim(rtrim(sprintf('%.7F', $lat), '0'), '.')
            . ',' . rtrim(rtrim(sprintf('%.7F', $lng), '0'), '.');
    }
    $address = trim((string) $address);
    if ($address !== '') {
        return 'geo:0,0?q=' . rawurlencode($address);
    }
    return null;
}

function workshop_coords_from_row(array $row): ?array
{
    if (!isset($row['location_lat'], $row['location_lng']) || $row['location_lat'] === null || $row['location_lng'] === null) {
        return null;
    }
    return [(float) $row['location_lat'], (float) $row['location_lng']];
}

function workshop_navigation_uri_from_row(array $row): ?string
{
    $coords = workshop_coords_from_row($row);
    if ($coords) {
        return workshop_navigation_geo_uri($coords[0], $coords[1], (string) ($row['location'] ?? ''));
    }
    return workshop_navigation_geo_uri(null, null, (string) ($row['location'] ?? ''));
}

function workshop_location_from_post(string $type): array
{
    if ($type !== 'IN_PERSON') {
        return [null, null, null];
    }
    $location = trim(post('location')) ?: null;
    $latRaw = trim(post('location_lat'));
    $lngRaw = trim(post('location_lng'));
    $lat = $latRaw !== '' ? (float) $latRaw : null;
    $lng = $lngRaw !== '' ? (float) $lngRaw : null;
    return [$location, $lat, $lng];
}

function patient_courses_new_count(PDO $pdo, string $patientId): int
{
    ensure_workshop_schema($pdo);
    $stmt = $pdo->prepare('
      SELECT COUNT(*) FROM workshops w
      ' . workshop_active_doctor_join('w') . '
      WHERE ' . workshop_patient_enrollable_sql('w') . '
        AND NOT EXISTS (
          SELECT 1 FROM workshop_enrollments e
          WHERE e.workshop_id = w.id AND e.patient_id = ?
            AND e.status IN ("PENDING_PAYMENT","CONFIRMED","COMPLETED")
        )
    ');
    $stmt->execute([$patientId]);
    return (int) $stmt->fetchColumn();
}

/** اطلاع درمانگران از کارگاه جدید (اختیاری: حذف یک پروفایل از لیست) */
function workshop_notify_doctors(
    PDO $pdo,
    string $creatorName,
    string $title,
    string $type,
    string $startsAt,
    ?string $excludeDoctorProfileId = null,
    ?string $ownerDoctorName = null
): void {
    require_once __DIR__ . '/notifications.php';
    $sql = 'SELECT dp.user_id FROM doctor_profiles dp WHERE dp.is_approved = 1 AND dp.is_active = 1';
    $params = [];
    if ($excludeDoctorProfileId) {
        $sql .= ' AND dp.id != ?';
        $params[] = $excludeDoctorProfileId;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $typeLabel = workshop_type_label($type);
    $ownerHint = $ownerDoctorName ? " — درمانگر: «{$ownerDoctorName}»" : '';
    if (workshop_is_offline($type)) {
        $body = "«{$creatorName}» دوره آفلاین «{$title}» منتشر کرد{$ownerHint}. مراجعه‌کنندگان در «دوره‌های من» می‌بینند.";
    } else {
        $when = format_fa_datetime($startsAt);
        $body = "«{$creatorName}» کارگاه {$typeLabel} «{$title}» ({$when}) منتشر کرد{$ownerHint}. مراجعه‌کنندگان در «دوره‌های من» می‌بینند.";
    }
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $userId) {
        notify_user(
            $pdo,
            (string) $userId,
            'کارگاه جدید',
            $body,
            '/doctor/workshops',
            'workshop'
        );
    }
}

/** اطلاع سایر درمانگران از کارگاه جدید */
function workshop_notify_other_doctors(
    PDO $pdo,
    string $excludeDoctorProfileId,
    string $creatorName,
    string $title,
    string $type,
    string $startsAt
): void {
    workshop_notify_doctors($pdo, $creatorName, $title, $type, $startsAt, $excludeDoctorProfileId);
}

/** فیلدهای مشترک ایجاد/ویرایش کارگاه از POST */
function workshop_save_fields_from_post(): array
{
    $title = trim(post('title'));
    $type = post('type');
    $startDate = post('start_date');
    $startTime = post('start_time');
    $endDate = post('end_date');
    $endTime = post('end_time');
    $price = max(0, (int) post('price', '0'));
    $capacityRaw = trim(post('capacity'));
    $capacity = $capacityRaw === '' ? null : max(1, (int) $capacityRaw);
    $published = isset($_POST['published']);

    if ($title === '' || !in_array($type, ['IN_PERSON', 'ONLINE', 'OFFLINE'], true)) {
        throw new RuntimeException('اطلاعات کارگاه ناقص است.');
    }

    if (workshop_is_offline($type)) {
        $placeholders = workshop_offline_datetimes();
        $startsAt = $placeholders['starts_at'];
        $endsAt = $placeholders['ends_at'];
    } else {
        $startsAt = workshop_datetime_from_post($startDate, $startTime);
        $endsAt = workshop_datetime_from_post($endDate, $endTime);
        if (strtotime($endsAt) <= strtotime($startsAt)) {
            throw new RuntimeException('زمان پایان باید بعد از شروع باشد.');
        }
    }

    if ($type === 'ONLINE' && trim(post('meeting_url')) === '') {
        throw new RuntimeException('برای کارگاه آنلاین، لینک جلسه الزامی است.');
    }
    if ($type === 'IN_PERSON' && trim(post('location')) === '') {
        throw new RuntimeException('برای کارگاه حضوری، آدرس محل برگزاری را بنویسید.');
    }
    if ($type === 'IN_PERSON') {
        $latRaw = trim(post('location_lat'));
        $lngRaw = trim(post('location_lng'));
        if ($latRaw === '' || $lngRaw === '') {
            throw new RuntimeException('روی نقشه موقعیت کارگاه را انتخاب کنید.');
        }
    }

    [$location, $meetingUrl, $contentUrl, $locationLat, $locationLng] = workshop_type_urls_from_post($type);
    $groupUrl = trim(post('group_url')) ?: null;
    if ($groupUrl !== null && !preg_match('#^https?://#i', $groupUrl)) {
        throw new RuntimeException('لینک گروه باید با http:// یا https:// شروع شود.');
    }

    return [
        'title' => $title,
        'type' => $type,
        'starts_at' => $startsAt,
        'ends_at' => $endsAt,
        'price' => $price,
        'capacity' => $capacity,
        'published' => $published,
        'items_to_bring' => trim(post('items_to_bring')) ?: null,
        'notes' => trim(post('notes')) ?: null,
        'description' => trim(post('description')) ?: null,
        'location' => $location,
        'location_lat' => $locationLat,
        'location_lng' => $locationLng,
        'meeting_url' => $meetingUrl,
        'content_url' => $contentUrl,
        'group_url' => $groupUrl,
    ];
}

function workshop_group_link_label(?string $url): string
{
    $url = strtolower((string) $url);
    if (str_contains($url, 't.me') || str_contains($url, 'telegram')) {
        return 'عضویت در گروه تلگرام';
    }
    if (str_contains($url, 'wa.me') || str_contains($url, 'whatsapp') || str_contains($url, 'chat.whatsapp')) {
        return 'عضویت در گروه واتساپ';
    }
    return 'عضویت در گروه';
}

function workshop_approved_doctors(PDO $pdo): array
{
    return $pdo->query("
      SELECT dp.id, u.name
      FROM doctor_profiles dp
      JOIN users u ON u.id = dp.user_id
      WHERE dp.is_approved = 1 AND dp.is_active = 1
      ORDER BY u.name ASC
    ")->fetchAll();
}

function workshop_session_notes_list(PDO $pdo, string $workshopId): array
{
    ensure_workshop_session_notes_schema($pdo);
    $stmt = $pdo->prepare('
      SELECT * FROM workshop_session_notes
      WHERE workshop_id = ?
      ORDER BY COALESCE(session_at, created_at) DESC, created_at DESC
    ');
    $stmt->execute([$workshopId]);
    return $stmt->fetchAll();
}

function workshop_session_note_save(
    PDO $pdo,
    string $workshopId,
    string $doctorProfileId,
    string $sessionTitle,
    string $noteText,
    ?string $sessionAt,
    ?string $noteId = null
): string {
    ensure_workshop_session_notes_schema($pdo);
    $sessionTitle = trim($sessionTitle);
    $noteText = trim($noteText);
    if ($sessionTitle === '' || $noteText === '') {
        throw new RuntimeException('عنوان جلسه و متن یادداشت الزامی است.');
    }
    $own = $pdo->prepare('SELECT id FROM workshops WHERE id=? AND doctor_id=? LIMIT 1');
    $own->execute([$workshopId, $doctorProfileId]);
    if (!$own->fetch()) {
        throw new RuntimeException('کارگاه یافت نشد.');
    }
    if ($sessionAt !== null && $sessionAt !== '') {
        if (!strtotime($sessionAt)) {
            throw new RuntimeException('تاریخ جلسه نامعتبر است.');
        }
    } else {
        $sessionAt = null;
    }

    if ($noteId) {
        $pdo->prepare('
          UPDATE workshop_session_notes
          SET session_title=?, session_at=?, note_text=?
          WHERE id=? AND workshop_id=? AND doctor_id=?
        ')->execute([$sessionTitle, $sessionAt, $noteText, $noteId, $workshopId, $doctorProfileId]);
        return $noteId;
    }

    $id = cuid();
    $pdo->prepare('
      INSERT INTO workshop_session_notes (id, workshop_id, doctor_id, session_title, session_at, note_text)
      VALUES (?,?,?,?,?,?)
    ')->execute([$id, $workshopId, $doctorProfileId, $sessionTitle, $sessionAt, $noteText]);
    return $id;
}

function workshop_session_note_delete(PDO $pdo, string $noteId, string $doctorProfileId): void
{
    ensure_workshop_session_notes_schema($pdo);
    $pdo->prepare('DELETE FROM workshop_session_notes WHERE id=? AND doctor_id=?')->execute([$noteId, $doctorProfileId]);
}

/** لیست ثبت‌نام‌شدگان فعال کارگاه برای خروجی */
function workshop_enrollments_export_rows(PDO $pdo, string $workshopId): array
{
    $stmt = $pdo->prepare("
      SELECT e.id AS enrollment_id, e.status, e.enrolled_at,
             u.name AS patient_name, u.username, u.phone, u.email,
             wp.amount, wp.wallet_amount, wp.status AS pay_status, wp.ref_id
      FROM workshop_enrollments e
      JOIN users u ON u.id = e.patient_id
      LEFT JOIN workshop_payments wp ON wp.enrollment_id = e.id
      WHERE e.workshop_id = ?
      ORDER BY e.enrolled_at ASC
    ");
    $stmt->execute([$workshopId]);
    return $stmt->fetchAll();
}

function workshop_normalize_time(string $time): string
{
    $time = trim($time);
    if (preg_match('/^\d{1,2}:\d{2}:\d{2}$/', $time)) {
        return $time;
    }
    if (preg_match('/^\d{1,2}:\d{2}$/', $time)) {
        return $time . ':00';
    }
    throw new RuntimeException('ساعت نامعتبر است.');
}

function workshop_datetime_from_post(string $date, string $time): string
{
    $date = trim($date);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        throw new RuntimeException('تاریخ را از تقویم شمسی انتخاب کنید.');
    }
    $dt = $date . ' ' . workshop_normalize_time($time);
    if (!strtotime($dt)) {
        throw new RuntimeException('زمان کارگاه نامعتبر است.');
    }
    return $dt;
}

function workshop_type_from_tab(string $tab): string
{
    return match ($tab) {
        'online' => 'ONLINE',
        'offline' => 'OFFLINE',
        default => 'IN_PERSON',
    };
}

function workshop_tab_from_type(string $type): string
{
    return match ($type) {
        'ONLINE' => 'online',
        'OFFLINE' => 'offline',
        default => 'in-person',
    };
}

function workshop_type_label(string $type): string
{
    return match ($type) {
        'ONLINE' => 'آنلاین',
        'OFFLINE' => 'آفلاین',
        default => 'حضوری',
    };
}

function enrollment_status_label(string $status): string
{
    return match ($status) {
        'PENDING_PAYMENT' => 'در انتظار پرداخت',
        'CONFIRMED' => 'تأیید شده',
        'CANCELLED' => 'لغو شده',
        'REFUNDED' => 'بازپرداخت شده',
        'COMPLETED' => 'برگزار شده',
        default => $status,
    };
}

function workshop_refund_allowed(string $startsAt): bool
{
    return strtotime($startsAt) - time() >= 24 * 3600;
}

function workshop_enrollment_count(PDO $pdo, string $workshopId): int
{
    $stmt = $pdo->prepare("
      SELECT COUNT(*) FROM workshop_enrollments
      WHERE workshop_id = ? AND status IN ('PENDING_PAYMENT','CONFIRMED','COMPLETED')
    ");
    $stmt->execute([$workshopId]);
    return (int) $stmt->fetchColumn();
}

function workshop_has_capacity(PDO $pdo, array $workshop): bool
{
    if ($workshop['capacity'] === null || $workshop['capacity'] === '') {
        return true;
    }
    return workshop_enrollment_count($pdo, $workshop['id']) < (int) $workshop['capacity'];
}

function workshop_type_urls_from_post(string $type): array
{
    $meetingUrl = null;
    $contentUrl = null;
    [$location, $lat, $lng] = workshop_location_from_post($type);
    if ($type === 'ONLINE') {
        $meetingUrl = trim(post('meeting_url')) ?: null;
        $location = null;
        $lat = null;
        $lng = null;
    } elseif ($type === 'OFFLINE') {
        $contentUrl = trim(post('content_url')) ?: null;
        $location = null;
        $lat = null;
        $lng = null;
    }
    return [$location, $meetingUrl, $contentUrl, $lat, $lng];
}

function workshop_datetime_parts(string $datetime): array
{
    $ts = strtotime($datetime);
    if (!$ts) {
        return ['date' => '', 'time' => '', 'jalali' => ''];
    }
    $ymd = date('Y-m-d', $ts);
    $time = date('H:i', $ts);
    [$jy, $jm, $jd] = gregorian_to_jalali(
        (int) date('Y', $ts),
        (int) date('m', $ts),
        (int) date('d', $ts)
    );
    $jalali = $jy . '/' . $jm . '/' . $jd;
    return ['date' => $ymd, 'time' => $time, 'jalali' => $jalali];
}

function confirm_workshop_payment(PDO $pdo, array $paymentRow): void
{
    require_once __DIR__ . '/notifications.php';

    $pdo->prepare("UPDATE workshop_payments SET status='PAID', ref_id=? WHERE id=?")
        ->execute([$paymentRow['ref_id'] ?? null, $paymentRow['id']]);
    $pdo->prepare("UPDATE workshop_enrollments SET status='CONFIRMED' WHERE id=?")
        ->execute([$paymentRow['enrollment_id']]);

    $info = $pdo->prepare("
      SELECT w.title, w.starts_at, w.doctor_id, dp.user_id AS doctor_user_id, u.name AS patient_name
      FROM workshop_enrollments e
      JOIN workshops w ON w.id = e.workshop_id
      JOIN doctor_profiles dp ON dp.id = w.doctor_id
      JOIN users u ON u.id = e.patient_id
      WHERE e.id = ?
      LIMIT 1
    ");
    $info->execute([$paymentRow['enrollment_id']]);
    $row = $info->fetch();
    if (!$row) {
        return;
    }

    $totalAmount = (int) $paymentRow['amount'];
    if ($totalAmount > 0) {
        $netAmount = $totalAmount - (int) ($paymentRow['wallet_amount'] ?? 0);
        if ($netAmount > 0) {
            wallet_hold_for_doctor(
                $pdo,
                (string) $row['doctor_user_id'],
                $netAmount,
                'workshop_enrollment',
                (string) $paymentRow['enrollment_id'],
                'پرداخت کارگاه: ' . $row['title']
            );
        }
        $walletAmount = (int) ($paymentRow['wallet_amount'] ?? 0);
        if ($walletAmount > 0) {
            wallet_hold_for_doctor(
                $pdo,
                (string) $row['doctor_user_id'],
                $walletAmount,
                'workshop_enrollment',
                (string) $paymentRow['enrollment_id'],
                'پرداخت از کیف پول — کارگاه: ' . $row['title']
            );
        }
    }

    $when = format_fa_datetime((string) $row['starts_at']);
    $patientName = (string) $row['patient_name'];
    notify_role(
        $pdo,
        'SECRETARY',
        'ثبت‌نام کارگاه',
        "«{$patientName}» در کارگاه «{$row['title']}» ({$when}) ثبت‌نام و پرداخت کرد.",
        '/secretary/workshops?tab=enroll',
        'workshop'
    );
    notify_doctor_profile(
        $pdo,
        (string) $row['doctor_id'],
        'ثبت‌نام کارگاه',
        "«{$patientName}» در کارگاه «{$row['title']}» ({$when}) ثبت‌نام کرد.",
        '/doctor/workshops',
        'workshop'
    );
}

/** ثبت ورودی کارگاه توسط منشی — برای همه منشی‌ها دیده می‌شود */
function workshop_enroll_by_staff(PDO $pdo, string $workshopId, string $patientId, string $staffUserId, string $staffLabel): string
{
    $stmt = $pdo->prepare('
      SELECT w.* FROM workshops w
      ' . workshop_active_doctor_join('w') . '
      WHERE w.id = ? AND ' . workshop_patient_list_sql('w') . '
      LIMIT 1
    ');
    $stmt->execute([$workshopId]);
    $workshop = $stmt->fetch();
    if (!$workshop) {
        throw new RuntimeException('کارگاه برای ثبت ورودی در دسترس نیست.');
    }
    if (!workshop_has_capacity($pdo, $workshop)) {
        throw new RuntimeException('ظرفیت این کارگاه تکمیل شده است.');
    }

    $exists = $pdo->prepare('
      SELECT id, status FROM workshop_enrollments
      WHERE workshop_id = ? AND patient_id = ?
    ');
    $exists->execute([$workshopId, $patientId]);
    $existing = $exists->fetch();
    if ($existing && in_array((string) $existing['status'], ['PENDING_PAYMENT', 'CONFIRMED', 'COMPLETED'], true)) {
        throw new RuntimeException('این مراجعه‌کننده قبلاً در این کارگاه ثبت شده است.');
    }

    $patientStmt = $pdo->prepare("SELECT id, name FROM users WHERE id=? AND role='PATIENT' LIMIT 1");
    $patientStmt->execute([$patientId]);
    $patient = $patientStmt->fetch();
    if (!$patient) {
        throw new RuntimeException('مراجعه‌کننده معتبر نیست.');
    }

    $enrollmentId = cuid();
    $paymentId = cuid();
    $amount = (int) $workshop['price'];

    $pdo->beginTransaction();
    try {
        if ($existing && in_array((string) $existing['status'], ['CANCELLED', 'REFUNDED'], true)) {
            $enrollmentId = (string) $existing['id'];
            $pdo->prepare("UPDATE workshop_enrollments SET status='CONFIRMED', enrolled_at=NOW(), created_by_user_id=? WHERE id=?")
                ->execute([$staffUserId, $enrollmentId]);
            $pdo->prepare('DELETE FROM workshop_payments WHERE enrollment_id=?')->execute([$enrollmentId]);
        } else {
            $pdo->prepare('INSERT INTO workshop_enrollments (id, workshop_id, patient_id, status, created_by_user_id) VALUES (?,?,?,?,?)')
                ->execute([$enrollmentId, $workshopId, $patientId, 'CONFIRMED', $staffUserId]);
        }
        $pdo->prepare('INSERT INTO workshop_payments (id, enrollment_id, amount, status, ref_id, recorded_by_user_id) VALUES (?,?,?,?,?,?)')
            ->execute([$paymentId, $enrollmentId, $amount, 'PAID', 'SECRETARY', $staffUserId]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw new RuntimeException('ثبت ورودی کارگاه انجام نشد: ' . $e->getMessage());
    }

    $when = format_fa_datetime((string) $workshop['starts_at']);
    $patientName = (string) $patient['name'];
    $title = (string) $workshop['title'];
    notify_role(
        $pdo,
        'SECRETARY',
        'ورودی کارگاه توسط منشی',
        "«{$patientName}» در کارگاه «{$title}» ({$when}) توسط {$staffLabel} ثبت شد.",
        '/secretary/workshops?tab=enroll',
        'workshop'
    );
    notify_doctor_profile(
        $pdo,
        (string) $workshop['doctor_id'],
        'ورودی کارگاه توسط منشی',
        "«{$patientName}» در کارگاه «{$title}» ({$when}) توسط {$staffLabel} ثبت شد.",
        '/doctor/workshops',
        'workshop'
    );

    return $enrollmentId;
}

function workshop_staff_enrollments_grouped(PDO $pdo): array
{
    ensure_workshop_schema($pdo);
    $rows = $pdo->query("
      SELECT e.id, e.workshop_id, e.status, e.enrolled_at, e.created_by_user_id,
             u.name AS patient_name, u.phone AS patient_phone, u.username AS patient_username,
             wp.id AS payment_id, wp.amount, wp.status AS pay_status, wp.receipt_path, wp.ref_id,
             cu.name AS actor_name, cu.username AS actor_username,
             ru.name AS recorder_name, ru.username AS recorder_username
      FROM workshop_enrollments e
      JOIN users u ON u.id = e.patient_id
      LEFT JOIN workshop_payments wp ON wp.enrollment_id = e.id
      LEFT JOIN users cu ON cu.id = e.created_by_user_id
      LEFT JOIN users ru ON ru.id = wp.recorded_by_user_id
      WHERE e.status IN ('PENDING_PAYMENT','CONFIRMED','COMPLETED')
      ORDER BY e.enrolled_at DESC
    ")->fetchAll();
    $grouped = [];
    foreach ($rows as $row) {
        $grouped[(string) $row['workshop_id']][] = $row;
    }
    return $grouped;
}

/** ثبت پرداخت نقدی/فیش کارگاه توسط منشی */
function workshop_mark_paid_by_staff(PDO $pdo, string $enrollmentId, string $staffUserId, string $staffLabel, array $file): void
{
    ensure_workshop_schema($pdo);
    $stmt = $pdo->prepare("
      SELECT e.*, w.title, w.starts_at, w.doctor_id,
             wp.id AS payment_id, wp.amount, wp.status AS pay_status, wp.receipt_path, wp.wallet_amount,
             u.name AS patient_name
      FROM workshop_enrollments e
      JOIN workshops w ON w.id = e.workshop_id
      JOIN users u ON u.id = e.patient_id
      LEFT JOIN workshop_payments wp ON wp.enrollment_id = e.id
      WHERE e.id = ?
      LIMIT 1
    ");
    $stmt->execute([$enrollmentId]);
    $row = $stmt->fetch();
    if (!$row) {
        throw new RuntimeException('ثبت‌نام یافت نشد.');
    }
    if (in_array((string) $row['status'], ['CANCELLED', 'REFUNDED'], true)) {
        throw new RuntimeException('این ثبت‌نام لغو شده است.');
    }

    $relative = staff_save_receipt($file, (string) ($row['payment_id'] ?: $enrollmentId));
    if (!empty($row['receipt_path'])) {
        $old = staff_receipt_abs((string) $row['receipt_path']);
        if (is_file($old)) {
            @unlink($old);
        }
    }

    $paymentId = (string) ($row['payment_id'] ?? '');
    $wasPending = ((string) ($row['pay_status'] ?? '') !== 'PAID')
        || in_array((string) $row['status'], ['PENDING_PAYMENT'], true);

    if ($paymentId === '') {
        $paymentId = cuid();
        $pdo->prepare('INSERT INTO workshop_payments (id, enrollment_id, amount, status, ref_id, receipt_path, recorded_by_user_id) VALUES (?,?,?,?,?,?,?)')
            ->execute([$paymentId, $enrollmentId, 0, 'PAID', 'SECRETARY', $relative, $staffUserId]);
        $pdo->prepare("UPDATE workshop_enrollments SET status='CONFIRMED' WHERE id=?")->execute([$enrollmentId]);
    } else {
        $pdo->prepare('UPDATE workshop_payments SET receipt_path=?, recorded_by_user_id=? WHERE id=?')
            ->execute([$relative, $staffUserId, $paymentId]);
        if ($wasPending) {
            confirm_workshop_payment($pdo, [
                'id' => $paymentId,
                'enrollment_id' => $enrollmentId,
                'amount' => (int) ($row['amount'] ?? 0),
                'wallet_amount' => (int) ($row['wallet_amount'] ?? 0),
                'ref_id' => 'SECRETARY',
            ]);
            $pdo->prepare('UPDATE workshop_payments SET recorded_by_user_id=? WHERE id=?')
                ->execute([$staffUserId, $paymentId]);
        }
    }

    if ($wasPending) {
        $when = format_fa_datetime((string) $row['starts_at']);
        notify_role(
            $pdo,
            'SECRETARY',
            'پرداخت کارگاه توسط منشی',
            "پرداخت «{$row['patient_name']}» برای کارگاه «{$row['title']}» ({$when}) توسط {$staffLabel} با فیش ثبت شد.",
            '/secretary/workshops',
            'workshop'
        );
    }
}

function cancel_workshop_enrollment(PDO $pdo, string $enrollmentId, bool $forceNoRefund = false): array
{
    $stmt = $pdo->prepare("
      SELECT e.*, w.starts_at, w.title, w.doctor_id, dp.user_id AS doctor_user_id, wp.amount, wp.wallet_amount, wp.status AS pay_status
      FROM workshop_enrollments e
      JOIN workshops w ON w.id = e.workshop_id
      JOIN doctor_profiles dp ON dp.id = w.doctor_id
      LEFT JOIN workshop_payments wp ON wp.enrollment_id = e.id
      WHERE e.id = ?
      LIMIT 1
    ");
    $stmt->execute([$enrollmentId]);
    $row = $stmt->fetch();
    if (!$row) {
        throw new RuntimeException('ثبت‌نام یافت نشد.');
    }
    if (!in_array($row['status'], ['PENDING_PAYMENT', 'CONFIRMED'], true)) {
        throw new RuntimeException('این ثبت‌نام قابل لغو نیست.');
    }

    $refundable = !$forceNoRefund
        && $row['status'] === 'CONFIRMED'
        && ($row['pay_status'] ?? '') === 'PAID'
        && workshop_refund_allowed((string) $row['starts_at']);

    if ($refundable) {
        $paidAmount = (int) $row['amount'];
        wallet_refund_from_doctor_hold(
            $pdo,
            (string) $row['doctor_user_id'],
            (string) $row['patient_id'],
            $paidAmount,
            $enrollmentId,
            'لغو کارگاه: ' . $row['title']
        );
        $pdo->prepare("UPDATE workshop_payments SET status='REFUNDED' WHERE enrollment_id=?")->execute([$enrollmentId]);
        $pdo->prepare("UPDATE workshop_enrollments SET status='REFUNDED' WHERE id=?")->execute([$enrollmentId]);
        return ['status' => 'REFUNDED', 'refunded' => true, 'amount' => $paidAmount];
    }

    if ($row['status'] === 'PENDING_PAYMENT') {
        $pdo->prepare("UPDATE workshop_payments SET status='FAILED' WHERE enrollment_id=? AND status='PENDING'")
            ->execute([$enrollmentId]);
    }
    $pdo->prepare("UPDATE workshop_enrollments SET status='CANCELLED' WHERE id=?")->execute([$enrollmentId]);
    return ['status' => 'CANCELLED', 'refunded' => false, 'amount' => 0];
}

function complete_workshop(PDO $pdo, string $workshopId, string $doctorProfileId): int
{
    $stmt = $pdo->prepare('SELECT * FROM workshops WHERE id=? AND doctor_id=? LIMIT 1');
    $stmt->execute([$workshopId, $doctorProfileId]);
    $workshop = $stmt->fetch();
    if (!$workshop) {
        throw new RuntimeException('کارگاه یافت نشد.');
    }

    $docUser = $pdo->prepare('SELECT user_id FROM doctor_profiles WHERE id=?');
    $docUser->execute([$doctorProfileId]);
    $doctorUserId = (string) $docUser->fetchColumn();

    $enrollments = $pdo->prepare("
      SELECT e.id, wp.amount
      FROM workshop_enrollments e
      JOIN workshop_payments wp ON wp.enrollment_id = e.id AND wp.status = 'PAID'
      WHERE e.workshop_id = ? AND e.status = 'CONFIRMED'
    ");
    $enrollments->execute([$workshopId]);
    $settled = 0;

    foreach ($enrollments->fetchAll() as $enrollment) {
        wallet_settle_doctor_hold(
            $pdo,
            $doctorUserId,
            (int) $enrollment['amount'],
            (string) $enrollment['id'],
            'تسویه کارگاه: ' . $workshop['title']
        );
        $pdo->prepare("UPDATE workshop_enrollments SET status='COMPLETED' WHERE id=?")
            ->execute([$enrollment['id']]);
        $settled++;
    }

    $pdo->prepare("UPDATE workshops SET status='COMPLETED', enrollment_open=0 WHERE id=?")->execute([$workshopId]);
    return $settled;
}

/** کارگاه تمام‌شده یا لغوشده در آرشیو دیده می‌شود */
function workshop_is_archived(array $workshop): bool
{
    $status = (string) ($workshop['status'] ?? '');
    if (in_array($status, ['COMPLETED', 'CANCELLED'], true)) {
        return true;
    }
    if ((string) ($workshop['type'] ?? '') === 'OFFLINE') {
        return false;
    }
    $end = strtotime((string) ($workshop['ends_at'] ?? ''));
    return $end !== false && $end <= time();
}

/** کارگاه حضوری/آنلاین که زمانش گذشته، تسویه و به آرشیو می‌رود */
function workshop_archive_expired(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $rows = $pdo->query("
          SELECT id, doctor_id FROM workshops
          WHERE status NOT IN ('COMPLETED','CANCELLED')
            AND type <> 'OFFLINE'
            AND ends_at <= NOW()
        ")->fetchAll();
    } catch (Throwable $e) {
        return;
    }
    foreach ($rows as $row) {
        $id = (string) ($row['id'] ?? '');
        $doctorId = (string) ($row['doctor_id'] ?? '');
        if ($id === '' || $doctorId === '') {
            continue;
        }
        try {
            complete_workshop($pdo, $id, $doctorId);
        } catch (Throwable $e) {
            // اگر تسویه نشد، فقط در تب آرشیو دیده می‌شود تا درمانگر دستی «تسویه و پایان» بزند
        }
    }
}

/** گروه‌بندی برای تب‌های رنگی کارگاه */
function workshop_group_for_tabs(array $workshops): array
{
    $out = [
        'in-person' => [],
        'online' => [],
        'offline' => [],
        'archive' => [],
    ];
    foreach ($workshops as $workshop) {
        if (workshop_is_archived($workshop)) {
            $out['archive'][] = $workshop;
            continue;
        }
        $type = (string) ($workshop['type'] ?? '');
        if ($type === 'ONLINE') {
            $out['online'][] = $workshop;
        } elseif ($type === 'OFFLINE') {
            $out['offline'][] = $workshop;
        } else {
            $out['in-person'][] = $workshop;
        }
    }
    return $out;
}

/**
 * داده تب‌های کارگاه برای پنل مراجعه‌کننده (داشبورد و دوره‌های من).
 *
 * @return array{
 *   wallet: array,
 *   grouped: array<string, array>,
 *   enrollmentsByTab: array<string, array>,
 *   enrollByWorkshop: array<string, array>,
 *   binderTabs: array<string, array{label: string, class: string, empty: string}>,
 *   enrollUrl: string,
 *   payUrl: string,
 *   cancelUrl: string
 * }
 */
function patient_workshop_tab_data(PDO $pdo, string $patientId): array
{
    require_once __DIR__ . '/workshop_media.php';
    ensure_workshop_schema($pdo);
    ensure_workshop_media_schema($pdo);
    $wallet = ensure_wallet($pdo, $patientId);

    $published = $pdo->query("
      SELECT w.*, u.name AS doctor_name,
        (SELECT COUNT(*) FROM workshop_media_items m WHERE m.workshop_id = w.id AND m.kind = 'VIDEO') AS video_count,
        (SELECT COUNT(*) FROM workshop_media_items m WHERE m.workshop_id = w.id AND m.kind = 'AUDIO') AS audio_count
      FROM workshops w
      " . workshop_active_doctor_join('w') . "
      JOIN users u ON u.id = dp.user_id
      WHERE w.is_published = 1
      ORDER BY w.starts_at DESC
    ")->fetchAll();

    $mine = $pdo->prepare("
      SELECT e.*, w.title, w.starts_at, w.ends_at, w.type, w.status AS workshop_status,
             w.meeting_url, w.content_url, w.group_url, w.location,
             w.location_lat, w.location_lng,
             (SELECT COUNT(*) FROM workshop_media_items m WHERE m.workshop_id = w.id AND m.kind = 'VIDEO') AS video_count,
             (SELECT COUNT(*) FROM workshop_media_items m WHERE m.workshop_id = w.id AND m.kind = 'AUDIO') AS audio_count,
             wp.amount, wp.wallet_amount, wp.status AS pay_status, u.name AS doctor_name
      FROM workshop_enrollments e
      JOIN workshops w ON w.id = e.workshop_id
      JOIN doctor_profiles dp ON dp.id = w.doctor_id
      JOIN users u ON u.id = dp.user_id
      LEFT JOIN workshop_payments wp ON wp.enrollment_id = e.id
      WHERE e.patient_id = ?
      ORDER BY e.enrolled_at DESC
    ");
    $mine->execute([$patientId]);
    $myEnrollments = $mine->fetchAll();

    $enrollByWorkshop = [];
    foreach ($myEnrollments as $row) {
        $wid = (string) ($row['workshop_id'] ?? '');
        if ($wid !== '' && !isset($enrollByWorkshop[$wid])) {
            $enrollByWorkshop[$wid] = $row;
        }
    }

    $visible = [];
    foreach ($published as $workshop) {
        if ((string) ($workshop['status'] ?? '') === 'CANCELLED' && empty($enrollByWorkshop[(string) $workshop['id']])) {
            continue;
        }
        $visible[] = $workshop;
    }
    $grouped = workshop_group_for_tabs($visible);

    $enrollmentsByTab = [
        'in-person' => [],
        'online' => [],
        'offline' => [],
        'archive' => [],
    ];
    foreach ($myEnrollments as $row) {
        if (workshop_is_archived([
            'status' => (string) ($row['workshop_status'] ?? ''),
            'type' => (string) ($row['type'] ?? ''),
            'ends_at' => (string) ($row['ends_at'] ?? ''),
        ])) {
            $enrollmentsByTab['archive'][] = $row;
            continue;
        }
        $tab = workshop_tab_from_type((string) ($row['type'] ?? ''));
        $enrollmentsByTab[$tab][] = $row;
    }

    return [
        'wallet' => $wallet,
        'grouped' => $grouped,
        'enrollmentsByTab' => $enrollmentsByTab,
        'enrollByWorkshop' => $enrollByWorkshop,
        'binderTabs' => [
            'in-person' => ['label' => 'حضوری', 'class' => 'binder-tab-in-person', 'empty' => 'کارگاه حضوری فعالی برای ثبت‌نام نیست.'],
            'online' => ['label' => 'آنلاین', 'class' => 'binder-tab-online', 'empty' => 'کارگاه آنلاین فعالی برای ثبت‌نام نیست.'],
            'offline' => ['label' => 'آفلاین', 'class' => 'binder-tab-offline', 'empty' => 'دوره آفلاین فعالی برای ثبت‌نام نیست.'],
        ],
        'enrollUrl' => url('/enroll-workshop'),
        'payUrl' => url('/pay-workshop'),
        'cancelUrl' => url('/cancel-enrollment'),
    ];
}
