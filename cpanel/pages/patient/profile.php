<?php
declare(strict_types=1);

$user = require_login(['PATIENT']);
require_once __DIR__ . '/../../includes/patient_panel.php';
require_once __DIR__ . '/../../includes/doctor_clinical.php';
require_once __DIR__ . '/../../includes/mail.php';

$stmt = $pdo->prepare('SELECT * FROM users WHERE id=?');
$stmt->execute([$user['id']]);
$profile = $stmt->fetch();

$therapistNotes = patient_visible_therapist_notes($pdo, (string) $user['id']);
$careNotes = patient_care_notes_list($pdo, (string) $user['id'], 80);

ob_start();
?>
<div class="stack" style="max-width:40rem">
  <h1>پروفایل</h1>
  <form class="panel form-stack" method="post" action="<?= e(url('/dashboard/profile')) ?>">
    <?= csrf_field() ?>
    <div>
      <label class="label">نام</label>
      <input class="input" name="name" value="<?= e($profile['name']) ?>" required>
    </div>
    <div>
      <label class="label">نام کاربری</label>
      <input class="input" value="<?= e((string)$profile['username']) ?>" disabled dir="ltr">
    </div>
    <div>
      <label class="label">ایمیل</label>
      <input class="input" name="email" type="email" value="<?= e(mail_is_real_email((string) ($profile['email'] ?? '')) ? (string) $profile['email'] : '') ?>" required dir="ltr" autocomplete="email" placeholder="you@example.com">
      <p class="muted" style="font-size:.85rem;margin:.35rem 0 0;line-height:1.7">برای فراموشی رمز و پیام‌های سایت، ایمیل واقعی لازم است.</p>
    </div>
    <div>
      <label class="label">موبایل</label>
      <input class="input" name="phone" value="<?= e((string)$profile['phone']) ?>" dir="ltr">
    </div>
    <button class="btn btn-primary" type="submit">ذخیره تغییرات</button>
  </form>

  <section class="panel stack" id="therapist-notes">
    <div>
      <h2 style="font-size:1.1rem;margin:0">نوت درمانگر</h2>
      <p class="muted" style="margin:.35rem 0 0;font-size:.9rem">یادداشت‌هایی که درمانگر برای شما نوشته (جلسات فردی و هر مورد مرتبط).</p>
    </div>
    <?php if (!$therapistNotes): ?>
      <p class="muted" style="margin:0">هنوز نوتی از درمانگر برای شما ثبت نشده.</p>
    <?php else: ?>
      <ul class="patient-note-feed">
        <?php foreach ($therapistNotes as $tn): ?>
          <li>
            <p class="muted" style="margin:0 0 .35rem;font-size:.85rem">
              <?= e((string) ($tn['doctor_name'] ?? 'درمانگر')) ?>
              <?php if (($tn['source'] ?? '') === 'workshop'): ?>
                · کارگاه<?= !empty($tn['workshop_title']) ? ' «' . e((string) $tn['workshop_title']) . '»' : '' ?>
              <?php elseif (!empty($tn['starts_at'])): ?>
                · جلسه <?= e(format_fa_datetime((string) $tn['starts_at'])) ?>
              <?php else: ?>
                · <?= e(format_fa_datetime((string) (($tn['updated_at'] ?? '') ?: ($tn['created_at'] ?? '')))) ?>
              <?php endif; ?>
            </p>
            <p style="margin:0;line-height:1.85;white-space:pre-wrap"><?= e((string) (($tn['body'] ?? '') ?: ($tn['note_text'] ?? ''))) ?></p>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>

  <section class="panel stack" id="my-notes">
    <div>
      <h2 style="font-size:1.1rem;margin:0">یادداشت‌های من برای درمانگر</h2>
      <p class="muted" style="margin:.35rem 0 0;font-size:.9rem">هر چیزی درباره جلسات فردی، کارگاه یا دوره‌ها که می‌خواهید درمانگر در پرونده‌تان ببیند.</p>
    </div>
    <form method="post" action="<?= e(url('/dashboard/care-notes')) ?>" class="form-stack">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="create">
      <label class="label" for="care_note_body">یادداشت جدید</label>
      <textarea class="input" id="care_note_body" name="body" rows="4" required placeholder="مثلاً تکلیف کارگاه، حس بعد از جلسه، سوال برای درمانگر…"></textarea>
      <button class="btn btn-primary" type="submit">ثبت یادداشت</button>
    </form>
    <?php if (!$careNotes): ?>
      <p class="muted" style="margin:0">هنوز یادداشتی ننوشته‌اید.</p>
    <?php else: ?>
      <ul class="patient-note-feed">
        <?php foreach ($careNotes as $cn): ?>
          <li>
            <div class="row-between" style="gap:.5rem;align-items:flex-start">
              <p class="muted" style="margin:0;font-size:.85rem"><?= e(format_fa_datetime((string) ($cn['created_at'] ?? ''))) ?></p>
              <form method="post" action="<?= e(url('/dashboard/care-notes')) ?>" onsubmit="return confirm('این یادداشت حذف شود؟');">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="note_id" value="<?= e((string) ($cn['id'] ?? '')) ?>">
                <button type="submit" class="btn btn-outline btn-sm" style="color:var(--danger)">حذف</button>
              </form>
            </div>
            <p style="margin:.4rem 0 0;line-height:1.85;white-space:pre-wrap"><?= e((string) ($cn['body'] ?? '')) ?></p>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>

  <form id="change-password" class="panel form-stack" method="post" action="<?= e(url('/change-password')) ?>" autocomplete="off">
    <?= csrf_field() ?>
    <input type="hidden" name="return_to" value="/dashboard/profile">
    <div>
      <h2 style="font-size:1.1rem;margin:0">تغییر رمز عبور</h2>
      <p class="muted" style="margin:.35rem 0 0;font-size:.9rem">برای امنیت حساب، رمز جدید را اینجا تنظیم کنید.</p>
    </div>
    <?= password_field_html('current_password', 'current_password', [
        'label' => 'رمز فعلی',
        'autocomplete' => 'current-password',
        'rules' => false,
    ]) ?>
    <?= password_field_html('new_password', 'new_password', [
        'label' => 'رمز جدید',
        'autocomplete' => 'new-password',
        'rules' => false,
    ]) ?>
    <?= password_field_html('new_password_confirm', 'new_password_confirm', [
        'label' => 'تکرار رمز جدید',
        'autocomplete' => 'new-password',
        'confirm' => true,
        'pair' => 'new_password',
    ]) ?>
    <button class="btn btn-primary" type="submit">ذخیره رمز جدید</button>
  </form>
</div>
<?php
render_patient_page('پروفایل', ob_get_clean());
