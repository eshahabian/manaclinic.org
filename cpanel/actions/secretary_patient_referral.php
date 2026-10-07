<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/user_referral.php';

require_login(['SECRETARY']);
csrf_verify();

$id = trim((string) ($_GET['id'] ?? ''));
if ($id === '' || !user_referral_save($pdo, $id, post('referral_source'))) {
    flash_set('error', 'معرف را انتخاب کنید.');
    redirect($id !== '' ? '/secretary/patients/' . $id : '/secretary/patients');
}

flash_set('success', 'معرف ذخیره شد.');
redirect('/secretary/patients/' . $id);
