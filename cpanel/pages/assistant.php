<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/assistant.php';

if (!assistant_enabled()) {
    flash_set('error', 'دستیار گفتگو فعلاً در دسترس نیست.');
    redirect('/');
}

ensure_assistant_schema($pdo);

$pageTitle = 'با من حرف بزن';
$user = current_user();
$isPatient = $user && ($user['role'] ?? '') === 'PATIENT';
$resumeSession = trim((string) ($_GET['session'] ?? ''));

$assistantConfig = [
    'chatUrl' => url('/assistant/chat'),
    'sendUrl' => url('/assistant/send'),
    'reportBase' => url('/assistant/report'),
    'loginUrl' => url('/login') . '?next=' . rawurlencode(url('/assistant' . ($resumeSession !== '' ? '?session=' . $resumeSession : ''))),
    'registerUrl' => url('/register') . '?next=' . rawurlencode(url('/assistant' . ($resumeSession !== '' ? '?session=' . $resumeSession : ''))),
    'resumeSession' => $resumeSession,
    'loggedIn' => (bool) $isPatient,
    'aiEnabled' => assistant_ai_available(),
];

ob_start();
?>
<section class="container-page section assistant-page">
  <div class="assistant-head">
    <h1>با من حرف بزن</h1>
    <p class="muted">
      <?= assistant_ai_available()
        ? 'گفتگوی واقعی با دستیار هوشمند برای پیدا کردن درمانگر یا کارگاه مناسب'
        : 'توجه: کلید API روی سرور تنظیم نشده — فعلاً حالت سوال‌های ثابت فعال است. برای هوش مصنوعی واقعی، openai_api_key را در config.php سرور بگذارید.' ?>
    </p>
  </div>

  <div class="assistant-shell panel">
    <div id="assistant-messages" class="assistant-messages" aria-live="polite"></div>
    <div id="assistant-controls" class="assistant-controls"></div>
    <div id="assistant-results" class="assistant-results" hidden></div>
    <p class="assistant-disclaimer muted">
      این ابزار تشخیص پزشکی یا روان‌پزشکی نیست. اگر در بحران هستید یا افکار آسیب به خود دارید، فوراً با اورژانس (۱۱۵) یا خطوط اضطراری تماس بگیرید.
    </p>
  </div>
</section>
<?php
$content = ob_get_clean();

$pageScripts = '<script>window.__ASSISTANT__ = ' . json_encode($assistantConfig, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ';</script>'
    . '<script src="' . e(url('/assets/js/assistant.js')) . '?v=20260904e"></script>';

require __DIR__ . '/../includes/layout.php';
