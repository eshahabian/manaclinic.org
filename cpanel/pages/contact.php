<?php
declare(strict_types=1);

$pageTitle = 'تماس با ما';
$pageDescription = 'تماس با مانا کلینیک سعادت‌آباد: ۰۹۱۰۱۳۸۷۸۳۸ و ۰۲۱۲۲۰۶۵۷۷۴. آدرس مطب، درخواست مشاوره و درخواست همکاری درمانگران.';
$pageCanonical = url('/contact');
$pageKeywords = 'تماس مانا کلینیک, آدرس مانا کلینیک, روانشناس سعادت آباد, درخواست همکاری';
ob_start();
?>
<div class="container-page section contact-page">
  <h1>تماس با ما</h1>
  <p class="muted" style="margin-top:.5rem;line-height:1.9">
    برای پرسش درباره نوبت‌ها و خدمات از راه‌های زیر با ما در ارتباط باشید.
    برای مشاوره یا همکاری، در بخش ثبت درخواست همان مورد را باز کنید و فرم را پر کنید.
  </p>

  <div class="contact-layout">
  <div class="panel stack">
    <div>
      <div class="label">شماره موبایل</div>
      <a href="tel:09101387838" style="color:var(--primary);font-weight:600" dir="ltr">۰۹۱۰ ۱۳۸ ۷۸۳۸</a>
    </div>
    <div>
      <div class="label">خط ثابت</div>
      <a href="tel:02122065774" style="color:var(--primary);font-weight:600" dir="ltr">۰۲۱ ۲۲۰۶ ۵۷۷۴</a>
    </div>
    <div>
      <div class="label">ایمیل</div>
      <a href="mailto:info@manaclinic.org" style="color:var(--primary);font-weight:600" dir="ltr">info@manaclinic.org</a>
    </div>
    <div>
      <div class="label">آدرس مطب</div>
      <p style="margin:0;line-height:1.9">
        سعادت‌آباد، خیابان ۳۱ شرقی (جندونی)، روبروی ساختمان پزشکان روزبه، پلاک ۴، واحد ۵، طبقه ۵
      </p>
    </div>
    <div>
      <div class="label">ساعات پشتیبانی</div>
      <p style="margin:0">همه روزه ۹ تا ۱۸</p>
    </div>
    <div>
      <div class="label">رزرو نوبت</div>
      <p style="margin:0">
        می‌توانید از بخش
        <a href="<?= e(url('/doctors')) ?>" style="color:var(--primary);font-weight:600">متخصصان</a>
        به‌صورت آنلاین نوبت بگیرید.
      </p>
    </div>
  </div>
  <div class="stack">
    <div class="panel consult-form-card contact-request" id="collaborate-request">
      <h2>ثبت درخواست</h2>
      <p class="muted consult-form-lead">یکی را باز کنید. درخواست مشاوره از اول باز است؛ برای همکاری همان ردیف را باز کنید.</p>
      <div class="contact-accord">
        <details class="contact-fold" data-fold="consult" open>
          <summary>درخواست مشاوره</summary>
          <form class="footer-consult" method="post" action="<?= e(url('/consult-request')) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="next" value="/contact">
            <input class="footer-consult-hp" type="text" name="website" tabindex="-1" autocomplete="off" aria-hidden="true">
            <label class="sr-only" for="contact-consult-name">نام و نام خانوادگی، الزامی</label>
            <input class="input" id="contact-consult-name" name="name" required maxlength="100" placeholder="نام و نام خانوادگی *" autocomplete="name">
            <label class="sr-only" for="contact-consult-phone">شماره تماس، الزامی</label>
            <input class="input input-rtl" id="contact-consult-phone" name="phone" required inputmode="tel" maxlength="20" dir="rtl" placeholder="شماره تماس *" autocomplete="tel">
            <label class="sr-only" for="contact-consult-message">توضیح درخواست، الزامی</label>
            <textarea class="input" id="contact-consult-message" name="message" required maxlength="1000" rows="4" placeholder="توضیح مختصر درباره درخواست شما *"></textarea>
            <button type="submit" class="btn btn-primary">ارسال درخواست مشاوره</button>
          </form>
        </details>
        <details class="contact-fold" data-fold="collab">
          <summary>درخواست همکاری</summary>
          <form class="footer-consult" method="post" action="<?= e(url('/collaborate')) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="next" value="/contact">
            <input class="footer-consult-hp" type="text" name="mana_hp" tabindex="-1" autocomplete="off" aria-hidden="true">
            <label class="sr-only" for="contact-collab-name">نام و نام خانوادگی، الزامی</label>
            <input class="input" id="contact-collab-name" name="name" required maxlength="120" placeholder="نام و نام خانوادگی *" autocomplete="name">
            <label class="sr-only" for="contact-collab-phone">شماره تماس، الزامی</label>
            <input class="input input-rtl" id="contact-collab-phone" name="phone" required inputmode="tel" maxlength="20" dir="rtl" placeholder="شماره تماس *" autocomplete="tel">
            <label class="sr-only" for="contact-collab-specialty">تخصص یا زمینه همکاری، الزامی</label>
            <input class="input" id="contact-collab-specialty" name="specialty" required maxlength="160" placeholder="تخصص یا زمینه همکاری *">
            <label class="sr-only" for="contact-collab-message">توضیح درخواست، الزامی</label>
            <textarea class="input" id="contact-collab-message" name="message" required maxlength="2000" rows="4" placeholder="توضیح سابقه، روزهای حضور یا نوع همکاری *"></textarea>
            <button type="submit" class="btn btn-primary">ارسال درخواست همکاری</button>
          </form>
        </details>
      </div>
    </div>
  </div>
  </div>
</div>
<script>
(function () {
  var folds = document.querySelectorAll(".contact-fold");
  function only(openFold) {
    folds.forEach(function (fold) {
      if (fold !== openFold) fold.open = false;
    });
  }
  folds.forEach(function (fold) {
    fold.addEventListener("toggle", function () {
      if (fold.open) only(fold);
    });
  });
  if (window.location.hash === "#collaborate-request") {
    var collab = document.querySelector('.contact-fold[data-fold="collab"]');
    if (collab) collab.open = true;
  }
})();
</script>
<?php
$content = ob_get_clean();
require __DIR__ . '/../includes/layout.php';
