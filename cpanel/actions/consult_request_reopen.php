<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/consult_requests.php';

$user = require_login(['SECRETARY', 'DOCTOR', 'ADMIN']);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    redirect(consult_panel_path($user));
}

csrf_verify();
ensure_consult_requests_schema($pdo);

$id = post('id');
if ($id !== '' && preg_match('/^[a-f0-9]{24}$/', $id)) {
    $pdo->prepare("
      UPDATE consult_requests
      SET status='new', seen_at=NULL
      WHERE id=? AND LOWER(TRIM(status)) <> 'new'
    ")->execute([$id]);
}

$back = consult_safe_return(post('next', consult_panel_path($user)));
$allowed = ['/secretary/consult-requests', '/doctor/consult-requests', '/admin/consult-requests', '/app/consult'];
$path = (string) (parse_url($back, PHP_URL_PATH) ?: '');
if (!in_array($path, $allowed, true)) {
    $back = consult_panel_path($user);
}

flash_set('success', 'درخواست به حالت پیگیری‌نشده برگشت.');
redirect($back);
