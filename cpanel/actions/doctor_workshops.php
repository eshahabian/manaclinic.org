<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/doctor_panel.php';
require_once __DIR__ . '/../includes/workshops.php';
require_once __DIR__ . '/../includes/workshop_media.php';

$ctx = require_doctor_profile($pdo);
ensure_workshop_schema($pdo);
ensure_workshop_media_schema($pdo);
$action = post('action');

if ($action === 'create') {
    try {
        $data = workshop_save_fields_from_post();
    } catch (RuntimeException $e) {
        flash_set('error', $e->getMessage());
        redirect('/doctor/workshops');
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
            redirect('/doctor/workshops');
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
            $ctx['profile']['id'],
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

        workshop_media_process_form_uploads($pdo, $id, $ctx['profile']['id']);
        if (workshop_is_offline($data['type']) && workshop_media_count($pdo, $id) < 1) {
            throw new RuntimeException('برای دوره آفلاین حداقل یک ویدیو یا فایل صوتی بارگذاری کنید.');
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        flash_set('error', $e->getMessage());
        redirect('/doctor/workshops');
    }

    workshop_notify_other_doctors(
        $pdo,
        $ctx['profile']['id'],
        $ctx['user']['name'],
        $data['title'],
        $data['type'],
        $data['starts_at']
    );
    flash_set('success', workshop_is_offline($data['type']) ? 'دوره آفلاین با محتوا ایجاد شد.' : 'کارگاه ایجاد شد.');
    redirect('/doctor/workshops');
}

if ($action === 'update') {
    $id = post('id');
    if ($id === '') {
        flash_set('error', 'کارگاه یافت نشد.');
        redirect('/doctor/workshops');
    }

    $own = $pdo->prepare('SELECT id, status, type FROM workshops WHERE id=? AND doctor_id=?');
    $own->execute([$id, $ctx['profile']['id']]);
    $existing = $own->fetch();
    if (!$existing) {
        flash_set('error', 'کارگاه یافت نشد.');
        redirect('/doctor/workshops');
    }
    if (in_array($existing['status'], ['COMPLETED', 'CANCELLED'], true)) {
        flash_set('error', 'کارگاه برگزار شده یا لغو شده قابل ویرایش نیست.');
        redirect('/doctor/workshops');
    }

    try {
        $data = workshop_save_fields_from_post();
    } catch (RuntimeException $e) {
        flash_set('error', $e->getMessage());
        redirect('/doctor/workshops?edit=' . urlencode($id));
    }

    try {
        $pdo->beginTransaction();
        $pdo->prepare('
          UPDATE workshops SET
            title=?, type=?, starts_at=?, ends_at=?, items_to_bring=?, notes=?, description=?,
            price=?, capacity=?, location=?, location_lat=?, location_lng=?, meeting_url=?, content_url=?, group_url=?
          WHERE id=? AND doctor_id=?
        ')->execute([
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
            $ctx['profile']['id'],
        ]);

        if (workshop_is_offline($data['type'])) {
            workshop_media_process_form_uploads($pdo, $id, $ctx['profile']['id']);
            if (workshop_media_count($pdo, $id) < 1) {
                throw new RuntimeException('دوره آفلاین باید حداقل یک ویدیو یا فایل صوتی داشته باشد.');
            }
        } else {
            workshop_media_process_form_uploads($pdo, $id, $ctx['profile']['id']);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        flash_set('error', $e->getMessage());
        redirect('/doctor/workshops?edit=' . urlencode($id));
    }

    flash_set('success', 'تغییرات کارگاه ذخیره شد.');
    redirect('/doctor/workshops');
}

$id = post('id');
if ($id === '') {
    redirect('/doctor/workshops');
}

$own = $pdo->prepare('SELECT id FROM workshops WHERE id=? AND doctor_id=?');
$own->execute([$id, $ctx['profile']['id']]);
if (!$own->fetch()) {
    flash_set('error', 'کارگاه یافت نشد.');
    redirect('/doctor/workshops');
}

if ($action === 'toggle') {
    $row = $pdo->prepare('SELECT is_published, title, type, starts_at FROM workshops WHERE id=?');
    $row->execute([$id]);
    $workshopRow = $row->fetch();
    if (!$workshopRow) {
        flash_set('error', 'کارگاه یافت نشد.');
        redirect('/doctor/workshops');
    }
    $pub = !(bool) $workshopRow['is_published'];
    $pdo->prepare('UPDATE workshops SET is_published=?, status=? WHERE id=?')
        ->execute([$pub ? 1 : 0, $pub ? 'PUBLISHED' : 'DRAFT', $id]);
    if ($pub) {
        workshop_notify_other_doctors(
            $pdo,
            $ctx['profile']['id'],
            $ctx['user']['name'],
            (string) $workshopRow['title'],
            (string) $workshopRow['type'],
            (string) $workshopRow['starts_at']
        );
    }
    flash_set('success', $pub ? 'کارگاه منتشر شد.' : 'انتشار لغو شد.');
} elseif ($action === 'toggle_enrollment') {
    $row = $pdo->prepare('SELECT enrollment_open FROM workshops WHERE id=?');
    $row->execute([$id]);
    $open = !(bool) $row->fetchColumn();
    $pdo->prepare('UPDATE workshops SET enrollment_open=? WHERE id=?')
        ->execute([$open ? 1 : 0, $id]);
    flash_set('success', $open ? 'ثبت‌نام باز شد.' : 'ثبت‌نام بسته شد.');
} elseif ($action === 'delete') {
    $pdo->prepare('DELETE FROM workshops WHERE id=?')->execute([$id]);
    flash_set('success', 'کارگاه حذف شد.');
} elseif ($action === 'complete') {
    try {
        $pdo->beginTransaction();
        $count = complete_workshop($pdo, $id, $ctx['profile']['id']);
        $pdo->commit();
        flash_set('success', "کارگاه پایان یافت. {$count} ثبت‌نام تسویه شد.");
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        flash_set('error', $e->getMessage());
    }
}

redirect('/doctor/workshops');
