<?php
declare(strict_types=1);
if (!workshop_can_add_member(current_user())) return;
require_once __DIR__.'/workshop_presenters.php';
workshop_presenters_schema($pdo);
$presenterWorkshopId=(string)$presenterWorkshop['id'];
$presenterQuery=$pdo->prepare('SELECT doctor_id FROM workshop_presenters WHERE workshop_id=?');
$presenterQuery->execute([$presenterWorkshopId]);$presenterSelected=$presenterQuery->fetchAll(PDO::FETCH_COLUMN);
$presenterChoices=$pdo->query('SELECT dp.id,u.name FROM doctor_profiles dp JOIN users u ON u.id=dp.user_id WHERE dp.is_active=1 AND dp.is_approved=1 AND COALESCE(u.is_disabled,0)=0 ORDER BY u.name')->fetchAll();
?>
<details style="margin-top:.75rem">
  <summary class="btn btn-outline btn-sm">اجراکنندگان کارگاه</summary>
  <form method="post" action="<?= e(url($presenterPostBase)) ?>" class="stack" style="padding:.75rem">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_presenters">
    <input type="hidden" name="id" value="<?= e($presenterWorkshopId) ?>">
    <p class="muted">یک یا چند درمانگر را انتخاب کنید. با ذخیره، برای اجراکنندگان تازه انتخاب‌شده پیامک تأیید در صف قرار می‌گیرد.</p>
    <div style="display:grid;gap:.5rem;max-height:16rem;overflow:auto">
    <?php foreach($presenterChoices as $presenterChoice): ?>
      <label><input type="checkbox" name="presenters[]" value="<?= e((string)$presenterChoice['id']) ?>"<?= in_array($presenterChoice['id'],$presenterSelected,true)?' checked':'' ?>> <?= e((string)$presenterChoice['name']) ?></label>
    <?php endforeach; ?>
    </div>
    <button class="btn btn-primary btn-sm" type="submit">ذخیره اجراکنندگان و اطلاع‌رسانی</button>
  </form>
</details>
