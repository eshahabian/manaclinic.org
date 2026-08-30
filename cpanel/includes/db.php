<?php
declare(strict_types=1);

function db_connect(array $config): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = sprintf(
        'mysql:host=%s;dbname=%s;charset=%s',
        $config['db_host'],
        $config['db_name'],
        $config['db_charset'] ?? 'utf8mb4'
    );

    try {
        $pdo = new PDO($dsn, $config['db_user'], $config['db_pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    } catch (PDOException $e) {
        if (str_contains($_SERVER['REQUEST_URI'] ?? '', '/install')) {
            throw $e;
        }
        http_response_code(500);
        echo 'اتصال به دیتابیس برقرار نشد. config.php و نصب را بررسی کنید. <a href="install">نصب</a>';
        exit;
    }

    db_ensure_schema($pdo);

    return $pdo;
}

/**
 * ارتقای سبک اسکیما برای دیتابیس‌هایی که بعد از آپدیت کد، ستون‌های جدید را ندارند.
 * فقط ستون‌های حیاتی؛ نصب کامل همچنان از install.php است.
 */
function db_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    try {
        $has = $pdo->query("SHOW COLUMNS FROM doctor_profiles LIKE 'is_approved'")->fetch();
        if (!$has) {
            $pdo->exec("ALTER TABLE doctor_profiles ADD COLUMN is_approved TINYINT(1) NOT NULL DEFAULT 0 AFTER session_price");
            // درمانگرهای قبلی که فعال بودند را تأییدشده در نظر بگیر
            $pdo->exec("UPDATE doctor_profiles SET is_approved=1 WHERE is_active=1");
        }
    } catch (Throwable $ignored) {
    }
}
