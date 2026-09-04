<?php
declare(strict_types=1);

$doctors = $pdo->query("
  SELECT dp.*, u.name
  FROM doctor_profiles dp
  JOIN users u ON u.id = dp.user_id
  WHERE dp.is_active = 1 AND dp.is_approved = 1
  ORDER BY
    CASE WHEN u.name LIKE '%گرانمایه%' THEN 0 ELSE 1 END,
    dp.created_at ASC
  LIMIT 3
")->fetchAll();

$articles = $pdo->query("
  SELECT a.*, u.name AS author_name
  FROM articles a
  JOIN users u ON u.id = a.author_id
  WHERE a.published = 1
  ORDER BY a.published_at DESC
  LIMIT 3
")->fetchAll();
$leadArticle = $articles[0] ?? null;
$moreArticles = array_slice($articles, 1);

$pageTitle = 'خانه';
$pageDescription = 'مانا کلینیک سعادت‌آباد؛ روانشناسی، روان‌درمانی، زوج‌درمانی و رزرو نوبت آنلاین. مقالات تخصصی و دستیار هوشمند سلامت روان.';
$pageCanonical = url('/');
$pageKeywords = 'مانا کلینیک, روانشناس سعادت آباد, رزرو نوبت روانشناسی, زوج درمانی, مشاوره اضطراب, دکتر عطیه گارسچی';
$heroSlides = [
    url('/assets/img/hero.png'),
    url('/assets/img/slide-1.png'),
    url('/assets/img/slide-2.png'),
    url('/assets/img/slide-3.png'),
    url('/assets/img/slide-4.png'),
];
ob_start();
?>
<section class="hero-surface" id="hero-slideshow" aria-label="اسلایدشو صفحه اصلی">
  <div class="hero-slides">
    <?php foreach ($heroSlides as $i => $src): ?>
      <div class="hero-slide<?= $i === 0 ? ' is-active' : '' ?>" style="background-image:url('<?= e($src) ?>')" role="img" aria-hidden="<?= $i === 0 ? 'false' : 'true' ?>"></div>
    <?php endforeach; ?>
  </div>
  <div class="hero-overlay" aria-hidden="true"></div>
  <div class="container-page hero-inner">
    <p class="eyebrow">مانا کلینیک</p>
    <h1>آرامش ذهن، مسیر روشن‌تر زندگی</h1>
    <p style="max-width:36rem;opacity:.92;margin-top:1rem;line-height:1.9">
      مقالات تخصصی بخوانید، متخصص مناسب را پیدا کنید و آنلاین نوبت بگیرید.
    </p>
    <div class="hero-actions">
      <a class="btn btn-accent" href="<?= e(url('/doctors')) ?>">رزرو نوبت</a>
      <a class="btn btn-ghost" href="<?= e(url('/articles')) ?>">خواندن مقالات</a>
    </div>
  </div>
  <div class="hero-dots" role="tablist" aria-label="انتخاب اسلاید">
    <?php foreach ($heroSlides as $i => $_): ?>
      <button type="button" class="hero-dot<?= $i === 0 ? ' is-active' : '' ?>" data-slide="<?= $i ?>" aria-label="اسلاید <?= $i + 1 ?>"></button>
    <?php endforeach; ?>
  </div>
</section>

<section class="container-page section">
      <div class="home-specialists-block">
        <div class="section-head">
          <div>
            <h2>متخصصان ما</h2>
            <p class="muted">متخصصان با تجربه برای همراهی در مسیر درمان</p>
          </div>
        </div>
        <div class="doctors-showcase">
          <?php foreach ($doctors as $doc): ?>
            <a class="panel card-link doctor-card" href="<?= e(url('/doctors/' . $doc['id'])) ?>">
              <div class="avatar"><?= e(mb_substr($doc['name'], 0, 1)) ?></div>
              <h3 class="doctor-card-name"><?= e($doc['name']) ?></h3>
              <p class="doctor-card-specialty"><?= e($doc['specialty']) ?></p>
              <p class="muted doctor-card-bio"><?= e($doc['bio']) ?></p>
            </a>
          <?php endforeach; ?>
          <?php if (!$doctors): ?><p class="muted">هنوز متخصصی ثبت نشده است.</p><?php endif; ?>
        </div>
        <?php if ($doctors): ?>
          <div class="articles-footer-link">
            <a class="articles-all-link" href="<?= e(url('/doctors')) ?>">مشاهده همه</a>
          </div>
        <?php endif; ?>
      </div>

      <?php if (function_exists('assistant_enabled') ? assistant_enabled() : true): ?>
      <a class="home-assistant-tile" href="<?= e(url('/assistant')) ?>">
        <span class="home-assistant-icon" aria-hidden="true">
          <svg viewBox="0 0 64 64" width="56" height="56" fill="none">
            <rect x="14" y="22" width="36" height="28" rx="10" fill="#1a5c4a"/>
            <rect x="18" y="26" width="28" height="20" rx="7" fill="#e8f6f1"/>
            <circle cx="26" cy="36" r="3.2" fill="#1a5c4a"/>
            <circle cx="38" cy="36" r="3.2" fill="#1a5c4a"/>
            <path d="M28 42c1.6 1.4 6.4 1.4 8 0" stroke="#1a5c4a" stroke-width="2" stroke-linecap="round"/>
            <rect x="29" y="12" width="6" height="10" rx="3" fill="#2d8a6e"/>
            <circle cx="32" cy="11" r="4" fill="#7ed6b5"/>
            <rect x="8" y="30" width="6" height="12" rx="3" fill="#2d8a6e"/>
            <rect x="50" y="30" width="6" height="12" rx="3" fill="#2d8a6e"/>
          </svg>
        </span>
        <span class="home-assistant-copy">
          <span class="home-assistant-eyebrow">دستیار هوشمند مانا</span>
          <span class="home-assistant-title">فقط کافیه حالت رو بهم بگی</span>
          <span class="home-assistant-cta">شروع گفتگو</span>
        </span>
      </a>
      <?php endif; ?>

      <div class="home-articles-block" style="margin-top:3rem">
        <div class="section-head">
          <div>
            <h2>آخرین مقالات</h2>
            <p class="muted">دانش کاربردی برای سلامت روان</p>
          </div>
        </div>
        <div class="articles-showcase">
          <?php foreach ($articles as $article): ?>
            <a class="panel card-link article-card" href="<?= e(url('/articles/' . $article['slug'])) ?>">
              <span class="badge"><?= e($article['author_name']) ?></span>
              <h3 class="article-card-title"><?= e($article['title']) ?></h3>
              <p class="muted article-card-excerpt"><?= e($article['excerpt']) ?></p>
            </a>
          <?php endforeach; ?>
        </div>
        <?php if ($articles): ?>
          <div class="articles-footer-link">
            <a class="articles-all-link" href="<?= e(url('/articles')) ?>">همه مقالات</a>
          </div>
        <?php endif; ?>
      </div>
</section>
<?php
$content = ob_get_clean();
$pageScripts = '
<script>
(function(){
  var root = document.getElementById("hero-slideshow");
  if (!root) return;
  var slides = Array.prototype.slice.call(root.querySelectorAll(".hero-slide"));
  var dots = Array.prototype.slice.call(root.querySelectorAll(".hero-dot"));
  if (slides.length < 2) return;
  var i = 0;
  var timer = null;

  function go(n){
    slides[i].classList.remove("is-active");
    slides[i].setAttribute("aria-hidden", "true");
    if (dots[i]) dots[i].classList.remove("is-active");
    i = (n + slides.length) % slides.length;
    slides[i].classList.add("is-active");
    slides[i].setAttribute("aria-hidden", "false");
    if (dots[i]) dots[i].classList.add("is-active");
  }

  function next(){ go(i + 1); }

  function start(){
    stop();
    timer = setInterval(next, 5500);
  }
  function stop(){
    if (timer) clearInterval(timer);
    timer = null;
  }

  dots.forEach(function(dot){
    dot.addEventListener("click", function(){
      var n = parseInt(dot.getAttribute("data-slide"), 10) || 0;
      go(n);
      start();
    });
  });

  root.addEventListener("mouseenter", stop);
  root.addEventListener("mouseleave", start);
  start();
})();
</script>
';
require __DIR__ . '/../includes/layout.php';
