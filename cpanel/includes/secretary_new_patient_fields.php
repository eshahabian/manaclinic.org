<?php
declare(strict_types=1);

$doctors = $doctors ?? [];
$secretaryPatientHint = $secretaryPatientHint ?? 'مراجعه‌کننده با نام کاربری و رمز زیر می‌تواند بعداً وارد شود. با تایپ نام فارسی، معادل انگلیسی پیشنهاد می‌شود.';
?>
    <p class="muted" style="margin:0 0 .75rem;font-size:.85rem;line-height:1.7"><?= e($secretaryPatientHint) ?></p>
    <p class="muted form-draft-hint" data-form-draft-status style="margin:0 0 .75rem;font-size:.8rem;line-height:1.6" hidden></p>
    <div class="grid-2">
      <div>
        <label class="label" for="new_first_name">نام</label>
        <input class="input input-rtl" name="new_first_name" id="new_first_name" dir="rtl" autocomplete="given-name" placeholder="نام">
      </div>
      <div>
        <label class="label label-ltr" for="new_name_en">name</label>
        <input class="input" name="new_name_en" id="new_name_en" dir="ltr" lang="en" autocomplete="off" placeholder="name">
        <p class="muted" style="font-size:.75rem;margin:.35rem 0 0">از دیتابیس نام‌ها و جستجوی آنلاین پیشنهاد می‌شود؛ در صورت نیاز ویرایش کنید.</p>
      </div>
      <div>
        <label class="label" for="new_last_name">نام خانوادگی</label>
        <input class="input input-rtl" name="new_last_name" id="new_last_name" dir="rtl" autocomplete="family-name" placeholder="نام خانوادگی">
      </div>
      <div>
        <label class="label label-ltr" for="new_surname">surname</label>
        <input class="input" name="new_surname" id="new_surname" dir="ltr" lang="en" autocomplete="off" placeholder="surname">
        <p class="muted" style="font-size:.75rem;margin:.35rem 0 0">از دیتابیس نام‌ها و جستجوی آنلاین پیشنهاد می‌شود؛ در صورت نیاز ویرایش کنید.</p>
      </div>
      <div style="grid-column:1/-1">
        <label class="label" for="new_preferred_doctor_id">درمانگر مربوط به مراجعه‌کننده</label>
        <select class="input" name="new_preferred_doctor_id" id="new_preferred_doctor_id">
          <option value="">انتخاب درمانگر</option>
          <?php foreach ($doctors as $d): ?>
            <option value="<?= e($d['id']) ?>"><?= e($d['name']) ?> — <?= e($d['specialty']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div style="grid-column:1/-1">
        <label class="label" for="new_phone">موبایل</label>
        <input class="input" name="new_phone" id="new_phone" dir="ltr" inputmode="tel" autocomplete="tel" placeholder="مثلاً 0912... یا +1..." title="شماره ایران یا بین‌المللی">
      </div>
      <div style="grid-column:1/-1">
        <label class="label" for="new_username">نام کاربری</label>
        <div style="display:flex;gap:.5rem;align-items:stretch">
          <input class="input" name="new_username" id="new_username" dir="ltr" pattern="[A-Za-z0-9._-]{3,32}" placeholder="حروف انگلیسی، عدد و ._- " style="flex:1">
          <button type="button" class="btn btn-outline" id="suggest-username" title="پیشنهاد از روی name و surname">پیشنهاد</button>
        </div>
        <p class="muted" id="username-hint" style="margin:.4rem 0 0;font-size:.8rem;line-height:1.6"></p>
      </div>
      <div>
        <label class="label" for="new_password">رمز عبور</label>
        <input class="input" name="new_password" id="new_password" type="password" dir="ltr" minlength="6" autocomplete="new-password" placeholder="حداقل ۶ کاراکتر">
      </div>
      <div>
        <label class="label" for="new_password_confirm">تکرار رمز عبور</label>
        <input class="input" name="new_password_confirm" id="new_password_confirm" type="password" dir="ltr" minlength="6" autocomplete="new-password" placeholder="تکرار رمز">
      </div>
    </div>
