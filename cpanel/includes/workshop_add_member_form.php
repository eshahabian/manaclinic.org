<?php
declare(strict_types=1);
if (!workshop_can_add_member(current_user()) || !isset($memberWorkshop)) return;
if (!isset($memberCandidates)) {
    $memberCandidates = $pdo->query("SELECT u.id, u.name, u.username FROM users u WHERE " . workshop_member_user_sql('u') . " ORDER BY u.name")->fetchAll();
}
$memberSelectId = 'member-patient-' . (string) $memberWorkshop['id'];
?>
<form method="post" action="<?= e(url('/staff/workshops/add-member')) ?>" class="stack workshop-member-form">
  <?= csrf_field() ?>
  <input type="hidden" name="workshop_id" value="<?= e((string) $memberWorkshop['id']) ?>">
  <label class="label" for="<?= e($memberSelectId) ?>">انتخاب عضو برای «<?= e((string) $memberWorkshop['title']) ?>»</label>
  <select class="input" id="<?= e($memberSelectId) ?>" name="patient_id" required>
    <option value="">انتخاب عضو</option>
    <?php foreach ($memberCandidates as $memberCandidate): ?>
      <option value="<?= e((string) $memberCandidate['id']) ?>"><?= e($memberCandidate['name'] . ' — ' . $memberCandidate['username']) ?></option>
    <?php endforeach; ?>
  </select>
  <p class="muted">عضویت تأیید و از طریق پیامک و پنل اطلاع‌رسانی می‌شود. پرداخت جداگانه ثبت می‌شود.</p>
  <button class="btn btn-primary" type="submit">اضافه کردن عضو</button>
</form>
