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
    $pdo->prepare('INSERT INTO users (id,username,name,email,phone,password_hash,role,preferred_doctor_id,created_by_user_id,must_change_password) VALUES (?,?,?,?,?,?,?,?,?,0)')
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
        ]);
    user_remember_password_plain($pdo, $patientId, $newPassword);

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
      WHERE dp.is_active = 1 AND dp.is_approved = 1
      ORDER BY u.name ASC
    ")->fetchAll();
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
