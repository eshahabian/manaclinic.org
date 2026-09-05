<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/admin_panel.php';
require_once __DIR__ . '/../includes/user_cleanup.php';
require_login(['ADMIN']);
csrf_verify();

$action = post('action');
$next = safe_next_path((string) ($_POST['next'] ?? '')) ?: '/admin/appointments';

if ($action === 'delete') {
    $id = post('appointment_id');
    try {
        delete_appointment_by_id($pdo, $id);
        flash_set('success', 'نوبت حذف شد.');
    } catch (Throwable $e) {
        flash_set('error', $e->getMessage());
    }
    redirect($next);
}

if ($action === 'delete_all') {
    try {
        $count = delete_all_appointments($pdo);
        flash_set('success', $count . ' نوبت حذف شد.');
    } catch (Throwable $e) {
        flash_set('error', 'حذف ناموفق: ' . $e->getMessage());
    }
    redirect('/admin/appointments');
}

flash_set('error', 'درخواست نامعتبر است.');
redirect('/admin/appointments');
