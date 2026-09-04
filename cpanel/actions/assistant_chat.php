<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/assistant.php';

header('Content-Type: application/json; charset=utf-8');

if (!assistant_enabled()) {
    http_response_code(403);
    echo json_encode(['error' => 'دستیار فعلاً غیرفعال است.'], JSON_UNESCAPED_UNICODE);
    exit;
}

ensure_assistant_schema($pdo);
$user = current_user();
$action = post('action') ?: (string) ($_GET['action'] ?? '');

try {
    if ($action === 'start') {
        $session = assistant_session_create($pdo, ($user && ($user['role'] ?? '') === 'PATIENT') ? (string) $user['id'] : null);
        $questions = assistant_questions();
        $first = $questions[0];
        echo json_encode([
            'sessionId' => $session['id'],
            'step' => 0,
            'total' => count($questions),
            'botMessage' => "سلام، خوش اومدید.\nمن دستیار اولیه مانا کلینیک هستم. با من حرف بزنید تا بهتر بفهمم چه کمکی می‌توانم بکنم.",
            'question' => $first,
            'done' => false,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'answer') {
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
            echo json_encode([
                'sessionId' => $sessionId,
                'done' => true,
                'botMessage' => "ممنون که با من حرف زدید.\nبر اساس پاسخ‌هایتان، چند درمانگر و کارگاه مرتبط پیشنهاد می‌کنم. یک درمانگر را انتخاب کنید تا شرح‌حال برایش ارسال شود.",
                'doctors' => $result['doctors'],
                'workshops' => $result['workshops'],
                'intakePreview' => $result['intake_text'],
                'loggedIn' => (bool) ($user && ($user['role'] ?? '') === 'PATIENT'),
                'loginUrl' => url('/login') . '?next=' . rawurlencode(url('/assistant?session=' . $sessionId)),
                'registerUrl' => url('/register') . '?next=' . rawurlencode(url('/assistant?session=' . $sessionId)),
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        assistant_save_progress($pdo, $sessionId, $nextStep, $answers);
        $nextQ = $questions[$nextStep];
        $ack = $isText
            ? (trim($text) !== '' ? 'یادداشت شما ثبت شد.' : 'باشه، رد شدیم.')
            : 'متوجه شدم.';

        echo json_encode([
            'sessionId' => $sessionId,
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
            'step' => (int) $session['current_step'],
            'total' => count(assistant_questions()),
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
