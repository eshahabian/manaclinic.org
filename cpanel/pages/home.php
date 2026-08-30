<?php
declare(strict_types=1);

$doctors = $pdo->query("
  SELECT dp.*, u.name
  FROM doctor_profiles dp
  JOIN users u ON u.id = dp.user_id
  WHERE dp.is_active = 1 AND dp.is_approved = 1
  ORDER BY dp.created_at ASC
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

$pageTitle = 'خانه';
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
  <div class="section-head">
    <div>
      <h2>متخصصان ما</h2>
      <p class="muted">متخصصان با تجربه برای همراهی در مسیر درمان</p>
    </div>
    <a href="<?= e(url('/doctors')) ?>" style="color:var(--primary);font-weight:600;font-size:.9rem">مشاهده همه</a>
  </div>
  <div class="grid-3">
    <?php foreach ($doctors as $doc): ?>
      <a class="panel card-link" href="<?= e(url('/doctors/' . $doc['id'])) ?>">
        <div class="avatar"><?= e(mb_substr($doc['name'], 0, 1)) ?></div>
        <h3 style="margin:0"><?= e($doc['name']) ?></h3>
        <p style="color:var(--primary);margin:.35rem 0 0;font-size:.9rem"><?= e($doc['specialty']) ?></p>
        <p class="muted line-clamp-3 whitespace-pre" style="font-size:.9rem;line-height:1.8;margin-top:.75rem"><?= e($doc['bio']) ?></p>
      </a>
    <?php endforeach; ?>
    <?php if (!$doctors): ?><p class="muted">هنوز متخصصی ثبت نشده است.</p><?php endif; ?>
  </div>
</section>

<section class="container-page section">
  <div class="section-head">
    <div>
      <h2>آخرین مقالات</h2>
      <p class="muted">دانش کاربردی برای سلامت روان</p>
    </div>
    <a href="<?= e(url('/articles')) ?>" style="color:var(--primary);font-weight:600;font-size:.9rem">همه مقالات</a>
  </div>
  <div class="grid-3">
    <?php foreach ($articles as $article): ?>
      <a class="panel card-link" href="<?= e(url('/articles/' . $article['slug'])) ?>">
        <span class="badge"><?= e($article['author_name']) ?></span>
        <h3 style="margin:.75rem 0 0;line-height:1.7"><?= e($article['title']) ?></h3>
        <p class="muted line-clamp-3" style="font-size:.9rem;line-height:1.8;margin-top:.75rem"><?= e($article['excerpt']) ?></p>
      </a>
    <?php endforeach; ?>
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
