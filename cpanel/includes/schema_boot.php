<?php
declare(strict_types=1);

/**
 * نسخهٔ بوت اسکیما — بعد از اضافه کردن ستون/جدول جدید در ensure_*ها این را عوض کن
 * تا یک‌بار روی سرور دوباره اجرا شود؛ بعد از آن هر درخواست SHOW COLUMNS نمی‌زند.
 */
function clinic_schema_boot_version(): string
{
    return '20261028-therapy-terms';
}

function clinic_schema_boot_ensure_meta(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    try {
        $pdo->exec("
          CREATE TABLE IF NOT EXISTS app_meta (
            k VARCHAR(64) PRIMARY KEY,
            v VARCHAR(255) NOT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
          ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (Throwable $ignored) {
    }
    $ready = true;
}

function clinic_schema_is_current(PDO $pdo): bool
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    clinic_schema_boot_ensure_meta($pdo);
    try {
        $stmt = $pdo->prepare("SELECT v FROM app_meta WHERE k='schema_boot_version' LIMIT 1");
        $stmt->execute();
        $cached = ((string) ($stmt->fetchColumn() ?: '')) === clinic_schema_boot_version();
    } catch (Throwable $ignored) {
        $cached = false;
    }

    return $cached;
}

function clinic_schema_mark_current(PDO $pdo): void
{
    clinic_schema_boot_ensure_meta($pdo);
    $version = clinic_schema_boot_version();
    try {
        $pdo->prepare("INSERT INTO app_meta (k, v) VALUES ('schema_boot_version', ?) ON DUPLICATE KEY UPDATE v=VALUES(v)")
            ->execute([$version]);
    } catch (Throwable $ignored) {
    }
}
