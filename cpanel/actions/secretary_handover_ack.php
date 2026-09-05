<?php
declare(strict_types=1);

$user = require_login(['SECRETARY']);
$noteId = post('note_id');
if ($noteId === '') {
    flash_set('error', 'پیام مشخص نیست.');
    redirect('/secretary/messages');
}

handover_ack($pdo, (string) $user['id'], $noteId);
unset($GLOBALS['handoverBlock']);
flash_set('success', 'پیام تحویل شیفت خوانده شد.');
redirect('/secretary/messages');
