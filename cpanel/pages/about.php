<?php
declare(strict_types=1);

$pageTitle = 'درباره ما';
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
</div>
<?php
$content = ob_get_clean();
require __DIR__ . '/../includes/layout.php';
