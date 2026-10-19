<?php
declare(strict_types=1);

$pageTitle = 'درباره ما';
$pageDescription = 'درباره مانا کلینیک سعادت‌آباد تهران؛ مشاوره روانشناسی، روان‌درمانی، زوج‌درمانی و فضای امن درمان. تلفن ۰۹۱۰۱۳۸۷۸۳۸ و ۰۲۱۲۲۰۶۵۷۷۴.';
$pageCanonical = url('/about');
$pageKeywords = 'درباره مانا کلینیک, کلینیک روانشناسی سعادت آباد, روان‌درمانی تهران';
$aboutDoctors = function_exists('clinic_filter_doctors') ? clinic_filter_doctors($pdo, []) : [];
ob_start();
?>
<div class="container-page section" style="max-width:48rem">
  <h1>درباره مانا کلینیک</h1>
  <p class="muted" style="margin-top:.5rem;line-height:1.9">
    مانا کلینیک فضایی امن برای یادگیری، رشد و دریافت خدمات روانشناسی است.
    ما باور داریم هر فرد شایسته آرامش ذهن و مسیر روشن‌تری در زندگی است.
  </p>

  <div class="panel stack" style="margin-top:1.5rem">
    <h2 style="margin:0;font-size:1.1rem">آنچه ارائه می‌دهیم</h2>
    <p style="margin:0;line-height:1.9">
      مشاوره تخصصی فردی، خانواده، کودک و نوجوان، همراه با امکان مطالعه مقالات کاربردی
      و رزرو نوبت آنلاین با متخصصان مجموعه.
    </p>
  </div>

  <div class="panel stack" style="margin-top:1rem">
    <h2 style="margin:0;font-size:1.1rem">رویکرد ما</h2>
    <p style="margin:0;line-height:1.9">
      احترام، محرمانگی و همراهی حرفه‌ای، پایه کار ماست.
      تلاش می‌کنیم مسیر درمان شفاف، در دسترس و انسانی باشد.
    </p>
  </div>
  <section class="panel stack" style="margin-top:1rem">
    <h2 style="margin:0;font-size:1.1rem">کلینیک روانشناسی در سعادت‌آباد تهران</h2>
    <p>جلسات حضوری مانا کلینیک در سعادت‌آباد، خیابان ۳۱ شرقی (جندونی)، روبروی ساختمان پزشکان روزبه، پلاک ۴، واحد ۵، طبقه ۵ برگزار می‌شود. برای درمانگرانی که جلسه آنلاین ارائه می‌کنند، امکان انتخاب این نوع جلسه هنگام رزرو وجود دارد.</p>
    <p><a href="<?= e(url('/contact')) ?>">آدرس روی نقشه و راه‌های تماس با کلینیک</a></p>
  </section>
  <section class="panel stack" style="margin-top:1rem">
    <h2 style="margin:0;font-size:1.1rem">آشنایی با درمانگران مانا</h2>
    <p>پیش از رزرو، حوزه فعالیت، رویکرد درمانی، تحصیلات و اطلاعات حرفه‌ای درج‌شده در پروفایل هر درمانگر را بررسی کنید. زمان‌های آزاد و نوع جلسه نیز در صفحه همان متخصص قابل مشاهده است.</p>
    <?php if ($aboutDoctors): ?>
      <ul>
        <?php foreach ($aboutDoctors as $aboutDoctor): ?>
          <li><a href="<?= e(url('/doctors/' . (string) $aboutDoctor['id'])) ?>"><?= e((string) $aboutDoctor['name']) ?></a></li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
    <p><a href="<?= e(url('/doctors')) ?>">مشاهده پروفایل و نوبت‌های متخصصان</a></p>
  </section>
  <section class="panel stack" style="margin-top:1rem">
    <h2 style="margin:0;font-size:1.1rem">از انتخاب خدمت تا جلسه اول</h2>
    <p>از <a href="<?= e(url('/services')) ?>">خدمات کلینیک</a> شروع کنید، متخصص مرتبط را بشناسید و در صفحه او تاریخ و ساعت مناسب را انتخاب کنید. برای پرسش درباره روند رزرو یا شرایط حضور، <a href="<?= e(url('/faq')) ?>">سوالات متداول</a> و <a href="<?= e(url('/rules')) ?>">قوانین و شرایط استفاده</a> در دسترس‌اند.</p>
  </section>
</div>
<?php
$content = ob_get_clean();
require __DIR__ . '/../includes/layout.php';
