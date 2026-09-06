<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/secretary_patient.php';

$user = require_login(['SECRETARY']);
csrf_verify();

$result = secretary_create_patient_from_post($pdo, $user);
if (empty($result['ok'])) {
    flash_set('error', (string) ($result['error'] ?? 'ثبت مراجعه‌کننده ممکن نشد.'));
    redirect('/secretary/patients?add=1');
}

flash_set('success', 'مراجعه‌کننده «' . (string) $result['name'] . '» ثبت شد. نام کاربری: ' . (string) $result['username']);
redirect('/secretary/patients?added=1');
