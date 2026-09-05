<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/admin_panel.php';
require_login(['ADMIN']);

$action = post('action');

if ($action === 'delete') {
    $id = post('notification_id');
    if ($id === '') {
        flash_set('error', 'پیام مشخص نیست.');
        redirect('/admin/messages');
    }
    delete_notification($pdo, $id);
    flash_set('success', 'پیام حذف شد.');
    redirect('/admin/messages');
}

if ($action === 'delete_all') {
    $count = delete_all_notifications($pdo);
    flash_set('success', $count . ' پیام حذف شد.');
    redirect('/admin/messages');
}

flash_set('error', 'درخواست نامعتبر است.');
redirect('/admin/messages');
