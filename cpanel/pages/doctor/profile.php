<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/doctor_panel.php';
$ctx = require_doctor_profile($pdo);
$p = $ctx['profile'];
$incomplete = !doctor_profile_is_complete($p);
$approaches = doctor_profile_filter_keys(doctor_profile_json_list($p['approaches_json'] ?? ''), doctor_approach_options());
$domains = doctor_profile_filter_keys(doctor_profile_json_list($p['domains_json'] ?? ''), doctor_domain_options());
$focus = doctor_profile_filter_keys(doctor_profile_json_list($p['focus_json'] ?? ''), doctor_focus_options());
$photoSrc = doctor_avatar_src($p['avatar_url'] ?? null);
$maxYear = doctor_current_jalali_year();
ob_start();
?>
<div class="doc-profile-page">
  <h1><?= $incomplete ? 'تکمیل پروفایل حرفه‌ای' : 'پروفایل حرفه‌ای' ?></h1>
  <?php if ($incomplete): ?>
    <p class="doc-onboard-banner">برای ورود به بقیه پنل، این موارد را کامل کنید. همه فیلدها الزامی است.</p>
  <?php else: ?>
    <p class="muted" style="margin-top:.35rem;line-height:1.7">همین اطلاعات در صفحه عمومی شما دیده می‌شود.</p>
  <?php endif; ?>

  <form class="doc-profile-form" method="post" action="<?= e(url('/doctor/profile')) ?>" enctype="multipart/form-data">
    <?= csrf_field() ?>

    <section class="panel doc-profile-section">
      <h2>عکس</h2>
      <div class="doc-photo-field">
        <div class="doc-photo-preview" id="doc-photo-preview">
          <?php if ($photoSrc !== ''): ?>
            <img src="<?= e($photoSrc) ?>" alt="">
          <?php else: ?>
            <span><?= e(mb_substr((string) ($p['name'] ?? ''), 0, 1)) ?></span>
          <?php endif; ?>
        </div>
        <div>
          <label class="label" for="avatar">عکس پروفایل</label>
          <input class="input" type="file" name="avatar" id="avatar" accept="image/jpeg,image/png,image/webp" <?= $photoSrc === '' ? 'required' : '' ?>>
          <p class="muted" style="font-size:.8rem;margin:.4rem 0 0">jpg، png یا webp — حداکثر ۵ مگابایت</p>
        </div>
      </div>
    </section>

    <section class="panel doc-profile-section">
      <h2>هویت حرفه‌ای</h2>
      <div class="doc-profile-grid">
        <div>
          <label class="label" for="name">نام نمایشی</label>
          <input class="input" name="name" id="name" value="<?= e((string) ($p['name'] ?? '')) ?>" required>
        </div>
        <div>
          <label class="label" for="degree">آخرین مدرک تحصیلی</label>
          <select class="input" name="degree" id="degree" required>
            <option value="">انتخاب کنید</option>
            <?php foreach (doctor_degree_options() as $key => $label): ?>
              <option value="<?= e($key) ?>" <?= (($p['degree'] ?? '') === $key) ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="label" for="license_no">شماره پروانه نظام</label>
          <input class="input" name="license_no" id="license_no" value="<?= e((string) ($p['license_no'] ?? '')) ?>" required dir="ltr">
        </div>
        <div>
          <label class="label" for="started_year">سال شروع فعالیت</label>
          <input class="input" type="number" name="started_year" id="started_year" value="<?= e((string) ($p['started_year'] ?? '')) ?>" required dir="ltr" min="1330" max="<?= (int) $maxYear ?>" placeholder="مثلاً <?= (int) ($maxYear - 8) ?>">
          <p class="muted" style="font-size:.8rem;margin:.4rem 0 0">فقط سال شمسی. برای مراجع، سابقه به سال نمایش داده می‌شود.</p>
        </div>
      </div>
    </section>

    <section class="panel doc-profile-section">
      <h2>رویکرد درمانی</h2>
      <p class="muted" style="margin:0 0 .7rem;font-size:.85rem">چند مورد را می‌توانید انتخاب کنید.</p>
      <?= doctor_chip_picker_html('approaches', doctor_approach_options(), $approaches) ?>
    </section>

    <section class="panel doc-profile-section">
      <h2>حوزه درمان</h2>
      <p class="muted" style="margin:0 0 .7rem;font-size:.85rem">چند مورد را می‌توانید انتخاب کنید.</p>
      <?= doctor_chip_picker_html('domains', doctor_domain_options(), $domains) ?>
    </section>

    <section class="panel doc-profile-section">
      <h2>زمینه تخصصی</h2>
      <p class="muted" style="margin:0 0 .7rem;font-size:.85rem">چند مورد را می‌توانید انتخاب کنید.</p>
      <?= doctor_chip_picker_html('focus', doctor_focus_options(), $focus) ?>
    </section>

    <section class="panel doc-profile-section">
      <h2>دوره‌های تخصصی گذرانده‌شده</h2>
      <label class="label" for="courses">هر دوره در یک خط</label>
      <textarea class="input" name="courses" id="courses" rows="5" required placeholder="مثلاً دوره طرحواره‌درمانی یانگ"><?= e((string) ($p['courses'] ?? '')) ?></textarea>
    </section>

    <section class="panel doc-profile-section">
      <h2>درباره من</h2>
      <label class="label" for="bio">متن کوتاهی که ترجیح می‌دهید مراجع درباره شما بخواند</label>
      <textarea class="input" name="bio" id="bio" rows="6" required minlength="20"><?= e((string) ($p['bio'] ?? '')) ?></textarea>
    </section>

    <section class="panel doc-profile-section">
      <h2>هزینه جلسه</h2>
      <label class="label" for="session_price">تومان</label>
      <input class="input" type="number" name="session_price" id="session_price" value="<?= e((string) ($p['session_price'] ?? 3000000)) ?>" required dir="ltr" min="1">
    </section>

    <button class="btn btn-primary" type="submit"><?= $incomplete ? 'ذخیره و ورود به پنل' : 'ذخیره' ?></button>
  </form>
</div>
<?php
$GLOBALS['pageScripts'] = ($GLOBALS['pageScripts'] ?? '') . '
<script>
(function(){
  var input = document.getElementById("avatar");
  var box = document.getElementById("doc-photo-preview");
  if (!input || !box) return;
  input.addEventListener("change", function(){
    var file = input.files && input.files[0];
    if (!file) return;
    var url = URL.createObjectURL(file);
    box.innerHTML = "<img src=\\"" + url + "\\" alt=\\"\\">";
  });
})();
</script>
';
$pageScripts = $GLOBALS['pageScripts'];
render_doctor_page($incomplete ? 'تکمیل پروفایل' : 'پروفایل', ob_get_clean());
