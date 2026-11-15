<?php
declare(strict_types=1);
$homeSlides = [
    ['01-couples-dialogue', 'کارگاه زوج‌درمانی', 'گفت‌وگو، قدمی برای نزدیک‌تر شدن', 'فرصتی برای تمرین شنیدن، بیان احساسات و شناخت بهتر رابطه.', '/workshops', 'مشاهده کارگاه‌ها'],
    ['02-couples-connection', 'زوج‌درمانی', 'برای رابطه‌تان وقت بگذارید', 'از الگوهای تکراری گفت‌وگو تا ساختن ارتباطی آگاهانه‌تر؛ مسیر را با همراهی شروع کنید.', '/services/couples', 'آشنایی با زوج‌درمانی'],
    ['03-mbsr-breathing', 'کارگاه MBSR', 'مکثی برای خودت، توجهی به اکنون', 'کاهش استرس مبتنی بر ذهن‌آگاهی؛ آشنایی با تمرین تنفس و توجه به تجربهٔ لحظهٔ حال.', '/workshops', 'مشاهده کارگاه‌ها'],
    ['04-mbsr-group', 'ذهن‌آگاهی · MBSR', 'تمرین حضور، در کنار هم', 'با تمرین‌های ذهن‌آگاهی، فرصتی برای توجه به بدن، افکار و احساسات روزمره بسازید.', '/workshops', 'آشنایی با کارگاه‌ها'],
    ['05-calm-mind', 'مانا کلینیک', 'آرامش ذهن، مسیر روشن‌تر زندگی', 'از مسئله‌ات شروع کن، درمانگر مناسب را ببین و نوبت حضوری یا آنلاین بگیر.', '/doctors', 'رزرو نوبت'],
];
?>
<section class="mana-home-slider" aria-label="معرفی خدمات و کارگاه‌های مانا" aria-roledescription="اسلایدشو">
  <?php foreach ($homeSlides as $i => $slide): ?>
  <article class="mana-home-slide" data-home-slide <?= $i ? 'hidden' : '' ?> aria-label="<?= $i + 1 ?> از ۵">
    <img src="<?= e(url('/assets/img/home-slideshow-v1/' . $slide[0] . '.webp')) ?>" alt="" width="1536" height="1024" <?= $i ? 'loading="lazy"' : 'fetchpriority="high" loading="eager"' ?> decoding="async">
    <div class="container-page mana-home-slide-inner">
      <div class="mana-home-slide-copy">
        <p class="eyebrow"><?= e($slide[1]) ?></p>
        <?php if ($i === 0): ?><h1><?= e($slide[2]) ?></h1><?php else: ?><h2><?= e($slide[2]) ?></h2><?php endif; ?>
        <p class="mana-home-slide-description"><?= e($slide[3]) ?></p>
        <div class="hero-actions"><a class="btn btn-accent" href="<?= e(url($slide[4])) ?>"><?= e($slide[5]) ?></a></div>
      </div>
    </div>
  </article>
  <?php endforeach; ?>
  <div class="mana-home-slider-controls" aria-label="کنترل اسلایدها">
    <?php foreach ($homeSlides as $i => $slide): ?>
    <button type="button" data-home-slide-to="<?= $i ?>" aria-label="<?= e($slide[1] . '؛ اسلاید ' . ($i + 1)) ?>" aria-pressed="<?= $i === 0 ? 'true' : 'false' ?>"><?= $i + 1 ?></button>
    <?php endforeach; ?>
    <button type="button" data-home-slide-pause aria-label="توقف پخش خودکار">توقف</button>
  </div>
</section>
