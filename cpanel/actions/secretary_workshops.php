<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/workshops.php';
require_once __DIR__ . '/../includes/workshop_media.php';
require_once __DIR__ . '/../includes/notifications.php';

$user = require_login(['SECRETARY']);
ensure_workshop_schema($pdo);
ensure_workshop_media_schema($pdo);
$action = post('action');
$base = '/secretary/workshops';

function secretary_resolve_doctor_id(PDO $pdo, string $doctorId): array
{
    if ($doctorId === '') {
        throw new RuntimeException('درمانگر را انتخاب کنید.');
    }
    $stmt = $pdo->prepare("
      SELECT dp.id, u.name
      FROM doctor_profiles dp
      JOIN users u ON u.id = dp.user_id
      WHERE dp.id = ? AND dp.is_approved = 1 AND dp.is_active = 1
      LIMIT 1
    ");
    $stmt->execute([$doctorId]);
    $row = $stmt->fetch();
    if (!$row) {
        throw new RuntimeException('درمانگر معتبر نیست.');
    }
    return $row;
}

if ($action === 'create') {
    try {
        $data = workshop_save_fields_from_post();
        $doctor = secretary_resolve_doctor_id($pdo, post('doctor_id'));
    } catch (RuntimeException $e) {
        flash_set('error', $e->getMessage());
        redirect($base);
    }

    if (workshop_is_offline($data['type'])) {
        $hasFile = false;
        $files = $_FILES['media_files'] ?? [];
        if (is_array($files['error'] ?? null)) {
            foreach ($files['error'] as $err) {
                if ((int) $err === UPLOAD_ERR_OK) {
                    $hasFile = true;
                    break;
                }
            }
        }
        if (!$hasFile) {
            flash_set('error', 'برای دوره آفلاین حداقل یک ویدیو یا فایل صوتی بارگذاری کنید.');
            redirect($base);
        }
    }

    $id = cuid();
    try {
        $pdo->beginTransaction();
        $pdo->prepare('
          INSERT INTO workshops
            (id, doctor_id, title, type, starts_at, ends_at, items_to_bring, notes, description, price, capacity, location, location_lat, location_lng, meeting_url, content_url, group_url, is_published, enrollment_open, status)
          VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ')->execute([
            $id,
            $doctor['id'],
            $data['title'],
            $data['type'],
            $data['starts_at'],
            $data['ends_at'],
            $data['items_to_bring'],
            $data['notes'],
            $data['description'],
            $data['price'],
            $data['capacity'],
            $data['location'],
            $data['location_lat'],
            $data['location_lng'],
            $data['meeting_url'],
            $data['content_url'],
            $data['group_url'],
            1,
            1,
            'PUBLISHED',
        ]);

        workshop_media_process_form_uploads($pdo, $id, null);
        if (workshop_is_offline($data['type']) && workshop_media_count($pdo, $id) < 1) {
            throw new RuntimeException('برای دوره آفلاین حداقل یک ویدیو یا فایل صوتی بارگذاری کنید.');
        }
        $pdo->prepare('UPDATE workshops SET created_by_user_id=?, updated_by_user_id=? WHERE id=?')
            ->execute([$user['id'], $user['id'], $id]);
        $pdo->commit();
        staff_log_action($pdo, (string) $user['id'], 'workshop_create', 'workshop', $id);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        flash_set('error', $e->getMessage());
        redirect($base);
    }

    $creatorName = (string) ($user['name'] ?? 'منشی');
    workshop_notify_doctors(
        $pdo,
        $creatorName,
        $data['title'],
        $data['type'],
        $data['starts_at'],
        null,
        (string) $doctor['name']
    );
    notify_doctor_profile(
        $pdo,
        (string) $doctor['id'],
        'کارگاه جدید برای شما',
        staff_actor_label($user) . " کارگاه «{$data['title']}» را به نام شما ثبت کرد.",
        '/doctor/workshops',
        'workshop'
    );
    notify_role(
        $pdo,
        'SECRETARY',
        'کارگاه جدید',
        staff_actor_label($user) . " کارگاه «{$data['title']}» را ثبت کرد.",
        '/secretary/workshops',
        'workshop'
    );

    flash_set('success', 'کارگاه ایجاد شد و به درمانگران اطلاع داده شد.');
    redirect($base);
}

if ($action === 'update') {
    $id = post('id');
    if ($id === '') {
        flash_set('error', 'کارگاه یافت نشد.');
        redirect($base);
    }
    $existing = $pdo->prepare('SELECT id, status FROM workshops WHERE id=? LIMIT 1');
    $existing->execute([$id]);
    $row = $existing->fetch();
    if (!$row) {
        flash_set('error', 'کارگاه یافت نشد.');
        redirect($base);
    }
    if (in_array($row['status'], ['COMPLETED', 'CANCELLED'], true)) {
        flash_set('error', 'کارگاه برگزار شده یا لغو شده قابل ویرایش نیست.');
        redirect($base . '?edit=' . urlencode($id));
    }

    try {
        $data = workshop_save_fields_from_post();
        $doctor = secretary_resolve_doctor_id($pdo, post('doctor_id'));
    } catch (RuntimeException $e) {
        flash_set('error', $e->getMessage());
        redirect($base . '?edit=' . urlencode($id));
    }

    try {
        $pdo->beginTransaction();
        $pdo->prepare('
          UPDATE workshops SET
            doctor_id=?, title=?, type=?, starts_at=?, ends_at=?, items_to_bring=?, notes=?, description=?,
            price=?, capacity=?, location=?, location_lat=?, location_lng=?, meeting_url=?, content_url=?, group_url=?
          WHERE id=?
        ')->execute([
            $doctor['id'],
            $data['title'],
            $data['type'],
            $data['starts_at'],
            $data['ends_at'],
            $data['items_to_bring'],
            $data['notes'],
            $data['description'],
            $data['price'],
            $data['capacity'],
            $data['location'],
            $data['location_lat'],
            $data['location_lng'],
            $data['meeting_url'],
            $data['content_url'],
            $data['group_url'],
            $id,
        ]);
        workshop_media_process_form_uploads($pdo, $id, null);
        if (workshop_is_offline($data['type']) && workshop_media_count($pdo, $id) < 1) {
            throw new RuntimeException('دوره آفلاین باید حداقل یک ویدیو یا فایل صوتی داشته باشد.');
        }
        $pdo->prepare('UPDATE workshops SET updated_by_user_id=? WHERE id=?')
            ->execute([$user['id'], $id]);
        $pdo->commit();
        staff_log_action($pdo, (string) $user['id'], 'workshop_update', 'workshop', $id);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        flash_set('error', $e->getMessage());
        redirect($base . '?edit=' . urlencode($id));
    }

    flash_set('success', 'تغییرات کارگاه ذخیره شد.');
    redirect($base);
}

if ($action === 'mark_paid') {
    $enrollmentId = post('enrollment_id');
    try {
        workshop_mark_paid_by_staff(
            $pdo,
            $enrollmentId,
            (string) $user['id'],
            staff_actor_label($user),
            $_FILES['receipt'] ?? []
        );
        staff_log_action($pdo, (string) $user['id'], 'workshop_mark_paid', 'enrollment', $enrollmentId);
        flash_set('success', 'پرداخت با فیش ثبت شد و برای منشی‌های دیگر هم دیده می‌شود.');
    } catch (RuntimeException $e) {
        flash_set('error', $e->getMessage());
    }
    redirect($base);
}

if ($action === 'enroll') {
    $workshopId = post('workshop_id');
    $patientId = post('patient_id');
    try {
        workshop_enroll_by_staff(
            $pdo,
            $workshopId,
            $patientId,
            (string) $user['id'],
            staff_actor_label($user)
        );
        staff_log_action($pdo, (string) $user['id'], 'workshop_enroll', 'workshop', $workshopId);
        flash_set('success', 'ورودی کارگاه ثبت شد و برای همه منشی‌ها نمایش داده می‌شود.');
    } catch (RuntimeException $e) {
        flash_set('error', $e->getMessage());
    }
    redirect($base . '?tab=enroll');
}

$id = post('id');
if ($id === '') {
    redirect($base);
}

$exists = $pdo->prepare('SELECT id FROM workshops WHERE id=?');
$exists->execute([$id]);
if (!$exists->fetch()) {
    flash_set('error', 'کارگاه یافت نشد.');
    redirect($base);
}

if ($action === 'toggle') {
    $row = $pdo->prepare('SELECT is_published, title, type, starts_at, doctor_id FROM workshops WHERE id=?');
    $row->execute([$id]);
    $workshopRow = $row->fetch();
    $pub = !(bool) $workshopRow['is_published'];
    $pdo->prepare('UPDATE workshops SET is_published=?, status=?, updated_by_user_id=? WHERE id=?')
        ->execute([$pub ? 1 : 0, $pub ? 'PUBLISHED' : 'DRAFT', $user['id'], $id]);
    staff_log_action($pdo, (string) $user['id'], $pub ? 'workshop_publish' : 'workshop_unpublish', 'workshop', $id);
    if ($pub) {
        $docName = $pdo->prepare('SELECT u.name FROM doctor_profiles dp JOIN users u ON u.id=dp.user_id WHERE dp.id=?');
        $docName->execute([$workshopRow['doctor_id']]);
        workshop_notify_doctors(
            $pdo,
            (string) ($user['name'] ?? 'منشی'),
            (string) $workshopRow['title'],
            (string) $workshopRow['type'],
            (string) $workshopRow['starts_at'],
            null,
            (string) ($docName->fetchColumn() ?: '')
        );
    }
    flash_set('success', $pub ? 'کارگاه منتشر شد.' : 'انتشار لغو شد.');
} elseif ($action === 'toggle_enrollment') {
    $row = $pdo->prepare('SELECT enrollment_open FROM workshops WHERE id=?');
    $row->execute([$id]);
    $open = !(bool) $row->fetchColumn();
    $pdo->prepare('UPDATE workshops SET enrollment_open=?, updated_by_user_id=? WHERE id=?')->execute([$open ? 1 : 0, $user['id'], $id]);
    staff_log_action($pdo, (string) $user['id'], $open ? 'workshop_enroll_open' : 'workshop_enroll_close', 'workshop', $id);
    flash_set('success', $open ? 'ثبت‌نام باز شد.' : 'ثبت‌نام بسته شد.');
} elseif ($action === 'delete') {
    $pdo->prepare('DELETE FROM workshops WHERE id=?')->execute([$id]);
    staff_log_action($pdo, (string) $user['id'], 'workshop_delete', 'workshop', $id);
    flash_set('success', 'کارگاه حذف شد.');
}

redirect($base);
