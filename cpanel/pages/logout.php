<?php
declare(strict_types=1);

$idle = isset($_GET['idle']) && $_GET['idle'] === '1';
logout_user($idle ? 'idle' : 'logout');
if ($idle) {
    flash_set('info', 'به‌خاطر ۱۰ دقیقه بی‌فعالیتی از حساب خارج شدید و ساعت کاری متوقف شد.');
    redirect('/login');
}
redirect('/');
