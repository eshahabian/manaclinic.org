<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/assistant.php';

if (!assistant_enabled()) {
    flash_set('error', 'دستیار گفتگو فعلاً در دسترس نیست.');
    redirect('/');
}

ensure_assistant_schema($pdo);
$sessionId = trim((string) ($_GET['session'] ?? ''));
$session = $sessionId !== '' ? assistant_session_get($pdo, $sessionId) : null;
if (!$session || !in_array($session['status'], ['COMPLETED', 'SENT'], true)) {
    flash_set('error', 'گزارش گفتگو یافت نشد.');
    redirect('/assistant');
}

$answers = assistant_answers_decode($session['answers_json'] ?? null);
$doctors = json_decode((string) ($session['matched_doctors_json'] ?? '[]'), true) ?: [];
$workshops = json_decode((string) ($session['matched_workshops_json'] ?? '[]'), true) ?: [];
$intake = (string) ($session['intake_text'] ?? '');
if ($intake === '') {
    $intake = assistant_build_intake_text($session, $answers, $doctors, $workshops);
}

$pageTitle = 'گزارش گفتگو';
$pageHead = '<style>
@media print {
  .site-header, .site-footer, #particle-canvas, .no-print, .flash { display: none !important; }
  body { background: #fff !important; }
  .assistant-report { box-shadow: none !important; border: none !important; }
}
.assistant-report { max-width: 48rem; margin: 0 auto; }
.assistant-report pre {
  white-space: pre-wrap;
  word-break: break-word;
  font-family: Vazirmatn, Tahoma, sans-serif;
  line-height: 1.9;
  font-size: .95rem;
  background: #f7faf8;
  border: 1px solid var(--line, #dde5e0);
  border-radius: .75rem;
  padding: 1rem 1.1rem;
}
</style>';

ob_start();
?>
<section class="container-page section">
  <div class="assistant-report panel">
    <div class="no-print" style="display:flex;flex-wrap:wrap;gap:.75rem;justify-content:space-between;align-items:center;margin-bottom:1rem">
      <div>
        <h1 style="margin:0">گزارش گفتگوی اولیه</h1>
        <p class="muted" style="margin:.35rem 0 0">قابل چاپ یا ذخیره به‌صورت PDF از طریق چاپ مرورگر</p>
      </div>
      <div style="display:flex;gap:.5rem;flex-wrap:wrap">
        <button type="button" class="btn btn-primary" onclick="window.print()">چاپ / PDF</button>
        <a class="btn btn-outline" href="<?= e(url('/assistant?session=' . rawurlencode($sessionId))) ?>">بازگشت به گفتگو</a>
      </div>
    </div>

    <p class="muted" style="font-size:.85rem">
      وضعیت: <?= e($session['status'] === 'SENT' ? 'ارسال‌شده به درمانگر' : 'تکمیل‌شده — در انتظار ارسال') ?>
      · شناسه: <?= e($sessionId) ?>
    </p>

    <h2 style="font-size:1.05rem;margin:1.25rem 0 .5rem">متن شرح‌حال</h2>
    <pre><?= e($intake) ?></pre>

    <?php if ($doctors): ?>
      <h2 style="font-size:1.05rem;margin:1.25rem 0 .5rem">پیشنهاد درمانگر</h2>
      <ol>
        <?php foreach ($doctors as $d): ?>
          <li><?= e($d['name'] ?? '') ?> — <?= e($d['specialty'] ?? '') ?></li>
        <?php endforeach; ?>
      </ol>
    <?php endif; ?>

    <?php if ($workshops): ?>
      <h2 style="font-size:1.05rem;margin:1.25rem 0 .5rem">پیشنهاد کارگاه</h2>
      <ol>
        <?php foreach ($workshops as $w): ?>
          <li><?= e($w['title'] ?? '') ?> (<?= e($w['type_label'] ?? $w['type'] ?? '') ?>) — <?= e($w['doctor_name'] ?? '') ?></li>
        <?php endforeach; ?>
      </ol>
    <?php endif; ?>

    <p class="muted" style="margin-top:1.5rem;font-size:.85rem;line-height:1.8">
      این گزارش تشخیص پزشکی نیست. در شرایط بحران به اورژانس یا خطوط اضطراری مراجعه کنید.
    </p>
  </div>
</section>
<?php
$content = ob_get_clean();
require __DIR__ . '/../includes/layout.php';
