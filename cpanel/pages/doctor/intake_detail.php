<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/doctor_panel.php';
require_once __DIR__ . '/../../includes/assistant.php';

$ctx = require_doctor_profile($pdo);
ensure_assistant_schema($pdo);

$id = trim((string) ($_GET['id'] ?? ''));
$session = $id !== '' ? assistant_session_get($pdo, $id) : null;
if (!$session || ($session['status'] ?? '') !== 'SENT') {
    flash_set('error', 'گفتگو یافت نشد.');
    redirect('/doctor/intakes');
}

$patientName = 'مراجع مهمان';
$patientPhone = '';
if (!empty($session['patient_id'])) {
    $st = $pdo->prepare('SELECT name, phone FROM users WHERE id=? LIMIT 1');
    $st->execute([(string) $session['patient_id']]);
    $p = $st->fetch();
    if ($p) {
        $patientName = (string) ($p['name'] ?? $patientName);
        $patientPhone = (string) ($p['phone'] ?? '');
    }
}

$messages = assistant_messages_decode($session['messages_json'] ?? null);
$intake = (string) ($session['intake_text'] ?? '');

ob_start();
?>
<div class="panel">
  <p class="panel-back">
    <a class="btn btn-outline btn-sm" href="<?= e(url('/doctor')) ?>">بازگشت به پنل</a>
    <a class="btn btn-outline btn-sm" href="<?= e(url('/doctor/intakes')) ?>">بازگشت به فهرست</a>
  </p>
  <h1>نسخه گفتگوی دستیار</h1>
  <p class="muted" style="margin-top:.35rem">
    <?= e($patientName) ?>
    <?php if ($patientPhone !== ''): ?> · <?= e($patientPhone) ?><?php endif; ?>
    · <?= e((string) ($session['sent_at'] ?? '')) ?>
  </p>

  <?php if (!empty($session['ai_summary'])): ?>
    <h2 style="font-size:1.05rem;margin:1.25rem 0 .5rem">خلاصه</h2>
    <p style="line-height:1.8"><?= e((string) $session['ai_summary']) ?></p>
  <?php endif; ?>

  <h2 style="font-size:1.05rem;margin:1.25rem 0 .5rem">شرح‌حال / متن ارسالی</h2>
  <pre style="white-space:pre-wrap;font-family:inherit;line-height:1.85;background:#f7faf8;border:1px solid var(--line);border-radius:.75rem;padding:.9rem;margin:0"><?= e($intake) ?></pre>

  <?php if ($messages): ?>
    <h2 style="font-size:1.05rem;margin:1.25rem 0 .5rem">متن گفتگو</h2>
    <div class="stack" style="display:grid;gap:.5rem">
      <?php foreach ($messages as $m): ?>
        <div class="panel" style="padding:.75rem .9rem">
          <strong><?= ($m['role'] ?? '') === 'assistant' ? 'دستیار' : 'مراجع' ?></strong>
          <p style="margin:.35rem 0 0;line-height:1.8;white-space:pre-wrap"><?= e((string) ($m['content'] ?? '')) ?></p>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
<?php
render_doctor_page('جزئیات گفتگو', ob_get_clean());
