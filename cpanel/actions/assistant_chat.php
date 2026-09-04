<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/assistant.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// جلوگیری از نشت HTML به‌خاطر notice/warning
@ini_set('display_errors', '0');
@set_time_limit(90);

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
        'mode' => 'ai',
        'botMessage' => $botMessage !== '' ? $botMessage : "ممنون که با من حرف زدید.\nبر اساس گفتگو، چند درمانگر و کارگاه مرتبط پیشنهاد می‌کنم.",
        'doctors' => $result['doctors'],
        'workshops' => $result['workshops'],
        'intakePreview' => $result['intake_text'],
        'loggedIn' => (bool) ($user && ($user['role'] ?? '') === 'PATIENT'),
    ], assistant_chat_auth_urls($sessionId));
}

try {
    if ($action === 'start') {
        $session = assistant_session_create($pdo, ($user && ($user['role'] ?? '') === 'PATIENT') ? (string) $user['id'] : null);
        $sessionId = (string) $session['id'];

        if ($aiMode) {
            $greeting = "سلام، خوش اومدید.\nمن دستیار مانا کلینیک هستم. با من حرف بزنید — هر چی الان روی دلتان است را بگویید تا کمک کنم درمانگر یا کارگاه مناسب پیدا کنیم.";
            try {
                $aiText = assistant_ai_chat([
                    ['role' => 'system', 'content' => assistant_ai_system_prompt()],
                    ['role' => 'user', 'content' => 'گفتگو را با یک سلام کوتاه و دعوت به حرف زدن شروع کن. هنوز سوال تخصصی نپرس؛ فقط خوش‌آمد بگو.'],
                ], 180);
                $parsed = assistant_ai_parse_reply($aiText);
                if ($parsed['text'] !== '') {
                    $greeting = $parsed['text'];
                }
            } catch (Throwable $e) {
                // همان پیام ثابت
            }
            $messages = [['role' => 'assistant', 'content' => $greeting]];
            assistant_messages_save($pdo, $sessionId, $messages);
            echo json_encode([
                'sessionId' => $sessionId,
                'mode' => 'ai',
                'botMessage' => $greeting,
                'done' => false,
                'canComplete' => false,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $questions = assistant_questions();
        $first = $questions[0];
        echo json_encode([
            'sessionId' => $sessionId,
            'mode' => 'guided',
            'step' => 0,
            'total' => count($questions),
            'botMessage' => "سلام، خوش اومدید.\nمن دستیار اولیه مانا کلینیک هستم. با من حرف بزنید تا بهتر بفهمم چه کمکی می‌توانم بکنم.",
            'question' => $first,
            'done' => false,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'message' && $aiMode) {
        $sessionId = trim(post('sessionId'));
        $text = trim(post('text'));
        $session = assistant_session_get($pdo, $sessionId);
        if (!$session || in_array($session['status'], ['SENT'], true)) {
            throw new RuntimeException('جلسه گفتگو یافت نشد.');
        }
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
        if ($text === '') {
            throw new RuntimeException('لطفاً پیامتان را بنویسید.');
        }
        if (mb_strlen($text) > 4000) {
            throw new RuntimeException('پیام خیلی طولانی است.');
        }

        $messages = assistant_messages_decode($session['messages_json'] ?? null);
        $messages[] = ['role' => 'user', 'content' => $text];
        $apiMessages = assistant_openai_messages_for_api($messages);
        $rawReply = assistant_ai_chat($apiMessages);
        $parsed = assistant_ai_parse_reply($rawReply);
        $messages[] = ['role' => 'assistant', 'content' => $parsed['text']];
        assistant_messages_save($pdo, $sessionId, $messages);

        $userTurns = count(array_filter($messages, static fn ($m) => ($m['role'] ?? '') === 'user'));

        if ($parsed['ready']) {
            $result = assistant_complete_from_ai($pdo, $sessionId, $messages, $parsed['tags'], $parsed['summary']);
            echo json_encode(assistant_chat_done_payload($sessionId, $result, $user, $parsed['text']), JSON_UNESCAPED_UNICODE);
            exit;
        }

        echo json_encode([
            'sessionId' => $sessionId,
            'mode' => 'ai',
            'botMessage' => $parsed['text'],
            'done' => false,
            'canComplete' => $userTurns >= 2,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'complete' && $aiMode) {
        $sessionId = trim(post('sessionId'));
        $session = assistant_session_get($pdo, $sessionId);
        if (!$session || $session['status'] === 'SENT') {
            throw new RuntimeException('جلسه گفتگو یافت نشد.');
        }
        if ($session['status'] === 'COMPLETED') {
            echo json_encode(assistant_chat_done_payload($sessionId, [
                'doctors' => json_decode((string) ($session['matched_doctors_json'] ?? '[]'), true) ?: [],
                'workshops' => json_decode((string) ($session['matched_workshops_json'] ?? '[]'), true) ?: [],
                'intake_text' => $session['intake_text'] ?? '',
            ], $user), JSON_UNESCAPED_UNICODE);
            exit;
        }
        $messages = assistant_messages_decode($session['messages_json'] ?? null);
        $userTurns = count(array_filter($messages, static fn ($m) => ($m['role'] ?? '') === 'user'));
        if ($userTurns < 1) {
            throw new RuntimeException('لطفاً کمی بیشتر درباره وضعیتتان بنویسید.');
        }
        $messages[] = ['role' => 'user', 'content' => 'لطفاً جمع‌بندی کن و پیشنهاد درمانگر/کارگاه را آماده کن.'];
        $apiMessages = assistant_openai_messages_for_api($messages);
        $apiMessages[] = ['role' => 'system', 'content' => 'الان باید بلوک <<<READY>>> را با tags و summary برگردانی.'];
        $rawReply = assistant_ai_chat($apiMessages, 500);
        $parsed = assistant_ai_parse_reply($rawReply);
        $messages[] = ['role' => 'assistant', 'content' => $parsed['text']];
        $result = assistant_complete_from_ai($pdo, $sessionId, $messages, $parsed['tags'], $parsed['summary']);
        echo json_encode(assistant_chat_done_payload($sessionId, $result, $user, $parsed['text']), JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'answer') {
        if ($aiMode) {
            throw new RuntimeException('در حالت هوش مصنوعی از ارسال پیام متنی استفاده کنید.');
        }
        $sessionId = trim(post('sessionId'));
        $session = assistant_session_get($pdo, $sessionId);
        if (!$session || in_array($session['status'], ['SENT'], true)) {
            throw new RuntimeException('جلسه گفتگو یافت نشد.');
        }

        $questions = assistant_questions();
        $step = (int) $session['current_step'];
        $answers = assistant_answers_decode($session['answers_json'] ?? null);

        if ($step >= count($questions)) {
            $doctors = json_decode((string) ($session['matched_doctors_json'] ?? '[]'), true) ?: [];
            $workshops = json_decode((string) ($session['matched_workshops_json'] ?? '[]'), true) ?: [];
            echo json_encode([
                'sessionId' => $sessionId,
                'done' => true,
                'mode' => 'guided',
                'botMessage' => 'گفتگو تمام شده. پیشنهادها را ببینید.',
                'doctors' => $doctors,
                'workshops' => $workshops,
                'intakePreview' => $session['intake_text'] ?? '',
                'loggedIn' => (bool) ($user && ($user['role'] ?? '') === 'PATIENT'),
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $q = $questions[$step];
        $isText = ($q['type'] ?? '') === 'text';
        $optionId = trim(post('optionId'));
        $text = trim(post('text'));

        if ($isText) {
            if ($text === '' && empty($q['optional'])) {
                throw new RuntimeException('لطفاً پاسخ را بنویسید.');
            }
            $answers[] = [
                'question_id' => $q['id'],
                'text' => $text,
            ];
        } else {
            $valid = false;
            foreach ($q['options'] ?? [] as $opt) {
                if ($opt['id'] === $optionId) {
                    $valid = true;
                    break;
                }
            }
            if (!$valid) {
                throw new RuntimeException('گزینه نامعتبر است.');
            }
            $answers[] = [
                'question_id' => $q['id'],
                'option_id' => $optionId,
            ];
        }

        $nextStep = $step + 1;
        if ($nextStep >= count($questions)) {
            $result = assistant_complete_matching($pdo, $sessionId, $answers);
            echo json_encode(array_merge([
                'sessionId' => $sessionId,
                'done' => true,
                'mode' => 'guided',
                'botMessage' => "ممنون که با من حرف زدید.\nبر اساس پاسخ‌هایتان، چند درمانگر و کارگاه مرتبط پیشنهاد می‌کنم. یک درمانگر را انتخاب کنید تا شرح‌حال برایش ارسال شود.",
                'doctors' => $result['doctors'],
                'workshops' => $result['workshops'],
                'intakePreview' => $result['intake_text'],
                'loggedIn' => (bool) ($user && ($user['role'] ?? '') === 'PATIENT'),
            ], assistant_chat_auth_urls($sessionId)), JSON_UNESCAPED_UNICODE);
            exit;
        }

        assistant_save_progress($pdo, $sessionId, $nextStep, $answers);
        $nextQ = $questions[$nextStep];
        $ack = $isText
            ? (trim($text) !== '' ? 'یادداشت شما ثبت شد.' : 'باشه، رد شدیم.')
            : 'متوجه شدم.';

        echo json_encode([
            'sessionId' => $sessionId,
            'mode' => 'guided',
            'step' => $nextStep,
            'total' => count($questions),
            'botMessage' => $ack,
            'question' => $nextQ,
            'done' => false,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'status') {
        $sessionId = trim(post('sessionId') ?: (string) ($_GET['sessionId'] ?? ''));
        $session = assistant_session_get($pdo, $sessionId);
        if (!$session) {
            throw new RuntimeException('جلسه یافت نشد.');
        }
        echo json_encode([
            'sessionId' => $sessionId,
            'status' => $session['status'],
            'mode' => $aiMode ? 'ai' : 'guided',
            'step' => (int) $session['current_step'],
            'total' => count(assistant_questions()),
            'messages' => assistant_messages_decode($session['messages_json'] ?? null),
            'doctors' => json_decode((string) ($session['matched_doctors_json'] ?? '[]'), true) ?: [],
            'workshops' => json_decode((string) ($session['matched_workshops_json'] ?? '[]'), true) ?: [],
            'intakePreview' => $session['intake_text'] ?? '',
            'selectedDoctorId' => $session['selected_doctor_id'] ?? null,
            'loggedIn' => (bool) ($user && ($user['role'] ?? '') === 'PATIENT'),
            'done' => in_array($session['status'], ['COMPLETED', 'SENT'], true),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    throw new RuntimeException('درخواست نامعتبر است.');
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
