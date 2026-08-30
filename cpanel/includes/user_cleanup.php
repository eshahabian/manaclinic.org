<?php
declare(strict_types=1);

/**
 * حذف امن کاربر و وابستگی‌هایی که FK ندارند.
 */
function delete_user_cascade(PDO $pdo, string $userId): void
{
    // اعلان‌ها FK ندارند
    try {
        ensure_notifications_table($pdo);
        $pdo->prepare('DELETE FROM notifications WHERE recipient_user_id = ?')->execute([$userId]);
    } catch (Throwable $ignored) {
    }

    // نوبت‌ها / پرداخت‌ها / پرونده با CASCADE روی users حذف می‌شوند
    $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);
}

/**
 * پیدا کردن کاربران تستی مشخص‌شده برای پاک‌سازی
 * @return list<array{id:string,name:string,username:?string,role:string}>
 */
function find_cleanup_test_users(PDO $pdo): array
{
    $stmt = $pdo->query("SELECT id, name, username, role FROM users WHERE role <> 'ADMIN'");
    $rows = $stmt->fetchAll();
    $out = [];
    foreach ($rows as $u) {
        $name = trim((string) ($u['name'] ?? ''));
        $norm = preg_replace('/\s+/u', ' ', $name) ?? $name;
        $match =
            preg_match('/برهان.*شاوردی|شاوردی.*برهان/u', $norm) ||
            preg_match('/علی\s*رضایی/u', $norm) ||
            ($norm === 'عماد' || preg_match('/^عماد(\s|$)/u', $norm));
        // حساب شخصی «عماد شهابیان» را حذف نکن
        if (preg_match('/شهابیان/u', $norm)) {
            $match = false;
            if (preg_match('/برهان.*شاوردی|شاوردی.*برهان|علی\s*رضایی/u', $norm)) {
                $match = true;
            }
        }
        if ($match) {
            $out[] = $u;
        }
    }
    return $out;
}
