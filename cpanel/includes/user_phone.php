<?php
declare(strict_types=1);

/** One comparison key for local, +98, 0098 and international forms. */
function user_phone_key(string $phone): ?string
{
    $phone = normalize_phone($phone);
    if (!is_valid_phone($phone)) return null;
    if (str_starts_with($phone, '+')) $phone = substr($phone, 1);
    elseif (str_starts_with($phone, '00')) $phone = substr($phone, 2);
    if (preg_match('/^09[0-9]{9}$/D', $phone)) return '98' . substr($phone, 1);
    if (preg_match('/^9[0-9]{9}$/D', $phone)) return '98' . $phone;
    return $phone;
}

/** Serialize the duplicate check and write across public and staff forms. */
function user_phone_write(PDO $pdo, string $phone, ?string $excludeId, callable $write): mixed
{
    $key = user_phone_key($phone);
    if ($key === null) throw new RuntimeException('شماره موبایل معتبر الزامی است.');
    $lock = 'mana-phone:' . substr(hash('sha256', $key), 0, 48);
    $stmt = $pdo->prepare('SELECT GET_LOCK(?, 5)');
    $stmt->execute([$lock]);
    if ((int) $stmt->fetchColumn() !== 1) throw new RuntimeException('ثبت موبایل هم‌زمان در حال انجام است؛ دوباره تلاش کنید.');
    try {
        $rows = $pdo->query("SELECT id, phone FROM users WHERE phone IS NOT NULL AND phone<>''");
        foreach ($rows->fetchAll() as $row) {
            if ((string) $row['id'] !== $excludeId && user_phone_key((string) $row['phone']) === $key) {
                throw new RuntimeException('این شماره موبایل قبلاً برای حساب دیگری ثبت شده است. از حساب موجود استفاده کنید.');
            }
        }
        $canonical = preg_match('/^989[0-9]{9}$/D', $key) ? '0' . substr($key, 2) : '+' . $key;
        return $write($canonical);
    } finally {
        $release = $pdo->prepare('SELECT RELEASE_LOCK(?)');
        $release->execute([$lock]);
    }
}
