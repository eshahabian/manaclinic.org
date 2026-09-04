<?php
declare(strict_types=1);

$pageTitle = 'تماس با ما';
$pageDescription = 'تماس با مانا کلینیک سعادت‌آباد: ۰۹۱۰۱۳۸۷۸۳۸ و ۰۲۱۲۲۰۶۵۷۷۴. آدرس مطب و موقعیت روی نقشه.';
$pageCanonical = url('/contact');
$pageKeywords = 'تماس مانا کلینیک, آدرس مانا کلینیک, روانشناس سعادت آباد';
$mapUrl = 'https://www.google.com/maps?q=35.772637,51.377568';
$mapEmbed = 'https://maps.google.com/maps?q=35.772637,51.377568&z=16&output=embed';
ob_start();
?>
<div class="container-page section" style="max-width:48rem">
  <h1>تماس با ما</h1>
  <p class="muted" style="margin-top:.5rem;line-height:1.9">
    برای پرسش درباره نوبت‌ها، خدمات یا همکاری، از راه‌های زیر با ما در ارتباط باشید.
  </p>

  <div class="panel stack" style="margin-top:1.5rem">
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

  <div class="panel" style="margin-top:1.25rem;overflow:hidden;padding:0">
    <iframe
      title="موقعیت مانا کلینیک روی نقشه"
      src="<?= e($mapEmbed) ?>"
      loading="lazy"
      referrerpolicy="no-referrer-when-downgrade"
      style="display:block;width:100%;height:min(22rem,50vw);border:0"
      allowfullscreen
    ></iframe>
    <p style="margin:0;padding:.85rem 1rem">
      <a href="<?= e($mapUrl) ?>" target="_blank" rel="noopener noreferrer" style="color:var(--primary);font-weight:600">باز کردن در گوگل‌مپ</a>
    </p>
  </div>
</div>
<?php
$content = ob_get_clean();
require __DIR__ . '/../includes/layout.php';
