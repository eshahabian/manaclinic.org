<?php
declare(strict_types=1);
if (!workshop_can_add_member(current_user())) {
    http_response_code(403);exit('دسترسی ندارید.');
}
require_once __DIR__.'/../includes/workshop_presenters.php';
try {
    workshop_presenters_save($pdo,post('id'),(array)($_POST['presenters']??[]));
    staff_log_action($pdo,(string)current_user()['id'],'workshop_presenters','workshop',post('id'),'updated');
    flash_set('success','اجراکنندگان ذخیره شدند؛ پیامک تأیید برای افراد تازه انتخاب‌شده در صف قرار گرفت.');
} catch (RuntimeException $e) { flash_set('error',$e->getMessage()); }
redirect($workshopBase);
