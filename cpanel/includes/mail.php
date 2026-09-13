<?php
declare(strict_types=1);

/**
 * ایمیل سایت — SMTP سبک بدون PHPMailer + تنظیمات قابل‌ذخیره در دیتابیس
 */

function ensure_mail_schema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    try {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS app_settings (
                setting_key VARCHAR(64) PRIMARY KEY,
                setting_value TEXT NULL,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    } catch (Throwable $ignored) {
    }
    try {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS password_reset_tokens (
                id VARCHAR(32) PRIMARY KEY,
                user_id VARCHAR(32) NOT NULL,
                token_hash VARCHAR(64) NOT NULL,
                expires_at DATETIME NOT NULL,
                used_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_prt_token (token_hash),
                INDEX idx_prt_user (user_id),
                INDEX idx_prt_expires (expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    } catch (Throwable $e) {
        error_log('ManaClinic ensure_mail_schema tokens: ' . $e->getMessage());
    }
    try {
        $col = $pdo->query("SHOW COLUMNS FROM password_reset_tokens LIKE 'token_hash'")->fetch(PDO::FETCH_ASSOC);
        if ($col && isset($col['Type']) && stripos((string) $col['Type'], 'char(64)') === 0) {
            $pdo->exec('ALTER TABLE password_reset_tokens MODIFY token_hash VARCHAR(64) NOT NULL');
        }
    } catch (Throwable $ignored) {
    }
    $ready = true;
}

function mail_default_from_email(): string
{
    return 'noreply@manaclinic.org';
}

function mail_default_from_name(): string
{
    global $config;
    $name = trim((string) ($config['app_name'] ?? 'مانا کلینیک'));
    return $name !== '' ? $name : 'مانا کلینیک';
}

function mail_setting_get(PDO $pdo, string $key, ?string $default = null): ?string
{
    ensure_mail_schema($pdo);
    try {
        $st = $pdo->prepare('SELECT setting_value FROM app_settings WHERE setting_key=? LIMIT 1');
        $st->execute([$key]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row && array_key_exists('setting_value', $row) && $row['setting_value'] !== null) {
            return (string) $row['setting_value'];
        }
    } catch (Throwable $ignored) {
    }
    return $default;
}

function mail_setting_set(PDO $pdo, string $key, ?string $value): void
{
    ensure_mail_schema($pdo);
    $pdo->prepare(
        'INSERT INTO app_settings (setting_key, setting_value) VALUES (?,?)
         ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)'
    )->execute([$key, $value]);
}

function mail_setting_delete(PDO $pdo, string $key): void
{
    ensure_mail_schema($pdo);
    $pdo->prepare('DELETE FROM app_settings WHERE setting_key=?')->execute([$key]);
}

/** @return array{host:string,port:int,user:string,pass:string,encryption:string,from_email:string,from_name:string,pass_source:string} */
function mail_config(PDO $pdo): array
{
    global $config;
    $cfg = is_array($config ?? null) ? $config : [];

    $host = trim((string) (mail_setting_get($pdo, 'mail_smtp_host') ?? ($cfg['smtp_host'] ?? 'localhost')));
    if ($host === '') {
        $host = 'localhost';
    }

    $portRaw = mail_setting_get($pdo, 'mail_smtp_port');
    $port = (int) ($portRaw !== null && $portRaw !== '' ? $portRaw : ($cfg['smtp_port'] ?? 587));
    if ($port < 1 || $port > 65535) {
        $port = 587;
    }

    $user = trim((string) (mail_setting_get($pdo, 'mail_smtp_user') ?? ($cfg['smtp_user'] ?? mail_default_from_email())));
    if ($user === '') {
        $user = mail_default_from_email();
    }

    $dbPass = mail_setting_get($pdo, 'mail_smtp_pass');
    $cfgPass = (string) ($cfg['smtp_pass'] ?? '');
    $passSource = 'none';
    if ($dbPass !== null && $dbPass !== '') {
        $pass = $dbPass;
        $passSource = 'database';
    } elseif ($cfgPass !== '') {
        $pass = $cfgPass;
        $passSource = 'config';
    } else {
        $pass = '';
    }

    $enc = strtolower(trim((string) (mail_setting_get($pdo, 'mail_smtp_encryption')
        ?? ($cfg['smtp_encryption'] ?? 'tls'))));
    if (!in_array($enc, ['none', 'tls', 'ssl'], true)) {
        $enc = $host === 'localhost' ? 'none' : 'tls';
    }
    if ($host === 'localhost' && ($port === 25 || $port === 26)) {
        $enc = 'none';
    }

    $fromEmail = trim((string) (mail_setting_get($pdo, 'mail_from_email')
        ?? ($cfg['mail_from'] ?? mail_default_from_email())));
    if ($fromEmail === '' || !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
        $fromEmail = mail_default_from_email();
    }

    $fromName = trim((string) (mail_setting_get($pdo, 'mail_from_name')
        ?? ($cfg['mail_from_name'] ?? mail_default_from_name())));
    if ($fromName === '') {
        $fromName = mail_default_from_name();
    }

    return [
        'host' => $host,
        'port' => $port,
        'user' => $user,
        'pass' => $pass,
        'encryption' => $enc,
        'from_email' => $fromEmail,
        'from_name' => $fromName,
        'pass_source' => $passSource,
    ];
}

/** میزبان‌های پیشنهادی برای پنل ادمین */
function mail_host_options(): array
{
    return [
        'localhost' => 'localhost (پیشنهادی سی‌پنل)',
        'mail.manaclinic.org' => 'mail.manaclinic.org',
        'smtp.gmail.com' => 'smtp.gmail.com',
    ];
}

function mail_is_real_email(?string $email): bool
{
    $email = trim((string) $email);
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    $lower = strtolower($email);
    if (str_ends_with($lower, '@manaclinic.local') || str_ends_with($lower, '.local')) {
        return false;
    }
    return true;
}

function mail_encode_header(string $value): string
{
    if (preg_match('/^[\x20-\x7E]*$/', $value)) {
        return $value;
    }
    return '=?UTF-8?B?' . base64_encode($value) . '?=';
}

function mail_build_message(
    string $to,
    string $subject,
    string $htmlBody,
    string $textBody,
    array $mc
): string {
    $boundary = 'mana_' . bin2hex(random_bytes(8));
    $from = mail_encode_header($mc['from_name']) . ' <' . $mc['from_email'] . '>';
    $headers = [
        'Date: ' . date('r'),
        'From: ' . $from,
        'Reply-To: ' . $mc['from_email'],
        'Message-ID: <' . bin2hex(random_bytes(12)) . '@manaclinic.org>',
        'MIME-Version: 1.0',
        'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
        'X-Mailer: ManaClinic-Mail/1.0',
    ];

    $textPart = quoted_printable_encode(str_replace(["\r\n", "\r"], "\n", $textBody));
    $htmlPart = quoted_printable_encode(str_replace(["\r\n", "\r"], "\n", $htmlBody));

    $body = '--' . $boundary . "\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\n"
        . "Content-Transfer-Encoding: quoted-printable\r\n\r\n"
        . $textPart . "\r\n"
        . '--' . $boundary . "\r\n"
        . "Content-Type: text/html; charset=UTF-8\r\n"
        . "Content-Transfer-Encoding: quoted-printable\r\n\r\n"
        . $htmlPart . "\r\n"
        . '--' . $boundary . "--\r\n";

    return 'To: ' . $to . "\r\n"
        . 'Subject: ' . mail_encode_header($subject) . "\r\n"
        . implode("\r\n", $headers) . "\r\n\r\n"
        . $body;
}

function mail_smtp_read($fp): string
{
    $data = '';
    while (!feof($fp)) {
        $line = fgets($fp, 515);
        if ($line === false) {
            break;
        }
        $data .= $line;
        if (isset($line[3]) && $line[3] === ' ') {
            break;
        }
    }
    return $data;
}

function mail_smtp_expect($fp, array $okCodes, string &$log, string $step): bool
{
    $resp = mail_smtp_read($fp);
    $log .= $resp;
    $code = (int) substr(trim($resp), 0, 3);
    if (!in_array($code, $okCodes, true)) {
        $log .= "[fail at {$step}: expected " . implode('/', $okCodes) . ", got {$code}]\n";
        return false;
    }
    return true;
}

function mail_smtp_cmd($fp, string $cmd, array $okCodes, string &$log, string $step, bool $hide = false): bool
{
    $log .= ($hide ? '[AUTH DATA]' : $cmd) . "\r\n";
    fwrite($fp, $cmd . "\r\n");
    return mail_smtp_expect($fp, $okCodes, $log, $step);
}

/**
 * اتصال مستقیم SMTP و گزارش کدهای پاسخ (بدون PHPMailer)
 * @return array{ok:bool,log:string,detail:string}
 */
function mail_smtp_probe(PDO $pdo, ?string $to = null, ?string $subject = null, ?string $html = null, ?string $text = null): array
{
    $mc = mail_config($pdo);
    $log = '';
    $host = $mc['host'];
    $port = $mc['port'];
    $enc = $mc['encryption'];

    $remote = ($enc === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;
    $log .= "Connecting to {$remote} …\n";

    $errno = 0;
    $errstr = '';
    $fp = @stream_socket_client(
        $remote,
        $errno,
        $errstr,
        20,
        STREAM_CLIENT_CONNECT,
        stream_context_create([
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true,
            ],
        ])
    );

    if (!$fp) {
        return [
            'ok' => false,
            'log' => $log . "Connection failed: {$errstr} ({$errno})\n",
            'detail' => "اتصال به {$host}:{$port} برقرار نشد.",
        ];
    }
    stream_set_timeout($fp, 20);

    if (!mail_smtp_expect($fp, [220], $log, 'banner')) {
        fclose($fp);
        return ['ok' => false, 'log' => $log, 'detail' => 'پاسخ اولیه SMTP نامعتبر بود.'];
    }

    $ehloHost = 'manaclinic.org';
    if (!mail_smtp_cmd($fp, 'EHLO ' . $ehloHost, [250], $log, 'EHLO')) {
        if (!mail_smtp_cmd($fp, 'HELO ' . $ehloHost, [250], $log, 'HELO')) {
            fclose($fp);
            return ['ok' => false, 'log' => $log, 'detail' => 'EHLO/HELO رد شد.'];
        }
    }

    if ($enc === 'tls') {
        if (!mail_smtp_cmd($fp, 'STARTTLS', [220], $log, 'STARTTLS')) {
            fclose($fp);
            return ['ok' => false, 'log' => $log, 'detail' => 'STARTTLS ناموفق بود.'];
        }
        if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            $log .= "TLS handshake failed\n";
            fclose($fp);
            return ['ok' => false, 'log' => $log, 'detail' => 'دست‌دهی TLS ناموفق بود.'];
        }
        if (!mail_smtp_cmd($fp, 'EHLO ' . $ehloHost, [250], $log, 'EHLO-after-TLS')) {
            fclose($fp);
            return ['ok' => false, 'log' => $log, 'detail' => 'EHLO بعد از TLS رد شد.'];
        }
    }

    $needAuth = $mc['pass'] !== '' || ($host !== 'localhost' && $mc['user'] !== '');
    if ($needAuth && $mc['pass'] !== '') {
        if (!mail_smtp_cmd($fp, 'AUTH LOGIN', [334], $log, 'AUTH LOGIN')) {
            fclose($fp);
            return ['ok' => false, 'log' => $log, 'detail' => 'AUTH LOGIN پشتیبانی نمی‌شود یا رد شد.'];
        }
        if (!mail_smtp_cmd($fp, base64_encode($mc['user']), [334], $log, 'AUTH user', true)) {
            fclose($fp);
            return ['ok' => false, 'log' => $log, 'detail' => 'نام کاربری SMTP رد شد.'];
        }
        if (!mail_smtp_cmd($fp, base64_encode($mc['pass']), [235], $log, 'AUTH pass', true)) {
            fclose($fp);
            return ['ok' => false, 'log' => $log, 'detail' => 'رمز SMTP رد شد (مثلاً خطای 535).'];
        }
    } elseif ($needAuth && $mc['pass'] === '') {
        $log .= "[skip AUTH: no password configured]\n";
    }

    if ($to !== null && $html !== null) {
        $text = $text ?? strip_tags($html);
        $subject = $subject ?? 'تست ایمیل مانا کلینیک';
        if (!mail_smtp_cmd($fp, 'MAIL FROM:<' . $mc['from_email'] . '>', [250], $log, 'MAIL FROM')) {
            fclose($fp);
            return ['ok' => false, 'log' => $log, 'detail' => 'MAIL FROM رد شد.'];
        }
        if (!mail_smtp_cmd($fp, 'RCPT TO:<' . $to . '>', [250, 251], $log, 'RCPT TO')) {
            fclose($fp);
            return ['ok' => false, 'log' => $log, 'detail' => 'RCPT TO رد شد.'];
        }
        if (!mail_smtp_cmd($fp, 'DATA', [354], $log, 'DATA')) {
            fclose($fp);
            return ['ok' => false, 'log' => $log, 'detail' => 'DATA رد شد.'];
        }
        $msg = mail_build_message($to, $subject, $html, $text, $mc);
        $msg = preg_replace('/^\./m', '..', $msg) ?? $msg;
        fwrite($fp, $msg . "\r\n.\r\n");
        $log .= "[message body omitted]\r\n.\r\n";
        if (!mail_smtp_expect($fp, [250], $log, 'DATA-end')) {
            fclose($fp);
            return ['ok' => false, 'log' => $log, 'detail' => 'ارسال پیام رد شد.'];
        }
    }

    mail_smtp_cmd($fp, 'QUIT', [221, 250], $log, 'QUIT');
    fclose($fp);

    return [
        'ok' => true,
        'log' => $log,
        'detail' => $to ? 'ایمیل با موفقیت از طریق SMTP ارسال شد.' : 'اتصال و احراز هویت SMTP موفق بود.',
    ];
}

/**
 * @return array{ok:bool,error:?string,log?:string}
 */
function mail_send(PDO $pdo, string $to, string $subject, string $htmlBody, ?string $textBody = null): array
{
    $to = trim($to);
    if (!mail_is_real_email($to)) {
        return ['ok' => false, 'error' => 'آدرس ایمیل گیرنده معتبر نیست.'];
    }
    $textBody = $textBody ?? trim(html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>'], "\n", $htmlBody)), ENT_QUOTES, 'UTF-8'));
    $result = mail_smtp_probe($pdo, $to, $subject, $htmlBody, $textBody);
    if ($result['ok']) {
        return ['ok' => true, 'error' => null, 'log' => $result['log']];
    }
    return ['ok' => false, 'error' => $result['detail'], 'log' => $result['log']];
}

function mail_wrap_html(string $title, string $innerHtml): string
{
    $safeTitle = e($title);
    return '<!DOCTYPE html><html lang="fa" dir="rtl"><head><meta charset="UTF-8"><title>'
        . $safeTitle . '</title></head><body style="margin:0;padding:0;background:#f7f5f0;font-family:Tahoma,Arial,sans-serif;color:#1a2e28">'
        . '<div style="max-width:560px;margin:24px auto;background:#fff;border:1px solid #d5e0da;border-radius:12px;overflow:hidden">'
        . '<div style="background:#1b5e4b;color:#fff;padding:16px 20px;font-size:18px;font-weight:700">مانا کلینیک</div>'
        . '<div style="padding:20px;line-height:1.9;font-size:15px">' . $innerHtml . '</div>'
        . '<div style="padding:12px 20px;background:#eef4f0;color:#5a6f66;font-size:12px;line-height:1.7">'
        . 'این پیام به‌صورت خودکار از noreply@manaclinic.org ارسال شده است.'
        . '</div></div></body></html>';
}

function mail_send_welcome(PDO $pdo, array $user): array
{
    $email = (string) ($user['email'] ?? '');
    if (!mail_is_real_email($email)) {
        return ['ok' => false, 'error' => 'ایمیل واقعی برای کاربر ثبت نشده.'];
    }
    $name = e((string) ($user['name'] ?? 'کاربر'));
    $username = e((string) ($user['username'] ?? ''));
    $loginUrl = e(seo_absolute_url('/login'));
    $role = (string) ($user['role'] ?? 'PATIENT');
    $extra = $role === 'DOCTOR'
        ? '<p>درخواست درمانگری شما ثبت شد. پس از تأیید مدیر سایت می‌توانید وارد شوید.</p>'
        : '<p>حساب شما آماده است. می‌توانید برای رزرو نوبت و کارگاه‌ها وارد شوید.</p>';

    $html = mail_wrap_html('خوش آمدید',
        '<p>سلام ' . $name . '،</p>'
        . '<p>ثبت‌نام شما در مانا کلینیک با موفقیت انجام شد.</p>'
        . ($username !== '' ? '<p>نام کاربری: <b dir="ltr">' . $username . '</b></p>' : '')
        . $extra
        . '<p><a href="' . $loginUrl . '" style="display:inline-block;padding:10px 18px;background:#1b5e4b;color:#fff;text-decoration:none;border-radius:8px">ورود به سایت</a></p>'
    );
    $text = "سلام {$user['name']}\nثبت‌نام شما در مانا کلینیک انجام شد.\nنام کاربری: {$user['username']}\nورود: " . seo_absolute_url('/login');
    return mail_send($pdo, $email, 'خوش آمدید به مانا کلینیک', $html, $text);
}

function mail_send_password_reset(PDO $pdo, array $user, string $resetUrl): array
{
    $email = (string) ($user['email'] ?? '');
    if (!mail_is_real_email($email)) {
        return ['ok' => false, 'error' => 'ایمیل واقعی برای کاربر ثبت نشده.'];
    }
    $name = e((string) ($user['name'] ?? 'کاربر'));
    $safeUrl = e($resetUrl);
    $html = mail_wrap_html('بازیابی رمز عبور',
        '<p>سلام ' . $name . '،</p>'
        . '<p>برای تعیین رمز جدید روی دکمه زیر بزنید. این لینک حدود دو ساعت معتبر است.</p>'
        . '<p><a href="' . $safeUrl . '" style="display:inline-block;padding:10px 18px;background:#c4783a;color:#fff;text-decoration:none;border-radius:8px">تعیین رمز جدید</a></p>'
        . '<p style="font-size:13px;color:#5a6f66">اگر این درخواست از طرف شما نبوده، این پیام را نادیده بگیرید.</p>'
        . '<p style="font-size:12px;direction:ltr;text-align:left;unicode-bidi:isolate;word-break:break-all">'
        . '<a href="' . $safeUrl . '" style="color:#1b5e4b">' . $safeUrl . '</a></p>'
    );
    $text = "سلام {$user['name']}\nبرای بازیابی رمز این آدرس را در مرورگر باز کنید (حدود ۲ ساعت معتبر):\n{$resetUrl}\nاگر این درخواست از شما نبوده، نادیده بگیرید.";
    return mail_send($pdo, $email, 'بازیابی رمز عبور مانا کلینیک', $html, $text);
}

/**
 * ساخت توکن ریست و برگرداندن لینک یک‌بارمصرف
 */
function password_reset_create_token(PDO $pdo, string $userId): string
{
    ensure_mail_schema($pdo);
    if ($userId === '') {
        throw new RuntimeException('user id empty for password reset');
    }

    // فقط توکن‌های همین کاربر + موارد منقضی
    $pdo->prepare('DELETE FROM password_reset_tokens WHERE user_id=? OR expires_at < UTC_TIMESTAMP()')->execute([$userId]);

    // ۳۲ کاراکتر: کمتر در ایمیل می‌شکند، همچنان امن است
    $raw = bin2hex(random_bytes(16));
    $hash = hash('sha256', $raw);
    $id = cuid();
    $pdo->prepare(
        'INSERT INTO password_reset_tokens (id, user_id, token_hash, expires_at, used_at, created_at)
         VALUES (?,?,?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 2 HOUR), NULL, UTC_TIMESTAMP())'
    )->execute([$id, $userId, $hash]);

    $check = $pdo->prepare(
        'SELECT id FROM password_reset_tokens
         WHERE id=? AND token_hash=? AND used_at IS NULL AND expires_at > UTC_TIMESTAMP()
         LIMIT 1'
    );
    $check->execute([$id, $hash]);
    if (!$check->fetch()) {
        throw new RuntimeException('password reset token was not saved');
    }

    return seo_absolute_url('/reset-password/' . $raw);
}

function password_reset_normalize_token(string $rawToken): string
{
    return strtolower(preg_replace('/[^a-f0-9]/i', '', trim($rawToken)) ?? '');
}

function password_reset_find_valid(PDO $pdo, string $rawToken): ?array
{
    ensure_mail_schema($pdo);
    $rawToken = password_reset_normalize_token($rawToken);
    // ۳۲ (جدید) یا ۶۴ (لینک‌های قبلی)
    $len = strlen($rawToken);
    if ($len !== 32 && $len !== 64) {
        return null;
    }
    $hash = hash('sha256', $rawToken);
    try {
        $st = $pdo->prepare(
            'SELECT t.id AS reset_id, t.user_id, t.expires_at, t.used_at,
                    u.username, u.name, u.email, u.role
             FROM password_reset_tokens t
             INNER JOIN users u ON u.id = t.user_id
             WHERE t.token_hash=? AND t.used_at IS NULL AND t.expires_at > UTC_TIMESTAMP()
             LIMIT 1'
        );
        $st->execute([$hash]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $row['id'] = (string) ($row['reset_id'] ?? '');
        return $row;
    } catch (Throwable $e) {
        error_log('ManaClinic password_reset_find_valid: ' . $e->getMessage());
        return null;
    }
}

function password_reset_mark_used(PDO $pdo, string $tokenId): void
{
    $pdo->prepare('UPDATE password_reset_tokens SET used_at=NOW() WHERE id=?')->execute([$tokenId]);
}
