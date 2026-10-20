<?php
declare(strict_types=1);
if (!workshop_can_add_member(current_user()) || !isset($memberWorkshop)) return;
if (!isset($memberCandidates)) {
    $memberCandidates = $pdo->query("SELECT u.id, u.name, u.username, u.phone FROM users u WHERE " . workshop_member_user_sql('u') . " ORDER BY u.name")->fetchAll();
}
$memberSelectId = 'member-patient-' . (string) $memberWorkshop['id'];
?>
<form method="post" action="<?= e(url('/staff/workshops/add-member')) ?>" class="stack workshop-member-form">
  <?= csrf_field() ?>
  <input type="hidden" name="workshop_id" value="<?= e((string) $memberWorkshop['id']) ?>">
  <label class="label" for="<?= e($memberSelectId) ?>">انتخاب عضو برای «<?= e((string) $memberWorkshop['title']) ?>»</label>
  <select class="input" id="<?= e($memberSelectId) ?>" name="patient_id" data-workshop-member-select required>
    <option value="">انتخاب عضو</option>
    <?php foreach ($memberCandidates as $memberCandidate): ?>
      <?php
        $memberPhone = function_exists('normalize_phone') ? normalize_phone((string) ($memberCandidate['phone'] ?? '')) : (string) ($memberCandidate['phone'] ?? '');
        $memberSearch = $memberCandidate['name'] . ' ' . $memberCandidate['username'] . ' ' . $memberPhone;
        if (preg_match('/^09[0-9]{9}$/D', $memberPhone)) {
            $memberSearch .= ' +98' . substr($memberPhone, 1) . ' 0098' . substr($memberPhone, 1);
        }
      ?>
      <option value="<?= e((string) $memberCandidate['id']) ?>" data-search="<?= e($memberSearch) ?>"><?= e($memberCandidate['name'] . ' — ' . $memberCandidate['username'] . ($memberPhone !== '' ? ' — ' . $memberPhone : '')) ?></option>
    <?php endforeach; ?>
  </select>
  <p class="muted">عضویت تأیید و از طریق پیامک و پنل اطلاع‌رسانی می‌شود. پرداخت جداگانه ثبت می‌شود.</p>
  <button class="btn btn-primary" type="submit">اضافه کردن عضو</button>
</form>

<?php if (empty($GLOBALS['workshopMemberSearchLoaded'])): $GLOBALS['workshopMemberSearchLoaded'] = true; ?>
<script src="<?= e(url('/assets/js/search-select.js')) ?>?v=20260905a" defer></script>
<script src="<?= e(url('/assets/js/workshop-member-search.js')) ?>?v=20261020a" defer></script>
<?php endif; ?>
