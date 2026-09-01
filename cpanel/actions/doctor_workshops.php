<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/doctor_panel.php';
require_once __DIR__ . '/../includes/workshops.php';

$ctx = require_doctor_profile($pdo);
ensure_workshop_schema($pdo);
$action = post('action');

if ($action === 'create') {
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
        flash_set('error', 'اطلاعات کارگاه ناقص است.');
        redirect('/doctor/workshops');
    }

    $startsAt = $startDate . ' ' . $startTime . ':00';
    $endsAt = $endDate . ' ' . $endTime . ':00';
    if (strtotime($endsAt) <= strtotime($startsAt)) {
        flash_set('error', 'زمان پایان باید بعد از شروع باشد.');
        redirect('/doctor/workshops');
    }

    $id = cuid();
    $pdo->prepare('
      INSERT INTO workshops
        (id, doctor_id, title, type, starts_at, ends_at, items_to_bring, notes, description, price, capacity, location, meeting_url, content_url, is_published, status)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
    ')->execute([
        $id,
        $ctx['profile']['id'],
        $title,
        $type,
        $startsAt,
        $endsAt,
        trim(post('items_to_bring')) ?: null,
        trim(post('notes')) ?: null,
        trim(post('description')) ?: null,
        $price,
        $capacity,
        trim(post('location')) ?: null,
        trim(post('meeting_url')) ?: null,
        trim(post('content_url')) ?: null,
        $published ? 1 : 0,
        $published ? 'PUBLISHED' : 'DRAFT',
    ]);
    flash_set('success', 'کارگاه ایجاد شد.');
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
    $row = $pdo->prepare('SELECT is_published FROM workshops WHERE id=?');
    $row->execute([$id]);
    $pub = !(bool) $row->fetchColumn();
    $pdo->prepare('UPDATE workshops SET is_published=?, status=? WHERE id=?')
        ->execute([$pub ? 1 : 0, $pub ? 'PUBLISHED' : 'DRAFT', $id]);
    flash_set('success', $pub ? 'کارگاه منتشر شد.' : 'انتشار لغو شد.');
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
