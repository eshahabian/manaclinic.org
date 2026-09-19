<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/doctor_panel.php';
require_once __DIR__ . '/../includes/workshops.php';
require_once __DIR__ . '/../includes/workshop_media.php';

$ctx = require_doctor_profile($pdo);
ensure_workshop_schema($pdo);
ensure_workshop_media_schema($pdo);
$action = post('action');
$base = '/doctor/workshops';
$actorUser = $ctx['user'] ?? current_user();
$staffUserId = (string) ($actorUser['id'] ?? doctor_ctx_user_id($ctx));
$staffLabel = function_exists('staff_actor_label') ? staff_actor_label($actorUser) : doctor_ctx_user_name($ctx);

if ($action === 'approve_enrollment') {
    $enrollmentId = post('enrollment_id');
    try {
        if (!workshop_enrollment_belongs_to_doctor($pdo, $enrollmentId, (string) $ctx['profile']['id'])) {
            throw new RuntimeException('این ثبت‌نام مربوط به کارگاه شما نیست.');
        }
        workshop_approve_enrollment_by_staff($pdo, $enrollmentId, $staffUserId, $staffLabel);
        if (function_exists('staff_log_action')) {
            staff_log_action($pdo, $staffUserId, 'workshop_approve_enrollment', 'enrollment', $enrollmentId);
        }
        flash_set('success', 'عضویت تأیید شد و کارگاه به «دوره‌های من» مراجعه‌کننده منتقل شد.');
    } catch (RuntimeException $e) {
        flash_set('error', $e->getMessage());
    }
    redirect($base);
}

if ($action === 'mark_paid') {
    $enrollmentId = post('enrollment_id');
    try {
        if (!workshop_enrollment_belongs_to_doctor($pdo, $enrollmentId, (string) $ctx['profile']['id'])) {
            throw new RuntimeException('این ثبت‌نام مربوط به کارگاه شما نیست.');
        }
        workshop_mark_paid_by_staff(
            $pdo,
            $enrollmentId,
            $staffUserId,
            $staffLabel,
            $_FILES['receipt'] ?? []
        );
        if (function_exists('staff_log_action')) {
            staff_log_action($pdo, $staffUserId, 'workshop_mark_paid', 'enrollment', $enrollmentId);
        }
        flash_set('success', 'پرداخت با فیش ثبت شد. برای ورود به «دوره‌های من»، عضویت را تأیید کنید.');
    } catch (RuntimeException $e) {
        flash_set('error', $e->getMessage());
    }
    redirect($base);
}

if ($action === 'create') {
    try {
        $data = workshop_save_fields_from_post();
    } catch (RuntimeException $e) {
        flash_set('error', $e->getMessage());
        redirect('/doctor/workshops');
    }

    $id = cuid();
    try {
        $pdo->beginTransaction();
        $pdo->prepare('
          INSERT INTO workshops
            (id, doctor_id, title, type, session_interval, starts_at, ends_at, items_to_bring, notes, description, price, capacity, location, location_lat, location_lng, meeting_url, content_url, group_url, is_published, enrollment_open, status)
          VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ')->execute([
            $id,
            $ctx['profile']['id'],
            $data['title'],
            $data['type'],
            $data['session_interval'],
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

        workshop_save_sessions_and_media($pdo, $id, $data, $ctx['profile']['id']);
        workshop_store_banner_from_request($pdo, $id);

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
        doctor_ctx_user_name($ctx),
        $data['title'],
        $data['type'],
        $data['starts_at']
    );
    workshop_notify_patients(
        $pdo,
        $id,
        $data['title'],
        $data['type'],
        $data['starts_at'],
        $staffUserId !== '' ? $staffUserId : null
    );
    flash_set('success', 'کارگاه ایجاد شد. فایل هر جلسه را از ویرایش کارگاه بارگذاری کنید.');
    redirect('/doctor/workshops?edit=' . urlencode($id));
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
            title=?, type=?, session_interval=?, starts_at=?, ends_at=?, items_to_bring=?, notes=?, description=?,
            price=?, capacity=?, location=?, location_lat=?, location_lng=?, meeting_url=?, content_url=?, group_url=?
          WHERE id=? AND doctor_id=?
        ')->execute([
            $data['title'],
            $data['type'],
            $data['session_interval'],
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

        workshop_save_sessions_and_media($pdo, $id, $data, $ctx['profile']['id']);
        workshop_store_banner_from_request($pdo, $id);

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
            doctor_ctx_user_name($ctx),
            (string) $workshopRow['title'],
            (string) $workshopRow['type'],
            (string) $workshopRow['starts_at']
        );
        workshop_notify_patients(
            $pdo,
            $id,
            (string) $workshopRow['title'],
            (string) $workshopRow['type'],
            (string) $workshopRow['starts_at'],
            $staffUserId !== '' ? $staffUserId : null
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
        redirect('/doctor/workshops?tab=archive');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        flash_set('error', $e->getMessage());
    }
}

redirect('/doctor/workshops');
