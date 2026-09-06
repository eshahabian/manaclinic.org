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
