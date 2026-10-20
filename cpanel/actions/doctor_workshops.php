<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/doctor_panel.php';
require_once __DIR__ . '/../includes/workshops.php';
require_once __DIR__ . '/../includes/workshop_media.php';

$ctx = require_doctor_profile($pdo);
ensure_workshop_schema($pdo);
ensure_workshop_media_schema($pdo);
require_once __DIR__.'/../includes/workshop_presenters.php';
workshop_presenters_schema($pdo);
ensure_outreach_schema($pdo);
$action = post('action');
$workshopBase = '/doctor/workshops';
if ($action === 'save_presenters') require __DIR__.'/workshop_presenters_save.php';
$actorUser = $ctx['user'] ?? current_user();
$staffUserId = (string) ($actorUser['id'] ?? doctor_ctx_user_id($ctx));
$staffLabel = function_exists('staff_actor_label') ? staff_actor_label($actorUser) : doctor_ctx_user_name($ctx);

if (in_array($action, ['approve_enrollment', 'mark_paid', 'enroll'], true)) {
    flash_set('error', 'تأیید عضویت کارگاه فقط با منشی است. شما فهرست افراد را می‌بینید.');
    redirect($workshopBase);
}

if ($action === 'create') {
    try {
        $data = workshop_save_fields_from_post();
    } catch (RuntimeException $e) {
        flash_set('error', $e->getMessage());
        redirect('/doctor/workshops');
    }

    $id = cuid();
    $queueOutreach = false;
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
        if(workshop_can_add_member(current_user())&&isset($_POST['presenters_present']))workshop_presenters_save($pdo,$id,(array)($_POST['presenters']??[]));

        db_commit($pdo);
        if(isset($_POST['presenters_present'])){try{workshop_presenters_dispatch($pdo,$id);}catch(Throwable $dispatchError){error_log('Presenter SMS dispatch pending: '.$dispatchError->getMessage());}}
        $queueOutreach = !empty($_POST['notify_outreach']);
    } catch (Throwable $e) {
        if (!workshop_save_caught($pdo, $e)) {
            redirect('/doctor/workshops');
        }
    }

    if ($queueOutreach && function_exists('outreach_queue_workshop')) {
        try {
            outreach_queue_workshop($pdo, (string) $data['title'], (string) $data['starts_at']);
        } catch (Throwable $ignored) {
        }
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
        if(workshop_can_add_member(current_user())&&isset($_POST['presenters_present']))workshop_presenters_save($pdo,$id,(array)($_POST['presenters']??[]));

        db_commit($pdo);
        if(isset($_POST['presenters_present'])){try{workshop_presenters_dispatch($pdo,$id);}catch(Throwable $dispatchError){error_log('Presenter SMS dispatch pending: '.$dispatchError->getMessage());}}
    } catch (Throwable $e) {
        if (!workshop_save_caught($pdo, $e)) {
            redirect('/doctor/workshops?edit=' . urlencode($id));
        }
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
    $row = $pdo->prepare('SELECT enrollment_open, closed_at, status FROM workshops WHERE id=?');
    $row->execute([$id]);
    $workshopRow = $row->fetch() ?: [];
    if (workshop_is_closed($workshopRow) || (string) ($workshopRow['status'] ?? '') === 'COMPLETED') {
        flash_set('error', 'این مورد تمام شده و ثبت‌نامش باز نمی‌شود.');
        redirect($workshopBase);
    }
    $open = !((int) ($workshopRow['enrollment_open'] ?? 0));
    $pdo->prepare('UPDATE workshops SET enrollment_open=? WHERE id=?')
        ->execute([$open ? 1 : 0, $id]);
    flash_set('success', $open ? 'ثبت‌نام باز شد.' : 'ثبت‌نام بسته شد.');
} elseif ($action === 'close') {
    try {
        $closed = workshop_close_early($pdo, $id, $staffUserId, (string) $ctx['profile']['id']);
        if (function_exists('staff_log_action')) {
            staff_log_action($pdo, $staffUserId, 'workshop_close', 'workshop', $id);
        }
        $doneLabel = workshop_is_offline((string) ($closed['type'] ?? '')) ? 'دوره' : 'کارگاه';
        flash_set('success', $doneLabel . ' تمام شد. ثبت‌نام جدید بسته است و در آرشیو می‌ماند.');
        redirect($workshopBase . '?tab=archive');
    } catch (RuntimeException $e) {
        flash_set('error', $e->getMessage());
        redirect($workshopBase);
    }
} elseif ($action === 'delete') {
    $pdo->prepare('DELETE FROM workshops WHERE id=?')->execute([$id]);
    flash_set('success', 'کارگاه حذف شد.');
} elseif ($action === 'complete') {
    try {
        $pdo->beginTransaction();
        $count = complete_workshop($pdo, $id, $ctx['profile']['id']);
        db_commit($pdo);
        flash_set('success', "کارگاه پایان یافت. {$count} ثبت‌نام تسویه شد.");
        redirect('/doctor/workshops?tab=archive');
    } catch (Throwable $e) {
        if (!workshop_save_caught($pdo, $e)) {
            redirect('/doctor/workshops');
        }
        flash_set('success', 'کارگاه پایان یافت.');
        redirect('/doctor/workshops?tab=archive');
    }
}

redirect('/doctor/workshops');
