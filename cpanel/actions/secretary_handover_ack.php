<?php
declare(strict_types=1);

$user = require_login(['SECRETARY']);
csrf_verify();
$noteId = post('note_id');
if ($noteId === '') {
    flash_set('error', 'پیام مشخص نیست.');
    redirect('/secretary/colleague');
}

handover_ack($pdo, (string) $user['id'], $noteId);
unset($GLOBALS['handoverBlock']);
flash_set('success', 'پیام همکار خوانده شد.');
redirect('/secretary/colleague');
