<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/doctor_panel.php';
require_once __DIR__ . '/../includes/workshops.php';

$ctx = require_doctor_profile($pdo);
ensure_workshop_schema($pdo);

$workshopId = trim((string) ($_GET['id'] ?? ''));
if ($workshopId === '') {
    flash_set('error', 'کارگاه یافت نشد.');
    redirect('/doctor/workshops');
}

$own = $pdo->prepare('SELECT id, title FROM workshops WHERE id=? AND doctor_id=? LIMIT 1');
$own->execute([$workshopId, $ctx['profile']['id']]);
$workshop = $own->fetch();
if (!$workshop) {
    flash_set('error', 'کارگاه یافت نشد.');
    redirect('/doctor/workshops');
}

$rows = workshop_enrollments_export_rows($pdo, $workshopId);
$filename = 'workshop-enrollments-' . preg_replace('/[^a-zA-Z0-9_-]+/', '-', (string) $workshop['id']) . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store');

$out = fopen('php://output', 'w');
// UTF-8 BOM for Excel
fwrite($out, "\xEF\xBB\xBF");
fputcsv($out, [
    'نام',
    'نام کاربری',
    'موبایل',
    'ایمیل',
    'وضعیت ثبت‌نام',
    'تاریخ ثبت‌نام',
    'مبلغ',
    'کیف پول',
    'وضعیت پرداخت',
    'کد پیگیری',
]);

foreach ($rows as $row) {
    fputcsv($out, [
        (string) ($row['patient_name'] ?? ''),
        (string) ($row['username'] ?? ''),
        (string) ($row['phone'] ?? ''),
        (string) ($row['email'] ?? ''),
        enrollment_status_label((string) ($row['status'] ?? '')),
        (string) ($row['enrolled_at'] ?? ''),
        (string) ($row['amount'] ?? ''),
        (string) ($row['wallet_amount'] ?? ''),
        (string) ($row['pay_status'] ?? ''),
        (string) ($row['ref_id'] ?? ''),
    ]);
}
fclose($out);
exit;
