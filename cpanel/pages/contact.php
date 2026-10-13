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
    برای همکاری درمانگران، فرم درخواست همکاری را در همین صفحه پر کنید.
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
    <div class="panel consult-form-card">
      <h2>درخواست مشاوره</h2>
      <p class="muted consult-form-lead">فرم را پر کنید؛ در اولین فرصت با شما تماس می‌گیریم. نام، شماره تماس و توضیح درخواست الزامی است.</p>
      <?php
        if (!function_exists('consult_request_form_html')) {
            require_once dirname(__DIR__) . '/includes/consult_requests.php';
        }
        echo consult_request_form_html('page-consult');
      ?>
    </div>
    <div class="panel consult-form-card" id="collaborate-request">
      <h2>درخواست همکاری</h2>
      <p class="muted consult-form-lead">اگر مایل به همکاری با مانا کلینیک هستید، نام، شماره تماس، تخصص و توضیح را بنویسید.</p>
      <?php
        if (!function_exists('collaboration_request_form_html')) {
            require_once dirname(__DIR__) . '/includes/collaboration.php';
        }
        echo collaboration_request_form_html('page-collab');
      ?>
    </div>
  </div>
  </div>
</div>
<?php
$content = ob_get_clean();
require __DIR__ . '/../includes/layout.php';
