<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/therapist_payouts.php';
require_once __DIR__ . '/../includes/secretary_patient.php';

$user = require_login(['SECRETARY', 'ACCOUNTANT']);
csrf_verify();

$base = ((string) ($user['role'] ?? '')) === 'ACCOUNTANT' ? '/accountant/payouts' : '/secretary/payouts';
$doctorId = trim((string) post('doctor_id'));
$from = parse_user_date((string) post('from_date'));
$to = parse_user_date((string) post('to_date'));
$back = $base . '?doctor=' . rawurlencode($doctorId)
    . '&from=' . rawurlencode((string) post('from_date'))
    . '&to=' . rawurlencode((string) post('to_date'));

$allowed = false;
foreach (secretary_active_doctors($pdo) as $doctor) {
    if ((string) ($doctor['id'] ?? '') === $doctorId) {
        $allowed = true;
        break;
    }
}
if (!$allowed || $from === null || $to === null) {
    flash_set('error', 'درمانگر و بازه تاریخ را کامل انتخاب کنید.');
    redirect($base);
}

try {
    $id = therapist_payout_settle($pdo, $doctorId, $from, $to, (string) ($user['id'] ?? ''));
} catch (RuntimeException $e) {
    flash_set('error', $e->getMessage());
    redirect($back);
} catch (Throwable $e) {
    flash_set('error', 'تسویه ذخیره نشد.');
    redirect($back);
}

flash_set('success', 'این بازه تسویه شد و به آرشیو رفت.');
redirect($base . '?archive=' . rawurlencode($id));
