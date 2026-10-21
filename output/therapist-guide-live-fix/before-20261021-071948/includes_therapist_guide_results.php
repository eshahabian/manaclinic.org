<?php
declare(strict_types=1);

function therapist_guide_can_read(PDO $pdo, ?array $user): bool
{
    require_once __DIR__ . '/collaboration.php';
    return $user !== null && empty($user['is_disabled']) && collaboration_user_can_read($pdo, $user);
}

function therapist_guide_schema(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS therapist_guide_results (
        id CHAR(64) PRIMARY KEY,
        user_id VARCHAR(32) NULL,
        domain VARCHAR(24) NOT NULL,
        version VARCHAR(24) NOT NULL,
        snapshot_json MEDIUMTEXT NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_guide_created (created_at, id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/** Build the stored snapshot from canonical questions, never client-supplied labels. */
function therapist_guide_snapshot(array $answers): array
{
    $bank = require __DIR__ . '/therapist_guide_questions.php';
    $domain = $answers['domain'] ?? '';
    if (!is_string($domain) || !isset($bank[$domain])) {
        throw new InvalidArgumentException('مسیر انتخاب‌شده معتبر نیست.');
    }
    $questions = $bank[$domain];
    if (count($answers) !== count($questions)) {
        throw new InvalidArgumentException('لطفاً به همه سؤال‌ها پاسخ بده.');
    }
    $rows = [];
    foreach ($questions as $q) {
        $value = $answers[$q['key']] ?? null;
        $options = array_column($q['options'], 1, 0);
        if (!is_string($value) || !array_key_exists($value, $options)) {
            throw new InvalidArgumentException('یکی از پاسخ‌ها معتبر نیست؛ سؤال‌ها را دوباره بررسی کن.');
        }
        $rows[] = ['key' => $q['key'], 'question' => $q['title'], 'value' => $value, 'answer' => $options[$value]];
    }
    $route = $domain === 'child' && $answers['age'] === 'adult' ? 'family'
        : ($domain === 'individual' && in_array($answers['age'], ['child', 'teen'], true) ? 'child' : $domain);
    $weights = [];
    foreach (['anxiety', 'depression', 'ocd'] as $area) {
        $items = array_values(array_filter($questions, static fn(array $q): bool => ($q['area'] ?? '') === $area));
        $values = array_map(static fn(array $q): string => $answers[$q['key']], $items);
        if ($items && count(array_filter($values, static fn(string $v): bool => in_array($v, ['0','1','2','3'], true))) === count($items)) {
            $weights[$area] = min(array_map('intval', $values));
        }
    }
    $highest = $weights ? max($weights) : 0;
    $areas = $highest > 0 ? array_keys(array_filter($weights, static fn(int $weight): bool => $weight === $highest)) : [];
    $descriptions = ['anxiety' => 'آرام‌تر شدن ذهن در موقعیت‌های نامطمئن', 'depression' => 'پیدا کردن ریتم و دل‌چسبی بیشتر در روزمره', 'ocd' => 'راحت‌تر عبور کردن از بررسی و تکرار کارها'];
    $nextSteps = [
        'child' => 'یک موقعیت واقعی از تعامل با فرزندت را به جلسه بیاور تا نیاز او و شیوه همراهی تو با هم بررسی شوند.',
        'couples' => 'برای شروع، یک اختلاف تکرارشونده و انتظار خودت از طرف مقابل را با درمانگر بررسی کن.',
        'premarital' => 'برای شروع، یک تصمیم واقعی پیش رو را بررسی کنید تا انتظارها و تفاوت‌ها روشن‌تر شوند.',
        'family' => 'برای شروع، یک موقعیت خانوادگی مشخص و نقش هر نفر را با درمانگر مرور کن.',
    ];
    if ($domain === 'individual') {
        $summary = [$areas ? 'در پاسخ‌هایت، موضوع «' . implode(' و ', array_map(static fn(string $area): string => $descriptions[$area], $areas)) . '» بیشتر به چشم می‌آید.' : 'پاسخ‌های فعلی برای برجسته کردن یک موضوع مشخص کافی نیستند.',
            'می‌توانی جلسه اول را با یک نمونه از روزمره‌ات شروع کنی تا نیازت روشن‌تر شود.'];
    } else {
        $priority = $rows[7];
        $summary = [$priority['value'] !== 'skip' ? 'از انتخابت پیداست که این خواسته برای شروع مهم‌تر است: «' . $priority['answer'] . '».' : 'هنوز اولویت مشخصی برای شروع انتخاب نکرده‌ای؛ می‌توان آن را در جلسه اول روشن کرد.', $nextSteps[$domain]];
    }
    return ['domain' => $domain, 'route' => $route, 'answers' => $rows, 'summary' => $summary, 'areas' => $areas];
}

function therapist_guide_recommendations(PDO $pdo, array $snapshot): array
{
    require_once __DIR__ . '/clinic_directory.php';
    $matches = [];
    foreach (clinic_public_doctors($pdo) as $doc) {
        if (!in_array($snapshot['route'], clinic_doctor_keys($doc, 'domain'), true)) continue;
        $matches[] = ['id' => (string) $doc['id'], 'name' => (string) $doc['name'],
            'relevance' => count(array_intersect($snapshot['areas'], clinic_doctor_keys($doc, 'focus')))];
    }
    usort($matches, static fn(array $a, array $b): int => $b['relevance'] <=> $a['relevance']);
    return array_map(static fn(array $doc): array => ['id' => $doc['id'], 'name' => $doc['name']], array_slice($matches, 0, 3));
}
