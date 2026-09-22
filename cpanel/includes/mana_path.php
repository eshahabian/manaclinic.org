<?php
declare(strict_types=1);

$manaPathCatalog = __DIR__ . '/mana_path_catalog.php';
if (is_file($manaPathCatalog)) {
    require_once $manaPathCatalog;
}

function mana_path_beta_usernames(): array
{
    // برای فعال‌سازی عمومی، این فهرست را گسترش بده یا بعداً به تنظیمات پنل وصل کن.
    return ['eshahabian'];
}

function mana_path_user_allowed(?array $user): bool
{
    if (!$user) {
        return false;
    }
    $name = strtolower(trim((string) ($user['username'] ?? '')));
    foreach (mana_path_beta_usernames() as $allowed) {
        if ($name === strtolower($allowed)) {
            return true;
        }
    }
    return false;
}

function mana_path_require_user(array $user): void
{
    if (!mana_path_user_allowed($user)) {
        http_response_code(404);
        require dirname(__DIR__) . '/pages/404.php';
        exit;
    }
}

function ensure_mana_path_schema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    try {
        $pdo->exec("
          CREATE TABLE IF NOT EXISTS mana_path_profiles (
            user_id VARCHAR(32) PRIMARY KEY,
            intro_done TINYINT(1) NOT NULL DEFAULT 0,
            concerns_json TEXT NULL,
            active_tree VARCHAR(32) NULL,
            energy_xp INT NOT NULL DEFAULT 0,
            gentle_streak INT NOT NULL DEFAULT 0,
            rest_days INT NOT NULL DEFAULT 0,
            last_activity_date DATE NULL,
            mood_today TINYINT NULL,
            mood_date DATE NULL,
            world_json TEXT NULL,
            crisis_flag TINYINT(1) NOT NULL DEFAULT 0,
            crisis_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_mana_path_activity (last_activity_date)
          ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $pdo->exec("
          CREATE TABLE IF NOT EXISTS mana_path_trees (
            id VARCHAR(32) PRIMARY KEY,
            user_id VARCHAR(32) NOT NULL,
            tree_id VARCHAR(32) NOT NULL,
            current_index INT NOT NULL DEFAULT 0,
            completed_json TEXT NULL,
            last_score INT NULL,
            last_band VARCHAR(80) NULL,
            high_checkins INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_mana_tree (user_id, tree_id),
            INDEX idx_mana_tree_user (user_id)
          ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $pdo->exec("
          CREATE TABLE IF NOT EXISTS mana_path_events (
            id VARCHAR(32) PRIMARY KEY,
            user_id VARCHAR(32) NOT NULL,
            event_type VARCHAR(40) NOT NULL,
            tree_id VARCHAR(32) NULL,
            step_id VARCHAR(40) NULL,
            payload_json TEXT NULL,
            energy_delta INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_mana_ev_user (user_id, created_at),
            INDEX idx_mana_ev_day (user_id, event_type, created_at)
          ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        try {
            $pdo->exec('ALTER TABLE mana_path_profiles ADD COLUMN companion_gender VARCHAR(8) NULL');
        } catch (Throwable $ignored) {
        }
        $ready = true;
    } catch (Throwable $e) {
        error_log('ManaClinic mana_path schema: ' . $e->getMessage());
    }
}

function mana_path_world_defaults(): array
{
    return [
        'light' => 18,
        'plant' => 8,
        'bed' => 12,
        'window' => 14,
        'desk' => 10,
        'outfit' => 0,
    ];
}

function mana_path_decode_json(?string $raw, array $fallback = []): array
{
    if ($raw === null || trim($raw) === '') {
        return $fallback;
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : $fallback;
}

function mana_path_load_profile(PDO $pdo, string $userId): array
{
    ensure_mana_path_schema($pdo);
    $empty = [
        'user_id' => $userId,
        'intro_done' => 0,
        'concerns_json' => '[]',
        'concerns' => [],
        'active_tree' => null,
        'energy_xp' => 0,
        'gentle_streak' => 0,
        'rest_days' => 0,
        'last_activity_date' => null,
        'mood_today' => null,
        'mood_date' => null,
        'world_json' => null,
        'world' => mana_path_world_defaults(),
        'crisis_flag' => 0,
        'companion_gender' => '',
    ];
    try {
        $stmt = $pdo->prepare('SELECT * FROM mana_path_profiles WHERE user_id = ?');
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        if (!$row) {
            $pdo->prepare('INSERT INTO mana_path_profiles (user_id, world_json) VALUES (?, ?)')
                ->execute([$userId, json_encode(mana_path_world_defaults(), JSON_UNESCAPED_UNICODE)]);
            $stmt->execute([$userId]);
            $row = $stmt->fetch() ?: $empty;
        }
        mana_path_refresh_rest($pdo, $row);
        $stmt->execute([$userId]);
        $row = $stmt->fetch() ?: $row;
        $row['concerns'] = mana_path_decode_json($row['concerns_json'] ?? null);
        $row['world'] = array_merge(mana_path_world_defaults(), mana_path_decode_json($row['world_json'] ?? null));
        $row['companion_gender'] = mana_path_normalize_gender((string) ($row['companion_gender'] ?? ''));
        if ($row['companion_gender'] === '') {
            $row['companion_gender'] = mana_path_normalize_gender((string) ($row['world']['companion_gender'] ?? ''));
        }
        return $row;
    } catch (Throwable $e) {
        error_log('ManaClinic mana_path profile: ' . $e->getMessage());
        return $empty;
    }
}

function mana_path_refresh_rest(PDO $pdo, array $row): void
{
    $today = date('Y-m-d');
    $last = (string) ($row['last_activity_date'] ?? '');
    if ($last === '' || $last === $today) {
        return;
    }
    $days = (int) ((strtotime($today) - strtotime($last)) / 86400);
    if ($days <= 0) {
        return;
    }
    $rest = max(0, $days - 1);
    try {
        $pdo->prepare('UPDATE mana_path_profiles SET rest_days = ? WHERE user_id = ?')
            ->execute([$rest, $row['user_id']]);
    } catch (Throwable $ignored) {
    }
}

function mana_path_xp_need(): int
{
    return 1000;
}

function mana_path_level(int $xp): int
{
    return max(1, intdiv($xp, mana_path_xp_need()) + 1);
}

function mana_path_normalize_gender(?string $gender): string
{
    $g = strtolower(trim((string) $gender));
    return $g === 'female' || $g === 'male' ? $g : '';
}

function mana_path_set_gender(PDO $pdo, array &$profile, string $gender): void
{
    $g = mana_path_normalize_gender($gender);
    if ($g === '') {
        throw new RuntimeException('یک همراه زن یا مرد انتخاب کن.');
    }
    $world = array_merge(mana_path_world_defaults(), $profile['world'] ?? []);
    $world['companion_gender'] = $g;
    $ok = false;
    try {
        $pdo->prepare('UPDATE mana_path_profiles SET companion_gender = ?, world_json = ? WHERE user_id = ?')
            ->execute([$g, json_encode($world, JSON_UNESCAPED_UNICODE), $profile['user_id']]);
        $ok = true;
    } catch (Throwable $ignored) {
    }
    if (!$ok) {
        $pdo->prepare('UPDATE mana_path_profiles SET world_json = ? WHERE user_id = ?')
            ->execute([json_encode($world, JSON_UNESCAPED_UNICODE), $profile['user_id']]);
    }
    $profile['companion_gender'] = $g;
    $profile['world'] = $world;
    if (function_exists('ensure_users_gender_schema')) {
        try {
            ensure_users_gender_schema($pdo);
            $pdo->prepare('UPDATE users SET gender = ? WHERE id = ?')->execute([$g, $profile['user_id']]);
        } catch (Throwable $ignored) {
        }
    }
    if (isset($_SESSION['user']) && (string) ($_SESSION['user']['id'] ?? '') === (string) $profile['user_id']) {
        $_SESSION['user']['gender'] = $g;
    }
}

function mana_path_unlock_items(array $world): array
{
    // TEMP: preview all room items unlocked; restore thresholds after review.
    $previewAllOn = true;
    $plant = (int) ($world['plant'] ?? 0);
    $desk = (int) ($world['desk'] ?? 0);
    $light = (int) ($world['light'] ?? 0);
    $window = (int) ($world['window'] ?? 0);
    $outfit = (int) ($world['outfit'] ?? 0);
    $items = [
        ['id' => 'room', 'label' => 'اتاق خواب', 'icon' => '🛏️', 'on' => true],
        ['id' => 'plant', 'label' => 'گیاه', 'icon' => '🪴', 'on' => $plant >= 20],
        ['id' => 'desk', 'label' => 'میز کار', 'icon' => '🪑', 'on' => $desk >= 35],
        ['id' => 'books', 'label' => 'کتابخانه', 'icon' => '📚', 'on' => $desk >= 55],
        ['id' => 'decor', 'label' => 'دکور', 'icon' => '🎨', 'on' => $outfit >= 1 || $light >= 40],
        ['id' => 'music', 'label' => 'موسیقی', 'icon' => '🎵', 'on' => $light >= 45],
        ['id' => 'window', 'label' => 'پنجره / منظره', 'icon' => '🪟', 'on' => $window >= 40 || $light >= 50],
        ['id' => 'pet', 'label' => 'حیوان خانگی', 'icon' => '🐶', 'on' => $plant >= 50],
    ];
    if ($previewAllOn) {
        foreach ($items as &$item) {
            $item['on'] = true;
        }
        unset($item);
    }
    return $items;
}

function mana_path_companion_state(array $profile): string
{
    if (!empty($profile['crisis_flag'])) {
        return 'crisis';
    }
    $rest = (int) ($profile['rest_days'] ?? 0);
    if ($rest >= 3) {
        return 'waiting';
    }
    if ($rest >= 1) {
        return 'tired';
    }
    $xp = (int) ($profile['energy_xp'] ?? 0);
    return $xp >= 200 ? 'bright' : 'calm';
}

function mana_path_user_trees(PDO $pdo, string $userId): array
{
    try {
        $stmt = $pdo->prepare('SELECT * FROM mana_path_trees WHERE user_id = ? ORDER BY updated_at DESC');
        $stmt->execute([$userId]);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $row['completed'] = mana_path_decode_json($row['completed_json'] ?? null);
            $out[(string) $row['tree_id']] = $row;
        }
        return $out;
    } catch (Throwable $e) {
        error_log('ManaClinic mana_path trees: ' . $e->getMessage());
        return [];
    }
}

function mana_path_ensure_tree(PDO $pdo, string $userId, string $treeId): array
{
    $trees = mana_path_trees();
    if (!isset($trees[$treeId])) {
        throw new RuntimeException('مسیر معتبر نیست.');
    }
    $stmt = $pdo->prepare('SELECT * FROM mana_path_trees WHERE user_id = ? AND tree_id = ?');
    $stmt->execute([$userId, $treeId]);
    $row = $stmt->fetch();
    if (!$row) {
        $id = cuid();
        $pdo->prepare('INSERT INTO mana_path_trees (id, user_id, tree_id, completed_json) VALUES (?,?,?,?)')
            ->execute([$id, $userId, $treeId, '[]']);
        $stmt->execute([$userId, $treeId]);
        $row = $stmt->fetch();
    }
    $row['completed'] = mana_path_decode_json($row['completed_json'] ?? null);
    return $row;
}

function mana_path_apply_world(array $world, array $delta): array
{
    foreach ($delta as $key => $val) {
        if ($key === 'companion_gender') {
            continue;
        }
        if ($key === 'outfit') {
            $world['outfit'] = min(3, max(0, (int) ($world['outfit'] ?? 0) + (int) $val));
            continue;
        }
        $world[$key] = max(0, min(100, (int) ($world[$key] ?? 0) + (int) $val));
    }
    return $world;
}

function mana_path_mark_activity(PDO $pdo, array &$profile, int $xp, array $worldDelta = []): void
{
    $today = date('Y-m-d');
    $last = (string) ($profile['last_activity_date'] ?? '');
    $streak = (int) ($profile['gentle_streak'] ?? 0);
    if ($last !== $today) {
        $streak++;
    }
    $world = array_merge(mana_path_world_defaults(), mana_path_decode_json($profile['world_json'] ?? null, $profile['world'] ?? []));
    $world = mana_path_apply_world($world, $worldDelta);
    $xpTotal = (int) ($profile['energy_xp'] ?? 0) + $xp;
    $pdo->prepare('
      UPDATE mana_path_profiles
      SET energy_xp = ?, gentle_streak = ?, rest_days = 0, last_activity_date = ?, world_json = ?
      WHERE user_id = ?
    ')->execute([$xpTotal, $streak, $today, json_encode($world, JSON_UNESCAPED_UNICODE), $profile['user_id']]);
    $profile['energy_xp'] = $xpTotal;
    $profile['gentle_streak'] = $streak;
    $profile['rest_days'] = 0;
    $profile['last_activity_date'] = $today;
    $profile['world'] = $world;
    $profile['world_json'] = json_encode($world, JSON_UNESCAPED_UNICODE);
}

function mana_path_log(PDO $pdo, string $userId, string $type, int $xp, ?string $treeId = null, ?string $stepId = null, array $payload = []): void
{
    $pdo->prepare('
      INSERT INTO mana_path_events (id, user_id, event_type, tree_id, step_id, payload_json, energy_delta)
      VALUES (?,?,?,?,?,?,?)
    ')->execute([
        cuid(),
        $userId,
        $type,
        $treeId,
        $stepId,
        $payload ? json_encode($payload, JSON_UNESCAPED_UNICODE) : null,
        $xp,
    ]);
}

function mana_path_today_mission_ids(PDO $pdo, string $userId): array
{
    try {
        $stmt = $pdo->prepare("
          SELECT step_id FROM mana_path_events
          WHERE user_id = ? AND event_type = 'mission' AND DATE(created_at) = CURDATE()
        ");
        $stmt->execute([$userId]);
        return array_values(array_filter(array_map(static fn ($r) => (string) ($r['step_id'] ?? ''), $stmt->fetchAll())));
    } catch (Throwable $e) {
        return [];
    }
}

function mana_path_score_screening(string $toolId, array $answers): array
{
    $tools = mana_path_screenings();
    $tool = $tools[$toolId] ?? null;
    if (!$tool) {
        throw new RuntimeException('ابزار غربالگری یافت نشد.');
    }
    $items = $tool['items'];
    $n = count($items);
    if (count($answers) !== $n) {
        throw new RuntimeException('همهٔ سؤال‌ها را پاسخ بده.');
    }
    $score = 0;
    $crisis = false;
    $crisisItems = $tool['crisis_items'] ?? [];
    $reverse = $tool['reverse'] ?? [];
    $maxOpt = 0;
    foreach ($tool['scale'] as $k => $_) {
        $maxOpt = max($maxOpt, (int) $k);
    }
    for ($i = 0; $i < $n; $i++) {
        $v = (int) $answers[$i];
        if ($v < 0 || $v > $maxOpt) {
            throw new RuntimeException('پاسخ نامعتبر است.');
        }
        if (in_array($i, $crisisItems, true) && $v > 0) {
            $crisis = true;
        }
        if (in_array($i, $reverse, true)) {
            $v = $maxOpt - $v;
        }
        $score += $v;
    }
    $band = $tool['bands'][0];
    foreach ($tool['bands'] as $b) {
        $band = $b;
        if ($score <= (int) $b['max']) {
            break;
        }
    }
    return [
        'score' => $score,
        'band' => $band['label'],
        'path' => $band['path'],
        'crisis' => $crisis,
        'tool' => $tool['tool'],
        'title' => $tool['title'],
    ];
}

function mana_path_text_crisis(string $text): bool
{
    $t = mb_strtolower($text);
    $needles = [
        'خودکشی', 'خودکشي', 'بکشم', 'بکشم خودم', 'نمی‌خوام زنده', 'نمیخوام زنده',
        'کاش بمیرم', 'کاش بميرم', 'آسیب به خود', 'آسيب به خود', 'قطع کردن رگ',
        'suicide', 'kill myself', 'want to die',
    ];
    foreach ($needles as $n) {
        if ($n !== '' && mb_strpos($t, $n) !== false) {
            return true;
        }
    }
    return false;
}

function mana_path_set_crisis(PDO $pdo, string $userId, bool $on): void
{
    $pdo->prepare('UPDATE mana_path_profiles SET crisis_flag = ?, crisis_at = ? WHERE user_id = ?')
        ->execute([$on ? 1 : 0, $on ? date('Y-m-d H:i:s') : null, $userId]);
}

function mana_path_save_intro(PDO $pdo, array &$profile, array $concerns, string $gender = ''): void
{
    $valid = array_keys(mana_path_concerns());
    $picked = array_values(array_intersect($valid, $concerns));
    if ($picked === []) {
        throw new RuntimeException('حداقل یک موضوع را انتخاب کن.');
    }
    $primary = $picked[0];
    $pdo->prepare('UPDATE mana_path_profiles SET intro_done = 1, concerns_json = ?, active_tree = ? WHERE user_id = ?')
        ->execute([json_encode($picked, JSON_UNESCAPED_UNICODE), $primary, $profile['user_id']]);
    foreach ($picked as $tid) {
        mana_path_ensure_tree($pdo, (string) $profile['user_id'], $tid);
    }
    $profile['intro_done'] = 1;
    $profile['concerns'] = $picked;
    $profile['active_tree'] = $primary;
    if (mana_path_normalize_gender($gender) !== '') {
        mana_path_set_gender($pdo, $profile, $gender);
    }
}

function mana_path_complete_step(PDO $pdo, array &$profile, string $treeId, string $stepId, array $payload = []): array
{
    $catalog = mana_path_trees();
    if (!isset($catalog[$treeId])) {
        throw new RuntimeException('مسیر یافت نشد.');
    }
    $treeRow = mana_path_ensure_tree($pdo, (string) $profile['user_id'], $treeId);
    $steps = $catalog[$treeId]['steps'];
    $idx = null;
    foreach ($steps as $i => $step) {
        if (($step['id'] ?? '') === $stepId) {
            $idx = $i;
            break;
        }
    }
    if ($idx === null) {
        throw new RuntimeException('مرحله یافت نشد.');
    }
    $current = (int) ($treeRow['current_index'] ?? 0);
    if ($idx > $current) {
        throw new RuntimeException('این مرحله هنوز باز نشده.');
    }
    $completed = $treeRow['completed'];
    if (in_array($stepId, $completed, true)) {
        return ['already' => true];
    }

    $step = $steps[$idx];
    $kind = (string) ($step['kind'] ?? '');
    $xp = (int) ($step['xp'] ?? 20);
    $world = $step['world'] ?? [];
    $extra = [];

    if ($kind === 'screen') {
        $toolId = (string) ($catalog[$treeId]['screening'] ?? '');
        $answers = $payload['answers'] ?? [];
        if (!is_array($answers)) {
            $answers = [];
        }
        $result = mana_path_score_screening($toolId, array_map('intval', array_values($answers)));
        $extra = $result;
        if (!empty($result['crisis'])) {
            mana_path_set_crisis($pdo, (string) $profile['user_id'], true);
        }
        $pdo->prepare('UPDATE mana_path_trees SET last_score = ?, last_band = ? WHERE id = ?')
            ->execute([(int) $result['score'], $result['band'], $treeRow['id']]);
    } elseif ($kind === 'checkin') {
        $score = (int) ($payload['checkin'] ?? -1);
        if ($score < 0 || $score > 10) {
            throw new RuntimeException('عدد ۰ تا ۱۰ را انتخاب کن.');
        }
        $high = (int) ($treeRow['high_checkins'] ?? 0);
        if ($score >= 7 && $treeId !== 'sleep') {
            $high++;
        } elseif ($treeId === 'sleep' && $score <= 3) {
            $high++;
        } else {
            $high = 0;
        }
        $pdo->prepare('UPDATE mana_path_trees SET high_checkins = ? WHERE id = ?')->execute([$high, $treeRow['id']]);
        $extra['checkin'] = $score;
        $extra['suggest_clinic'] = $high >= 2;
    } elseif (in_array($kind, ['practice', 'real', 'lesson'], true)) {
        $note = trim((string) ($payload['note'] ?? ''));
        if ($note !== '' && mana_path_text_crisis($note)) {
            mana_path_set_crisis($pdo, (string) $profile['user_id'], true);
        }
        $extra['note'] = $note;
    }

    $completed[] = $stepId;
    if ($idx >= $current) {
        $current = min($idx + 1, count($steps) - 1);
    }
    $pdo->prepare('UPDATE mana_path_trees SET completed_json = ?, current_index = ? WHERE id = ?')
        ->execute([json_encode($completed, JSON_UNESCAPED_UNICODE), $current, $treeRow['id']]);

    $crisisNow = !empty($extra['crisis']);
    if (!$crisisNow) {
        mana_path_mark_activity($pdo, $profile, $xp, is_array($world) ? $world : []);
    } else {
        $xp = 0;
    }
    mana_path_log($pdo, (string) $profile['user_id'], 'step', $xp, $treeId, $stepId, $extra);
    $pdo->prepare('UPDATE mana_path_profiles SET active_tree = ? WHERE user_id = ?')
        ->execute([$treeId, $profile['user_id']]);

    return ['ok' => true, 'xp' => $xp, 'extra' => $extra, 'crisis' => !empty($profile['crisis_flag']) || !empty($extra['crisis'])];
}

function mana_path_complete_mission(PDO $pdo, array &$profile, string $missionId, array $payload = []): array
{
    $missions = mana_path_daily_missions();
    $found = null;
    foreach ($missions as $m) {
        if ($m['id'] === $missionId) {
            $found = $m;
            break;
        }
    }
    if (!$found) {
        throw new RuntimeException('ماموریت امروز این نیست.');
    }
    $done = mana_path_today_mission_ids($pdo, (string) $profile['user_id']);
    if (in_array($missionId, $done, true)) {
        return ['already' => true];
    }
    $note = trim((string) ($payload['note'] ?? ''));
    if ($note !== '' && mana_path_text_crisis($note)) {
        mana_path_set_crisis($pdo, (string) $profile['user_id'], true);
    }
    $xp = (int) $found['xp'];
    mana_path_mark_activity($pdo, $profile, $xp, $found['world'] ?? []);
    mana_path_log($pdo, (string) $profile['user_id'], 'mission', $xp, null, $missionId, ['note' => $note]);
    return ['ok' => true, 'xp' => $xp];
}

function mana_path_set_mood(PDO $pdo, array &$profile, int $mood): void
{
    if ($mood < 1 || $mood > 5) {
        throw new RuntimeException('حال معتبر نیست.');
    }
    $today = date('Y-m-d');
    $already = (string) ($profile['mood_date'] ?? '') === $today;
    $pdo->prepare('UPDATE mana_path_profiles SET mood_today = ?, mood_date = ? WHERE user_id = ?')
        ->execute([$mood, $today, $profile['user_id']]);
    $xp = $already ? 0 : 5;
    mana_path_mark_activity($pdo, $profile, $xp, $already ? [] : ['light' => 2]);
    mana_path_log($pdo, (string) $profile['user_id'], 'mood', $xp, null, 'mood', ['mood' => $mood]);
}

function mana_path_add_tree(PDO $pdo, array &$profile, string $treeId): void
{
    if (!isset(mana_path_trees()[$treeId])) {
        throw new RuntimeException('مسیر معتبر نیست.');
    }
    $concerns = $profile['concerns'] ?? [];
    if (!in_array($treeId, $concerns, true)) {
        $concerns[] = $treeId;
        $pdo->prepare('UPDATE mana_path_profiles SET concerns_json = ?, active_tree = ? WHERE user_id = ?')
            ->execute([json_encode($concerns, JSON_UNESCAPED_UNICODE), $treeId, $profile['user_id']]);
        $profile['concerns'] = $concerns;
    }
    mana_path_ensure_tree($pdo, (string) $profile['user_id'], $treeId);
    $pdo->prepare('UPDATE mana_path_profiles SET active_tree = ? WHERE user_id = ?')
        ->execute([$treeId, $profile['user_id']]);
    $profile['active_tree'] = $treeId;
}

function mana_path_moods(): array
{
    return [
        1 => ['emoji' => '😟', 'label' => 'خیلی بد'],
        2 => ['emoji' => '😕', 'label' => 'بد'],
        3 => ['emoji' => '😐', 'label' => 'معمولی'],
        4 => ['emoji' => '🙂', 'label' => 'خوب'],
        5 => ['emoji' => '😊', 'label' => 'عالی'],
    ];
}

function mana_path_crisis_html(): string
{
    $tel1 = '09101387838';
    $tel2 = '02122065774';
    ob_start();
    ?>
    <aside class="mpath-crisis" role="alert">
      <h2>اگر در بحران هستی، اول ایمنی</h2>
      <p>اگر فکر آسیب به خود یا خطر فوری داری، همین حالا کمک بگیر. مسیر مانا جایگزین اورژانس یا درمان نیست.</p>
      <p>
        اورژانس: <a href="tel:123" dir="ltr">۱۲۳</a>
        · اورژانس اجتماعی: <a href="tel:123" dir="ltr">۱۲۳</a>
        · مانا کلینیک:
        <a href="tel:<?= e($tel1) ?>" dir="ltr"><?= e(to_fa_digits($tel1)) ?></a>
        ·
        <a href="tel:<?= e($tel2) ?>" dir="ltr"><?= e(to_fa_digits($tel2)) ?></a>
      </p>
      <p class="muted">اگر توانستی، به کسی که در اطرافت امن است بگو. لازم نیست تمرین امروز را تمام کنی.</p>
    </aside>
    <?php
    return (string) ob_get_clean();
}
