<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/doctor_panel.php';
require_once __DIR__ . '/../includes/workshops.php';

$ctx = require_doctor_profile($pdo);
ensure_workshop_schema($pdo);
$action = post('action');

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

    $startsAt = workshop_datetime_from_post($startDate, $startTime);
    $endsAt = workshop_datetime_from_post($endDate, $endTime);
    if (strtotime($endsAt) <= strtotime($startsAt)) {
        throw new RuntimeException('زمان پایان باید بعد از شروع باشد.');
    }

    if ($type === 'ONLINE' && trim(post('meeting_url')) === '') {
        throw new RuntimeException('برای کارگاه آنلاین، لینک جلسه الزامی است.');
    }
    if ($type === 'OFFLINE' && trim(post('content_url')) === '') {
        throw new RuntimeException('برای کارگاه آفلاین، لینک محتوا الزامی است.');
    }
    if ($type === 'IN_PERSON' && trim(post('location')) === '') {
        throw new RuntimeException('برای کارگاه حضوری، آدرس محل برگزاری را بنویسید.');
    }

    [$location, $meetingUrl, $contentUrl] = workshop_type_urls_from_post($type);

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
        'meeting_url' => $meetingUrl,
        'content_url' => $contentUrl,
    ];
}

if ($action === 'create') {
    try {
        $data = workshop_save_fields_from_post();
    } catch (RuntimeException $e) {
        flash_set('error', $e->getMessage());
        redirect('/doctor/workshops');
    }

    $id = cuid();
    $pdo->prepare('
      INSERT INTO workshops
        (id, doctor_id, title, type, starts_at, ends_at, items_to_bring, notes, description, price, capacity, location, meeting_url, content_url, is_published, enrollment_open, status)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
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
        $data['meeting_url'],
        $data['content_url'],
        1,
        1,
        'PUBLISHED',
    ]);
    workshop_notify_other_doctors(
        $pdo,
        $ctx['profile']['id'],
        $ctx['user']['name'],
        $data['title'],
        $data['type'],
        $data['starts_at']
    );
    flash_set('success', 'کارگاه ایجاد شد.');
    redirect('/doctor/workshops');
}

if ($action === 'update') {
    $id = post('id');
    if ($id === '') {
        flash_set('error', 'کارگاه یافت نشد.');
        redirect('/doctor/workshops');
    }

    $own = $pdo->prepare('SELECT id, status FROM workshops WHERE id=? AND doctor_id=?');
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

    // ویرایش محتوا — وضعیت انتشار فقط با دکمه «انتشار / لغو انتشار» تغییر می‌کند
    $pdo->prepare('
      UPDATE workshops SET
        title=?, type=?, starts_at=?, ends_at=?, items_to_bring=?, notes=?, description=?,
        price=?, capacity=?, location=?, meeting_url=?, content_url=?
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
        $data['meeting_url'],
        $data['content_url'],
        $id,
        $ctx['profile']['id'],
    ]);
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
