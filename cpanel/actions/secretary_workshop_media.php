<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/workshop_media.php';

require_login(['SECRETARY']);
ensure_workshop_media_schema($pdo);
$action = post('action');
$workshopId = post('workshop_id');

if ($workshopId === '') {
    flash_set('error', 'کارگاه یافت نشد.');
    redirect('/secretary/workshops');
}

$back = '/secretary/workshops?edit=' . urlencode($workshopId) . '#workshop-form';

if ($action === 'upload') {
    try {
        $kind = post('kind');
        $title = trim(post('title'));
        $description = trim(post('description')) ?: null;
        $file = $_FILES['media_file'] ?? [];
        workshop_media_save_upload($pdo, $workshopId, null, $kind, $title, $description, $file);
        flash_set('success', 'فایل با موفقیت اضافه شد.');
    } catch (RuntimeException $e) {
        flash_set('error', $e->getMessage());
    }
    redirect($back);
}

if ($action === 'delete') {
    $itemId = post('item_id');
    try {
        workshop_media_delete($pdo, $itemId, null);
        flash_set('success', 'فایل حذف شد.');
    } catch (RuntimeException $e) {
        flash_set('error', $e->getMessage());
    }
    redirect($back);
}

flash_set('error', 'درخواست نامعتبر است.');
redirect($back);
