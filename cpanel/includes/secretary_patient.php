<?php
declare(strict_types=1);

/**
 * ساخت حساب مراجعه‌کننده توسط منشی/ادمین.
 *
 * @return array{ok:bool,error?:string,id?:string,name?:string,username?:string}
 */
function secretary_create_patient_from_post(PDO $pdo, array $actor, array $opts = []): array
{
    $firstName = trim(post('new_first_name'));
    $lastName = trim(post('new_last_name'));
    $nameEn = trim(post('new_name_en'));
    $surname = trim(post('new_surname'));
    $newName = trim($firstName . ' ' . $lastName);
    $newPhone = normalize_phone(post('new_phone'));
    $newUsername = mb_strtolower(normalize_input(post('new_username')));
    $newPassword = (string) ($_POST['new_password'] ?? '');
    $newPasswordConfirm = (string) ($_POST['new_password_confirm'] ?? '');
    $preferredDoctorId = post('new_preferred_doctor_id') ?: null;
    $fallbackDoctorId = trim((string) ($opts['fallback_doctor_id'] ?? ''));
    $actorId = (string) ($actor['id'] ?? '');
    $actorName = staff_actor_label($actor);

    if ($firstName === '' || $lastName === '' || $nameEn === '' || $surname === '') {
        return ['ok' => false, 'error' => 'نام، نام خانوادگی، name و surname الزامی هستند.'];
    }
    if ($preferredDoctorId === null || $preferredDoctorId === '') {
        $preferredDoctorId = $fallbackDoctorId !== '' ? $fallbackDoctorId : null;
    }
    if ($preferredDoctorId === null || $preferredDoctorId === '') {
        return ['ok' => false, 'error' => 'درمانگر مربوط به مراجعه‌کننده را انتخاب کنید.'];
    }
    $prefDoc = $pdo->prepare('SELECT id FROM doctor_profiles WHERE id=? AND is_active=1 AND is_approved=1');
    $prefDoc->execute([$preferredDoctorId]);
    if (!$prefDoc->fetch()) {
        return ['ok' => false, 'error' => 'درمانگر مربوط به مراجعه‌کننده معتبر نیست.'];
    }
    if (!is_valid_phone($newPhone)) {
        return ['ok' => false, 'error' => 'موبایل الزامی است. شماره ایران یا بین‌المللی معتبر وارد کنید.'];
    }
    if ($newUsername === '') {
        return ['ok' => false, 'error' => 'نام کاربری مراجعه‌کننده جدید الزامی است.'];
    }
    if (!preg_match('/^[a-z0-9._-]{3,32}$/', $newUsername)) {
        return ['ok' => false, 'error' => 'نام کاربری نامعتبر است. فقط حروف انگلیسی، عدد و ._- (۳ تا ۳۲ کاراکتر).'];
    }
    if (!function_exists('user_referral_normalize')) {
        require_once __DIR__ . '/user_referral.php';
    }
    $referralSource = user_referral_normalize(post('new_referral_source'));
    if ($referralSource === null) {
        return ['ok' => false, 'error' => 'معرف را انتخاب کنید.'];
    }
    if (strlen($newPassword) < 6) {
        return ['ok' => false, 'error' => 'رمز عبور حداقل ۶ کاراکتر باشد.'];
    }
    if ($newPassword !== $newPasswordConfirm) {
        return ['ok' => false, 'error' => 'رمز عبور و تکرار آن یکسان نیست.'];
    }

    $newUsername = unique_username($pdo, $newUsername);
    if ($newUsername === '') {
        return ['ok' => false, 'error' => 'ساخت نام کاربری ممکن نشد. نام انگلیسی را تغییر دهید.'];
    }

    $patientId = cuid();
    $pdo->prepare('INSERT INTO users (id,username,name,email,phone,password_hash,role,preferred_doctor_id,created_by_user_id,referral_source,must_change_password) VALUES (?,?,?,?,?,?,?,?,?,?,0)')
        ->execute([
            $patientId,
            $newUsername,
            $newName,
            $newUsername . '@manaclinic.local',
            $newPhone,
            password_hash($newPassword, PASSWORD_DEFAULT),
            'PATIENT',
            $preferredDoctorId,
            $actorId,
            $referralSource,
        ]);
    user_remember_password_plain($pdo, $patientId, $newPassword);
    if (function_exists('outreach_link_user_by_phone')) {
        outreach_link_user_by_phone($pdo, $newPhone, $patientId);
    }

    if (function_exists('ensure_wallet')) {
        ensure_wallet($pdo, $patientId);
    }
    remember_registration_name_transliterations($pdo, $firstName, $lastName, $nameEn, $surname);

    $docNameStmt = $pdo->prepare('SELECT u.name FROM doctor_profiles dp JOIN users u ON u.id = dp.user_id WHERE dp.id = ?');
    $docNameStmt->execute([$preferredDoctorId]);
    $doctorName = (string) ($docNameStmt->fetchColumn() ?: 'درمانگر');
    notify_role(
        $pdo,
        'SECRETARY',
        'مراجعه‌کننده جدید توسط منشی',
        "مراجعه‌کننده «{$newName}» توسط {$actorName} ثبت شد (درمانگر: {$doctorName}).",
        '/secretary/patients/' . $patientId,
        'appointment'
    );
    notify_doctor_profile(
        $pdo,
        $preferredDoctorId,
        'مراجعه‌کننده جدید',
        "مراجعه‌کننده «{$newName}» به شما اختصاص داده شد.",
        '/doctor/patients/' . $patientId,
        'appointment'
    );
    staff_log_action($pdo, $actorId, 'create_patient', 'user', $patientId, $newName);

    return [
        'ok' => true,
        'id' => $patientId,
        'name' => $newName,
        'username' => $newUsername,
    ];
}

function secretary_active_doctors(PDO $pdo): array
{
    return $pdo->query("
      SELECT dp.id, u.name, dp.specialty
      FROM doctor_profiles dp
      JOIN users u ON u.id = dp.user_id
      WHERE dp.is_active = 1 AND dp.is_approved = 1 AND u.is_disabled = 0
      ORDER BY u.name ASC
    ")->fetchAll();
}

/** مراجعه‌کننده‌های قابل انتخاب برای نوبت؛ حساب عماد شهابیان مدیر است و جدا اضافه می‌شود. */
function secretary_bookable_patients(PDO $pdo): array
{
    if (!function_exists('ensure_user_referral_schema')) {
        require_once __DIR__ . '/user_referral.php';
    }
    ensure_user_referral_schema($pdo);

    return $pdo->query("
      SELECT u.id, u.name, u.username, u.phone, u.role, u.is_disabled, u.preferred_doctor_id, u.referral_source, du.name AS doctor_name
      FROM users u
      LEFT JOIN doctor_profiles dp ON dp.id = u.preferred_doctor_id
      LEFT JOIN users du ON du.id = dp.user_id
      WHERE (
          u.role = 'PATIENT'
          OR LOWER(u.username) = 'eshahabian'
          OR (u.name LIKE '%عماد%' AND u.name LIKE '%شهابیان%')
        )
      ORDER BY u.is_disabled ASC, u.name ASC
    ")->fetchAll() ?: [];
}

function secretary_person_name_key(string $name): string
{
    $name = str_replace(["\u{200c}", 'ي', 'ك', 'ة', 'أ', 'إ', 'آ'], [' ', 'ی', 'ک', 'ه', 'ا', 'ا', 'ا'], trim($name));
    $name = preg_replace('/\s+/u', ' ', $name) ?? $name;

    return mb_strtolower($name);
}

/** @return array{first:string,last:string} */
function secretary_person_name_parts(string $name): array
{
    $parts = preg_split('/\s+/u', trim($name), 2, PREG_SPLIT_NO_EMPTY) ?: [];

    return [
        'first' => (string) ($parts[0] ?? ''),
        'last' => (string) ($parts[1] ?? ''),
    ];
}

function secretary_patient_is_inactive(array $row): bool
{
    if (($row['kind'] ?? '') === 'outreach' || str_starts_with((string) ($row['book_id'] ?? ''), 'outreach:')) {
        return true;
    }

    return (int) ($row['is_disabled'] ?? 0) === 1;
}

/**
 * موردهای ناقص پروفایل برای فعال‌سازی دوباره.
 * اگر فهرست درمانگرهای فعال داده شود، درمانگر حذف‌شده هم ناقص حساب می‌شود.
 *
 * @param list<string> $activeDoctorIds
 * @return list<'phone'|'doctor'|'referral'>
 */
function secretary_patient_profile_gaps(array $row, array $activeDoctorIds = []): array
{
    $gaps = [];
    if (!is_valid_phone((string) ($row['phone'] ?? ''))) {
        $gaps[] = 'phone';
    }
    $doctorId = trim((string) ($row['preferred_doctor_id'] ?? ''));
    $doctorOk = $doctorId !== '';
    if ($doctorOk && $activeDoctorIds !== []) {
        $doctorOk = in_array($doctorId, $activeDoctorIds, true);
    }
    if (!$doctorOk) {
        $gaps[] = 'doctor';
    }
    if (!function_exists('user_referral_normalize')) {
        require_once __DIR__ . '/user_referral.php';
    }
    if (user_referral_normalize((string) ($row['referral_source'] ?? '')) === null) {
        $gaps[] = 'referral';
    }

    return $gaps;
}

/** درمانگر، معرف یا موبایل ندارد، یا هنوز اصلاً حساب ساخته نشده. */
function secretary_patient_profile_missing(array $row, array $activeDoctorIds = []): bool
{
    if (($row['kind'] ?? '') === 'outreach') {
        return true;
    }
    if (strtoupper((string) ($row['role'] ?? '')) !== 'PATIENT') {
        return false;
    }

    return secretary_patient_profile_gaps($row, $activeDoctorIds) !== [];
}

/**
 * یک فهرست برای همه: حساب فعال، حساب غیرفعال، و نام‌وشماره بدون حساب.
 * اگر همان شخص هم حساب دارد و هم در فهرست نام‌وشماره مانده، فقط حسابش برمی‌گردد.
 *
 * @return list<array<string,mixed>>
 */
function secretary_patient_directory(PDO $pdo): array
{
    if (!function_exists('ensure_user_referral_schema')) {
        require_once __DIR__ . '/user_referral.php';
    }
    ensure_user_referral_schema($pdo);
    if (!function_exists('ensure_outreach_schema')) {
        require_once __DIR__ . '/outreach.php';
    }
    ensure_outreach_schema($pdo);

    $users = $pdo->query("
      SELECT u.id, u.username, u.name, u.role, u.phone, u.is_disabled, u.created_at, u.last_login_at,
             u.referral_source, u.preferred_doctor_id,
             du.name AS doctor_name,
             cu.name AS created_by_name, cu.username AS created_by_username, cu.role AS created_by_role
      FROM users u
      LEFT JOIN users cu ON cu.id = u.created_by_user_id
      LEFT JOIN doctor_profiles dp ON dp.id = u.preferred_doctor_id
      LEFT JOIN users du ON du.id = dp.user_id
      WHERE u.role = 'PATIENT'
         OR LOWER(u.username) = 'eshahabian'
         OR (u.name LIKE '%عماد%' AND u.name LIKE '%شهابیان%')
      ORDER BY u.name ASC
    ")->fetchAll() ?: [];

    $rows = [];
    $byId = [];
    $byPhone = [];
    $nameOwners = [];
    foreach ($users as $user) {
        if (!is_array($user)) {
            continue;
        }
        $id = (string) ($user['id'] ?? '');
        if ($id === '') {
            continue;
        }
        $user['kind'] = 'user';
        $user['book_id'] = $id;
        $rows[] = $user;
        $byId[$id] = true;
        $phone = normalize_phone((string) ($user['phone'] ?? ''));
        if ($phone !== '') {
            $byPhone[$phone] = $id;
        }
        $nameKey = secretary_person_name_key((string) ($user['name'] ?? ''));
        if ($nameKey !== '') {
            $nameOwners[$nameKey][] = $id;
        }
    }

    foreach (outreach_contacts($pdo) as $contact) {
        if (!is_array($contact)) {
            continue;
        }
        $contactId = (string) ($contact['id'] ?? '');
        $linked = trim((string) ($contact['linked_user_id'] ?? ''));
        $phone = normalize_phone((string) ($contact['phone'] ?? ''));
        $nameKey = secretary_person_name_key((string) ($contact['name'] ?? ''));
        $matchedUser = '';
        $matchByPhone = false;
        if ($linked !== '' && isset($byId[$linked])) {
            $matchedUser = $linked;
        } elseif ($phone !== '' && isset($byPhone[$phone])) {
            $matchedUser = $byPhone[$phone];
            $matchByPhone = true;
        } elseif ($nameKey !== '' && count($nameOwners[$nameKey] ?? []) === 1) {
            $matchedUser = (string) $nameOwners[$nameKey][0];
        }
        if ($matchedUser !== '') {
            if ($matchByPhone && $linked !== $matchedUser && $contactId !== '') {
                try {
                    $pdo->prepare('UPDATE outreach_contacts SET linked_user_id=? WHERE id=?')->execute([$matchedUser, $contactId]);
                } catch (Throwable $ignored) {
                }
            }
            continue;
        }
        if ($contactId === '') {
            continue;
        }
        $rows[] = [
            'kind' => 'outreach',
            'id' => $contactId,
            'book_id' => 'outreach:' . $contactId,
            'username' => '',
            'name' => (string) ($contact['name'] ?? ''),
            'role' => '',
            'phone' => (string) ($contact['phone'] ?? ''),
            'is_disabled' => 1,
            'created_at' => (string) ($contact['created_at'] ?? ''),
            'last_login_at' => '',
            'referral_source' => '',
            'preferred_doctor_id' => '',
            'doctor_name' => '',
            'created_by_name' => '',
            'created_by_username' => '',
            'created_by_role' => '',
            'note' => (string) ($contact['note'] ?? ''),
        ];
    }

    usort($rows, static function (array $a, array $b): int {
        return strnatcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
    });

    return $rows;
}

/** حساب قدیمی، غیرفعال، یا بدون درمانگر و معرف و موبایل. */
function secretary_patient_needs_setup(array $row): bool
{
    if ((int) ($row['is_disabled'] ?? 0) === 1) {
        return true;
    }
    if (strtoupper((string) ($row['role'] ?? '')) !== 'PATIENT') {
        return false;
    }
    if (trim((string) ($row['phone'] ?? '')) === '') {
        return true;
    }
    if (trim((string) ($row['preferred_doctor_id'] ?? '')) === '') {
        return true;
    }
    if (!function_exists('user_referral_normalize')) {
        require_once __DIR__ . '/user_referral.php';
    }

    return user_referral_normalize((string) ($row['referral_source'] ?? '')) === null;
}

/** قبل از نوبت، کاربر غیرفعال را فعال می‌کند و پروفایل ناقص را کامل می‌کند. */
function secretary_complete_patient_profile(PDO $pdo, string $patientId): ?string
{
    $stmt = $pdo->prepare('SELECT id, name, phone, is_disabled, preferred_doctor_id, referral_source, role FROM users WHERE id=? LIMIT 1');
    $stmt->execute([$patientId]);
    $row = $stmt->fetch();
    if (!$row) {
        return 'مراجعه‌کننده پیدا نشد.';
    }
    if (!secretary_patient_needs_setup($row)) {
        return null;
    }
    $phone = normalize_phone((string) post('legacy_phone'));
    if ($phone === '') {
        $phone = normalize_phone((string) ($row['phone'] ?? ''));
    }
    $doctorId = trim((string) post('legacy_doctor_id'));
    if ($doctorId === '') {
        $doctorId = trim((string) ($row['preferred_doctor_id'] ?? ''));
    }
    if (!function_exists('user_referral_normalize')) {
        require_once __DIR__ . '/user_referral.php';
    }
    $referral = user_referral_normalize((string) post('legacy_referral'));
    if ($referral === null) {
        $referral = user_referral_normalize((string) ($row['referral_source'] ?? ''));
    }
    if (!is_valid_phone($phone)) {
        return 'برای فعال کردن «' . (string) $row['name'] . '» موبایل معتبر لازم است.';
    }
    $doc = $pdo->prepare('SELECT id FROM doctor_profiles WHERE id=? AND is_active=1 AND is_approved=1 LIMIT 1');
    $doc->execute([$doctorId]);
    if (!$doc->fetch()) {
        return 'برای فعال کردن «' . (string) $row['name'] . '» درمانگر پروفایل را انتخاب کنید.';
    }
    if ($referral === null) {
        return 'برای فعال کردن «' . (string) $row['name'] . '» معرف را انتخاب کنید.';
    }
    $pdo->prepare('UPDATE users SET is_disabled=0, phone=?, preferred_doctor_id=?, referral_source=? WHERE id=?')
        ->execute([$phone, $doctorId, $referral, $patientId]);
    if (function_exists('ensure_wallet')) {
        require_once __DIR__ . '/wallet.php';
        ensure_wallet($pdo, $patientId);
    }

    return null;
}

/**
 * برای کاربران قدیمی که created_by خالی است، از لاگ منشی و اولین نوبت/ثبت‌نام پر می‌کند.
 */
function users_backfill_created_by(PDO $pdo): int
{
    static $done = false;
    if ($done) {
        return 0;
    }
    $done = true;
    if (function_exists('ensure_staff_desk_schema')) {
        ensure_staff_desk_schema($pdo);
    }
    $updated = 0;
    try {
        $stmt = $pdo->prepare("
          UPDATE users u
          INNER JOIN (
            SELECT sal.target_id AS patient_id, sal.user_id AS creator_id
            FROM secretary_action_log sal
            INNER JOIN (
              SELECT target_id, MIN(created_at) AS first_at
              FROM secretary_action_log
              WHERE action = 'create_patient'
                AND target_type = 'user'
                AND target_id IS NOT NULL
                AND target_id <> ''
              GROUP BY target_id
            ) first_log ON first_log.target_id = sal.target_id AND first_log.first_at = sal.created_at
            WHERE sal.action = 'create_patient' AND sal.target_type = 'user'
          ) src ON src.patient_id = u.id
          SET u.created_by_user_id = src.creator_id
          WHERE u.created_by_user_id IS NULL
            AND u.role = 'PATIENT'
            AND src.creator_id IS NOT NULL
            AND src.creator_id <> ''
        ");
        $stmt->execute();
        $updated += (int) $stmt->rowCount();
    } catch (Throwable $ignored) {
    }

    try {
        $stmt = $pdo->prepare("
          UPDATE users u
          INNER JOIN (
            SELECT a.patient_id, a.created_by_user_id AS creator_id
            FROM appointments a
            INNER JOIN (
              SELECT patient_id, MIN(starts_at) AS first_at
              FROM appointments
              WHERE created_by_user_id IS NOT NULL AND created_by_user_id <> ''
              GROUP BY patient_id
            ) fa ON fa.patient_id = a.patient_id AND fa.first_at = a.starts_at
            INNER JOIN users cu ON cu.id = a.created_by_user_id AND cu.role IN ('SECRETARY','ADMIN')
            WHERE a.created_by_user_id IS NOT NULL
          ) src ON src.patient_id = u.id
          SET u.created_by_user_id = src.creator_id
          WHERE u.created_by_user_id IS NULL
            AND u.role = 'PATIENT'
        ");
        $stmt->execute();
        $updated += (int) $stmt->rowCount();
    } catch (Throwable $ignored) {
    }

    try {
        $stmt = $pdo->prepare("
          UPDATE users u
          INNER JOIN (
            SELECT e.patient_id, e.created_by_user_id AS creator_id
            FROM workshop_enrollments e
            INNER JOIN (
              SELECT patient_id, MIN(enrolled_at) AS first_at
              FROM workshop_enrollments
              WHERE created_by_user_id IS NOT NULL AND created_by_user_id <> ''
              GROUP BY patient_id
            ) fe ON fe.patient_id = e.patient_id AND fe.first_at = e.enrolled_at
            INNER JOIN users cu ON cu.id = e.created_by_user_id AND cu.role IN ('SECRETARY','ADMIN')
            WHERE e.created_by_user_id IS NOT NULL
          ) src ON src.patient_id = u.id
          SET u.created_by_user_id = src.creator_id
          WHERE u.created_by_user_id IS NULL
            AND u.role = 'PATIENT'
        ");
        $stmt->execute();
        $updated += (int) $stmt->rowCount();
    } catch (Throwable $ignored) {
    }

    return $updated;
}

/** برچسب فارسی «ثبت توسط …» برای نمایش در لیست‌ها */
function user_created_by_label(?array $creatorRow, ?string $fallback = 'نامشخص'): string
{
    if (!$creatorRow) {
        return (string) ($fallback ?? 'نامشخص');
    }
    $name = trim((string) ($creatorRow['name'] ?? ''));
    $username = trim((string) ($creatorRow['username'] ?? ''));
    if ($name === '' && $username === '') {
        return (string) ($fallback ?? 'نامشخص');
    }
    $role = strtoupper((string) ($creatorRow['role'] ?? ''));
    $label = staff_actor_label($creatorRow);
    if ($role === 'SECRETARY') {
        return 'منشی: ' . $label;
    }
    if ($role === 'ADMIN') {
        return 'مدیر: ' . $label;
    }
    if ($role === 'DOCTOR') {
        return 'درمانگر: ' . $label;
    }

    return $label;
}

function ensure_patient_secretary_notes_schema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    $pdo->exec("
      CREATE TABLE IF NOT EXISTS patient_secretary_notes (
        id VARCHAR(32) PRIMARY KEY,
        patient_user_id VARCHAR(32) NOT NULL,
        body VARCHAR(1000) NOT NULL,
        created_by VARCHAR(32) NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_patient_sec_note (patient_user_id, created_at)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $ready = true;
}

/** @param list<string> $ids */
function patient_secretary_note_ids(array $ids): array
{
    $out = [];
    foreach ($ids as $id) {
        $id = trim((string) $id);
        if ($id !== '' && preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $id)) {
            $out[$id] = $id;
        }
    }

    return array_values($out);
}

/** @param list<string> $patientIds @return array<string, list<array{body:string,at:string,by:string}>> */
function patient_secretary_notes_grouped(PDO $pdo, array $patientIds): array
{
    ensure_patient_secretary_notes_schema($pdo);
    $ids = patient_secretary_note_ids($patientIds);
    $grouped = [];
    if (!$ids) {
        return $grouped;
    }
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("
      SELECT n.patient_user_id, n.body, n.created_at, u.name AS author_name
      FROM patient_secretary_notes n
      LEFT JOIN users u ON u.id = n.created_by
      WHERE n.patient_user_id IN ($marks)
      ORDER BY n.created_at DESC
    ");
    $stmt->execute($ids);
    foreach ($stmt->fetchAll() ?: [] as $row) {
        $pid = (string) ($row['patient_user_id'] ?? '');
        if (!isset($grouped[$pid])) {
            $grouped[$pid] = [];
        }
        if (count($grouped[$pid]) >= 20) {
            continue;
        }
        $at = trim((string) ($row['created_at'] ?? ''));
        $grouped[$pid][] = [
            'body' => (string) ($row['body'] ?? ''),
            'at' => $at !== '' ? format_fa_datetime($at) : '',
            'by' => trim((string) ($row['author_name'] ?? '')),
        ];
    }

    return $grouped;
}

/** @param list<string> $patientIds @return array<string, array{next:string,last:string,upcoming:string}> */
function patient_secretary_appointment_briefs(PDO $pdo, array $patientIds): array
{
    $ids = patient_secretary_note_ids($patientIds);
    $out = [];
    if (!$ids) {
        return $out;
    }
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("
      SELECT patient_id,
        MIN(CASE WHEN starts_at >= NOW() AND status <> 'CANCELLED' THEN starts_at END) AS next_at,
        MAX(CASE WHEN starts_at < NOW() AND status <> 'CANCELLED' THEN starts_at END) AS last_at,
        SUM(CASE WHEN starts_at >= NOW() AND status <> 'CANCELLED' THEN 1 ELSE 0 END) AS upcoming_count
      FROM appointments
      WHERE patient_id IN ($marks)
      GROUP BY patient_id
    ");
    $stmt->execute($ids);
    foreach ($stmt->fetchAll() ?: [] as $row) {
        $pid = (string) ($row['patient_id'] ?? '');
        $next = trim((string) ($row['next_at'] ?? ''));
        $last = trim((string) ($row['last_at'] ?? ''));
        $out[$pid] = [
            'next' => $next !== '' ? format_fa_datetime($next) : '',
            'last' => $last !== '' ? format_fa_datetime($last) : '',
            'upcoming' => to_fa_digits((string) (int) ($row['upcoming_count'] ?? 0)),
        ];
    }

    return $out;
}
