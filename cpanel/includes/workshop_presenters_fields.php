<?php
declare(strict_types=1);
if(!workshop_can_add_member(current_user()))return;
require_once __DIR__.'/workshop_presenters.php';
workshop_presenters_schema($pdo);
$presenterSelected=[];
if($presenterEditWorkshop){
    $presenterQuery=$pdo->prepare('SELECT doctor_id FROM workshop_presenters WHERE workshop_id=?');
    $presenterQuery->execute([(string)$presenterEditWorkshop['id']]);
    $presenterSelected=$presenterQuery->fetchAll(PDO::FETCH_COLUMN);
}
$presenterChoices=$pdo->query('SELECT dp.id,u.name FROM doctor_profiles dp JOIN users u ON u.id=dp.user_id WHERE dp.is_active=1 AND dp.is_approved=1 AND COALESCE(u.is_disabled,0)=0 ORDER BY u.name')->fetchAll();
?>
<fieldset style="border:1px solid var(--line);border-radius:12px;padding:1rem">
  <legend>اجراکنندگان کارگاه</legend>
  <input type="hidden" name="presenters_present" value="1">
  <p class="muted">یک یا چند درمانگر را انتخاب کنید. برای افراد تازه انتخاب‌شده، پیامک تأیید با زمان و محل ثبت‌شدهٔ کارگاه در صف قرار می‌گیرد.</p>
  <div style="display:grid;gap:.5rem;max-height:16rem;overflow:auto">
    <?php foreach($presenterChoices as $presenterChoice): ?>
      <label><input type="checkbox" name="presenters[]" value="<?= e((string)$presenterChoice['id']) ?>"<?= in_array($presenterChoice['id'],$presenterSelected,true)?' checked':'' ?>> <?= e((string)$presenterChoice['name']) ?></label>
    <?php endforeach; ?>
  </div>
</fieldset>
