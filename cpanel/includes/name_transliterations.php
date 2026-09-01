<?php
declare(strict_types=1);

function ensure_name_transliterations_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS name_transliterations (
          id VARCHAR(32) PRIMARY KEY,
          persian VARCHAR(100) NOT NULL,
          latin VARCHAR(100) NOT NULL,
          part ENUM('first','last','any') NOT NULL DEFAULT 'any',
          source VARCHAR(32) NOT NULL DEFAULT 'seed',
          hits INT UNSIGNED NOT NULL DEFAULT 1,
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          UNIQUE KEY uniq_name_latin_part (persian, latin, part),
          INDEX idx_lookup (persian, part, hits DESC)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    sync_name_transliterations_seed($pdo);
    sync_name_transliterations_from_users($pdo);
}

function normalize_persian_name_part(string $value): string
{
    $value = trim($value);
    $value = str_replace(['ي', 'ك', '‌'], ['ی', 'ک', ''], $value);
    $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
    return trim($value);
}

function clean_latin_seed(string $value): string
{
    $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $value = strtr($value, [
        'ā' => 'a', 'ī' => 'i', 'ū' => 'u', 'ē' => 'e', 'ō' => 'o',
        'Á' => 'a', 'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
        'ă' => 'a', 'š' => 'sh', 'ž' => 'z', 'č' => 'ch',
    ]);
    $value = preg_replace('/\([^)]*\)/', '', $value) ?? $value;
    $value = preg_replace('/\s+/', ' ', $value) ?? $value;
    $value = preg_replace("/[^a-zA-Z\s'-]/", '', $value) ?? $value;
    return trim($value);
}

/**
 * @return list<array{0:string,1:string,2:string,3:string}>
 */
function name_transliterations_seed_data(): array
{
    $rows = [
        // iran-oxford.com
        ['محمد', 'Mohammad', 'first', 'iran-oxford'],
        ['علی', 'Ali', 'first', 'iran-oxford'],
        ['حسین', 'Hossein', 'first', 'iran-oxford'],
        ['حسن', 'Hassan', 'first', 'iran-oxford'],
        ['مریم', 'Maryam', 'first', 'iran-oxford'],
        ['فاطمه', 'Fatima', 'first', 'iran-oxford'],
        ['یوسف', 'Joseph', 'first', 'iran-oxford'],
        ['مهدی', 'Mahdi', 'first', 'iran-oxford'],
        ['ابراهیم', 'Ibrahim', 'first', 'iran-oxford'],
        ['احمد', 'Ahmad', 'first', 'iran-oxford'],
        ['زهرا', 'Zahra', 'first', 'iran-oxford'],
        ['نوح', 'Noah', 'first', 'iran-oxford'],
        ['سارا', 'Sara', 'first', 'iran-oxford'],
        ['داوود', 'David', 'first', 'iran-oxford'],
        ['حوا', 'Eve', 'first', 'iran-oxford'],
        ['موسی', 'Moses', 'first', 'iran-oxford'],
        ['یعقوب', 'Jacob', 'first', 'iran-oxford'],
        ['نورا', 'Nora', 'first', 'iran-oxford'],
        ['یاسمن', 'Jasmine', 'first', 'iran-oxford'],
        ['یاسمین', 'Jasmine', 'first', 'iran-oxford'],
        ['مائده', 'Maede', 'first', 'iran-oxford'],
        ['طاها', 'Taha', 'first', 'iran-oxford'],
        ['پریا', 'Paria', 'first', 'iran-oxford'],
        ['ایلیا', 'Ilia', 'first', 'iran-oxford'],
        ['النا', 'Elena', 'first', 'iran-oxford'],
        ['مسعود', 'Masud', 'first', 'iran-oxford'],
        ['مهشید', 'Mahshid', 'first', 'iran-oxford'],
        ['سمانه', 'Samane', 'first', 'iran-oxford'],
        ['مبینا', 'Mobina', 'first', 'iran-oxford'],
        ['هانیه', 'Hanie', 'first', 'iran-oxford'],
        ['مرضیه', 'Marzie', 'first', 'iran-oxford'],
        ['سجاد', 'Sajad', 'first', 'iran-oxford'],
        ['ملینا', 'Melina', 'first', 'iran-oxford'],
        ['مصطفی', 'Mustafa', 'first', 'iran-oxford'],
        ['آیناز', 'Ainaz', 'first', 'iran-oxford'],
        ['عباس', 'Abbas', 'first', 'iran-oxford'],
        ['آوا', 'Ava', 'first', 'iran-oxford'],
        ['باران', 'Baran', 'first', 'iran-oxford'],
        ['دیانا', 'Diana', 'first', 'iran-oxford'],
        ['احسان', 'Ehsan', 'first', 'iran-oxford'],
        ['روژان', 'Rojan', 'first', 'iran-oxford'],
        ['الهه', 'Elahe', 'first', 'iran-oxford'],
        ['رامین', 'Ramin', 'first', 'iran-oxford'],
        ['کامران', 'Kamran', 'first', 'iran-oxford'],
        ['شیرین', 'Shirin', 'first', 'iran-oxford'],
        // namefarsi.com
        ['هلن', 'Helen', 'first', 'namefarsi'],
        ['تارا', 'Tara', 'first', 'namefarsi'],
        ['میکائیل', 'Michael', 'first', 'namefarsi'],
        // کاربر / درخواست
        ['عماد', 'Emad', 'first', 'user'],
        // abadis.ir (مرسوم‌ترین‌ها)
        ['معصومه', 'Masoumeh', 'first', 'abadis'],
        ['زینب', 'Zeynab', 'first', 'abadis'],
        ['سکینه', 'Sakineh', 'first', 'abadis'],
        ['رقیه', 'Roghayeh', 'first', 'abadis'],
        ['ابوالفضل', 'Abolfazl', 'first', 'abadis'],
        ['خدیجه', 'Khadijeh', 'first', 'abadis'],
        ['سعید', 'Saeed', 'first', 'abadis'],
        ['محسن', 'Mohsen', 'first', 'abadis'],
        ['سمیه', 'Somayeh', 'first', 'abadis'],
        ['محمود', 'Mahmoud', 'first', 'abadis'],
        ['صدیقه', 'Sedigheh', 'first', 'abadis'],
        ['مجید', 'Majid', 'first', 'abadis'],
        ['طاهره', 'Tahereh', 'first', 'abadis'],
        ['حمید', 'Hamid', 'first', 'abadis'],
        ['جواد', 'Javad', 'first', 'abadis'],
        ['نرگس', 'Narges', 'first', 'abadis'],
        ['امیر', 'Amir', 'first', 'abadis'],
        ['زهره', 'Zohreh', 'first', 'abadis'],
        ['عبدالله', 'Abdollah', 'first', 'abadis'],
        ['اعظم', 'Azam', 'first', 'abadis'],
        ['اکبر', 'Akbar', 'first', 'abadis'],
        ['هادی', 'Hadi', 'first', 'abadis'],
        ['اکرم', 'Akram', 'first', 'abadis'],
        ['اسماعیل', 'Esmaeil', 'first', 'abadis'],
        ['الهام', 'Elham', 'first', 'abadis'],
        ['ناصر', 'Naser', 'first', 'abadis'],
        ['بتول', 'Batoul', 'first', 'abadis'],
        ['وحید', 'Vahid', 'first', 'abadis'],
        ['راضیه', 'Razieh', 'first', 'abadis'],
        ['هاجر', 'Hajar', 'first', 'abadis'],
        ['ریحانه', 'Reyhaneh', 'first', 'abadis'],
        ['میلاد', 'Milad', 'first', 'abadis'],
        ['آمنه', 'Ameneh', 'first', 'abadis'],
        ['امید', 'Omid', 'first', 'abadis'],
        ['فرزانه', 'Farzaneh', 'first', 'abadis'],
        ['طیبه', 'Tayyebah', 'first', 'abadis'],
        ['حامد', 'Hamed', 'first', 'abadis'],
        ['نسرین', 'Nasrin', 'first', 'abadis'],
        ['محبوبه', 'Mahboubeh', 'first', 'abadis'],
        ['اصغر', 'Asghar', 'first', 'abadis'],
        ['مهناز', 'Mahnaz', 'first', 'abadis'],
        ['فرشته', 'Fereshteh', 'first', 'abadis'],
        ['فریده', 'Farideh', 'first', 'abadis'],
        ['امین', 'Amin', 'first', 'abadis'],
        ['جعفر', 'Jafar', 'first', 'abadis'],
        ['قاسم', 'Ghasem', 'first', 'abadis'],
        ['حمیده', 'Hamideh', 'first', 'abadis'],
        ['رسول', 'Rasoul', 'first', 'abadis'],
        ['محدثه', 'Mohaddeseh', 'first', 'abadis'],
        ['عصمت', 'Esmat', 'first', 'abadis'],
        ['سحر', 'Sahar', 'first', 'abadis'],
        ['پروین', 'Parvin', 'first', 'abadis'],
        ['رضا', 'Reza', 'first', 'abadis'],
        // نام‌های خانوادگی رایج
        ['رضایی', 'Rezaei', 'last', 'seed'],
        ['محمدی', 'Mohammadi', 'last', 'seed'],
        ['حسینی', 'Hosseini', 'last', 'seed'],
        ['احمدی', 'Ahmadi', 'last', 'seed'],
        ['کریمی', 'Karimi', 'last', 'seed'],
        ['موسوی', 'Mousavi', 'last', 'seed'],
        ['جعفری', 'Jafari', 'last', 'seed'],
        ['شریفی', 'Sharifi', 'last', 'seed'],
        ['قاسمی', 'Ghasemi', 'last', 'seed'],
        ['اکبری', 'Akbari', 'last', 'seed'],
        ['رحیمی', 'Rahimi', 'last', 'seed'],
        ['نوری', 'Nouri', 'last', 'seed'],
        ['صادقی', 'Sadeghi', 'last', 'seed'],
        ['ملکی', 'Malaki', 'last', 'seed'],
        ['باقری', 'Bagheri', 'last', 'seed'],
        ['شاهرودی', 'Shahroudi', 'last', 'seed'],
        ['شاوردی', 'Shaverdi', 'last', 'seed'],
    ];

    return $rows;
}

function upsert_name_transliteration(
    PDO $pdo,
    string $persian,
    string $latin,
    string $part = 'any',
    string $source = 'seed',
    int $hits = 1,
    bool $increment = false
): void {
    $persian = normalize_persian_name_part($persian);
    $latin = format_latin_name(clean_latin_name($latin));
    if ($persian === '' || $latin === '') {
        return;
    }
    if (!in_array($part, ['first', 'last', 'any'], true)) {
        $part = 'any';
    }

    $stmt = $pdo->prepare('SELECT id, hits FROM name_transliterations WHERE persian=? AND latin=? AND part=? LIMIT 1');
    $stmt->execute([$persian, $latin, $part]);
    $row = $stmt->fetch();
    if ($row) {
        $newHits = $increment ? ((int) $row['hits'] + max(1, $hits)) : max((int) $row['hits'], $hits);
        $pdo->prepare('UPDATE name_transliterations SET hits=?, source=?, updated_at=NOW() WHERE id=?')
            ->execute([$newHits, $source, $row['id']]);
        return;
    }

    $pdo->prepare('INSERT INTO name_transliterations (id,persian,latin,part,source,hits) VALUES (?,?,?,?,?,?)')
        ->execute([cuid(), $persian, $latin, $part, $source, max(1, $hits)]);
}

function sync_name_transliterations_seed(PDO $pdo): void
{
    foreach (name_transliterations_seed_data() as [$persian, $latin, $part, $source]) {
        upsert_name_transliteration($pdo, $persian, $latin, $part, $source, 5, false);
    }
}

function sync_name_transliterations_from_users(PDO $pdo): void
{
    $stmt = $pdo->query("
        SELECT name, username FROM users
        WHERE name IS NOT NULL AND name <> '' AND username IS NOT NULL AND username <> ''
    ");
    foreach ($stmt->fetchAll() as $row) {
        $fullName = trim((string) $row['name']);
        $username = strtolower((string) $row['username']);
        $parts = preg_split('/\s+/u', $fullName) ?: [];
        if (count($parts) === 0 || !preg_match('/^[a-z][a-z0-9._-]{1,31}$/', $username)) {
            continue;
        }

        $firstFa = $parts[0];
        $lastFa = count($parts) > 1 ? $parts[count($parts) - 1] : '';
        $surnameLatin = $lastFa !== '' ? latin_suffix_from_username($username) : null;

        if ($firstFa !== '') {
            if ($lastFa === '' || $surnameLatin === null || !str_ends_with($username, $surnameLatin)) {
                if (strlen($username) >= 3) {
                    upsert_name_transliteration($pdo, $firstFa, $username, 'first', 'user', 8, false);
                }
            }
        }

        if ($lastFa !== '' && $surnameLatin !== null && str_ends_with($username, $surnameLatin)) {
            upsert_name_transliteration($pdo, $lastFa, $surnameLatin, 'last', 'user', 8, false);
        }
    }
}

function lookup_name_transliteration(PDO $pdo, string $persian, string $part = 'first'): ?string
{
    ensure_name_transliterations_schema($pdo);
    $persian = normalize_persian_name_part($persian);
    if ($persian === '') {
        return null;
    }

    $stmt = $pdo->prepare("
        SELECT latin FROM name_transliterations
        WHERE persian = ? AND part IN (?, 'any')
        ORDER BY hits DESC, FIELD(part, ?, 'any'), updated_at DESC
        LIMIT 1
    ");
    $stmt->execute([$persian, $part, $part]);
    $latin = $stmt->fetchColumn();
    if (!$latin) {
        return null;
    }

    return format_latin_name((string) $latin);
}

function remember_name_transliteration(
    PDO $pdo,
    string $persian,
    string $latin,
    string $part = 'first',
    string $source = 'user'
): void {
    ensure_name_transliterations_schema($pdo);
    upsert_name_transliteration($pdo, $persian, $latin, $part, $source, 10, true);
}

function remember_registration_name_transliterations(
    PDO $pdo,
    string $firstName,
    string $lastName,
    string $nameEn,
    string $surname
): void {
    if ($firstName !== '' && $nameEn !== '') {
        remember_name_transliteration($pdo, $firstName, $nameEn, 'first', 'user');
    }
    if ($lastName !== '' && $surname !== '') {
        remember_name_transliteration($pdo, $lastName, $surname, 'last', 'user');
    }
}
