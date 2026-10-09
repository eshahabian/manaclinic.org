<?php
declare(strict_types=1);

$idle = isset($_GET['idle']) && $_GET['idle'] === '1';
$replaced = isset($_GET['replaced']) && $_GET['replaced'] === '1';
$return = safe_staff_app_return((string) ($_GET['next'] ?? ''));
if ($return === null) {
    $return = safe_staff_app_return((string) ($_GET['return'] ?? ''));
}
if ($replaced) {
    auth_drop_local_session();
    if (empty($_SESSION['flash'])) {
        flash_set('info', 'این حساب از مرورگر یا دستگاه دیگری وارد شد و این نشست بسته شد.');
    }
    redirect($return ?? '/login');
}
$leaving = current_user();
if ($leaving) {
    logout_user($idle ? 'idle' : 'logout');
}
if ($idle) {
    if ($leaving && empty($_SESSION['flash'])) {
        flash_set('info', auth_idle_message($leaving));
    } elseif (empty($_SESSION['flash'])) {
        flash_set('info', 'به‌خاطر ۱۰ دقیقه بی‌فعالیتی از حساب خارج شدید.');
    }
    redirect($return ?? '/login');
}
redirect($return ?? '/');
