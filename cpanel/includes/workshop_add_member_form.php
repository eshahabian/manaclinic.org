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

<?php
$memberProfilePrefix = 'member-profile-' . (string) $memberWorkshop['id'];
require_once __DIR__ . '/user_referral.php';
?>
<details class="workshop-create-profile" style="margin:.75rem 0" data-workshop-create-profile>
  <summary class="btn btn-outline btn-sm">فرد در فهرست نیست؟ ساخت پروفایل جدید</summary>
  <form class="stack" method="post" action="<?= e(url('/staff/workshops/add-member')) ?>" data-workshop-create-form data-workshop-select="<?= e($memberSelectId) ?>" data-transliterate-url="<?= e(url('/api/transliterate-name')) ?>" style="margin-top:.75rem">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="create_profile">
    <input type="hidden" name="workshop_id" value="<?= e((string) $memberWorkshop['id']) ?>">
    <div class="grid-2">
      <?php foreach (['new_first_name'=>'نام','new_last_name'=>'نام خانوادگی','new_name_en'=>'نام به انگلیسی','new_surname'=>'نام خانوادگی به انگلیسی'] as $memberField=>$memberLabel): ?>
        <div>
          <label class="label" for="<?= e($memberProfilePrefix . '-' . $memberField) ?>"><?= e($memberLabel) ?></label>
          <input class="input" id="<?= e($memberProfilePrefix . '-' . $memberField) ?>" name="<?= e($memberField) ?>" required<?= in_array($memberField, ['new_name_en','new_surname'], true) ? ' dir="ltr"' : '' ?>>
        </div>
      <?php endforeach; ?>
      <div>
        <label class="label" for="<?= e($memberProfilePrefix) ?>-phone">موبایل</label>
        <input class="input" id="<?= e($memberProfilePrefix) ?>-phone" name="new_phone" dir="ltr" inputmode="tel" autocomplete="tel" required>
      </div>
      <div>
        <label class="label" for="<?= e($memberProfilePrefix) ?>-username">نام کاربری</label>
        <input class="input" id="<?= e($memberProfilePrefix) ?>-username" name="new_username" dir="ltr" pattern="[a-zA-Z0-9._-]{3,32}" required>
      </div>
      <div>
        <label class="label" for="<?= e($memberProfilePrefix) ?>-referral">معرف</label>
        <?= user_referral_options_html('new_referral_source', $memberProfilePrefix . '-referral', '', true) ?>
      </div>
      <div>
        <label class="label">درمانگر مربوط به پروفایل</label>
        <p class="muted">درمانگر همین کارگاه</p>
      </div>
      <?= password_field_html($memberProfilePrefix . '-password', 'new_password', ['label'=>'رمز عبور','autocomplete'=>'new-password','minlength'=>6,'rules'=>false,'suggest'=>true]) ?>
      <?= password_field_html($memberProfilePrefix . '-password-confirm', 'new_password_confirm', ['label'=>'تکرار رمز عبور','autocomplete'=>'new-password','minlength'=>6,'confirm'=>true,'pair'=>$memberProfilePrefix . '-password','rules'=>false]) ?>
    </div>
    <p class="muted" data-workshop-create-status role="status" aria-live="polite">پس از ساخت پروفایل، فرد برای اضافه شدن به همین کارگاه انتخاب می‌شود.</p>
    <button class="btn btn-primary btn-sm" type="submit">ساخت پروفایل و انتخاب عضو</button>
  </form>
</details>

<?php if (empty($GLOBALS['workshopMemberSearchLoaded'])): $GLOBALS['workshopMemberSearchLoaded'] = true; ?>
<script src="<?= e(url('/assets/js/search-select.js')) ?>?v=20260905a" defer></script>
<script src="<?= e(url('/assets/js/name-transliterate.js')) ?>?v=20260914a" defer></script>
<script src="<?= e(url('/assets/js/workshop-member-search.js')) ?>?v=20261020profile2" defer></script>
<?php endif; ?>
