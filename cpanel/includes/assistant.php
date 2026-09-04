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

function assistant_ai_config(): array
{
    global $config;
    return [
        'api_key' => trim((string) ($config['openai_api_key'] ?? '')),
        'base_url' => rtrim((string) ($config['openai_base_url'] ?? 'https://api.metisai.ir/openai/v1'), '/'),
        'model' => trim((string) ($config['openai_model'] ?? 'gpt-4o-mini')) ?: 'gpt-4o-mini',
    ];
}

function assistant_ai_available(): bool
{
    $cfg = assistant_ai_config();
    return $cfg['api_key'] !== '';
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
        messages_json MEDIUMTEXT NULL,
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
    try {
        $cols = $pdo->query('SHOW COLUMNS FROM assistant_sessions LIKE \'messages_json\'')->fetchAll();
        if (!$cols) {
            $pdo->exec('ALTER TABLE assistant_sessions ADD COLUMN messages_json MEDIUMTEXT NULL AFTER answers_json');
        }
    } catch (Throwable $e) {
        // ignore
    }
    try {
        $cols = $pdo->query('SHOW COLUMNS FROM assistant_sessions LIKE \'assigned_at\'')->fetchAll();
        if (!$cols) {
            $pdo->exec('ALTER TABLE assistant_sessions ADD COLUMN assigned_at DATETIME NULL AFTER sent_at');
        }
    } catch (Throwable $e) {
        // ignore
    }
    try {
        $cols = $pdo->query('SHOW COLUMNS FROM assistant_sessions LIKE \'ai_summary\'')->fetchAll();
        if (!$cols) {
            $pdo->exec('ALTER TABLE assistant_sessions ADD COLUMN ai_summary TEXT NULL AFTER intake_text');
        }
    } catch (Throwable $e) {
        // ignore
    }
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

function assistant_messages_decode(?string $json): array
{
    if ($json === null || $json === '') {
        return [];
    }
    $data = json_decode($json, true);
    return is_array($data) ? $data : [];
}

function assistant_messages_save(PDO $pdo, string $sessionId, array $messages): void
{
    $pdo->prepare('UPDATE assistant_sessions SET messages_json=? WHERE id=?')
        ->execute([json_encode($messages, JSON_UNESCAPED_UNICODE), $sessionId]);
}

/**
 * فهرست کوتاه درمانگران و کارگاه‌های فعال از دیتابیس سایت
 */
function assistant_catalog_brief(PDO $pdo): string
{
    $lines = [];
    $lines[] = '— درمانگران فعال مانا کلینیک —';
    try {
        $docs = $pdo->query("
          SELECT u.name, dp.specialty, LEFT(COALESCE(dp.bio,''), 160) AS bio
          FROM doctor_profiles dp
          JOIN users u ON u.id = dp.user_id
          WHERE dp.is_active = 1 AND dp.is_approved = 1
          ORDER BY u.name ASC
          LIMIT 20
        ")->fetchAll();
        if ($docs) {
            foreach ($docs as $i => $d) {
                $lines[] = ($i + 1) . '. ' . trim((string) $d['name'])
                    . ' | تخصص: ' . trim((string) ($d['specialty'] ?? '—'))
                    . ' | ' . trim((string) ($d['bio'] ?? ''));
            }
        } else {
            $lines[] = '(فعلاً درمانگر فعالی در فهرست نیست — به صفحه /doctors ارجاع بده.)';
        }
    } catch (Throwable $e) {
        $lines[] = '(خطا در خواندن درمانگران)';
    }

    $lines[] = '';
    $lines[] = '— کارگاه‌ها و دوره‌های فعال مانا کلینیک —';
    try {
        ensure_workshop_schema($pdo);
        $sql = '
          SELECT w.title, w.type, w.description, u.name AS doctor_name
          FROM workshops w
          ' . workshop_active_doctor_join('w') . '
          JOIN users u ON u.id = dp.user_id
          WHERE ' . workshop_patient_list_sql('w') . '
          ORDER BY w.created_at DESC
          LIMIT 25
        ';
        $ws = $pdo->query($sql)->fetchAll();
        if ($ws) {
            foreach ($ws as $i => $w) {
                $typeLabel = function_exists('workshop_type_label')
                    ? workshop_type_label((string) $w['type'])
                    : (string) $w['type'];
                $desc = trim(mb_substr((string) ($w['description'] ?? ''), 0, 120));
                $lines[] = ($i + 1) . '. «' . trim((string) $w['title']) . '»'
                    . ' (' . $typeLabel . ')'
                    . ' — مدرس: ' . trim((string) ($w['doctor_name'] ?? '—'))
                    . ($desc !== '' ? ' | ' . $desc : '');
            }
        } else {
            $lines[] = '(فعلاً کارگاه فعالی نیست — در صورت نیاز زوج‌درمانی/مشاوره فردی را از فهرست درمانگران پیشنهاد بده.)';
        }
    } catch (Throwable $e) {
        $lines[] = '(خطا در خواندن کارگاه‌ها)';
    }

    return implode("\n", $lines);
}

function assistant_ai_system_prompt(?string $catalog = null): string
{
    $catalog = trim((string) $catalog);
    if ($catalog === '') {
        $catalog = 'فهرست در دسترس نیست؛ کاربر را به صفحات متخصصان و کارگاه‌های مانا کلینیک راهنمایی کن.';
    }

    return <<<PROMPT
تو دستیار گفت‌وگوی اولیه «مانا کلینیک» هستی. فقط به فارسی، با لحن گرم، کوتاه و محترمانه صحبت کن.
تو نماینده همین کلینیک هستی و باید خدمات خود مانا کلینیک را معرفی کنی.

======= محدوده پاسخ (اجباری) =======
فقط درباره این موضوعات حرف بزن و کمک کن:
- روانشناسی، سلامت روان، مشاوره و روان‌درمانی
- اضطراب، افسردگی، استرس، خواب، خلق، فرسودگی
- رابطه عاطفی، زوج‌درمانی، خانواده، فرزندپروری، خودشناسی
- پیدا کردن درمانگر / کارگاه / نوع جلسه (فردی، زوجی، حضوری، آنلاین، آفلاین)

اگر کاربر درباره موضوع غیرمرتبط پرسید (مثل برنامه‌نویسی، سیاست، اخبار، ریاضی، ورزش، پزشکی غیرروان، دارو، دستور پخت، جوک بی‌ربط، و هر چیز خارج از روانشناسی/روان‌درمانی):
1) مودبانه بگو فقط درباره موضوعات روانشناسی و روان‌درمانی می‌توانی کمک کنی.
2) یک سوال کوتاه برای برگرداندن گفتگو به حال روانی / نیاز مشاوره‌ای بپرس.
3) به موضوع غیرمرتبط جواب محتوایی نده.

======= پیشنهاد از دیتابیس مانا کلینیک (اجباری) =======
فقط از فهرست زیر پیشنهاد بده. این داده‌های واقعی سایت است:
{$catalog}

قواعد پیشنهاد:
- اگر مشکل زوجی / با همسر / خانم / شوهر / ازدواج بود: حتماً تگ couple بگذار و از فهرست، درمانگر مرتبط با زوج‌درمانی/رابطه و هر کارگاه زوجی یا رابطه را با نام دقیق معرفی کن.
- اگر کارگاه مرتبط نبود، صریح بگو و به‌جای آن جلسه زوج‌درمانی یا مشاوره فردی با نام درمانگر از همین فهرست پیشنهاد بده.
- هرگز نگو «نمی‌توانم درمانگر خاصی معرفی کنم» یا «نمی‌توانم کارگاه پیشنهاد دهم» یا «خیلی از مراکز آنلاین…».
- مراکز دیگر، لینک خارجی، یا پیشنهاد کلی خارج از مانا کلینیک ممنوع است.
- در پیام‌های میانی هم می‌توانی ۱–۲ نام مرتبط از فهرست را کوتاه بگویی؛ در پایان با <<<READY>>> پیشنهاد نهایی را قطعی کن.

======= ممنوع =======
- تشخیص قطعی اختلال نده
- دارو، دوز دارو، یا دستور پزشکی نده
- خودت را درمانگر جایگزین معرفی نکن
- اگر بحران یا خطر جدی / افکار آسیب به خود بود، فوراً به اورژانس ۱۱۵ و کمک اضطراری ارجاع بده

======= سبک گفتگو =======
- هر پیام حداکثر ۱ یا ۲ جمله سوال داشته باشد
- سوال تکراری نپرس
- موضوعات مفید: موضوع اصلی، شدت، مدت، فردی/زوجی/کارگاه، حضوری/آنلاین/آفلاین، خواب/حمایت، هدف مراجعه

======= پایان گفتگو =======
وقتی حداقل موضوع اصلی + شدت/نیاز و ترجیح جلسه را فهمیدی، جمع‌بندی همدلانه بنویس و دقیقاً در انتهای پیام این بلوک را بگذار:

<<<READY>>>
{"tags":["couple","relationship","therapy","moderate"],"summary":"خلاصه کوتاه فارسی از وضعیت و نیاز مراجع"}

تگ‌های مجاز: anxiety, depression, stress, burnout, couple, relationship, family, parenting, growth, self, mild, moderate, high, urgent, recent, months, chronic, individual, therapy, workshop, group, unsure, IN_PERSON, ONLINE, OFFLINE, sleep, support, mood.
PROMPT;
}

/**
 * فراخوانی Chat Completions سازگار با OpenAI (Metis و مشابه)
 * @param list<array{role:string,content:string}> $messages
 */
function assistant_ai_chat(array $messages, int $maxTokens = 700): string
{
    $cfg = assistant_ai_config();
    if ($cfg['api_key'] === '') {
        throw new RuntimeException('کلید API هوش مصنوعی تنظیم نشده است.');
    }

    $url = $cfg['base_url'] . '/chat/completions';
    $payload = [
        'model' => $cfg['model'],
        'messages' => $messages,
        'temperature' => 0.6,
        'max_tokens' => $maxTokens,
    ];
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
    $headers = [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $cfg['api_key'],
        'x-api-key: ' . $cfg['api_key'],
    ];

    $raw = false;
    $httpCode = 0;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_TIMEOUT => 60,
        ]);
        $raw = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
    } else {
        $ctx = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headers) . "\r\n",
                'content' => $json,
                'timeout' => 60,
                'ignore_errors' => true,
            ],
        ]);
        $raw = @file_get_contents($url, false, $ctx);
        if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
            $httpCode = (int) $m[1];
        }
    }

    if ($raw === false || $raw === '') {
        throw new RuntimeException('ارتباط با سرویس هوش مصنوعی برقرار نشد.');
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        throw new RuntimeException('پاسخ نامعتبر از سرویس هوش مصنوعی.');
    }
    if ($httpCode >= 400 || isset($data['error'])) {
        $msg = is_array($data['error'] ?? null)
            ? (string) ($data['error']['message'] ?? 'خطای API')
            : (string) ($data['message'] ?? $data['error'] ?? 'خطای API');
        throw new RuntimeException('هوش مصنوعی: ' . $msg);
    }
    $content = $data['choices'][0]['message']['content'] ?? '';
    if (!is_string($content) || trim($content) === '') {
        throw new RuntimeException('پاسخ خالی از مدل دریافت شد.');
    }
    return trim($content);
}

/** @return array{text:string,ready:bool,tags:list<string>,summary:string} */
function assistant_ai_parse_reply(string $content): array
{
    $ready = false;
    $tags = [];
    $summary = '';
    $text = $content;

    if (preg_match('/<<<READY>>>\s*(\{.*\})\s*$/s', $content, $m)) {
        $ready = true;
        $text = trim(preg_replace('/<<<READY>>>\s*\{.*\}\s*$/s', '', $content) ?? $content);
        $meta = json_decode($m[1], true);
        if (is_array($meta)) {
            $tags = array_values(array_filter(array_map('strval', $meta['tags'] ?? [])));
            $summary = trim((string) ($meta['summary'] ?? ''));
        }
    }

    return [
        'text' => $text !== '' ? $text : 'متوجه شدم. لطفاً کمی بیشتر توضیح دهید.',
        'ready' => $ready,
        'tags' => $tags,
        'summary' => $summary,
    ];
}

function assistant_answers_from_ai_tags(array $tags, string $summary): array
{
    $tagSet = [];
    foreach ($tags as $t) {
        $tagSet[(string) $t] = true;
    }
    $answers = [];
    foreach (assistant_questions() as $q) {
        if (($q['type'] ?? '') === 'text') {
            if ($summary !== '') {
                $answers[] = ['question_id' => $q['id'], 'text' => $summary];
            }
            continue;
        }
        $bestId = null;
        $bestScore = 0;
        foreach ($q['options'] ?? [] as $opt) {
            $score = 0;
            foreach ($opt['tags'] ?? [] as $t) {
                if (isset($tagSet[(string) $t])) {
                    $score++;
                }
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestId = $opt['id'];
            }
        }
        if ($bestId !== null) {
            $answers[] = ['question_id' => $q['id'], 'option_id' => $bestId];
        }
    }
    return $answers;
}

function assistant_openai_messages_for_api(array $stored, ?PDO $pdo = null): array
{
    $catalog = $pdo ? assistant_catalog_brief($pdo) : '';
    $out = [['role' => 'system', 'content' => assistant_ai_system_prompt($catalog)]];
    foreach ($stored as $m) {
        $role = ($m['role'] ?? '') === 'assistant' ? 'assistant' : 'user';
        $content = trim((string) ($m['content'] ?? ''));
        if ($content === '') {
            continue;
        }
        $out[] = ['role' => $role, 'content' => $content];
    }
    return $out;
}

function assistant_complete_from_ai(PDO $pdo, string $sessionId, array $messages, array $tags, string $summary): array
{
    $transcript = assistant_transcript_plain($messages);
    $inferred = assistant_infer_tags_from_text($transcript . "\n" . $summary);
    foreach ($inferred as $tag => $weight) {
        if (!in_array((string) $tag, $tags, true)) {
            $tags[] = (string) $tag;
        }
    }

    if ($tags === []) {
        // استخراج اجباری با یک درخواست کوتاه
        $extractPrompt = [
            ['role' => 'system', 'content' => 'از گفتگو تگ و خلاصه بساز. فقط JSON معتبر برگردان: {"tags":[...],"summary":"..."} تگ‌ها فقط از لیست مجاز دستیار مانا کلینیک. اگر مشکل زوجی/همسر بود حتماً couple و relationship بگذار.'],
            ['role' => 'user', 'content' => "گفتگو:\n" . $transcript . "\n\nJSON:"],
        ];
        try {
            $raw = assistant_ai_chat($extractPrompt, 400);
            if (preg_match('/\{.*\}/s', $raw, $m)) {
                $meta = json_decode($m[0], true);
                if (is_array($meta)) {
                    $tags = array_values(array_filter(array_map('strval', $meta['tags'] ?? [])));
                    if ($summary === '') {
                        $summary = trim((string) ($meta['summary'] ?? ''));
                    }
                }
            }
        } catch (Throwable $e) {
            // ادامه با تگ خالی → پیشنهاد عمومی
        }
    }

    $answers = assistant_answers_from_ai_tags($tags, $summary);
    if ($summary !== '' && $answers === []) {
        $answers[] = ['question_id' => 'free_note', 'text' => $summary];
    }
    $result = assistant_complete_matching($pdo, $sessionId, $answers, $messages, $summary);
    assistant_messages_save($pdo, $sessionId, $messages);
    return $result;
}

function assistant_transcript_plain(array $messages): string
{
    $lines = [];
    foreach ($messages as $m) {
        $who = ($m['role'] ?? '') === 'assistant' ? 'دستیار' : 'مراجع';
        $lines[] = $who . ': ' . trim((string) ($m['content'] ?? ''));
    }
    return implode("\n", $lines);
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
        'couple' => [
            'زوج', 'ازدواج', 'زناشویی', 'زوج‌درمانی', 'زوج درمانی', 'زوجین',
            'همسر', 'خانومم', 'خانمم', 'شوهرم', 'زنم', 'با خانوم', 'با همسر',
            'مشکل با خانم', 'مشکل با زن',
        ],
        'relationship' => ['رابطه', 'عاطفی', 'عشق', 'ارتباط زوجی', 'مهارت ارتباطی'],
        'family' => ['خانواده', 'فرزند', 'والدین', 'کودک'],
        'parenting' => ['فرزندپروری', 'فرزند', 'کودک'],
        'growth' => ['رشد', 'خودشناسی', 'مهارت'],
        'sleep' => ['خواب', 'بی‌خوابی'],
        'therapy' => ['درمان', 'مشاوره', 'روان‌درمانی', 'روانشناس', 'درمانگر'],
        'workshop' => ['کارگاه', 'دوره', 'گروه', 'کلاس'],
        'IN_PERSON' => ['حضوری'],
        'ONLINE' => ['آنلاین'],
        'OFFLINE' => ['آفلاین', 'ویدیو'],
    ];
}

/** استخراج تگ از متن آزاد گفتگو (مثل «با خانومم مشکل دارم») */
function assistant_infer_tags_from_text(string $text): array
{
    $text = trim($text);
    if ($text === '') {
        return [];
    }
    $found = [];
    foreach (assistant_keyword_map() as $tag => $keywords) {
        foreach ($keywords as $kw) {
            if ($kw !== '' && mb_stripos($text, (string) $kw) !== false) {
                $found[$tag] = ($found[$tag] ?? 0) + 2;
                break;
            }
        }
    }
    return $found;
}

function assistant_merge_tag_maps(array ...$maps): array
{
    $out = [];
    foreach ($maps as $map) {
        foreach ($map as $tag => $weight) {
            $out[(string) $tag] = ($out[(string) $tag] ?? 0) + (int) $weight;
        }
    }
    return $out;
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

function assistant_match_doctors(PDO $pdo, array $answers, int $limit = 5, string $extraText = ''): array
{
    $tags = assistant_merge_tag_maps(
        assistant_collect_tags($answers),
        assistant_infer_tags_from_text($extraText)
    );
    $stmt = $pdo->query("
      SELECT dp.id, dp.specialty, dp.bio, dp.session_price, dp.avatar_url, u.name
      FROM doctor_profiles dp
      JOIN users u ON u.id = dp.user_id
      WHERE dp.is_active = 1 AND dp.is_approved = 1
      ORDER BY dp.created_at ASC
    ");
    $rows = $stmt->fetchAll();
    $scored = [];
    $wantCouple = isset($tags['couple']) || isset($tags['relationship']);
    foreach ($rows as $row) {
        $blob = ((string) $row['specialty']) . ' ' . ((string) ($row['bio'] ?? '')) . ' ' . ((string) $row['name']);
        $score = assistant_score_text($blob, $tags);
        if ($wantCouple) {
            if (mb_stripos($blob, 'زوج') !== false || mb_stripos($blob, 'رابطه') !== false || mb_stripos($blob, 'خانواده') !== false) {
                $score += 5;
            } else {
                // مشکل زوجی است؛ حتی بدون کلمه «زوج» در پروفایل هم پیشنهاد بده
                $score += 1;
            }
        }
        if (isset($tags['therapy']) || isset($tags['anxiety']) || isset($tags['depression']) || isset($tags['stress'])) {
            $score += 1;
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
    $positive = array_values(array_filter($scored, static fn ($r) => ($r['score'] ?? 0) > 0));
    if (!$positive) {
        // fallback: حداقل چند درمانگر کلینیک را نشان بده
        return array_slice($scored, 0, min(3, $limit));
    }
    return array_slice($positive, 0, min(3, $limit));
}

function assistant_match_workshops(PDO $pdo, array $answers, int $limit = 5, string $extraText = ''): array
{
    ensure_workshop_schema($pdo);
    $tags = assistant_merge_tag_maps(
        assistant_collect_tags($answers),
        assistant_infer_tags_from_text($extraText)
    );
    $preferTypes = [];
    foreach (['IN_PERSON', 'ONLINE', 'OFFLINE'] as $t) {
        if (isset($tags[$t])) {
            $preferTypes[] = $t;
        }
    }
    $wantCouple = isset($tags['couple']) || isset($tags['relationship']);

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
        if ($wantCouple) {
            if (
                mb_stripos($blob, 'زوج') !== false
                || mb_stripos($blob, 'ازدواج') !== false
                || mb_stripos($blob, 'رابطه') !== false
                || mb_stripos($blob, 'همسر') !== false
                || mb_stripos($blob, 'زناشویی') !== false
            ) {
                $score += 6;
            }
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
    $positive = array_values(array_filter($scored, static fn ($r) => ($r['score'] ?? 0) > 0));
    if (!$positive) {
        return [];
    }
    return array_slice($positive, 0, min(3, $limit));
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

function assistant_build_intake_text(array $session, array $answers, array $doctors, array $workshops, ?string $selectedDoctorName = null, ?array $messages = null, ?string $aiSummary = null): string
{
    $lines = [];
    $lines[] = '=== شرح‌حال اولیه — دستیار گفت‌وگوی مانا کلینیک ===';
    $lines[] = 'تاریخ: ' . date('Y-m-d H:i');
    $lines[] = 'شناسه جلسه: ' . ($session['id'] ?? '');
    if ($selectedDoctorName) {
        $lines[] = 'درمانگر انتخاب‌شده توسط مراجع: ' . $selectedDoctorName;
    }
    $lines[] = '';
    if ($aiSummary) {
        $lines[] = '— خلاصه هوش مصنوعی —';
        $lines[] = $aiSummary;
        $lines[] = '';
    }
    if ($messages) {
        $lines[] = '— متن گفتگو —';
        $lines[] = assistant_transcript_plain($messages);
        $lines[] = '';
    }
    if ($answers) {
        $lines[] = '— پاسخ‌های ساخت‌یافته —';
        foreach ($answers as $ans) {
            $qid = (string) ($ans['question_id'] ?? '');
            $q = assistant_question_by_id($qid);
            $qText = $q['text'] ?? $qid;
            $lines[] = 'سوال: ' . $qText;
            $lines[] = 'پاسخ: ' . assistant_answer_label($ans);
            $lines[] = '';
        }
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

function assistant_ai_pick_ids(string $summary, array $candidates, string $kind, int $limit = 3): array
{
    if (!assistant_ai_available() || $candidates === [] || trim($summary) === '') {
        return array_slice(array_column($candidates, 'id'), 0, $limit);
    }
    $lines = [];
    foreach ($candidates as $i => $c) {
        if ($kind === 'doctor') {
            $lines[] = ($i + 1) . '. id=' . $c['id'] . ' | ' . ($c['name'] ?? '') . ' | ' . ($c['specialty'] ?? '') . ' | score=' . ($c['score'] ?? 0);
        } else {
            $lines[] = ($i + 1) . '. id=' . $c['id'] . ' | ' . ($c['title'] ?? '') . ' | ' . ($c['type_label'] ?? $c['type'] ?? '') . ' | score=' . ($c['score'] ?? 0);
        }
    }
    $prompt = [
        ['role' => 'system', 'content' => 'فقط JSON برگردان: {"ids":["id1","id2"]} حداکثر ' . $limit . ' مورد مرتبط‌ترین را انتخاب کن. اگر هیچ‌کدام مرتبط نبود {"ids":[]}.'],
        ['role' => 'user', 'content' => "خلاصه نیاز مراجع:\n{$summary}\n\nنامزدها ({$kind}):\n" . implode("\n", $lines)],
    ];
    try {
        $raw = assistant_ai_chat($prompt, 200);
        if (preg_match('/\{.*\}/s', $raw, $m)) {
            $meta = json_decode($m[0], true);
            $ids = array_values(array_filter(array_map('strval', $meta['ids'] ?? [])));
            $allowed = array_column($candidates, 'id');
            $ids = array_values(array_filter($ids, static fn ($id) => in_array($id, $allowed, true)));
            return array_slice($ids, 0, $limit);
        }
    } catch (Throwable $e) {
        // fallback keyword order
    }
    return array_slice(array_column($candidates, 'id'), 0, $limit);
}

function assistant_filter_by_ids(array $items, array $ids): array
{
    if ($ids === []) {
        return [];
    }
    $map = [];
    foreach ($items as $item) {
        $map[(string) ($item['id'] ?? '')] = $item;
    }
    $out = [];
    foreach ($ids as $id) {
        if (isset($map[$id])) {
            $out[] = $map[$id];
        }
    }
    return $out;
}

function assistant_complete_matching(PDO $pdo, string $sessionId, array $answers, ?array $messages = null, ?string $aiSummary = null): array
{
    $extraText = trim((string) $aiSummary);
    if ($messages) {
        $extraText .= "\n" . assistant_transcript_plain($messages);
    }

    $doctors = assistant_match_doctors($pdo, $answers, 8, $extraText);
    $workshops = assistant_match_workshops($pdo, $answers, 8, $extraText);
    $summary = trim((string) $aiSummary);
    if ($summary === '' && $messages) {
        $summary = mb_substr(assistant_transcript_plain($messages), 0, 800);
    }

    if ($doctors) {
        $docIds = assistant_ai_pick_ids($summary !== '' ? $summary : 'نیاز مشاوره روانشناسی در مانا کلینیک', $doctors, 'doctor', 3);
        $doctors = assistant_filter_by_ids($doctors, $docIds);
    }
    if ($workshops) {
        $wsIds = assistant_ai_pick_ids($summary !== '' ? $summary : 'نیاز کارگاه در مانا کلینیک', $workshops, 'workshop', 3);
        $workshops = assistant_filter_by_ids($workshops, $wsIds);
    }

    $intake = assistant_build_intake_text(['id' => $sessionId], $answers, $doctors, $workshops, null, $messages, $aiSummary);
    $pdo->prepare('
      UPDATE assistant_sessions
      SET status=?, current_step=?, answers_json=?, matched_doctors_json=?, matched_workshops_json=?, intake_text=?, ai_summary=?
      WHERE id=?
    ')->execute([
        'COMPLETED',
        count(assistant_questions()),
        json_encode($answers, JSON_UNESCAPED_UNICODE),
        json_encode($doctors, JSON_UNESCAPED_UNICODE),
        json_encode($workshops, JSON_UNESCAPED_UNICODE),
        $intake,
        $aiSummary,
        $sessionId,
    ]);
    return [
        'doctors' => $doctors,
        'workshops' => $workshops,
        'intake_text' => $intake,
        'ai_summary' => $aiSummary,
    ];
}

/** ارسال خلاصه به کلینیک (منشی) — ارجاع نهایی با منشی است */
function assistant_send_to_clinic(PDO $pdo, array $session, string $patientId, ?string $preferredDoctorId = null): void
{
    $answers = assistant_answers_decode($session['answers_json'] ?? null);
    $doctors = json_decode((string) ($session['matched_doctors_json'] ?? '[]'), true) ?: [];
    $workshops = json_decode((string) ($session['matched_workshops_json'] ?? '[]'), true) ?: [];
    $messages = assistant_messages_decode($session['messages_json'] ?? null);
    $aiSummary = trim((string) ($session['ai_summary'] ?? ''));

    $preferredName = null;
    if ($preferredDoctorId) {
        $docStmt = $pdo->prepare("
          SELECT dp.id, u.name FROM doctor_profiles dp
          JOIN users u ON u.id = dp.user_id
          WHERE dp.id=? AND dp.is_active=1 AND dp.is_approved=1 LIMIT 1
        ");
        $docStmt->execute([$preferredDoctorId]);
        $doctor = $docStmt->fetch();
        if (!$doctor) {
            throw new RuntimeException('درمانگر انتخاب‌شده معتبر نیست.');
        }
        $preferredName = (string) $doctor['name'];
    }

    $intake = assistant_build_intake_text(
        $session,
        $answers,
        $doctors,
        $workshops,
        $preferredName,
        $messages ?: null,
        $aiSummary !== '' ? $aiSummary : null
    );

    $patient = $pdo->prepare('SELECT name, phone FROM users WHERE id=? LIMIT 1');
    $patient->execute([$patientId]);
    $p = $patient->fetch() ?: ['name' => 'مراجع', 'phone' => ''];

    $pdo->prepare('
      UPDATE assistant_sessions
      SET status=?, patient_id=?, selected_doctor_id=?, intake_text=?, sent_at=NOW(), assigned_at=NULL
      WHERE id=?
    ')->execute(['SENT', $patientId, $preferredDoctorId, $intake, $session['id']]);

    $prefNote = $preferredName ? ("ترجیح مراجع: «{$preferredName}». ") : 'هنوز درمانگر نهایی انتخاب نشده. ';
    $short = $aiSummary !== '' ? $aiSummary : mb_substr($intake, 0, 280);

    notify_role(
        $pdo,
        'SECRETARY',
        'خلاصه گفتگوی دستیار — نیازمند ارجاع',
        $prefNote . 'مراجع «' . ($p['name'] ?? '') . '»: ' . $short,
        '/secretary/intakes/' . $session['id']
    );

    // اطلاع کوتاه به درمانگران پیشنهادی (بدون ثبت پرونده تا ارجاع منشی)
    foreach (array_slice($doctors, 0, 3) as $d) {
        if (!empty($d['id'])) {
            notify_doctor_profile(
                $pdo,
                (string) $d['id'],
                'گفتگوی اولیه جدید در کلینیک',
                'یک خلاصه گفتگو برای ارجاع منشی ثبت شد' . ($preferredName ? " (ترجیح: {$preferredName})" : '') . '.',
                '/doctor'
            );
        }
    }
}

/** ارجاع منشی به درمانگر مشخص + ثبت در پرونده */
function assistant_assign_to_doctor(PDO $pdo, array $session, string $doctorProfileId, ?string $secretaryNote = null): void
{
    if (($session['status'] ?? '') !== 'SENT') {
        throw new RuntimeException('این گفتگو برای ارجاع آماده نیست.');
    }
    if (!empty($session['assigned_at'])) {
        throw new RuntimeException('قبلاً به درمانگر ارجاع شده است.');
    }
    $patientId = (string) ($session['patient_id'] ?? '');
    if ($patientId === '') {
        throw new RuntimeException('مراجع این جلسه مشخص نیست.');
    }

    $docStmt = $pdo->prepare("
      SELECT dp.id, u.name FROM doctor_profiles dp
      JOIN users u ON u.id = dp.user_id
      WHERE dp.id=? AND dp.is_active=1 AND dp.is_approved=1 LIMIT 1
    ");
    $docStmt->execute([$doctorProfileId]);
    $doctor = $docStmt->fetch();
    if (!$doctor) {
        throw new RuntimeException('درمانگر معتبر نیست.');
    }

    $intake = trim((string) ($session['intake_text'] ?? ''));
    if ($secretaryNote) {
        $intake .= "\n\n— یادداشت منشی —\n" . trim($secretaryNote);
    }
    $intake .= "\nدرمانگر ارجاع‌شده: " . $doctor['name'];

    $chart = get_or_create_patient_chart($pdo, $doctorProfileId, $patientId);
    $existing = trim((string) ($chart['history_text'] ?? ''));
    $newHistory = $existing === '' ? $intake : ($existing . "\n\n" . $intake);
    $pdo->prepare('UPDATE doctor_patient_charts SET history_text=? WHERE id=?')
        ->execute([$newHistory, $chart['id']]);

    $pdo->prepare('
      UPDATE assistant_sessions
      SET selected_doctor_id=?, intake_text=?, assigned_at=NOW()
      WHERE id=?
    ')->execute([$doctorProfileId, $intake, $session['id']]);

    // ترجیح درمانگر مراجع
    $pdo->prepare('UPDATE users SET preferred_doctor_id=? WHERE id=? AND role=?')
        ->execute([$doctorProfileId, $patientId, 'PATIENT']);

    notify_doctor_profile(
        $pdo,
        $doctorProfileId,
        'ارجاع شرح‌حال از منشی',
        'منشی یک شرح‌حال گفتگوی اولیه را به شما ارجاع داد.',
        '/doctor/patients/' . $patientId
    );
}

/** سازگاری قدیمی */
function assistant_send_to_doctor(PDO $pdo, array $session, string $patientId, string $doctorProfileId): void
{
    assistant_send_to_clinic($pdo, $session, $patientId, $doctorProfileId);
}
