<?php
declare(strict_types=1);

// پاک‌سازی بافر تا noticeها پاسخ JSON را خراب نکنند
while (ob_get_level() > 0) {
    ob_end_clean();
}
ob_start();

@ini_set('display_errors', '0');
@ini_set('html_errors', '0');
@set_time_limit(90);

register_shutdown_function(static function (): void {
    $err = error_get_last();
    if (!$err) {
        return;
    }
    $type = (int) ($err['type'] ?? 0);
    if (!in_array($type, [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
        return;
    }
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    echo json_encode([
        'error' => 'خطای داخلی سرور در دستیار. لطفاً دوباره تلاش کنید.',
    ], JSON_UNESCAPED_UNICODE);
});

require_once __DIR__ . '/../includes/assistant.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

if (!assistant_enabled()) {
    http_response_code(403);
    echo json_encode(['error' => 'دستیار فعلاً غیرفعال است.'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    ensure_assistant_schema($pdo);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'آماده‌سازی دستیار ناموفق بود.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$user = current_user();
$action = post('action') ?: (string) ($_GET['action'] ?? '');
$aiMode = assistant_ai_available();

function assistant_chat_auth_urls(string $sessionId): array
{
    return [
        'loginUrl' => url('/login') . '?next=' . rawurlencode(url('/assistant?session=' . $sessionId)),
        'registerUrl' => url('/register') . '?next=' . rawurlencode(url('/assistant?session=' . $sessionId)),
    ];
}

function assistant_chat_done_payload(string $sessionId, array $result, $user, string $botMessage = ''): array
{
    return array_merge([
        'sessionId' => $sessionId,
        'done' => true,
        'mode' => 'guided_ai',
        'phase' => 'done',
        'status' => $result['status'] ?? 'SENT',
        'delivered' => (bool) ($result['delivered'] ?? true),
        'botMessage' => $botMessage !== '' ? $botMessage : "ممنون که با من حرف زدید.\nبر اساس گفتگو، چند درمانگر و کارگاه مرتبط پیشنهاد می‌کنم.\nنسخه‌ای از این گفتگو برای درمانگران کلینیک ارسال شد.",
        'doctors' => $result['doctors'],
        'workshops' => $result['workshops'],
        'intakePreview' => $result['intake_text'],
        'loggedIn' => (bool) ($user && ($user['role'] ?? '') === 'PATIENT'),
    ], assistant_chat_auth_urls($sessionId));
}

function assistant_chat_require_session(PDO $pdo, string $sessionId, $user): array
{
    $session = assistant_session_get($pdo, $sessionId);
    if (!$session || !assistant_session_accessible($session, $user) || in_array($session['status'], ['SENT'], true)) {
        throw new RuntimeException('جلسه گفتگو یافت نشد.');
    }

    return $session;
}

function assistant_chat_topics_payload(string $sessionId, string $botMessage): array
{
    $topics = [];
    foreach (assistant_topic_options() as $t) {
        $topics[] = ['id' => $t['id'], 'label' => $t['label']];
    }

    return [
        'sessionId' => $sessionId,
        'mode' => 'guided_ai',
        'phase' => 'topic',
        'botMessage' => $botMessage,
        'topics' => $topics,
        'done' => false,
        'canComplete' => false,
    ];
}

function assistant_chat_explore_payload(string $sessionId, string $topicId, string $topicLabel, string $botMessage, bool $includeQuestions = true): array
{
    $questions = [];
    if ($includeQuestions) {
        foreach (assistant_topic_questions($topicId) as $q) {
            $questions[] = ['id' => $q['id'], 'text' => $q['text']];
        }
    }

    return [
        'sessionId' => $sessionId,
        'mode' => 'guided_ai',
        'phase' => 'explore',
        'botMessage' => $botMessage,
        'topic' => ['id' => $topicId, 'label' => $topicLabel],
        'questions' => $questions,
        'done' => false,
        'canComplete' => false,
    ];
}

function assistant_chat_choice_payload(string $sessionId, string $botMessage): array
{
    return [
        'sessionId' => $sessionId,
        'mode' => 'guided_ai',
        'phase' => 'choice',
        'botMessage' => $botMessage,
        'choices' => [
            ['id' => 'talk', 'label' => 'می‌خواهم بیشتر در این مورد حرف بزنم'],
            ['id' => 'therapist', 'label' => 'درمانگر مناسب معرفی کن'],
        ],
        'done' => false,
        'canComplete' => false,
    ];
}

try {
    if ($action === 'start') {
        throttle_guard_json('assistant_start', 12, 3600, 'درخواست گفتگو زیاد بود. کمی بعد دوباره تلاش کنید.');
        throttle_hit('assistant_start', 3600);
        $session = assistant_session_create($pdo, ($user && ($user['role'] ?? '') === 'PATIENT') ? (string) $user['id'] : null);
        $sessionId = (string) $session['id'];
        $greeting = "سلام، خوش آمدید.\nمن دستیار مانا کلینیک هستم. میخواهی در مورد چی صحبت کنیم ؟";
        $answers = assistant_flow_meta_set([], [
            'phase' => 'topic',
            'topic' => '',
            'topic_label' => '',
            'explored' => [],
            'custom_note' => '',
        ]);
        assistant_save_progress($pdo, $sessionId, 0, $answers);
        $messages = [['role' => 'assistant', 'content' => $greeting]];
        assistant_messages_save($pdo, $sessionId, $messages);
        echo json_encode(assistant_chat_topics_payload($sessionId, $greeting), JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'free_topic') {
        throttle_guard_json('assistant_msg', 40, 600, 'پیام‌های پشت‌سرهم زیاد بود. کمی صبر کنید.');
        throttle_hit('assistant_msg', 600);
        $sessionId = trim(post('sessionId'));
        $note = trim(post('text'));
        $session = assistant_chat_require_session($pdo, $sessionId, $user);
        if ($session['status'] === 'COMPLETED') {
            throw new RuntimeException('گفتگو تمام شده است.');
        }
        if ($note === '') {
            throw new RuntimeException('لطفاً بنویسید دوست دارید درباره چه حرف بزنیم.');
        }
        if (mb_strlen($note) > 2000) {
            throw new RuntimeException('متن خیلی طولانی است.');
        }
        $answers = assistant_answers_decode($session['answers_json'] ?? null);
        $meta = assistant_flow_meta($answers);
        if (($meta['phase'] ?? '') !== 'topic') {
            throw new RuntimeException('در این مرحله نمی‌توان این پیام را ثبت کرد.');
        }
        $meta['custom_note'] = $note;
        $answers = assistant_flow_meta_set($answers, $meta);
        assistant_save_progress($pdo, $sessionId, 0, $answers);

        $bot = 'ممنون که گفتید. اگر بخواهید دقیق‌تر کمک کنم، یکی از حوزه‌های درمان زیر را هم انتخاب کنید.';
        $messages = assistant_messages_decode($session['messages_json'] ?? null);
        $messages[] = ['role' => 'user', 'content' => $note];
        $messages[] = ['role' => 'assistant', 'content' => $bot];
        assistant_messages_save($pdo, $sessionId, $messages);

        echo json_encode(assistant_chat_topics_payload($sessionId, $bot), JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'select_topic') {
        throttle_guard_json('assistant_msg', 40, 600, 'پیام‌های پشت‌سرهم زیاد بود. کمی صبر کنید.');
        throttle_hit('assistant_msg', 600);
        $sessionId = trim(post('sessionId'));
        $topicId = trim(post('topicId'));
        $session = assistant_chat_require_session($pdo, $sessionId, $user);
        if ($session['status'] === 'COMPLETED') {
            throw new RuntimeException('گفتگو تمام شده است.');
        }
        $topic = assistant_topic_by_id($topicId);
        if (!$topic) {
            throw new RuntimeException('موضوع نامعتبر است.');
        }
        $answers = assistant_answers_decode($session['answers_json'] ?? null);
        $meta = assistant_flow_meta($answers);
        $meta['phase'] = 'explore';
        $meta['topic'] = $topicId;
        $meta['topic_label'] = (string) $topic['label'];
        $meta['explored'] = [];
        $answers = assistant_flow_meta_set($answers, $meta);
        assistant_save_progress($pdo, $sessionId, 1, $answers);

        $bot = "موضوع «{$topic['label']}» را انتخاب کردید.\nچند سوال تخصصی و به‌روز در این حوزه می‌بینید — هر کدام را بزنید تا توضیح کوتاه بگیرم. اگر مورد دیگری مدنظرتان است پایین بنویسید، بعد ادامه دهید.";
        $messages = assistant_messages_decode($session['messages_json'] ?? null);
        $messages[] = ['role' => 'user', 'content' => (string) $topic['label']];
        $messages[] = ['role' => 'assistant', 'content' => $bot];
        assistant_messages_save($pdo, $sessionId, $messages);

        echo json_encode(assistant_chat_explore_payload($sessionId, $topicId, (string) $topic['label'], $bot), JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'faq') {
        throttle_guard_json('assistant_msg', 40, 600, 'پیام‌های پشت‌سرهم زیاد بود. کمی صبر کنید.');
        throttle_hit('assistant_msg', 600);
        $sessionId = trim(post('sessionId'));
        $questionId = trim(post('questionId'));
        $session = assistant_chat_require_session($pdo, $sessionId, $user);
        $answers = assistant_answers_decode($session['answers_json'] ?? null);
        $meta = assistant_flow_meta($answers);
        $topicId = (string) ($meta['topic'] ?? '');
        if ($topicId === '' || ($meta['phase'] ?? '') !== 'explore') {
            throw new RuntimeException('اول یک موضوع انتخاب کنید.');
        }
        $q = assistant_topic_question($topicId, $questionId);
        if (!$q) {
            throw new RuntimeException('سوال نامعتبر است.');
        }
        $explored = is_array($meta['explored'] ?? null) ? $meta['explored'] : [];
        if (!in_array($questionId, $explored, true)) {
            $explored[] = $questionId;
        }
        $meta['explored'] = $explored;
        $answers = assistant_flow_meta_set($answers, $meta);
        assistant_save_progress($pdo, $sessionId, 1, $answers);

        $tip = (string) ($q['tip'] ?? '');
        $messages = assistant_messages_decode($session['messages_json'] ?? null);
        $messages[] = ['role' => 'user', 'content' => (string) $q['text']];
        $messages[] = ['role' => 'assistant', 'content' => $tip];
        assistant_messages_save($pdo, $sessionId, $messages);

        echo json_encode([
            'sessionId' => $sessionId,
            'mode' => 'guided_ai',
            'phase' => 'explore',
            'botMessage' => $tip,
            'userMessage' => (string) $q['text'],
            'topic' => ['id' => $topicId, 'label' => (string) ($meta['topic_label'] ?? '')],
            'questions' => [], // فرانت لیست را نگه می‌دارد
            'explored' => $explored,
            'done' => false,
            'canComplete' => false,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'add_note') {
        throttle_guard_json('assistant_msg', 40, 600, 'پیام‌های پشت‌سرهم زیاد بود. کمی صبر کنید.');
        throttle_hit('assistant_msg', 600);
        $sessionId = trim(post('sessionId'));
        $note = trim(post('text'));
        $session = assistant_chat_require_session($pdo, $sessionId, $user);
        if ($note === '') {
            throw new RuntimeException('لطفاً نکته‌تان را بنویسید.');
        }
        if (mb_strlen($note) > 2000) {
            throw new RuntimeException('متن خیلی طولانی است.');
        }
        $answers = assistant_answers_decode($session['answers_json'] ?? null);
        $meta = assistant_flow_meta($answers);
        if (($meta['phase'] ?? '') !== 'explore') {
            throw new RuntimeException('در این مرحله نمی‌توان یادداشت افزود.');
        }
        $meta['custom_note'] = $note;
        $answers = assistant_flow_meta_set($answers, $meta);
        assistant_save_progress($pdo, $sessionId, 1, $answers);

        $ack = 'یادداشت شما ثبت شد. می‌توانید سوال دیگری ببینید یا ادامه دهید.';
        $messages = assistant_messages_decode($session['messages_json'] ?? null);
        $messages[] = ['role' => 'user', 'content' => $note];
        $messages[] = ['role' => 'assistant', 'content' => $ack];
        assistant_messages_save($pdo, $sessionId, $messages);

        echo json_encode([
            'sessionId' => $sessionId,
            'mode' => 'guided_ai',
            'phase' => 'explore',
            'botMessage' => $ack,
            'done' => false,
            'canComplete' => false,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'next_step') {
        throttle_guard_json('assistant_msg', 40, 600, 'پیام‌های پشت‌سرهم زیاد بود. کمی صبر کنید.');
        throttle_hit('assistant_msg', 600);
        $sessionId = trim(post('sessionId'));
        $session = assistant_chat_require_session($pdo, $sessionId, $user);
        $answers = assistant_answers_decode($session['answers_json'] ?? null);
        $meta = assistant_flow_meta($answers);
        if (($meta['phase'] ?? '') !== 'explore' || trim((string) ($meta['topic'] ?? '')) === '') {
            throw new RuntimeException('اول موضوع و سوال‌ها را ببینید.');
        }
        $meta['phase'] = 'choice';
        $answers = assistant_flow_meta_set($answers, $meta);
        assistant_save_progress($pdo, $sessionId, 2, $answers);

        $label = (string) ($meta['topic_label'] ?? 'این موضوع');
        $bot = "اگر بخواهید، می‌توانیم بیشتر درباره «{$label}» حرف بزنیم؛ یا همین الان درمانگر مرتبط از کلینیک را معرفی کنم. کدام را ترجیح می‌دهید؟";
        $messages = assistant_messages_decode($session['messages_json'] ?? null);
        $messages[] = ['role' => 'assistant', 'content' => $bot];
        assistant_messages_save($pdo, $sessionId, $messages);

        echo json_encode(assistant_chat_choice_payload($sessionId, $bot), JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'choose_path') {
        throttle_guard_json('assistant_msg', 40, 600, 'پیام‌های پشت‌سرهم زیاد بود. کمی صبر کنید.');
        throttle_hit('assistant_msg', 600);
        $sessionId = trim(post('sessionId'));
        $path = trim(post('path'));
        $session = assistant_chat_require_session($pdo, $sessionId, $user);
        $answers = assistant_answers_decode($session['answers_json'] ?? null);
        $meta = assistant_flow_meta($answers);
        if (($meta['phase'] ?? '') !== 'choice') {
            throw new RuntimeException('الان زمان انتخاب مسیر نیست.');
        }
        $topicLabel = (string) ($meta['topic_label'] ?? 'موضوع انتخابی');

        if ($path === 'talk') {
            $meta['phase'] = 'chat';
            $answers = assistant_flow_meta_set($answers, $meta);
            assistant_save_progress($pdo, $sessionId, 3, $answers);
            $bot = "باشه — درباره «{$topicLabel}» بیشتر بگویید چه چیزی الان بیشتر اذیتتان می‌کند یا دوست دارید روی چه بخشی کار کنیم؟";
            if ($aiMode) {
                try {
                    $topicRow = assistant_topic_by_id((string) ($meta['topic'] ?? 'other'));
                    $aiText = assistant_ai_chat([
                        ['role' => 'system', 'content' => assistant_ai_system_prompt($topicLabel, $topicRow['tags'] ?? [])],
                        ['role' => 'user', 'content' => "کاربر می‌خواهد درباره «{$topicLabel}» بیشتر حرف بزند. با یک دعوت کوتاه و یک سوال مشخص شروع کن. بلوک READY نگذار."],
                    ], 220);
                    $parsed = assistant_ai_parse_reply($aiText);
                    if ($parsed['text'] !== '') {
                        $bot = $parsed['text'];
                    }
                } catch (Throwable $e) {
                    // پیام ثابت
                }
            }
            $messages = assistant_messages_decode($session['messages_json'] ?? null);
            $messages[] = ['role' => 'user', 'content' => 'می‌خواهم بیشتر حرف بزنم'];
            $messages[] = ['role' => 'assistant', 'content' => $bot];
            assistant_messages_save($pdo, $sessionId, $messages);

            echo json_encode([
                'sessionId' => $sessionId,
                'mode' => 'guided_ai',
                'phase' => 'chat',
                'aiChat' => $aiMode,
                'botMessage' => $bot,
                'done' => false,
                'canComplete' => true,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($path === 'therapist') {
            $meta['phase'] = 'done';
            $answers = assistant_flow_meta_set($answers, $meta);
            $matchAnswers = assistant_answers_for_matching($answers);
            $messages = assistant_messages_decode($session['messages_json'] ?? null);
            $messages[] = ['role' => 'user', 'content' => 'درمانگر معرفی کن'];
            $summary = 'موضوع: ' . $topicLabel;
            if (!empty($meta['custom_note'])) {
                $summary .= ' — نکته کاربر: ' . $meta['custom_note'];
            }
            if (!empty($meta['explored']) && is_array($meta['explored'])) {
                $summary .= ' — سوال‌های دیده‌شده: ' . count($meta['explored']);
            }
            $bot = "بر اساس موضوع «{$topicLabel}»، درمانگر و کارگاه مرتبط از مانا کلینیک را پیشنهاد می‌کنم.";
            $messages[] = ['role' => 'assistant', 'content' => $bot];
            $result = assistant_complete_matching($pdo, $sessionId, $matchAnswers, $messages, $summary);
            assistant_messages_save($pdo, $sessionId, $messages);
            echo json_encode(assistant_chat_done_payload($sessionId, $result, $user, $bot), JSON_UNESCAPED_UNICODE);
            exit;
        }

        throw new RuntimeException('مسیر نامعتبر است.');
    }

    if ($action === 'message' && $aiMode) {
        throttle_guard_json('assistant_msg', 40, 600, 'پیام‌های پشت‌سرهم زیاد بود. کمی صبر کنید.');
        throttle_hit('assistant_msg', 600);
        $sessionId = trim(post('sessionId'));
        $text = trim(post('text'));
        $session = assistant_chat_require_session($pdo, $sessionId, $user);
        if ($session['status'] === 'COMPLETED') {
            $doctors = json_decode((string) ($session['matched_doctors_json'] ?? '[]'), true) ?: [];
            $workshops = json_decode((string) ($session['matched_workshops_json'] ?? '[]'), true) ?: [];
            echo json_encode(assistant_chat_done_payload($sessionId, [
                'doctors' => $doctors,
                'workshops' => $workshops,
                'intake_text' => $session['intake_text'] ?? '',
            ], $user, 'گفتگو تمام شده. پیشنهادها را ببینید.'), JSON_UNESCAPED_UNICODE);
            exit;
        }
        $answers = assistant_answers_decode($session['answers_json'] ?? null);
        $meta = assistant_flow_meta($answers);
        if (($meta['phase'] ?? '') !== 'chat') {
            throw new RuntimeException('الان در مرحله گفتگوی آزاد نیستید.');
        }
        if ($text === '') {
            throw new RuntimeException('لطفاً پیامتان را بنویسید.');
        }
        if (mb_strlen($text) > 4000) {
            throw new RuntimeException('پیام خیلی طولانی است.');
        }

        $messages = assistant_messages_decode($session['messages_json'] ?? null);
        $messages[] = ['role' => 'user', 'content' => $text];
        $apiMessages = assistant_openai_messages_for_api($messages, $meta);
        $rawReply = assistant_ai_chat($apiMessages);
        $parsed = assistant_ai_parse_reply($rawReply);
        $messages[] = ['role' => 'assistant', 'content' => $parsed['text']];
        assistant_messages_save($pdo, $sessionId, $messages);

        $userTurns = count(array_filter($messages, static fn ($m) => ($m['role'] ?? '') === 'user'));

        if ($parsed['ready']) {
            $matchAnswers = assistant_answers_for_matching($answers);
            $result = assistant_complete_from_ai($pdo, $sessionId, $messages, $parsed['tags'], $parsed['summary']);
            // تکمیل با تگ‌های موضوع اگر AI تگ نداد
            if (($result['doctors'] ?? []) === [] && $matchAnswers !== []) {
                $result = assistant_complete_matching($pdo, $sessionId, $matchAnswers, $messages, $parsed['summary']);
            }
            echo json_encode(assistant_chat_done_payload($sessionId, $result, $user, $parsed['text']), JSON_UNESCAPED_UNICODE);
            exit;
        }

        echo json_encode([
            'sessionId' => $sessionId,
            'mode' => 'guided_ai',
            'phase' => 'chat',
            'aiChat' => true,
            'botMessage' => $parsed['text'],
            'done' => false,
            'canComplete' => $userTurns >= 2,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'message' && !$aiMode) {
        // بدون API: یادداشت را ذخیره و به معرفی درمانگر نزدیک شو
        throttle_guard_json('assistant_msg', 40, 600, 'پیام‌های پشت‌سرهم زیاد بود. کمی صبر کنید.');
        throttle_hit('assistant_msg', 600);
        $sessionId = trim(post('sessionId'));
        $text = trim(post('text'));
        $session = assistant_chat_require_session($pdo, $sessionId, $user);
        $answers = assistant_answers_decode($session['answers_json'] ?? null);
        $meta = assistant_flow_meta($answers);
        if (($meta['phase'] ?? '') !== 'chat') {
            throw new RuntimeException('الان در مرحله گفتگوی آزاد نیستید.');
        }
        if ($text === '') {
            throw new RuntimeException('لطفاً پیامتان را بنویسید.');
        }
        $meta['custom_note'] = trim(($meta['custom_note'] ?? '') . "\n" . $text);
        $answers = assistant_flow_meta_set($answers, $meta);
        assistant_save_progress($pdo, $sessionId, 3, $answers);
        $bot = "متوجه شدم. می‌توانید بیشتر بنویسید یا با دکمه «پیشنهاد درمانگر» ادامه دهید.";
        $messages = assistant_messages_decode($session['messages_json'] ?? null);
        $messages[] = ['role' => 'user', 'content' => $text];
        $messages[] = ['role' => 'assistant', 'content' => $bot];
        assistant_messages_save($pdo, $sessionId, $messages);
        echo json_encode([
            'sessionId' => $sessionId,
            'mode' => 'guided_ai',
            'phase' => 'chat',
            'aiChat' => false,
            'botMessage' => $bot,
            'done' => false,
            'canComplete' => true,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'complete') {
        throttle_guard_json('assistant_msg', 40, 600, 'پیام‌های پشت‌سرهم زیاد بود. کمی صبر کنید.');
        throttle_hit('assistant_msg', 600);
        $sessionId = trim(post('sessionId'));
        $session = assistant_chat_require_session($pdo, $sessionId, $user);
        if ($session['status'] === 'COMPLETED') {
            echo json_encode(assistant_chat_done_payload($sessionId, [
                'doctors' => json_decode((string) ($session['matched_doctors_json'] ?? '[]'), true) ?: [],
                'workshops' => json_decode((string) ($session['matched_workshops_json'] ?? '[]'), true) ?: [],
                'intake_text' => $session['intake_text'] ?? '',
            ], $user), JSON_UNESCAPED_UNICODE);
            exit;
        }
        $answers = assistant_answers_decode($session['answers_json'] ?? null);
        $meta = assistant_flow_meta($answers);
        $messages = assistant_messages_decode($session['messages_json'] ?? null);
        $matchAnswers = assistant_answers_for_matching($answers);
        $topicLabel = (string) ($meta['topic_label'] ?? 'نیاز مشاوره');

        if ($aiMode && ($meta['phase'] ?? '') === 'chat' && count(array_filter($messages, static fn ($m) => ($m['role'] ?? '') === 'user')) >= 1) {
            $messages[] = ['role' => 'user', 'content' => 'لطفاً اول چند راهکار عملی مرتبط با مشکلم بگو، بعد جمع‌بندی کن و پیشنهاد درمانگر/کارگاه را آماده کن.'];
            $apiMessages = assistant_openai_messages_for_api($messages, $meta);
            $apiMessages[] = ['role' => 'system', 'content' => 'اول ۲ تا ۴ راهکار ساده و امن مرتبط بنویس، بعد جمع‌بندی همدلانه، و در انتهای همان پیام بلوک <<<READY>>> را با tags و summary برگردان.'];
            try {
                $rawReply = assistant_ai_chat($apiMessages, 700);
                $parsed = assistant_ai_parse_reply($rawReply);
                $messages[] = ['role' => 'assistant', 'content' => $parsed['text']];
                $result = assistant_complete_from_ai($pdo, $sessionId, $messages, $parsed['tags'], $parsed['summary'] !== '' ? $parsed['summary'] : $topicLabel);
                echo json_encode(assistant_chat_done_payload($sessionId, $result, $user, $parsed['text']), JSON_UNESCAPED_UNICODE);
                exit;
            } catch (Throwable $e) {
                // fallback matching
            }
        }

        $summary = 'موضوع: ' . $topicLabel;
        if (!empty($meta['custom_note'])) {
            $summary .= ' — ' . $meta['custom_note'];
        }
        $bot = "بر اساس موضوع «{$topicLabel}»، پیشنهاد درمانگر و کارگاه آماده است.";
        $messages[] = ['role' => 'assistant', 'content' => $bot];
        $result = assistant_complete_matching($pdo, $sessionId, $matchAnswers, $messages, $summary);
        assistant_messages_save($pdo, $sessionId, $messages);
        echo json_encode(assistant_chat_done_payload($sessionId, $result, $user, $bot), JSON_UNESCAPED_UNICODE);
        exit;
    }

    // حالت قدیمی guided (اگر هنوز فراخوانی شد)
    if ($action === 'answer') {
        throw new RuntimeException('لطفاً صفحه را تازه کنید و از گزینه‌های جدید استفاده کنید.');
    }

    if ($action === 'status') {
        $sessionId = trim(post('sessionId') ?: (string) ($_GET['sessionId'] ?? ''));
        $session = assistant_session_get($pdo, $sessionId);
        if (!$session || !assistant_session_accessible($session, $user)) {
            throw new RuntimeException('جلسه یافت نشد.');
        }
        $answers = assistant_answers_decode($session['answers_json'] ?? null);
        $meta = assistant_flow_meta($answers);
        $phase = (string) ($meta['phase'] ?? 'topic');
        $payload = [
            'sessionId' => $sessionId,
            'status' => $session['status'],
            'mode' => 'guided_ai',
            'phase' => $phase,
            'messages' => assistant_messages_decode($session['messages_json'] ?? null),
            'doctors' => json_decode((string) ($session['matched_doctors_json'] ?? '[]'), true) ?: [],
            'workshops' => json_decode((string) ($session['matched_workshops_json'] ?? '[]'), true) ?: [],
            'intakePreview' => $session['intake_text'] ?? '',
            'selectedDoctorId' => $session['selected_doctor_id'] ?? null,
            'loggedIn' => (bool) ($user && ($user['role'] ?? '') === 'PATIENT'),
            'done' => in_array($session['status'], ['COMPLETED', 'SENT'], true),
            'canComplete' => $phase === 'chat',
            'aiChat' => $aiMode && $phase === 'chat',
        ];
        if ($phase === 'topic') {
            $payload['topics'] = array_map(static fn ($t) => ['id' => $t['id'], 'label' => $t['label']], assistant_topic_options());
        } elseif ($phase === 'explore') {
            $topicId = (string) ($meta['topic'] ?? 'individual');
            $payload['topic'] = ['id' => $topicId, 'label' => (string) ($meta['topic_label'] ?? '')];
            $payload['questions'] = array_map(static fn ($q) => ['id' => $q['id'], 'text' => $q['text']], assistant_topic_questions($topicId));
            $payload['explored'] = $meta['explored'] ?? [];
        } elseif ($phase === 'choice') {
            $payload['choices'] = [
                ['id' => 'talk', 'label' => 'می‌خواهم بیشتر در این مورد حرف بزنم'],
                ['id' => 'therapist', 'label' => 'درمانگر مناسب معرفی کن'],
            ];
        }
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
        exit;
    }

    throw new RuntimeException('درخواست نامعتبر است.');
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
