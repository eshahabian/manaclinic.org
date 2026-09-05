<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/assistant.php';
require_once __DIR__ . '/../../includes/secretary_panel.php';

$user = require_login(['SECRETARY']);
ensure_assistant_schema($pdo);

$id = trim((string) ($_GET['id'] ?? ''));
$session = $id !== '' ? assistant_session_get($pdo, $id) : null;
if (!$session || ($session['status'] ?? '') !== 'SENT') {
    flash_set('error', 'گفتگو یافت نشد.');
    redirect('/secretary/intakes');
}

$patient = null;
if (!empty($session['patient_id'])) {
    $st = $pdo->prepare('SELECT id, name, phone, username FROM users WHERE id=? LIMIT 1');
    $st->execute([$session['patient_id']]);
    $patient = $st->fetch() ?: null;
}

$doctors = $pdo->query("
  SELECT dp.id, u.name, dp.specialty
  FROM doctor_profiles dp
  JOIN users u ON u.id = dp.user_id
  WHERE dp.is_active=1 AND dp.is_approved=1
  ORDER BY u.name ASC
")->fetchAll();

$matched = json_decode((string) ($session['matched_doctors_json'] ?? '[]'), true) ?: [];
$assigned = !empty($session['assigned_at']);
$assignedBy = null;
if (!empty($session['assigned_by_user_id'])) {
    $assignedBy = staff_user_by_id($pdo, (string) $session['assigned_by_user_id']);
}

ob_start();
?>
<p><a href="<?= e(url('/secretary/intakes')) ?>" class="muted">← بازگشت به فهرست</a></p>
<h1 style="margin-top:.75rem">ارجاع گفتگوی دستیار</h1>

<div class="panel stack" style="margin-top:1rem">
  <div>
    <strong>مراجعه‌کننده:</strong>
    <?= e($patient['name'] ?? '—') ?>
    <?php if (!empty($patient['phone'])): ?>
      <span class="muted"> · <?= e($patient['phone']) ?></span>
    <?php endif; ?>
  </div>
  <?php if (!empty($session['ai_summary'])): ?>
    <div>
      <strong>خلاصه هوش مصنوعی</strong>
      <p style="margin:.35rem 0 0;line-height:1.85"><?= e($session['ai_summary']) ?></p>
    </div>
  <?php endif; ?>
  <div>
    <strong>متن کامل شرح‌حال</strong>
    <pre style="white-space:pre-wrap;font-family:inherit;line-height:1.85;background:#f7faf8;border:1px solid var(--line);border-radius:.75rem;padding:.9rem;margin:.5rem 0 0"><?= e($session['intake_text'] ?? '') ?></pre>
  </div>
</div>

<?php if ($matched): ?>
  <div class="panel stack" style="margin-top:1rem">
    <strong>پیشنهاد سیستم</strong>
    <ul style="margin:0;padding-inline-start:1.2rem;line-height:1.8">
      <?php foreach ($matched as $d): ?>
        <li><?= e($d['name'] ?? '') ?> — <?= e($d['specialty'] ?? '') ?></li>
      <?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<?php if ($assigned): ?>
  <div class="flash flash-success" style="margin-top:1rem">
    این گفتگو قبلاً ارجاع شده است.
    <?= staff_sign_html($assignedBy, 'ارجاع توسط') ?>
  </div>
<?php else: ?>
  <form class="panel stack" method="post" action="<?= e(url('/secretary/intakes/' . $session['id'] . '/assign')) ?>" style="margin-top:1rem">
    <h2 style="margin:0;font-size:1.05rem">ارجاع به درمانگر</h2>
    <div>
      <label class="label" for="doctor_id">درمانگر</label>
      <select class="input" name="doctor_id" id="doctor_id" required>
        <option value="">انتخاب کنید…</option>
        <?php foreach ($doctors as $d): ?>
          <option value="<?= e($d['id']) ?>" <?= (($session['selected_doctor_id'] ?? '') === $d['id']) ? 'selected' : '' ?>>
            <?= e($d['name']) ?> — <?= e($d['specialty']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label class="label" for="note">یادداشت منشی (اختیاری)</label>
      <textarea class="input" name="note" id="note" rows="3" placeholder="مثلاً اولویت نوبت / نکته هماهنگی"></textarea>
    </div>
    <button class="btn btn-primary" type="submit">ارجاع و ثبت در پرونده درمانگر</button>
  </form>
<?php endif; ?>
<?php
render_secretary_page('ارجاع گفتگو', ob_get_clean());
