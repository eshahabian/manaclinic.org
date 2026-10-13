<?php
declare(strict_types=1);

$pageTitle = 'درخواست همکاری با مانا کلینیک';
$pageDescription = 'فرم درخواست همکاری درمانگران با مانا کلینیک در سعادت‌آباد. ثبت‌نام درمانگر از سایت بسته است و همکاری از همین فرم بررسی می‌شود.';
$pageCanonical = url('/collaborate');
$pageKeywords = 'همکاری با مانا کلینیک, درخواست همکاری درمانگر, روانشناس سعادت آباد';

ob_start();
?>
<div class="container-page section">
  <h1>درخواست همکاری</h1>
  <p class="muted" style="margin-top:.45rem;line-height:1.9;max-width:40rem">
    حساب درمانگر از سایت ساخته نمی‌شود. اگر مایل به همکاری با مانا کلینیک در سعادت‌آباد هستید، این فرم را بفرستید تا منشی بررسی کند.
  </p>
  <form class="panel form-stack" method="post" action="<?= e(url('/collaborate')) ?>" style="margin-top:1rem;max-width:36rem">
    <?= csrf_field() ?>
    <input class="footer-consult-hp" type="text" name="mana_hp" tabindex="-1" autocomplete="off" aria-hidden="true">
    <div>
      <label class="label" for="collab-name">نام</label>
      <input class="input" id="collab-name" name="name" required maxlength="120">
    </div>
    <div>
      <label class="label" for="collab-phone">موبایل</label>
      <input class="input" id="collab-phone" name="phone" required dir="ltr" inputmode="tel" autocomplete="tel">
    </div>
    <div>
      <label class="label" for="collab-specialty">تخصص یا زمینه همکاری</label>
      <input class="input" id="collab-specialty" name="specialty" required maxlength="160" placeholder="مثلاً روان‌درمانی فردی">
    </div>
    <div>
      <label class="label" for="collab-message">توضیح</label>
      <textarea class="input" id="collab-message" name="message" required rows="5" maxlength="2000" placeholder="سابقه، روزهای حضور، یا نوع همکاری"></textarea>
    </div>
    <button class="btn btn-primary" type="submit" style="justify-self:start">ارسال درخواست</button>
  </form>
</div>
<?php
$content = ob_get_clean();
require __DIR__ . '/../includes/layout.php';
