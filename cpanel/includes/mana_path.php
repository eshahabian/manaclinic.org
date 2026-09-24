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

function mana_path_room_live(): bool
{
    // اتاق ذهن ۲ (اتاق تصویری) خاموش است؛ فایل‌ها مانده‌اند تا دوباره روشن شود.
    return false;
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
    $today = mana_path_today_ymd();
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
    // TEMP: keep items unlocked so room rewards can be reviewed; set false for live progression.
    $previewAllOn = true;
    $plant = (int) ($world['plant'] ?? 0);
    $desk = (int) ($world['desk'] ?? 0);
    $light = (int) ($world['light'] ?? 0);
    $window = (int) ($world['window'] ?? 0);
    $outfit = (int) ($world['outfit'] ?? 0);
    $items = [
        ['id' => 'room', 'label' => 'اتاق خواب', 'icon' => '🛏️', 'on' => true, 'prop' => 'layer-room.png'],
        ['id' => 'plant', 'label' => 'گیاه', 'icon' => '🪴', 'on' => $plant >= 20, 'prop' => 'layer-plant.png'],
        ['id' => 'desk', 'label' => 'میز کار', 'icon' => '🪑', 'on' => $desk >= 35, 'prop' => 'layer-desk.png'],
        ['id' => 'books', 'label' => 'کتابخانه', 'icon' => '📚', 'on' => $desk >= 55, 'prop' => 'layer-books.png'],
        ['id' => 'decor', 'label' => 'دکور', 'icon' => '🎨', 'on' => $outfit >= 1 || $light >= 40, 'prop' => 'layer-decor.png'],
        ['id' => 'music', 'label' => 'موسیقی', 'icon' => '🎵', 'on' => $light >= 45],
        ['id' => 'window', 'label' => 'پنجره / منظره', 'icon' => '🪟', 'on' => $window >= 40 || $light >= 50],
        ['id' => 'pet', 'label' => 'حیوان خانگی', 'icon' => '🐶', 'on' => $plant >= 50, 'prop' => 'layer-pet.png'],
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
    $today = mana_path_today_ymd();
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
    $today = mana_path_today_ymd();
    try {
        $stmt = $pdo->prepare("
          SELECT step_id, created_at FROM mana_path_events
          WHERE user_id = ? AND event_type = 'mission'
        ");
        $stmt->execute([$userId]);
        $ids = [];
        foreach ($stmt->fetchAll() as $r) {
            if (!in_array($today, mana_path_event_ymds((string) ($r['created_at'] ?? '')), true)) {
                continue;
            }
            $sid = (string) ($r['step_id'] ?? '');
            if ($sid !== '' && !in_array($sid, $ids, true)) {
                $ids[] = $sid;
            }
        }
        return $ids;
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

function mana_path_add_concerns(PDO $pdo, array &$profile, array $concerns): void
{
    $valid = array_keys(mana_path_concerns());
    $picked = array_values(array_intersect($valid, $concerns));
    if ($picked === []) {
        throw new RuntimeException('حداقل یک موضوع تازه را انتخاب کن.');
    }
    $existing = [];
    foreach ($profile['concerns'] ?? [] as $c) {
        $c = (string) $c;
        if ($c !== '' && !in_array($c, $existing, true)) {
            $existing[] = $c;
        }
    }
    $added = [];
    foreach ($picked as $tid) {
        if (!in_array($tid, $existing, true)) {
            $existing[] = $tid;
            $added[] = $tid;
        }
    }
    if ($added === []) {
        throw new RuntimeException('این موضوع‌ها از قبل در مسیرت هستند.');
    }
    $primary = $existing[0];
    $pdo->prepare('UPDATE mana_path_profiles SET intro_done = 1, concerns_json = ?, active_tree = ? WHERE user_id = ?')
        ->execute([json_encode($existing, JSON_UNESCAPED_UNICODE), $primary, $profile['user_id']]);
    foreach ($added as $tid) {
        mana_path_ensure_tree($pdo, (string) $profile['user_id'], $tid);
    }
    $profile['intro_done'] = 1;
    $profile['concerns'] = $existing;
    $profile['active_tree'] = $primary;
}

function mana_path_set_concerns(PDO $pdo, array &$profile, array $concerns): void
{
    $valid = array_keys(mana_path_concerns());
    $picked = [];
    foreach ($concerns as $c) {
        $c = (string) $c;
        if (isset($valid[$c]) || in_array($c, $valid, true)) {
            if (!in_array($c, $picked, true)) {
                $picked[] = $c;
            }
        }
    }
    if ($picked === []) {
        throw new RuntimeException('حداقل یک دغدغه را انتخاب کن.');
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
    $missions = mana_path_daily_missions($profile['concerns'] ?? []);
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
    $advance = mana_path2_try_advance($pdo, $profile);
    return ['ok' => true, 'xp' => $xp, 'advanced' => $advance];
}

function mana_path_tz(): DateTimeZone
{
    try {
        return new DateTimeZone('Asia/Tehran');
    } catch (Throwable $e) {
        return new DateTimeZone('UTC');
    }
}

function mana_path_now(): DateTimeImmutable
{
    return new DateTimeImmutable('now', mana_path_tz());
}

function mana_path_today_ymd(): string
{
    return mana_path_now()->format('Y-m-d');
}

function mana_path_jalali_parts(?string $ymd = null): array
{
    $dt = $ymd
        ? DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $ymd . ' 12:00:00', mana_path_tz())
        : mana_path_now();
    if (!$dt) {
        $dt = mana_path_now();
    }
    $gy = (int) $dt->format('Y');
    $gm = (int) $dt->format('n');
    $gd = (int) $dt->format('j');
    $jy = $gy;
    $jm = $gm;
    $jd = $gd;
    if (function_exists('gregorian_to_jalali')) {
        [$jy, $jm, $jd] = gregorian_to_jalali($gy, $gm, $gd);
    }
    $months = function_exists('jalali_month_names') ? jalali_month_names() : [];
    $widx = ((int) $dt->format('w') + 1) % 7;
    $wdays = ['شنبه', 'یکشنبه', 'دوشنبه', 'سه‌شنبه', 'چهارشنبه', 'پنجشنبه', 'جمعه'];
    return [
        'ymd' => $dt->format('Y-m-d'),
        'jy' => (int) $jy,
        'jm' => (int) $jm,
        'jd' => (int) $jd,
        'widx' => $widx,
        'wday' => $wdays[$widx] ?? '',
        'month' => (string) ($months[(int) $jm] ?? ''),
        'label' => ($wdays[$widx] ?? '') . ' ' . to_fa_digits((string) $jd) . ' ' . (string) ($months[(int) $jm] ?? '') . ' ' . to_fa_digits((string) $jy),
        'short' => to_fa_digits((string) $jd) . ' ' . (string) ($months[(int) $jm] ?? ''),
    ];
}

function mana_path_event_ymds(string $createdAt): array
{
    $raw = trim($createdAt);
    if ($raw === '') {
        return [mana_path_today_ymd()];
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
        return [$raw];
    }
    $dates = [];
    try {
        $dates[] = (new DateTimeImmutable($raw, mana_path_tz()))->format('Y-m-d');
    } catch (Throwable $ignored) {
    }
    try {
        $asUtc = new DateTimeImmutable($raw, new DateTimeZone('UTC'));
        $dates[] = $asUtc->setTimezone(mana_path_tz())->format('Y-m-d');
    } catch (Throwable $ignored) {
    }
    $dates = array_values(array_unique(array_filter($dates)));
    return $dates !== [] ? $dates : [substr($raw, 0, 10)];
}

function mana_path_event_ymd(string $createdAt): string
{
    $all = mana_path_event_ymds($createdAt);
    return $all[0] ?? mana_path_today_ymd();
}

function mana_path2_required_missions_done(array $doneIds, ?array $concerns = null): bool
{
    $need = mana_path_daily_missions($concerns);
    if ($need === []) {
        return false;
    }
    foreach ($need as $m) {
        if (!in_array((string) ($m['id'] ?? ''), $doneIds, true)) {
            return false;
        }
    }
    return true;
}
function mana_path2_weekdays(?array $concerns = null): array
{
    $days = [
        ['id' => 'sat', 'label' => 'شنبه', 'short' => 'ش', 'focus' => 'چک‌این حال', 'icon' => '◎'],
        ['id' => 'sun', 'label' => 'یکشنبه', 'short' => 'ی', 'focus' => 'شناخت افکار', 'icon' => '🧠'],
        ['id' => 'mon', 'label' => 'دوشنبه', 'short' => 'د', 'focus' => 'شناخت احساسات', 'icon' => '🌱'],
        ['id' => 'tue', 'label' => 'سه‌شنبه', 'short' => 'س', 'focus' => 'تنظیم اضطراب', 'icon' => '🔔'],
        ['id' => 'wed', 'label' => 'چهارشنبه', 'short' => 'چ', 'focus' => 'تمرین مهارت', 'icon' => '✦'],
        ['id' => 'thu', 'label' => 'پنجشنبه', 'short' => 'پ', 'focus' => 'حرکت واقعی', 'icon' => '🚶'],
        ['id' => 'fri', 'label' => 'جمعه', 'short' => 'ج', 'focus' => 'جمع‌بندی روز', 'icon' => '🏆'],
    ];
    $focus = [
        'anxiety' => 'تنظیم اضطراب',
        'mood' => 'فعال‌سازی خلق',
        'stress' => 'کاهش تنش',
        'relationship' => 'جرأت‌مندی در رابطه',
        'sleep' => 'مراقبت از خواب',
        'confidence' => 'مهربانی با خود',
        'procrastination' => 'شروع کوچک کار',
    ];
    $wanted = [];
    $valid = array_keys(mana_path_concerns());
    foreach ($concerns ?? [] as $c) {
        $c = (string) $c;
        if (in_array($c, $valid, true) && !in_array($c, $wanted, true)) {
            $wanted[] = $c;
        }
    }
    if ($wanted === []) {
        return $days;
    }
    foreach ($days as $i => &$day) {
        $key = $wanted[$i % count($wanted)];
        $day['focus'] = $focus[$key] ?? $day['focus'];
        $day['concern'] = $key;
    }
    unset($day);
    return $days;
}

function mana_path2_weekday_index(?string $ymd = null): int
{
    return (int) (mana_path_jalali_parts($ymd)['widx'] ?? 0);
}

function mana_path2_week_start(?string $ymd = null): string
{
    $parts = mana_path_jalali_parts($ymd);
    $idx = (int) ($parts['widx'] ?? 0);
    $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', ($parts['ymd'] ?? mana_path_today_ymd()) . ' 12:00:00', mana_path_tz());
    if (!$dt) {
        $dt = mana_path_now();
    }
    return $dt->modify('-' . $idx . ' days')->format('Y-m-d');
}

function mana_path2_activity_dates(PDO $pdo, string $userId, string $from, string $to): array
{
    $out = [];
    try {
        $fromDt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $from . ' 00:00:00', mana_path_tz()) ?: mana_path_now();
        $toDt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $to . ' 00:00:00', mana_path_tz()) ?: mana_path_now();
        // حاشیهٔ ۲ روز: DATETIME یوتی‌سی حوالی نیمه‌شب تهران از پنجرهٔ DATE() جا نماند.
        $sqlFrom = $fromDt->modify('-2 days')->format('Y-m-d H:i:s');
        $sqlTo = $toDt->modify('+3 days')->format('Y-m-d H:i:s');
        $stmt = $pdo->prepare("
          SELECT created_at, event_type, step_id
          FROM mana_path_events
          WHERE user_id = ?
            AND created_at >= ?
            AND created_at < ?
            AND event_type IN ('mission', 'mood', 'path2_day', 'step')
          ORDER BY created_at ASC
        ");
        $stmt->execute([$userId, $sqlFrom, $sqlTo]);
        foreach ($stmt->fetchAll() as $row) {
            $dates = mana_path_event_ymds((string) ($row['created_at'] ?? ''));
            $type = (string) ($row['event_type'] ?? '');
            $sid = (string) ($row['step_id'] ?? '');
            foreach ($dates as $d) {
                if ($d === '' || $d < $from || $d > $to) {
                    continue;
                }
                if (!isset($out[$d])) {
                    $out[$d] = ['mission' => [], 'mood' => 0, 'path2_day' => 0, 'step' => 0];
                }
                if ($type === 'mission') {
                    if ($sid !== '' && !in_array($sid, $out[$d]['mission'], true)) {
                        $out[$d]['mission'][] = $sid;
                    }
                } elseif (isset($out[$d][$type])) {
                    $out[$d][$type]++;
                }
            }
        }
    } catch (Throwable $e) {
        return [];
    }
    return $out;
}

function mana_path2_day_complete(array $dayRow, bool $requireAllMissions = false): bool
{
    $missions = $dayRow['mission'] ?? [];
    if ($requireAllMissions) {
        return count($missions) >= count(mana_path_daily_missions());
    }
    return count($missions) > 0 || !empty($dayRow['path2_day']) || !empty($dayRow['mood']) || !empty($dayRow['step']);
}

function mana_path2_week_status(PDO $pdo, string $userId, int $streak = 0, ?array $concerns = null): array
{
    unset($streak); // سبز کردن با streak ممنوع؛ فقط رویداد واقعی روز.
    $days = mana_path2_weekdays($concerns);
    $start = mana_path2_week_start();
    $todayIdx = mana_path2_weekday_index();
    $today = mana_path_today_ymd();
    $startDt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $start . ' 12:00:00', mana_path_tz()) ?: mana_path_now();
    $end = $startDt->modify('+6 days')->format('Y-m-d');
    $byDate = mana_path2_activity_dates($pdo, $userId, $start, $end);
    $todayMissions = mana_path_today_mission_ids($pdo, $userId);
    $todayAll = mana_path2_required_missions_done($todayMissions, $concerns);
    $out = [];
    foreach ($days as $i => $day) {
        $ymd = $startDt->modify('+' . $i . ' days')->format('Y-m-d');
        $j = mana_path_jalali_parts($ymd);
        $row = $byDate[$ymd] ?? ['mission' => [], 'mood' => 0, 'path2_day' => 0, 'step' => 0];
        $isToday = $i === $todayIdx || $ymd === $today;
        if ($isToday) {
            $done = $todayAll || !empty($row['path2_day']) || count($row['mission'] ?? []) > 0;
        } elseif ($i < $todayIdx) {
            $done = mana_path2_day_complete($row, false);
        } else {
            $done = false;
        }
        $out[] = [
            'id' => $day['id'],
            'label' => $day['label'],
            'short' => $day['short'],
            'focus' => $day['focus'],
            'icon' => $day['icon'],
            'ymd' => $ymd,
            'jlabel' => (string) ($j['short'] ?? ''),
            'done' => $done,
            'today' => $isToday,
            'now' => $isToday && !$done,
            'future' => $i > $todayIdx && !$isToday,
        ];
    }
    return $out;
}

function mana_path2_month_history(PDO $pdo, string $userId): array
{
    $titles = [];
    foreach (mana_path_mission_catalog() as $id => $m) {
        $titles[(string) $id] = (string) ($m['title'] ?? $id);
    }
    $pool = [
        'mood' => 'ثبت حال',
    ];
    $titles = array_merge($pool, $titles);
    $nowJ = mana_path_jalali_parts();
    $jy = (int) $nowJ['jy'];
    $jm = (int) $nowJ['jm'];
    $jd = (int) $nowJ['jd'];
    $monthLen = function_exists('jalali_month_length') ? jalali_month_length($jy, $jm) : 31;
    $monthName = function_exists('jalali_month_names') ? (jalali_month_names()[$jm] ?? '') : '';
    if (function_exists('jalali_to_gregorian')) {
        [$gy1, $gm1, $gd1] = jalali_to_gregorian($jy, $jm, 1);
        [$gy2, $gm2, $gd2] = jalali_to_gregorian($jy, $jm, $monthLen);
        $from = sprintf('%04d-%02d-%02d', $gy1, $gm1, $gd1);
        $to = sprintf('%04d-%02d-%02d', $gy2, $gm2, $gd2);
    } else {
        $from = date('Y-m-01');
        $to = date('Y-m-t');
    }
    $byDate = mana_path2_activity_dates($pdo, $userId, $from, $to);
    $weekdays = mana_path2_weekdays();
    $days = [];
    krsort($byDate);
    foreach ($byDate as $ymd => $row) {
        $items = [];
        foreach ($row['mission'] as $mid) {
            $items[] = $titles[$mid] ?? $mid;
        }
        if (!empty($row['mood'])) {
            $items[] = 'ثبت حال';
        }
        if ($items === []) {
            continue;
        }
        $widx = mana_path2_weekday_index($ymd);
        $jLabel = $ymd;
        if (function_exists('gregorian_to_jalali')) {
            $ts = strtotime($ymd . ' 12:00:00') ?: time();
            [$ay, $am, $ad] = gregorian_to_jalali((int) date('Y', $ts), (int) date('n', $ts), (int) date('j', $ts));
            $jLabel = to_fa_digits((string) $ad) . ' ' . (function_exists('jalali_month_names') ? (jalali_month_names()[$am] ?? '') : '');
        }
        $days[] = [
            'ymd' => $ymd,
            'label' => $jLabel,
            'weekday' => $weekdays[$widx]['label'] ?? '',
            'items' => $items,
        ];
    }
    return [
        'title' => trim($monthName . ' ' . (function_exists('to_fa_digits') ? to_fa_digits((string) $jy) : (string) $jy)),
        'days' => $days,
        'from' => $from,
        'to' => $to,
        'jy' => $jy,
        'jm' => $jm,
        'jd' => $jd,
        'month_len' => $monthLen,
        'month_name' => $monthName,
    ];
}

function mana_path2_report_axes_for(array $concerns): array
{
    $all = mana_path_concerns();
    $picked = [];
    foreach ($concerns as $c) {
        $c = (string) $c;
        if (isset($all[$c])) {
            $picked[] = ['key' => $c, 'label' => (string) $all[$c]['label']];
        }
    }
    if ($picked === []) {
        $picked[] = ['key' => 'anxiety', 'label' => (string) $all['anxiety']['label']];
    }
    return $picked;
}

function mana_path2_month_notes(PDO $pdo, string $userId, string $from, string $to): array
{
    $out = [];
    try {
        $stmt = $pdo->prepare("
          SELECT created_at, step_id, payload_json
          FROM mana_path_events
          WHERE user_id = ? AND event_type = 'mission'
            AND created_at >= ? AND created_at < ?
          ORDER BY created_at ASC
        ");
        $fromDt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $from . ' 00:00:00', mana_path_tz()) ?: mana_path_now();
        $toDt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $to . ' 00:00:00', mana_path_tz()) ?: mana_path_now();
        $stmt->execute([
            $userId,
            $fromDt->modify('-2 days')->format('Y-m-d H:i:s'),
            $toDt->modify('+3 days')->format('Y-m-d H:i:s'),
        ]);
        foreach ($stmt->fetchAll() as $row) {
            $payload = mana_path_decode_json($row['payload_json'] ?? null);
            $note = trim((string) ($payload['note'] ?? ''));
            if ($note === '') {
                continue;
            }
            $d = mana_path_event_ymd((string) ($row['created_at'] ?? ''));
            if ($d < $from || $d > $to) {
                continue;
            }
            $out[] = [
                'src' => 'mission',
                'date' => $d,
                'text' => $note,
            ];
        }
    } catch (Throwable $ignored) {
    }
    $journalFile = __DIR__ . '/patient_journal.php';
    if (is_file($journalFile)) {
        require_once $journalFile;
        try {
            if (function_exists('patient_journal_fetch_range')) {
                foreach (patient_journal_fetch_range($pdo, $userId, $from, $to) as $j) {
                    $body = trim((string) ($j['body'] ?? ''));
                    if ($body === '') {
                        continue;
                    }
                    $out[] = [
                        'src' => 'journal',
                        'date' => (string) ($j['entry_date'] ?? ''),
                        'text' => $body,
                    ];
                }
            }
        } catch (Throwable $ignored) {
        }
    }
    return $out;
}

function mana_path2_notes_ai(PDO $pdo, array &$profile, array $axes, array $notes, string $monthKey): array
{
    $empty = ['scores' => [], 'summary' => '', 'count' => count($notes)];
    if ($notes === []) {
        $empty['summary'] = 'این ماه یادداشت روزانه کمی ثبت شده. با نوشتن در کارهای امروز یا Journal، تحلیل دقیق‌تر می‌شود.';
        return $empty;
    }
    $bits = [];
    foreach (array_slice($notes, 0, 40) as $n) {
        $bits[] = ((string) ($n['date'] ?? '')) . ' — ' . mb_substr((string) ($n['text'] ?? ''), 0, 280);
    }
    $blob = implode("\n", $bits);
    if (function_exists('mb_substr')) {
        $blob = mb_substr($blob, 0, 4500);
    } else {
        $blob = substr($blob, 0, 4500);
    }
    $hash = sha1($monthKey . '|' . implode(',', array_column($axes, 'key')) . '|' . $blob);
    $world = array_merge(mana_path_world_defaults(), $profile['world'] ?? []);
    $cached = is_array($world['report_note_ai'] ?? null) ? $world['report_note_ai'] : [];
    if (($cached['hash'] ?? '') === $hash && is_array($cached['scores'] ?? null)) {
        return [
            'scores' => $cached['scores'],
            'summary' => (string) ($cached['summary'] ?? ''),
            'count' => count($notes),
        ];
    }
    $assistantFile = __DIR__ . '/assistant.php';
    if (!is_file($assistantFile)) {
        return $empty;
    }
    require_once $assistantFile;
    if (!function_exists('assistant_ai_available') || !assistant_ai_available()) {
        $empty['summary'] = 'تحلیل یادداشت‌ها فعلاً در دسترس نیست.';
        return $empty;
    }
    $axisLine = [];
    foreach ($axes as $ax) {
        $axisLine[] = (string) ($ax['key'] ?? '') . '=' . (string) ($ax['label'] ?? '');
    }
    $prompt = "تو تحلیل‌گر غربالگری مسیر درمان «مانا کلینیک» هستی. تشخیص قطعی نده.\n"
        . "فقط JSON معتبر برگردان، بدون توضیح اضافه و بدون markdown.\n"
        . "محورهای مجاز: " . implode('، ', $axisLine) . "\n"
        . "برای هر محور عدد 0 تا 100 بده: شدت حضور آن دغدغه در متن یادداشت‌ها.\n"
        . "خلاصه حداکثر ۳ جمله فارسی، همدلانه و غیرتشخیصی.\n"
        . "قالب: {\"scores\":{\"<key>\":0},\"summary\":\"...\"}\n\n"
        . "یادداشت‌های این ماه:\n" . $blob;
    try {
        $raw = assistant_ai_chat([
            ['role' => 'system', 'content' => 'فقط JSON برگردان. تشخیص اختلال نده.'],
            ['role' => 'user', 'content' => $prompt],
        ], 500);
        $raw = trim($raw);
        if (str_starts_with($raw, '```')) {
            $raw = preg_replace('/^```(?:json)?\s*/i', '', $raw) ?? $raw;
            $raw = preg_replace('/\s*```$/', '', $raw) ?? $raw;
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return $empty;
        }
        $scores = [];
        $allowed = [];
        foreach ($axes as $ax) {
            $allowed[(string) $ax['key']] = true;
        }
        foreach ((array) ($data['scores'] ?? []) as $k => $v) {
            $k = (string) $k;
            if (!isset($allowed[$k])) {
                continue;
            }
            $scores[$k] = max(0, min(100, (int) round((float) $v)));
        }
        $summary = trim((string) ($data['summary'] ?? ''));
        $out = ['scores' => $scores, 'summary' => $summary, 'count' => count($notes)];
        $world['report_note_ai'] = ['hash' => $hash, 'scores' => $scores, 'summary' => $summary];
        try {
            $pdo->prepare('UPDATE mana_path_profiles SET world_json = ? WHERE user_id = ?')
                ->execute([json_encode($world, JSON_UNESCAPED_UNICODE), $profile['user_id']]);
        } catch (Throwable $ignored) {
        }
        $profile['world'] = $world;
        $profile['world_json'] = json_encode($world, JSON_UNESCAPED_UNICODE);
        return $out;
    } catch (Throwable $e) {
        $empty['summary'] = 'تحلیل یادداشت‌ها این بار انجام نشد. بعداً دوباره صفحه را باز کن.';
        return $empty;
    }
}

function mana_path2_report_data(PDO $pdo, array $profile): array
{
    $userId = (string) ($profile['user_id'] ?? '');
    $hist = mana_path2_month_history($pdo, $userId);
    $concerns = $profile['concerns'] ?? [];
    if (!is_array($concerns) || $concerns === []) {
        $concerns = ['anxiety'];
    }
    $all = mana_path_concerns();
    $primary = (string) $concerns[0];
    $primaryLabel = (string) ($all[$primary]['label'] ?? 'اضطراب');
    $trees = mana_path_user_trees($pdo, $userId);
    $screenMax = [
        'anxiety' => 21,
        'mood' => 27,
        'stress' => 16,
        'sleep' => 16,
        'relationship' => 15,
        'confidence' => 15,
        'procrastination' => 15,
    ];
    $missionAxis = [
        'breathe' => ['anxiety', 'stress'],
        'unhook' => ['anxiety'],
        'thoughts' => ['anxiety', 'mood', 'procrastination'],
        'walk' => ['anxiety', 'mood', 'stress', 'procrastination'],
        'feelings' => ['mood', 'relationship'],
        'activation' => ['mood', 'procrastination'],
        'bodyscan' => ['stress', 'anxiety'],
        'assert' => ['relationship', 'confidence'],
        'needtalk' => ['relationship'],
        'sleep' => ['sleep', 'stress'],
        'winddown' => ['sleep'],
        'kind' => ['confidence', 'mood'],
        'evidence' => ['confidence'],
        'start2' => ['procrastination'],
    ];
    $missionHits = [];
    $moodSum = 0;
    $moodN = 0;
    $missionN = 0;
    try {
        $fromDt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', (string) $hist['from'] . ' 00:00:00', mana_path_tz()) ?: mana_path_now();
        $toDt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', (string) $hist['to'] . ' 00:00:00', mana_path_tz()) ?: mana_path_now();
        $stmt = $pdo->prepare("
          SELECT event_type, step_id, payload_json, created_at
          FROM mana_path_events
          WHERE user_id = ? AND created_at >= ? AND created_at < ?
            AND event_type IN ('mission', 'mood')
        ");
        $stmt->execute([
            $userId,
            $fromDt->modify('-2 days')->format('Y-m-d H:i:s'),
            $toDt->modify('+3 days')->format('Y-m-d H:i:s'),
        ]);
        $histFrom = (string) $hist['from'];
        $histTo = (string) $hist['to'];
        foreach ($stmt->fetchAll() as $row) {
            $d = mana_path_event_ymd((string) ($row['created_at'] ?? ''));
            if ($d < $histFrom || $d > $histTo) {
                continue;
            }
            if ((string) $row['event_type'] === 'mission') {
                $missionN++;
                $sid = (string) ($row['step_id'] ?? '');
                foreach ($missionAxis[$sid] ?? [] as $k) {
                    $missionHits[$k] = ($missionHits[$k] ?? 0) + 1;
                }
            } else {
                $payload = mana_path_decode_json($row['payload_json'] ?? null);
                $mv = (int) ($payload['mood'] ?? 0);
                if ($mv >= 1 && $mv <= 5) {
                    $moodSum += $mv;
                    $moodN++;
                }
            }
        }
    } catch (Throwable $ignored) {
    }
    $moodAvg = $moodN > 0 ? ($moodSum / $moodN) : 3;
    $moodLoad = (int) round((5 - $moodAvg) * 18);
    $axesMeta = mana_path2_report_axes_for($concerns);
    $monthNotes = mana_path2_month_notes($pdo, $userId, (string) $hist['from'], (string) $hist['to']);
    $monthKey = (string) ($hist['jy'] ?? '') . '-' . (string) ($hist['jm'] ?? '');
    $noteAi = mana_path2_notes_ai($pdo, $profile, $axesMeta, $monthNotes, $monthKey);
    $axes = [];
    foreach ($axesMeta as $i => $ax) {
        $key = (string) $ax['key'];
        $treeKey = $key;
        if ($key === 'worry' || $key === 'body' || $key === 'fear' || $key === 'focus') {
            $treeKey = 'anxiety';
        }
        if ($key === 'energy') {
            $treeKey = 'mood';
        }
        if ($key === 'assert') {
            $treeKey = 'relationship';
        }
        $you = 42 + $moodLoad;
        if (isset($trees[$treeKey]['last_score'])) {
            $max = $screenMax[$treeKey] ?? 21;
            $you = (int) round(100 * ((int) $trees[$treeKey]['last_score']) / max(1, $max));
        }
        $you += (int) min(18, ($missionHits[$key] ?? 0) * 4);
        if (in_array($key, $concerns, true) || $key === $primary) {
            $you += 8;
        }
        if (isset($noteAi['scores'][$key])) {
            $you = (int) round($you * 0.65 + ((int) $noteAi['scores'][$key]) * 0.35);
        }
        $you = max(12, min(96, $you));
        $axes[] = [
            'key' => $key,
            'label' => (string) $ax['label'],
            'you' => $you,
        ];
    }
    $score = 0;
    foreach ($axes as $ax) {
        $score += (int) $ax['you'];
    }
    $score = (int) round($score / max(1, count($axes)));
    $band = 'خیلی کم';
    if ($score > 20) {
        $band = 'کم';
    }
    if ($score > 40) {
        $band = 'متوسط';
    }
    if ($score > 60) {
        $band = 'نسبتاً بالا';
    }
    if ($score > 80) {
        $band = 'بسیار بالا';
    }
    $elapsed = max(1, min((int) ($hist['jd'] ?? 1), (int) ($hist['month_len'] ?? 30)));
    $expected = $elapsed * 3;
    $accuracy = (int) min(100, round(100 * $missionN / max(1, $expected)));
    $top = $axes;
    usort($top, static fn($a, $b) => ($b['you'] <=> $a['you']));
    $topNames = array_map(static fn($a) => $a['label'], array_slice($top, 0, 3));
    $analysis = 'بر اساس پاسخ‌ها و کارهای این ماه، سطح ' . $primaryLabel . ' در محدودهٔ «' . $band . '» قرار دارد. بیشترین نشانه‌ها در بخش '
        . implode('، ', $topNames) . ' دیده می‌شود. این گزارش غربالگری مسیر است، نه تشخیص.';
    $recs = [];
    foreach (array_slice($top, 0, 4) as $row) {
        $map = [
            'anxiety' => ['title' => 'مشاوره تخصصی', 'text' => 'اگر اضطراب روزها را تنگ کرده، جلسه با درمانگر مانا کمک می‌کند.', 'tone' => 'purple'],
            'worry' => ['title' => 'ثبت نگرانی', 'text' => 'هر روز یک نگرانی را بنویس و همان را کوچک کن.', 'tone' => 'blue'],
            'body' => ['title' => 'فعالیت بدنی منظم', 'text' => 'روزانه ۱۰ تا ۲۰ دقیقه حرکت ملایم تنش جسمی را کم می‌کند.', 'tone' => 'blue'],
            'fear' => ['title' => 'مواجههٔ تدریجی', 'text' => 'یک موقعیت کوچک امن را انتخاب کن و قدم‌به‌قدم نزدیک شو.', 'tone' => 'yellow'],
            'sleep' => ['title' => 'بهبود کیفیت خواب', 'text' => 'روتین آرام شب، نور کمتر و گوشی دورتر.', 'tone' => 'yellow'],
            'focus' => ['title' => 'تمرکز کوتاه', 'text' => 'بازهٔ ۱۰ دقیقه‌ای بدون حواس‌پرتی را تمرین کن.', 'tone' => 'green'],
            'mood' => ['title' => 'فعال‌سازی رفتاری', 'text' => 'یک کار کوچک معنادار در برنامهٔ روزانه بگذار.', 'tone' => 'green'],
            'stress' => ['title' => 'بازیابی روزانه', 'text' => 'چهار دقیقه تنفس و یک استراحت کوتاه بین کارها.', 'tone' => 'purple'],
            'relationship' => ['title' => 'جرأت‌مندی ملایم', 'text' => 'یک خواسته را شفاف و آرام بیان کن.', 'tone' => 'blue'],
            'confidence' => ['title' => 'جمله مهربان', 'text' => 'همان حرفی که به دوستت می‌زدی را به خودت بگو.', 'tone' => 'green'],
            'procrastination' => ['title' => 'شروع ۲ دقیقه‌ای', 'text' => 'کوچک‌ترین تکهٔ کار را همین امروز بردار.', 'tone' => 'yellow'],
            'energy' => ['title' => 'انرژی کم‌حجم', 'text' => 'خواب، نور صبح و یک پیاده‌روی کوتاه.', 'tone' => 'green'],
            'assert' => ['title' => 'تمرین جمله صادقانه', 'text' => 'پیش‌نویس یک حدومرز کوتاه برای موقعیت پرتنش.', 'tone' => 'blue'],
        ];
        $recs[] = $map[$row['key']] ?? ['title' => $row['label'], 'text' => 'تمرین روزانه این محور را در اتاق ذهن ادامه بده.', 'tone' => 'purple'];
    }
    $jDate = to_fa_digits((string) ($hist['jy'] ?? '')) . '/' . to_fa_digits(sprintf('%02d', (int) ($hist['jm'] ?? 1))) . '/' . to_fa_digits(sprintf('%02d', (int) ($hist['jd'] ?? 1)));
    return [
        'title' => 'نتیجه گزارش ماهانه ' . $primaryLabel,
        'primary' => $primary,
        'primary_label' => $primaryLabel,
        'lead' => 'این گزارش روی دغدغه‌هایی که در شروع اتاق ذهن انتخاب کردی بنا شده؛ کارهای روزانه، خلق، و تحلیل یادداشت‌هایت در ' . ($hist['title'] ?? 'این ماه') . ' روی نمودار اثر می‌گذارند.',
        'test_name' => 'گزارش ماهانه مسیر ' . $primaryLabel,
        'questions' => max(3, $missionN),
        'minutes' => max(8, min(25, 4 * count($hist['days']))),
        'date' => $jDate,
        'month_title' => (string) ($hist['title'] ?? ''),
        'score' => $score,
        'band' => $band,
        'accuracy' => $accuracy,
        'accuracy_done' => $missionN,
        'accuracy_need' => $expected,
        'axes' => $axes,
        'concerns' => $concerns,
        'note_ai' => $noteAi,
        'analysis' => $analysis,
        'recs' => $recs,
        'history' => $hist,
    ];
}

function mana_path2_consult_pack(array $report): array
{
    $primary = (string) ($report['primary'] ?? 'anxiety');
    $topicId = $primary === 'relationship' ? 'couples' : 'individual';
    $topic = function_exists('assistant_topic_by_id') ? assistant_topic_by_id($topicId) : null;
    $topicLabel = is_array($topic) ? (string) $topic['label'] : ($topicId === 'couples' ? 'زوج درمانی' : 'مشاوره فردی');
    $label = (string) ($report['primary_label'] ?? 'اضطراب');
    $band = (string) ($report['band'] ?? 'متوسط');
    $score = (int) ($report['score'] ?? 0);
    $month = (string) ($report['month_title'] ?? '');
    $histDays = $report['history']['days'] ?? [];
    $doneBits = [];
    foreach (array_slice($histDays, 0, 8) as $day) {
        $items = $day['items'] ?? [];
        if ($items !== []) {
            $doneBits[] = (string) ($day['weekday'] ?? '') . ' ' . (string) ($day['label'] ?? '') . ': ' . implode('، ', $items);
        }
    }
    $axesLine = [];
    foreach ($report['axes'] ?? [] as $ax) {
        $axesLine[] = (string) ($ax['label'] ?? '') . ' ' . (int) ($ax['you'] ?? 0) . '٪';
    }
    $tips = [];
    foreach (array_slice($report['recs'] ?? [], 0, 3) as $rec) {
        $tips[] = (string) ($rec['title'] ?? '') . ': ' . (string) ($rec['text'] ?? '');
    }
    $questions = [
        'این ماه کدام موقعیت بیشتر ' . $label . ' را بالا می‌برد؟',
        'وقتی شدت بالا می‌رود معمولاً چه کار می‌کنی — و کدام‌یک کمکت می‌کند؟',
        'از کارهای روزانه اتاق ذهن، کدام برایت مفیدتر بود؟',
        'امشب یا فردا یک قدم خیلی کوچک چه می‌تواند باشد؟',
    ];
    if (in_array($primary, ['sleep'], true) || str_contains(implode(' ', $axesLine), 'خواب')) {
        array_splice($questions, 1, 0, ['الگوی خواب این ماه چطور بوده: به‌خواب رفتن، بیدار شدن، یا هر دو؟']);
        $questions = array_slice($questions, 0, 5);
    }
    if ($primary === 'relationship') {
        $questions[0] = 'در رابطه‌ات کدام بخش بیشتر فشار می‌آورد: حرف زدن، حدومرز، یا فاصله؟';
    }
    if (in_array($band, ['نسبتاً بالا', 'بسیار بالا'], true)) {
        $questions[] = 'آیا کسی در اطرافت از این وضعیت خبر دارد که بتوانی به او تکیه کنی؟';
    }
    $brief = 'موضوع اصلی مسیر: ' . $label . "\n"
        . 'سطح غربالگری این ماه: ' . $band . ' (امتیاز ' . $score . " از ۱۰۰)\n"
        . 'ماه: ' . $month . "\n"
        . 'محورهای نمودار: ' . implode('، ', $axesLine) . "\n"
        . 'تحلیل یادداشت‌ها: ' . (string) ($report['note_ai']['summary'] ?? '') . "\n"
        . "کارهای ثبت‌شده:\n" . ($doneBits !== [] ? implode("\n", $doneBits) : 'هنوز کار روزانه کمی ثبت شده.') . "\n"
        . "پیشنهادهای گزارش:\n" . implode("\n", $tips);
    $firstQ = $questions[0] ?? 'الان بیشتر دوست داری از کجا شروع کنیم؟';
    $opening = 'سلام. گزارش ماهانه اتاق ذهن را دیدم. سطح ' . $label . ' در محدودهٔ «' . $band . '» است. '
        . 'این تشخیص نیست؛ یک غربالگری مسیر است.' . "\n"
        . ($tips !== [] ? ('برای شروع، این‌ها را می‌توانی همین امروز کوچک نگه داری: ' . (string) (($report['recs'][0]['text'] ?? 'یک تمرین کوتاه تنفس یا ثبت یک فکر.'))) : '')
        . "\n\nبرای مشاورهٔ کوتاه، از این سوال شروع می‌کنیم:\n" . $firstQ;
    return [
        'topic_id' => $topicId,
        'topic_label' => $topicLabel,
        'brief' => $brief,
        'questions' => $questions,
        'opening' => $opening,
    ];
}

function mana_path2_radar_points(array $values, float $cx, float $cy, float $rMax): string
{
    $n = max(1, count($values));
    $pts = [];
    for ($i = 0; $i < $n; $i++) {
        $ang = deg2rad(-90 + ($i * (360 / $n)));
        $v = max(0, min(100, (float) $values[$i])) / 100;
        $pts[] = round($cx + cos($ang) * $rMax * $v, 1) . ',' . round($cy + sin($ang) * $rMax * $v, 1);
    }
    return implode(' ', $pts);
}

function mana_path2_try_advance(PDO $pdo, array &$profile): bool
{
    $userId = (string) $profile['user_id'];
    $doneM = mana_path_today_mission_ids($pdo, $userId);
    foreach (mana_path_daily_missions($profile['concerns'] ?? []) as $m) {
        if (!in_array((string) $m['id'], $doneM, true)) {
            return false;
        }
    }
    if (mana_path2_advanced_today($pdo, $userId)) {
        return false;
    }
    $days = mana_path2_weekdays($profile['concerns'] ?? []);
    $today = $days[mana_path2_weekday_index()] ?? null;
    if (!$today) {
        return false;
    }
    mana_path_log($pdo, $userId, 'path2_day', 40, 'week', (string) $today['id'], ['weekday' => $today['label']]);
    mana_path_mark_activity($pdo, $profile, 40, ['light' => 4]);
    mana_path_plant_advance($pdo, $profile);
    return true;
}

function mana_path_plant_day(PDO $pdo, array &$profile): int
{
    $stored = max(1, min(30, (int) ($profile['world']['plant_day'] ?? 1)));
    $n = mana_path_success_days($pdo, (string) $profile['user_id']);
    $day = min(30, max(1, $stored, $n));
    if ($day > $stored) {
        mana_path_plant_save($pdo, $profile, $day);
    }
    return $day;
}

function mana_path_success_days(PDO $pdo, string $userId): int
{
    $need = max(1, count(mana_path_daily_missions()));
    $byDay = [];
    try {
        $stmt = $pdo->prepare("
          SELECT event_type, step_id, created_at
          FROM mana_path_events
          WHERE user_id = ? AND event_type IN ('mission', 'path2_day')
        ");
        $stmt->execute([$userId]);
        foreach ($stmt->fetchAll() as $row) {
            $d = mana_path_event_ymd((string) ($row['created_at'] ?? ''));
            if (!isset($byDay[$d])) {
                $byDay[$d] = ['mission' => [], 'path2' => false];
            }
            if ((string) ($row['event_type'] ?? '') === 'path2_day') {
                $byDay[$d]['path2'] = true;
                continue;
            }
            $sid = (string) ($row['step_id'] ?? '');
            if ($sid !== '' && !in_array($sid, $byDay[$d]['mission'], true)) {
                $byDay[$d]['mission'][] = $sid;
            }
        }
    } catch (Throwable $e) {
        return 0;
    }
    $n = 0;
    foreach ($byDay as $row) {
        if (!empty($row['path2']) || count($row['mission']) >= $need) {
            $n++;
        }
    }
    return $n;
}

function mana_path_plant_save(PDO $pdo, array &$profile, int $day): void
{
    $day = max(1, min(30, $day));
    $world = array_merge(mana_path_world_defaults(), $profile['world'] ?? []);
    if ((int) ($world['plant_day'] ?? 0) === $day) {
        return;
    }
    $world['plant_day'] = $day;
    try {
        $pdo->prepare('UPDATE mana_path_profiles SET world_json = ? WHERE user_id = ?')
            ->execute([json_encode($world, JSON_UNESCAPED_UNICODE), $profile['user_id']]);
    } catch (Throwable $ignored) {
    }
    $profile['world'] = $world;
    $profile['world_json'] = json_encode($world, JSON_UNESCAPED_UNICODE);
}

function mana_path_plant_advance(PDO $pdo, array &$profile): int
{
    $next = min(30, mana_path_plant_day($pdo, $profile));
    mana_path_plant_save($pdo, $profile, $next);
    return $next;
}

function mana_path_plant_src(int $day): string
{
    $day = max(1, min(30, $day));
    $dir = dirname(__DIR__) . '/assets/img/plant';
    for ($d = $day; $d >= 1; $d--) {
        $name = sprintf('plant-day-%02d.png', $d);
        if (is_file($dir . '/' . $name)) {
            return url('/assets/img/plant/' . $name) . '?v=20260924i';
        }
    }
    return url('/assets/img/plant/plant-day-01.png') . '?v=20260924i';
}

function mana_path2_advanced_today(PDO $pdo, string $userId): bool
{
    $today = mana_path_today_ymd();
    try {
        $stmt = $pdo->prepare("
          SELECT created_at FROM mana_path_events
          WHERE user_id = ? AND event_type = 'path2_day'
        ");
        $stmt->execute([$userId]);
        foreach ($stmt->fetchAll() as $row) {
            if (in_array($today, mana_path_event_ymds((string) ($row['created_at'] ?? '')), true)) {
                return true;
            }
        }
    } catch (Throwable $e) {
        return false;
    }
    return false;
}

function mana_path_set_mood(PDO $pdo, array &$profile, int $mood): void
{
    if ($mood < 1 || $mood > 5) {
        throw new RuntimeException('حال معتبر نیست.');
    }
    $today = mana_path_today_ymd();
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
