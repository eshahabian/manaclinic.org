<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/user_cleanup.php';
$actor = require_login(['SECRETARY', 'ADMIN']);

$action = post('action');

if ($action === 'delete_patient') {
    $id = post('patient_id');
    $row = $pdo->prepare("SELECT id, name, role FROM users WHERE id = ? AND role = 'PATIENT'");
    $row->execute([$id]);
    $user = $row->fetch();
    if (!$user) {
        flash_set('error', 'بیمار یافت نشد.');
        redirect('/secretary/appointments?tab=new');
    }
    try {
        $pdo->beginTransaction();
        delete_user_cascade($pdo, $id);
        $pdo->commit();
        if (($actor['role'] ?? '') === 'SECRETARY') {
            staff_log_action($pdo, (string) $actor['id'], 'delete_patient', 'user', $id, (string) $user['name']);
        }
        flash_set('success', 'بیمار «' . $user['name'] . '» از لیست حذف شد.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        flash_set('error', 'حذف ناموفق: ' . $e->getMessage());
    }
    redirect('/secretary/appointments?tab=new');
}

if ($action === 'purge_test_patients') {
    $deleted = 0;
    $names = [];
    try {
        $pdo->beginTransaction();

        // حذف مستقیم با SQL تا مطمئن شویم
        $stmt = $pdo->query("
          SELECT id, name, username FROM users
          WHERE role = 'PATIENT'
            AND (
              name LIKE '%برهان%'
              OR name LIKE '%شاوردی%'
              OR name LIKE '%رضایی%'
              OR name = 'عماد'
              OR name LIKE 'عماد %'
              OR name LIKE '% عماد'
              OR username IN ('emad','ali','borhan','shaverdi','bshaverdi','alirezaei')
              OR username LIKE '%borhan%'
              OR username LIKE '%shaverdi%'
              OR username LIKE '%rezaei%'
            )
            AND name NOT LIKE '%شهابیان%'
            AND IFNULL(username,'') NOT LIKE '%shahabian%'
        ");
        foreach ($stmt->fetchAll() as $u) {
            delete_user_cascade($pdo, (string) $u['id']);
            $names[] = (string) $u['name'];
            $deleted++;
        }

        // دوباره با منطق PHP
        foreach (find_cleanup_test_users($pdo) as $u) {
            if (($u['role'] ?? '') === 'ADMIN') {
                continue;
            }
            delete_user_cascade($pdo, (string) $u['id']);
            $names[] = (string) $u['name'];
            $deleted++;
        }

        delete_all_appointments($pdo);
        $pdo->commit();

        $label = $names ? implode('، ', array_unique($names)) : 'موردی';
        flash_set('success', "حذف شد ({$deleted}): {$label}. همه نوبت‌ها هم پاک شد.");
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        flash_set('error', 'حذف ناموفق: ' . $e->getMessage());
    }
    redirect('/secretary/appointments?tab=new');
}

flash_set('error', 'درخواست نامعتبر.');
redirect('/secretary/appointments?tab=new');
