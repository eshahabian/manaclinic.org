<?php
declare(strict_types=1);

require_once __DIR__ . '/workshops.php';
require_once __DIR__ . '/doctor_clinical.php';
require_once __DIR__ . '/notifications.php';

function assistant_enabled(): bool
{
    global $config;
    return (bool) ($config['assistant_enabled'] ?? true);
}

function ensure_assistant_schema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    $pdo->exec("
      CREATE TABLE IF NOT EXISTS assistant_sessions (
        id VARCHAR(32) PRIMARY KEY,
        patient_id VARCHAR(32) NULL,
        status ENUM('IN_PROGRESS','COMPLETED','SENT') NOT NULL DEFAULT 'IN_PROGRESS',
        current_step INT NOT NULL DEFAULT 0,
        answers_json MEDIUMTEXT NULL,
        matched_doctors_json MEDIUMTEXT NULL,
        matched_workshops_json MEDIUMTEXT NULL,
        selected_doctor_id VARCHAR(32) NULL,
        intake_text MEDIUMTEXT NULL,
        sent_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_assistant_patient (patient_id),
        INDEX idx_assistant_status (status)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $ready = true;
}

/** کاتالوگ سوالات هدایت‌شده */
function assistant_questions(): array
{
    return [
        [
            'id' => 'main_concern',
            'text' => 'الان بیشتر دوست دارید درباره چه موضوعی حرف بزنیم؟',
            'options' => [
                ['id' => 'anxiety', 'label' => 'اضطراب و نگرانی', 'tags' => ['anxiety', 'stress']],
                ['id' => 'depression', 'label' => 'خلق پایین / بی‌حوصلگی', 'tags' => ['depression', 'mood']],
                ['id' => 'stress', 'label' => 'استرس و فشار زندگی', 'tags' => ['stress', 'burnout']],
                ['id' => 'relationship', 'label' => 'رابطه عاطفی یا زوجی', 'tags' => ['couple', 'relationship']],
                ['id' => 'family', 'label' => 'خانواده و فرزندپروری', 'tags' => ['family', 'parenting']],
                ['id' => 'self', 'label' => 'خودشناسی و رشد فردی', 'tags' => ['growth', 'self']],
            ],
        ],
        [
            'id' => 'intensity',
            'text' => 'شدت این موضوع در روزهای اخیر چقدر بوده؟',
            'options' => [
                ['id' => 'mild', 'label' => 'کم — گاهی اذیتم می‌کند', 'tags' => ['mild']],
                ['id' => 'moderate', 'label' => 'متوسط — روی کار و روابط اثر دارد', 'tags' => ['moderate']],
                ['id' => 'high', 'label' => 'زیاد — خیلی درگیرم', 'tags' => ['high', 'urgent']],
            ],
        ],
        [
            'id' => 'duration',
            'text' => 'حدوداً از کی این وضعیت را احساس می‌کنید؟',
            'options' => [
                ['id' => 'weeks', 'label' => 'چند هفته اخیر', 'tags' => ['recent']],
                ['id' => 'months', 'label' => 'چند ماه', 'tags' => ['months']],
                ['id' => 'years', 'label' => 'بیش از یک سال', 'tags' => ['chronic']],
            ],
        ],
        [
            'id' => 'format',
            'text' => 'ترجیح می‌دهید چطور ادامه دهید؟',
            'options' => [
                ['id' => 'individual', 'label' => 'جلسه فردی با درمانگر', 'tags' => ['individual', 'therapy']],
                ['id' => 'couple', 'label' => 'جلسه زوجی', 'tags' => ['couple']],
                ['id' => 'workshop', 'label' => 'کارگاه یا دوره گروهی', 'tags' => ['workshop', 'group']],
                ['id' => 'unsure', 'label' => 'هنوز مطمئن نیستم', 'tags' => ['unsure']],
            ],
        ],
        [
            'id' => 'mode',
            'text' => 'نوع جلسه یا دوره را بیشتر چطور می‌پسندید؟',
            'options' => [
                ['id' => 'in_person', 'label' => 'حضوری', 'tags' => ['IN_PERSON']],
                ['id' => 'online', 'label' => 'آنلاین', 'tags' => ['ONLINE']],
                ['id' => 'offline', 'label' => 'آفلاین (ویدیو/صوت)', 'tags' => ['OFFLINE']],
                ['id' => 'any', 'label' => 'فرقی ندارد', 'tags' => ['IN_PERSON', 'ONLINE', 'OFFLINE']],
            ],
        ],
        [
            'id' => 'sleep',
            'text' => 'خوابتان در این مدت چطور بوده؟',
            'options' => [
                ['id' => 'ok', 'label' => 'معمولی / قابل قبول', 'tags' => []],
                ['id' => 'poor', 'label' => 'خوابم بهم ریخته', 'tags' => ['sleep', 'anxiety']],
                ['id' => 'too_much', 'label' => 'بیش از حد می‌خوابم / بی‌انرژی‌ام', 'tags' => ['depression', 'mood']],
            ],
        ],
        [
            'id' => 'support',
            'text' => 'الان چقدر احساس می‌کنید حمایت دارید؟',
            'options' => [
                ['id' => 'good', 'label' => 'حمایت خوبی دارم', 'tags' => []],
                ['id' => 'some', 'label' => 'کم و بیش', 'tags' => ['support']],
                ['id' => 'lonely', 'label' => 'خیلی تنها حس می‌کنم', 'tags' => ['support', 'depression']],
            ],
        ],
        [
            'id' => 'goal',
            'text' => 'از این گفتگو بیشتر چه انتظاری دارید؟',
            'options' => [
                ['id' => 'find_doctor', 'label' => 'پیدا کردن درمانگر مناسب', 'tags' => ['therapy']],
                ['id' => 'find_course', 'label' => 'پیشنهاد کارگاه یا دوره', 'tags' => ['workshop']],
                ['id' => 'both', 'label' => 'هر دو', 'tags' => ['therapy', 'workshop']],
                ['id' => 'clarify', 'label' => 'فقط روشن‌تر شدن موضوع', 'tags' => ['growth']],
            ],
        ],
        [
            'id' => 'free_note',
            'text' => 'اگر نکته‌ای هست که دوست دارید درمانگر بداند، همین‌جا بنویسید (اختیاری).',
            'type' => 'text',
            'optional' => true,
            'placeholder' => 'مثلاً: قبلاً مشاوره رفته‌ام / دارو مصرف می‌کنم / ...',
        ],
    ];
}

function assistant_question_by_id(string $id): ?array
{
    foreach (assistant_questions() as $q) {
        if ($q['id'] === $id) {
            return $q;
        }
    }
    return null;
}

function assistant_session_get(PDO $pdo, string $id): ?array
{
    ensure_assistant_schema($pdo);
    $stmt = $pdo->prepare('SELECT * FROM assistant_sessions WHERE id=? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function assistant_session_create(PDO $pdo, ?string $patientId = null): array
{
    ensure_assistant_schema($pdo);
    $id = cuid();
    $pdo->prepare('
      INSERT INTO assistant_sessions (id, patient_id, status, current_step, answers_json)
      VALUES (?,?,?,?,?)
    ')->execute([$id, $patientId, 'IN_PROGRESS', 0, json_encode([], JSON_UNESCAPED_UNICODE)]);
    return assistant_session_get($pdo, $id) ?: ['id' => $id, 'current_step' => 0, 'answers_json' => '[]'];
}

function assistant_answers_decode(?string $json): array
{
    if ($json === null || $json === '') {
        return [];
    }
    $data = json_decode($json, true);
    return is_array($data) ? $data : [];
}

function assistant_collect_tags(array $answers): array
{
    $tags = [];
    foreach ($answers as $ans) {
        $qid = (string) ($ans['question_id'] ?? '');
        $q = assistant_question_by_id($qid);
        if (!$q || ($q['type'] ?? '') === 'text') {
            continue;
        }
        $oid = (string) ($ans['option_id'] ?? '');
        foreach ($q['options'] ?? [] as $opt) {
            if ($opt['id'] === $oid) {
                foreach ($opt['tags'] ?? [] as $t) {
                    $tags[$t] = ($tags[$t] ?? 0) + 1;
                }
            }
        }
    }
    return $tags;
}

function assistant_keyword_map(): array
{
    return [
        'anxiety' => ['اضطراب', 'نگرانی', 'panic', 'فوبیا', 'ترس'],
        'depression' => ['افسردگی', 'خلق', 'بی‌حوصلگی', 'mood'],
        'stress' => ['استرس', 'فرسودگی', 'burnout', 'فشار'],
        'couple' => ['زوج', 'ازدواج', 'عاطفی', 'رابطه', 'زوج‌درمانی', 'زوج درمانی'],
        'relationship' => ['رابطه', 'عاطفی', 'عشق'],
        'family' => ['خانواده', 'فرزند', 'والدین', 'کودک'],
        'parenting' => ['فرزندپروری', 'فرزند', 'کودک'],
        'growth' => ['رشد', 'خودشناسی', 'مهارت'],
        'sleep' => ['خواب', 'بی‌خوابی'],
        'therapy' => ['درمان', 'مشاوره', 'روان‌درمانی'],
        'workshop' => ['کارگاه', 'دوره', 'گروه'],
        'IN_PERSON' => ['حضوری'],
        'ONLINE' => ['آنلاین'],
        'OFFLINE' => ['آفلاین', 'ویدیو'],
    ];
}

function assistant_score_text(string $haystack, array $tags): int
{
    $haystack = mb_strtolower($haystack);
    $map = assistant_keyword_map();
    $score = 0;
    foreach ($tags as $tag => $weight) {
        $keywords = $map[$tag] ?? [$tag];
        foreach ($keywords as $kw) {
            if ($kw !== '' && mb_stripos($haystack, (string) $kw) !== false) {
                $score += (int) $weight + 1;
            }
        }
    }
    return $score;
}

function assistant_match_doctors(PDO $pdo, array $answers, int $limit = 5): array
{
    $tags = assistant_collect_tags($answers);
    $stmt = $pdo->query("
      SELECT dp.id, dp.specialty, dp.bio, dp.session_price, dp.avatar_url, u.name
      FROM doctor_profiles dp
      JOIN users u ON u.id = dp.user_id
      WHERE dp.is_active = 1 AND dp.is_approved = 1
      ORDER BY dp.created_at ASC
    ");
    $rows = $stmt->fetchAll();
    $scored = [];
    foreach ($rows as $row) {
        $blob = ((string) $row['specialty']) . ' ' . ((string) ($row['bio'] ?? '')) . ' ' . ((string) $row['name']);
        $score = assistant_score_text($blob, $tags);
        if (isset($tags['couple']) || isset($tags['relationship'])) {
            if (mb_stripos($blob, 'زوج') !== false) {
                $score += 4;
            }
        }
        $scored[] = [
            'id' => $row['id'],
            'name' => $row['name'],
            'specialty' => $row['specialty'],
            'bio' => $row['bio'],
            'session_price' => (int) $row['session_price'],
            'score' => $score,
            'url' => url('/doctors/' . $row['id']),
        ];
    }
    usort($scored, static fn ($a, $b) => $b['score'] <=> $a['score']);
    if (!$scored) {
        return [];
    }
    // اگر همه صفر بودند، همچنان چند درمانگر اول را برگردان
    if (($scored[0]['score'] ?? 0) === 0) {
        return array_slice($scored, 0, min(3, $limit));
    }
    return array_slice($scored, 0, $limit);
}

function assistant_match_workshops(PDO $pdo, array $answers, int $limit = 5): array
{
    ensure_workshop_schema($pdo);
    $tags = assistant_collect_tags($answers);
    $preferTypes = [];
    foreach (['IN_PERSON', 'ONLINE', 'OFFLINE'] as $t) {
        if (isset($tags[$t])) {
            $preferTypes[] = $t;
        }
    }

    $sql = '
      SELECT w.id, w.title, w.type, w.price, w.description, w.starts_at, u.name AS doctor_name
      FROM workshops w
      ' . workshop_active_doctor_join('w') . '
      JOIN users u ON u.id = dp.user_id
      WHERE ' . workshop_patient_list_sql('w') . '
      ORDER BY w.created_at DESC
      LIMIT 40
    ';
    $rows = $pdo->query($sql)->fetchAll();
    $scored = [];
    foreach ($rows as $row) {
        $blob = ((string) $row['title']) . ' ' . ((string) ($row['description'] ?? '')) . ' ' . ((string) $row['doctor_name']);
        $score = assistant_score_text($blob, $tags);
        if ($preferTypes && in_array($row['type'], $preferTypes, true)) {
            $score += 3;
        }
        if (isset($tags['workshop']) || isset($tags['group'])) {
            $score += 1;
        }
        $scored[] = [
            'id' => $row['id'],
            'title' => $row['title'],
            'type' => $row['type'],
            'type_label' => workshop_type_label((string) $row['type']),
            'price' => (int) $row['price'],
            'doctor_name' => $row['doctor_name'],
            'score' => $score,
            'url' => url('/dashboard/courses?type=' . workshop_courses_tab_for_type((string) $row['type'])),
        ];
    }
    usort($scored, static fn ($a, $b) => $b['score'] <=> $a['score']);
    if (!$scored) {
        return [];
    }
    if (($scored[0]['score'] ?? 0) === 0) {
        return array_slice($scored, 0, min(3, $limit));
    }
    return array_slice(array_filter($scored, static fn ($r) => $r['score'] > 0) ?: array_slice($scored, 0, 3), 0, $limit);
}

function assistant_answer_label(array $answer): string
{
    $qid = (string) ($answer['question_id'] ?? '');
    $q = assistant_question_by_id($qid);
    if (!$q) {
        return (string) ($answer['text'] ?? $answer['option_id'] ?? '');
    }
    if (($q['type'] ?? '') === 'text') {
        return trim((string) ($answer['text'] ?? '')) ?: '—';
    }
    $oid = (string) ($answer['option_id'] ?? '');
    foreach ($q['options'] ?? [] as $opt) {
        if ($opt['id'] === $oid) {
            return (string) $opt['label'];
        }
    }
    return $oid;
}

function assistant_build_intake_text(array $session, array $answers, array $doctors, array $workshops, ?string $selectedDoctorName = null): string
{
    $lines = [];
    $lines[] = '=== شرح‌حال اولیه — دستیار گفت‌وگوی مانا کلینیک ===';
    $lines[] = 'تاریخ: ' . date('Y-m-d H:i');
    $lines[] = 'شناسه جلسه: ' . ($session['id'] ?? '');
    if ($selectedDoctorName) {
        $lines[] = 'درمانگر انتخاب‌شده توسط مراجع: ' . $selectedDoctorName;
    }
    $lines[] = '';
    $lines[] = '— پاسخ‌های گفتگو —';
    foreach ($answers as $ans) {
        $qid = (string) ($ans['question_id'] ?? '');
        $q = assistant_question_by_id($qid);
        $qText = $q['text'] ?? $qid;
        $lines[] = 'سوال: ' . $qText;
        $lines[] = 'پاسخ: ' . assistant_answer_label($ans);
        $lines[] = '';
    }
    if ($doctors) {
        $lines[] = '— پیشنهادهای درمانگر (سیستم) —';
        foreach ($doctors as $i => $d) {
            $lines[] = ($i + 1) . '. ' . $d['name'] . ' — ' . ($d['specialty'] ?? '');
        }
        $lines[] = '';
    }
    if ($workshops) {
        $lines[] = '— پیشنهادهای کارگاه (سیستم) —';
        foreach ($workshops as $i => $w) {
            $lines[] = ($i + 1) . '. ' . $w['title'] . ' (' . ($w['type_label'] ?? $w['type']) . ') — ' . ($w['doctor_name'] ?? '');
        }
        $lines[] = '';
    }
    $lines[] = 'توجه: این متن تشخیص پزشکی نیست و صرفاً خلاصه گفتگوی اولیه مراجع در سایت است.';
    return implode("\n", $lines);
}

function assistant_save_progress(PDO $pdo, string $sessionId, int $step, array $answers, ?string $status = null): void
{
    $fields = 'current_step=?, answers_json=?';
    $params = [$step, json_encode($answers, JSON_UNESCAPED_UNICODE)];
    if ($status) {
        $fields .= ', status=?';
        $params[] = $status;
    }
    $params[] = $sessionId;
    $pdo->prepare("UPDATE assistant_sessions SET {$fields} WHERE id=?")->execute($params);
}

function assistant_complete_matching(PDO $pdo, string $sessionId, array $answers): array
{
    $doctors = assistant_match_doctors($pdo, $answers);
    $workshops = assistant_match_workshops($pdo, $answers);
    $intake = assistant_build_intake_text(['id' => $sessionId], $answers, $doctors, $workshops);
    $pdo->prepare('
      UPDATE assistant_sessions
      SET status=?, current_step=?, answers_json=?, matched_doctors_json=?, matched_workshops_json=?, intake_text=?
      WHERE id=?
    ')->execute([
        'COMPLETED',
        count(assistant_questions()),
        json_encode($answers, JSON_UNESCAPED_UNICODE),
        json_encode($doctors, JSON_UNESCAPED_UNICODE),
        json_encode($workshops, JSON_UNESCAPED_UNICODE),
        $intake,
        $sessionId,
    ]);
    return [
        'doctors' => $doctors,
        'workshops' => $workshops,
        'intake_text' => $intake,
    ];
}

function assistant_send_to_doctor(PDO $pdo, array $session, string $patientId, string $doctorProfileId): void
{
    $answers = assistant_answers_decode($session['answers_json'] ?? null);
    $doctors = json_decode((string) ($session['matched_doctors_json'] ?? '[]'), true) ?: [];
    $workshops = json_decode((string) ($session['matched_workshops_json'] ?? '[]'), true) ?: [];

    $docStmt = $pdo->prepare("
      SELECT dp.id, u.name FROM doctor_profiles dp
      JOIN users u ON u.id = dp.user_id
      WHERE dp.id=? AND dp.is_active=1 AND dp.is_approved=1 LIMIT 1
    ");
    $docStmt->execute([$doctorProfileId]);
    $doctor = $docStmt->fetch();
    if (!$doctor) {
        throw new RuntimeException('درمانگر انتخاب‌شده معتبر نیست.');
    }

    $intake = assistant_build_intake_text($session, $answers, $doctors, $workshops, (string) $doctor['name']);
    $chart = get_or_create_patient_chart($pdo, $doctorProfileId, $patientId);
    $existing = trim((string) ($chart['history_text'] ?? ''));
    $block = "\n\n" . $intake . "\n";
    $newHistory = $existing === '' ? $intake : ($existing . $block);
    $pdo->prepare('UPDATE doctor_patient_charts SET history_text=? WHERE id=?')
        ->execute([$newHistory, $chart['id']]);

    $pdo->prepare('
      UPDATE assistant_sessions
      SET status=?, patient_id=?, selected_doctor_id=?, intake_text=?, sent_at=NOW()
      WHERE id=?
    ')->execute(['SENT', $patientId, $doctorProfileId, $intake, $session['id']]);

    notify_doctor_profile(
        $pdo,
        $doctorProfileId,
        'شرح‌حال اولیه از دستیار سایت',
        'یک مراجع از طریق «با من حرف بزن» شرح‌حال اولیه ارسال کرد. در پرونده بیماران ببینید.',
        '/doctor/patients/' . $patientId
    );
}
