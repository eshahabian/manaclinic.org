<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/mail.php';

if (current_user()) {
    redirect(panel_href_for(current_user()) ?: '/');
}

throttle_guard_page(
    'forgot_password',
    5,
    900,
    '/forgot-password',
    'درخواست‌های بازیابی زیاد بود. چند دقیقه بعد دوباره تلاش کنید.'
);

$account = normalize_input(post('account'));
$generic = 'اگر حسابی با این مشخصات و ایمیل واقعی وجود داشته باشد، لینک بازیابی ارسال شد. صندوق ورودی و هرزنامه را بررسی کنید.';

if ($account === '') {
    flash_set('error', 'نام کاربری یا ایمیل را وارد کنید.');
    redirect('/forgot-password');
}

ensure_mail_schema($pdo);
throttle_hit('forgot_password', 900);

$user = null;
if (str_contains($account, '@')) {
    $email = mb_strtolower($account);
    if (mail_is_real_email($email)) {
        $st = $pdo->prepare('SELECT * FROM users WHERE LOWER(email)=? LIMIT 1');
        $st->execute([$email]);
        $user = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }
} else {
    $username = mb_strtolower($account);
    $st = $pdo->prepare('SELECT * FROM users WHERE username=? LIMIT 1');
    $st->execute([$username]);
    $user = $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

if ($user && mail_is_real_email((string) ($user['email'] ?? ''))) {
    try {
        $resetUrl = password_reset_create_token($pdo, (string) $user['id']);
        $sent = mail_send_password_reset($pdo, $user, $resetUrl);
        if (!$sent['ok']) {
            // عمداً جزئیات را به کاربر عمومی نشان نمی‌دهیم؛ برای ادمین در لاگ تست قابل‌دیدن است
            error_log('ManaClinic password reset mail failed: ' . ($sent['error'] ?? ''));
        }
    } catch (Throwable $e) {
        error_log('ManaClinic password reset error: ' . $e->getMessage());
    }
}

flash_set('success', $generic);
redirect('/login');
