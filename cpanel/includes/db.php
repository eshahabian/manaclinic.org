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
        echo 'اتصال به دیتابیس برقرار نشد.';
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

    try {
        $has = $pdo->query("SHOW COLUMNS FROM users LIKE 'preferred_doctor_id'")->fetch();
        if (!$has) {
            $pdo->exec("ALTER TABLE users ADD COLUMN preferred_doctor_id VARCHAR(32) NULL AFTER role");
        }
    } catch (Throwable $ignored) {
    }

    try {
        if (function_exists('ensure_users_password_plain_schema')) {
            ensure_users_password_plain_schema($pdo);
        } else {
            $has = $pdo->query("SHOW COLUMNS FROM users LIKE 'password_plain'")->fetch();
            if (!$has) {
                $pdo->exec("ALTER TABLE users ADD COLUMN password_plain VARCHAR(255) NULL AFTER password_hash");
            }
        }
    } catch (Throwable $ignored) {
    }

    try {
        $has = $pdo->query("SHOW COLUMNS FROM users LIKE 'avatar_url'")->fetch();
        if (!$has) {
            $pdo->exec('ALTER TABLE users ADD COLUMN avatar_url VARCHAR(255) NULL AFTER phone');
        }
    } catch (Throwable $ignored) {
    }

    try {
        ensure_name_transliterations_schema($pdo);
    } catch (Throwable $ignored) {
    }

    try {
        if (!function_exists('ensure_user_referral_schema')) {
            require_once __DIR__ . '/user_referral.php';
        }
        ensure_user_referral_schema($pdo);
    } catch (Throwable $ignored) {
    }

    try {
        if (!function_exists('ensure_therapist_presence_schema')) {
            require_once __DIR__ . '/therapist_presence.php';
        }
        ensure_therapist_presence_schema($pdo);
        if (!function_exists('ensure_therapist_payouts_schema')) {
            require_once __DIR__ . '/therapist_payouts.php';
        }
        ensure_therapist_payouts_schema($pdo);
        if (!function_exists('ensure_petty_cash_schema')) {
            require_once __DIR__ . '/petty_cash.php';
        }
        ensure_petty_cash_schema($pdo);
    } catch (Throwable $ignored) {
    }

    if (function_exists('ensure_staff_desk_schema')) {
        try {
            ensure_staff_desk_schema($pdo);
        } catch (Throwable $ignored) {
        }
    }

    try {
        $taken = $pdo->query("SELECT id FROM users WHERE username='shgeranmaye' LIMIT 1")->fetch();
        if (!$taken) {
            $pdo->exec("UPDATE users SET username='shgeranmaye' WHERE username='doctor' AND name LIKE '%گرانمایه%' LIMIT 1");
        }
    } catch (Throwable $ignored) {
    }

    try {
        $emad = $pdo->query("SELECT id, role FROM users WHERE username='eemadian' LIMIT 1")->fetch();
        if (is_array($emad) && (string) ($emad['id'] ?? '') !== '') {
            $emadId = (string) $emad['id'];
            if ((string) ($emad['role'] ?? '') !== 'DOCTOR') {
                $pdo->prepare("UPDATE users SET role='DOCTOR' WHERE id=?")->execute([$emadId]);
            }
            $profile = $pdo->prepare('SELECT id, is_approved, is_active FROM doctor_profiles WHERE user_id=? LIMIT 1');
            $profile->execute([$emadId]);
            $profileRow = $profile->fetch();
            if (!is_array($profileRow)) {
                $pdo->prepare('INSERT INTO doctor_profiles (id,user_id,specialty,bio,session_price,is_approved,is_active) VALUES (?,?,?,?,?,1,1)')
                    ->execute([cuid(), $emadId, 'روان‌شناسی', '', 3000000]);
            } elseif ((int) ($profileRow['is_approved'] ?? 0) !== 1 || (int) ($profileRow['is_active'] ?? 0) !== 1) {
                $pdo->prepare('UPDATE doctor_profiles SET is_approved=1, is_active=1 WHERE user_id=?')->execute([$emadId]);
            }
        }
    } catch (Throwable $ignored) {
    }
}
