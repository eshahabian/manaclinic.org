<?php
declare(strict_types=1);

function doctor_approach_options(): array
{
    return [
        'cbt' => 'درمان شناختی-رفتاری (CBT)',
        'act' => 'درمان مبتنی بر پذیرش و تعهد (ACT)',
        'schema' => 'طرحواره‌درمانی',
        'dbt' => 'رفتاردرمانی دیالکتیکی (DBT)',
        'psychodynamic' => 'روان‌درمانی روان‌پویشی',
        'psychoanalysis' => 'روان‌کاوی',
        'eft' => 'درمان هیجان‌مدار (EFT)',
        'systemic' => 'خانواده‌درمانی / درمان سیستمی',
        'sfbt' => 'درمان راه‌حل‌محور (SFBT)',
    ];
}

function doctor_domain_options(): array
{
    return [
        'individual' => 'مشاوره فردی',
        'couples' => 'زوج درمانی',
        'premarital' => 'پیش از ازدواج',
        'family' => 'خانواده درمانی',
        'child' => 'کودک و نوجوان',
        'organizational' => 'مشاوره سازمانی',
    ];
}

function doctor_focus_options(): array
{
    return [
        'anxiety' => 'اضطراب',
        'depression' => 'افسردگی',
        'ocd' => 'وسواس',
        'personality' => 'اختلالات شخصیت',
        'eating' => 'اختلال خوردن',
        'trauma' => 'تروما',
        'grief' => 'سوگ و فقدان',
        'panic' => 'حملات پانیک',
        'sexual' => 'مشکلات جنسی',
        'relationships' => 'مشکلات روابط عاطفی',
        'growth' => 'رشد فردی',
    ];
}

function doctor_degree_options(): array
{
    return [
        'phd' => 'دکترا روانشناسی',
        'masters' => 'کارشناسی ارشد روانشناسی',
    ];
}

function ensure_doctor_profile_schema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    foreach ([
        'approaches_json' => 'TEXT NULL',
        'domains_json' => 'TEXT NULL',
        'focus_json' => 'TEXT NULL',
        'license_no' => 'VARCHAR(64) NULL',
        'started_year' => 'SMALLINT NULL',
        'courses' => 'TEXT NULL',
        'degree' => 'VARCHAR(32) NULL',
        'profile_completed' => 'TINYINT(1) NOT NULL DEFAULT 0',
    ] as $col => $ddl) {
        try {
            $has = $pdo->query("SHOW COLUMNS FROM doctor_profiles LIKE " . $pdo->quote($col))->fetch();
            if (!$has) {
                $pdo->exec("ALTER TABLE doctor_profiles ADD COLUMN {$col} {$ddl}");
            }
        } catch (Throwable $ignored) {
        }
    }
    $ready = true;
}

function doctor_profile_json_list(mixed $raw): array
{
    if (is_array($raw)) {
        return array_values(array_filter(array_map('strval', $raw), static fn($v) => $v !== ''));
    }
    $decoded = json_decode((string) $raw, true);
    if (!is_array($decoded)) {
        return [];
    }

    return array_values(array_filter(array_map('strval', $decoded), static fn($v) => $v !== ''));
}

function doctor_profile_filter_keys(array $keys, array $options): array
{
    $allowed = array_keys($options);
    $out = [];
    foreach ($keys as $key) {
        $key = trim((string) $key);
        if ($key !== '' && in_array($key, $allowed, true) && !in_array($key, $out, true)) {
            $out[] = $key;
        }
    }

    return $out;
}

function doctor_profile_labels(array $keys, array $options): array
{
    $labels = [];
    foreach ($keys as $key) {
        if (isset($options[$key])) {
            $labels[] = $options[$key];
        }
    }

    return $labels;
}

function doctor_current_jalali_year(): int
{
    [$jy] = gregorian_to_jalali((int) date('Y'), (int) date('n'), (int) date('j'));

    return (int) $jy;
}

function doctor_experience_years(?int $startedYear): int
{
    $startedYear = (int) $startedYear;
    if ($startedYear < 1330) {
        return 0;
    }

    return max(0, doctor_current_jalali_year() - $startedYear);
}

function doctor_experience_label(?int $startedYear): string
{
    $years = doctor_experience_years($startedYear);
    if ($years < 1) {
        return 'کمتر از یک سال سابقه فعالیت';
    }

    return to_fa_digits((string) $years) . ' سال سابقه فعالیت';
}

function doctor_degree_label(string $degree): string
{
    return doctor_degree_options()[$degree] ?? '';
}

function doctor_courses_list(string $courses): array
{
    $lines = preg_split('/\r\n|\r|\n/', $courses) ?: [];
    $out = [];
    foreach ($lines as $line) {
        $line = trim((string) $line);
        if ($line !== '') {
            $out[] = $line;
        }
    }

    return $out;
}

function doctor_profile_is_complete(array $row): bool
{
    $approaches = doctor_profile_filter_keys(doctor_profile_json_list($row['approaches_json'] ?? ''), doctor_approach_options());
    $domains = doctor_profile_filter_keys(doctor_profile_json_list($row['domains_json'] ?? ''), doctor_domain_options());
    $focus = doctor_profile_filter_keys(doctor_profile_json_list($row['focus_json'] ?? ''), doctor_focus_options());
    $avatar = trim((string) ($row['avatar_url'] ?? ''));
    $license = trim((string) ($row['license_no'] ?? ''));
    $year = (int) ($row['started_year'] ?? 0);
    $courses = doctor_courses_list((string) ($row['courses'] ?? ''));
    $degree = trim((string) ($row['degree'] ?? ''));
    $bio = trim((string) ($row['bio'] ?? ''));
    $bioLen = function_exists('mb_strlen') ? mb_strlen($bio) : strlen($bio);
    $maxYear = doctor_current_jalali_year();

    return $avatar !== ''
        && $approaches !== []
        && $domains !== []
        && $focus !== []
        && $license !== ''
        && $year >= 1330
        && $year <= $maxYear
        && $courses !== []
        && isset(doctor_degree_options()[$degree])
        && $bioLen >= 20;
}

function doctor_is_shiva(?array $user): bool
{
    if (!$user) {
        return false;
    }
    $username = strtolower(trim((string) ($user['username'] ?? '')));
    if (in_array($username, ['shgeranmaye', 'doctor'], true)) {
        return true;
    }

    return str_contains((string) ($user['name'] ?? ''), 'گرانمایه');
}

function doctor_must_complete_profile(PDO $pdo, array $user): bool
{
    if (($user['role'] ?? '') !== 'DOCTOR') {
        return false;
    }
    if (doctor_is_shiva($user)) {
        return false;
    }
    static $cache = [];
    $id = (string) ($user['id'] ?? '');
    if ($id === '') {
        return false;
    }
    if (array_key_exists($id, $cache)) {
        return $cache[$id];
    }
    ensure_doctor_profile_schema($pdo);
    $stmt = $pdo->prepare('SELECT * FROM doctor_profiles WHERE user_id=? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    $cache[$id] = is_array($row) && !doctor_profile_is_complete($row);

    return $cache[$id];
}

function doctor_avatar_src(?string $avatarUrl): string
{
    $avatarUrl = trim((string) $avatarUrl);
    if ($avatarUrl === '') {
        return '';
    }
    if (str_starts_with($avatarUrl, 'http://') || str_starts_with($avatarUrl, 'https://')) {
        return $avatarUrl;
    }

    return url($avatarUrl);
}

function doctor_photo_html(array $doc, string $class = 'doctor-photo'): string
{
    $name = trim((string) ($doc['name'] ?? ''));
    $src = doctor_avatar_src($doc['avatar_url'] ?? null);
    if ($src !== '') {
        return '<img class="' . e($class) . '" src="' . e($src) . '" alt="' . e($name) . '">';
    }
    $initial = $name !== '' ? mb_substr($name, 0, 1) : '؟';

    return '<div class="' . e($class) . ' doctor-photo--fallback" aria-hidden="true">' . e($initial) . '</div>';
}

function doctor_domains_line(array $doc): string
{
    $labels = doctor_profile_labels(
        doctor_profile_filter_keys(doctor_profile_json_list($doc['domains_json'] ?? ''), doctor_domain_options()),
        doctor_domain_options()
    );
    if ($labels !== []) {
        return implode(' · ', $labels);
    }

    return trim((string) ($doc['specialty'] ?? ''));
}

function doctor_chips_html(array $keys, array $options): string
{
    $labels = doctor_profile_labels($keys, $options);
    if ($labels === []) {
        return '';
    }
    $out = '<div class="doctor-chip-row">';
    foreach ($labels as $label) {
        $out .= '<span class="doctor-chip">' . e($label) . '</span>';
    }
    $out .= '</div>';

    return $out;
}

function doctor_card_html(array $doc): string
{
    $name = trim((string) ($doc['name'] ?? ''));
    $href = url('/doctors/' . (string) ($doc['id'] ?? ''));
    $domains = doctor_profile_filter_keys(doctor_profile_json_list($doc['domains_json'] ?? ''), doctor_domain_options());
    $chips = doctor_chips_html($domains, doctor_domain_options());
    if ($chips === '') {
        $legacy = trim((string) ($doc['specialty'] ?? ''));
        if ($legacy !== '') {
            $chips = '<div class="doctor-chip-row"><span class="doctor-chip">' . e($legacy) . '</span></div>';
        }
    }

    ob_start();
    ?>
    <a class="panel card-link doctor-card" href="<?= e($href) ?>">
      <?= doctor_photo_html($doc, 'doctor-photo doctor-card-photo') ?>
      <h3 class="doctor-card-name"><?= e($name) ?></h3>
      <?= $chips ?>
    </a>
    <?php

    return (string) ob_get_clean();
}

function doctor_chip_picker_html(string $field, array $options, array $selected): string
{
    ob_start();
    ?>
    <div class="pick-grid">
      <?php foreach ($options as $key => $label): ?>
        <label class="pick-chip">
          <input type="checkbox" name="<?= e($field) ?>[]" value="<?= e($key) ?>" <?= in_array($key, $selected, true) ? 'checked' : '' ?>>
          <span><?= e($label) ?></span>
        </label>
      <?php endforeach; ?>
    </div>
    <?php

    return (string) ob_get_clean();
}

function doctor_photo_storage_root(): string
{
    $root = dirname(__DIR__) . '/uploads/doctors';
    if (!is_dir($root)) {
        @mkdir($root, 0755, true);
    }

    return $root;
}

function doctor_save_photo(string $doctorProfileId, array $file): string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return '';
    }
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('آپلود عکس ناموفق بود.');
    }
    $mime = function_exists('article_detect_mime')
        ? article_detect_mime((string) $file['tmp_name'])
        : (string) ($file['type'] ?? '');
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (($file['size'] ?? 0) > 5 * 1024 * 1024) {
        throw new RuntimeException('حجم عکس حداکثر ۵ مگابایت باشد.');
    }
    if (!isset($allowed[$mime])) {
        throw new RuntimeException('فرمت عکس باید jpg، png یا webp باشد.');
    }
    doctor_photo_storage_root();
    $name = $doctorProfileId . '-' . substr(cuid(), 0, 8) . '.' . $allowed[$mime];
    $dest = doctor_photo_storage_root() . '/' . $name;
    if (!move_uploaded_file((string) $file['tmp_name'], $dest)) {
        throw new RuntimeException('ذخیره عکس ناموفق بود.');
    }

    return '/uploads/doctors/' . $name;
}

function doctor_delete_photo_file(?string $publicPath): void
{
    $publicPath = (string) $publicPath;
    if ($publicPath === '' || !str_starts_with($publicPath, '/uploads/doctors/')) {
        return;
    }
    $full = doctor_photo_storage_root() . '/' . basename($publicPath);
    if (is_file($full)) {
        @unlink($full);
    }
}
