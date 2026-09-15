<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/admin_staff_messages.php';

$user = require_login(['SECRETARY']);
csrf_verify();

$recipientId = post('recipient_id');
if ($recipientId === '') {
    flash_set('error', 'پیام یافت نشد.');
    redirect('/secretary/messages?msg=admin');
}

admin_staff_msg_ack($pdo, (string) $user['id'], $recipientId);
unset($GLOBALS['adminStaffMsgBlock']);
flash_set('success', 'پیام مدیر تأیید شد.');

$pending = admin_staff_msg_pending_for($pdo, (string) $user['id']);
if ($pending) {
    redirect('/secretary/messages?msg=admin');
}
redirect('/secretary/messages?msg=admin');
