<?php
declare(strict_types=1);
if (!workshop_can_add_member(current_user())) return;
$memberPatients = $pdo->query("SELECT id, name, username FROM users WHERE role='PATIENT' AND COALESCE(is_disabled,0)=0 ORDER BY name")->fetchAll();
$memberWorkshops = $pdo->query("SELECT w.id, w.title FROM workshops w " . workshop_active_doctor_join('w') . " WHERE " . workshop_patient_list_sql('w') . " ORDER BY w.created_at DESC")->fetchAll();
?>
<details class="panel" style="margin:1rem 0">
  <summary style="cursor:pointer;font-weight:700">افزودن دستی عضو به کارگاه</summary>
  <form method="post" action="<?= e(url('/staff/workshops/add-member')) ?>" class="stack" style="margin-top:1rem">
    <?= csrf_field() ?>
    <label class="label" for="member-patient">مراجعه‌کننده</label>
    <select class="input" id="member-patient" name="patient_id" required>
      <option value="">انتخاب مراجعه‌کننده</option>
      <?php foreach ($memberPatients as $memberPatient): ?>
        <option value="<?= e((string) $memberPatient['id']) ?>"><?= e($memberPatient['name'] . ' — ' . $memberPatient['username']) ?></option>
      <?php endforeach; ?>
    </select>
    <label class="label" for="member-workshop">کارگاه</label>
    <select class="input" id="member-workshop" name="workshop_id" required>
      <option value="">انتخاب کارگاه</option>
      <?php foreach ($memberWorkshops as $memberWorkshop): ?>
        <option value="<?= e((string) $memberWorkshop['id']) ?>"><?= e((string) $memberWorkshop['title']) ?></option>
      <?php endforeach; ?>
    </select>
    <p class="muted">عضویت تأیید می‌شود و پیامک و پیام داخل پنل برای فرد ارسال می‌شود. این گزینه به‌معنی ثبت پرداخت نیست.</p>
    <button class="btn btn-primary" type="submit">افزودن عضو و اطلاع‌رسانی</button>
  </form>
</details>
