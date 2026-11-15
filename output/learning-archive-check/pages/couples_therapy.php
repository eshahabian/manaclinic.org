<?php
declare(strict_types=1);

$pageTitle = 'سرِ اتفاق دعوا می‌کنیم، یا سرِ معنای آن؟';
$pageDescription = 'درس اول مجموعهٔ آموزشی رابطه از زاویه‌ای دیگر؛ یک اتفاق، دو برداشت و تمرینی برای گفت‌وگو دربارهٔ معنای اتفاق‌ها.';
require_once __DIR__ . '/../includes/learning.php';
$learningSeries = $learningSeries ?? 'couples-therapy';
$learningSeason = $learningSeason ?? 1;
$learningEpisode = $learningEpisode ?? 1;
$learningCatalog = mana_learning_catalog();
$learningItems = mana_learning_lessons($learningSeries, $learningCatalog[$learningSeries]);
$currentLessonIndex = array_search(mana_learning_lesson_path($learningSeries, $learningSeason, $learningEpisode), array_column($learningItems, 'path'), true);
$pageCanonical = url(mana_learning_lesson_path($learningSeries, $learningSeason, $learningEpisode));
$pageHead = '<link rel="stylesheet" href="' . e(url('/assets/css/home-redesign-v2.css')) . '?v=20261115j"><link rel="stylesheet" href="' . e(url('/assets/css/learning.css')) . '?v=20261115a">';
ob_start();
?>
<article class="container-page section mana-couples-lesson">
  <nav class="learning-breadcrumb" aria-label="مسیر یادگیری"><a href="<?= e(url('/learn')) ?>">یادگیری با مانا</a><span aria-hidden="true">/</span><a href="<?= e(url('/learn/couples-therapy')) ?>">Couples Therapy</a><span aria-hidden="true">/</span><a href="<?= e(url('/learn/couples-therapy#season-1')) ?>">فصل اول</a><span aria-hidden="true">/</span><span aria-current="page">قسمت اول</span></nav>
  <header>
    <p class="mana-kicker">رابطه از زاویه‌ای دیگر · درس اول</p>
    <h1>سرِ اتفاق دعوا می‌کنیم، یا سرِ معنای آن؟</h1>
    <p class="muted">نقطهٔ شروع: <bdi lang="en">Couples Therapy</bdi> با درمانگری اورنا گورالنیک · فصل اول، قسمت اول</p>
    <p>گاهی چیزی که برای یکی از ما یک اتفاق ساده است، برای دیگری معنای نادیده‌گرفته‌شدن یا تنها ماندن دارد. پیش از پاسخ‌دادن به واکنش هم، می‌توانیم دربارهٔ معنایی که پشت آن است کنجکاو شویم.</p>
  </header>
  <aside class="panel" aria-label="دربارهٔ مبنای این درس">
    <strong>دربارهٔ این درس</strong>
    <p>این یادداشت، طرح آموزشی اولیه بر پایهٔ خلاصهٔ قسمت است. ویدئو یا زیرنویس هنوز برای تحلیل دقیق صحنه‌ها بررسی نشده؛ مثال و تمرین زیر نوشتهٔ آموزشی ماناست و نقل‌قول یا بازسازی صحنه‌های مستند نیست.</p>
  </aside>
  <section>
    <h2>یک اتفاق؛ دو برداشت</h2>
    <p>مثال فرضی: پیامی چند ساعت بی‌پاسخ می‌ماند. فرستنده ممکن است آن را نشانهٔ کم‌اهمیت‌بودن خودش بداند؛ گیرنده ممکن است فقط درگیر کاری باشد و پاسخ‌دادن را به بعد موکول کرده باشد. هیچ‌کدام از این برداشت‌ها به‌تنهایی قصد طرف مقابل را ثابت نمی‌کند.</p>
    <p>پرسش آموزشی این است: چه اتفاقی افتاد، من چه معنایی به آن دادم، و طرف مقابل چه تجربه‌ای داشت؟ فهمیدن این تفاوت، راهی برای روشن‌ترشدن اختلاف است.</p>
  </section>
  <section class="panel">
    <p class="mana-kicker">مکث و تمرین</p>
    <h2>اتفاق را از برداشت جدا کنیم</h2>
    <ol>
      <li><strong>اتفاق:</strong> اگر دوربین آنجا بود، چه چیز قابل‌مشاهده‌ای ثبت می‌کرد؟</li>
      <li><strong>برداشت:</strong> من در ذهنم این اتفاق را نشانهٔ چه چیزی دانستم؟</li>
      <li><strong>احساس و خواسته:</strong> چه احساسی داشتم و چه درخواست مشخصی دارم؟</li>
      <li><strong>شنیدن:</strong> از طرف مقابلم بپرسم او این اتفاق را چگونه تجربه کرده است.</li>
    </ol>
    <p>الگوی پیشنهادی مانا برای شروع گفت‌وگو: وقتی … اتفاق افتاد، من این برداشت را کردم که … و احساس … داشتم. می‌خواهم بدانم تو آن لحظه چه تجربه‌ای داشتی.</p>
  </section>
  <section>
    <h2>موضوع بعدی: خواستنِ بچه و آماده‌بودن برای آن</h2>
    <p>در طرح اولیه، رابطهٔ سارا و لورن نقطهٔ شروع این پرسش بود: آیا میل به بچه‌داشتن، با احساس آمادگی برای والدشدن یکی است؟ برای نسبت‌دادن جزئیات یا نتیجه‌گیری دربارهٔ تجربهٔ این دو نفر، ابتدا باید ویدئو یا زیرنویس بررسی شود.</p>
    <p>فعلاً می‌توان پرسش عمومی را نگه داشت: هرکدام از ما از خواستن، آمادگی و مسئولیت مشترک چه می‌فهمیم؟</p>
  </section>
  <footer class="panel">
    <p>این مجموعه برای آموزش و تأمل دربارهٔ رابطه است و جایگزین ارزیابی یا درمان فردی نیست. هنوز اسلاید یا ویدئویی برای این درس منتشر نشده است.</p>
  </footer>
  <nav class="learning-lesson-nav" aria-label="درس‌های مجموعه">
    <?php if ($currentLessonIndex !== false && $currentLessonIndex > 0): $previousLesson = $learningItems[$currentLessonIndex - 1]; ?><a rel="prev" href="<?= e(url($previousLesson['path'])) ?>">درس قبلی: <?= e($previousLesson['title']) ?></a><?php endif; ?>
    <a href="<?= e(url('/learn/couples-therapy#season-1')) ?>">فهرست فصل‌ها و قسمت‌ها</a>
    <?php if ($currentLessonIndex !== false && isset($learningItems[$currentLessonIndex + 1])): $nextLesson = $learningItems[$currentLessonIndex + 1]; ?><a rel="next" href="<?= e(url($nextLesson['path'])) ?>">درس بعدی: <?= e($nextLesson['title']) ?></a><?php else: ?><span class="muted">قسمت بعدی پس از آماده‌شدن اضافه می‌شود.</span><?php endif; ?>
  </nav>
</article>
<?php
$content = ob_get_clean();
require __DIR__ . '/../includes/layout.php';
