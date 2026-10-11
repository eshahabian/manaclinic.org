<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/petty_cash.php';
require_once __DIR__ . '/../includes/doctor_profile_fields.php';
require_once __DIR__ . '/../includes/staff_desk.php';

$user = require_login();
if (!petty_cash_user_allowed($user)) {
    flash_set('error', 'دسترسی به تنخواه مجاز نیست.');
    redirect('/');
}

$role = (string) ($user['role'] ?? '');
$cashPath = match ($role) {
    'SECRETARY' => '/secretary/petty-cash',
    'ACCOUNTANT' => '/accountant/petty-cash',
    default => '/doctor/petty-cash',
};
$jy = (int) post('jy');
$jm = (int) post('jm');
$back = $cashPath . '?jy=' . $jy . '&jm=' . $jm;
csrf_verify();

try {
    flash_set('success', petty_cash_apply_post($pdo, $user));
} catch (RuntimeException $e) {
    flash_set('error', $e->getMessage());
}
redirect($back);
