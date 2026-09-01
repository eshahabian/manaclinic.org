<?php
declare(strict_types=1);

$user = require_login(['PATIENT']);
require_once __DIR__ . '/../../includes/patient_panel.php';

$sections = [
    'in-person' => 'دوره‌های حضوری',
    'online' => 'دوره‌های آنلاین',
    'offline' => 'دوره‌های آفلاین',
];
$active = (string) ($_GET['type'] ?? 'in-person');
if (!isset($sections[$active])) {
    $active = 'in-person';
}

ob_start();
?>
<div class="stack">
  <h1>دوره‌های من</h1>
  <p class="muted">دوره‌های ثبت‌نام‌شده شما در سه دسته حضوری، آنلاین و آفلاین.</p>

  <nav class="course-tabs" aria-label="دسته‌بندی دوره‌ها">
    <?php foreach ($sections as $key => $label): ?>
      <a
        href="<?= e(url('/dashboard/courses?type=' . $key)) ?>"
        class="course-tab<?= $active === $key ? ' active' : '' ?>"
      ><?= e($label) ?></a>
    <?php endforeach; ?>
  </nav>

  <section class="panel">
    <h2 style="margin:0;font-size:1.1rem"><?= e($sections[$active]) ?></h2>
    <p class="muted" style="margin:1rem 0 0">هنوز دوره‌ای در این بخش ثبت نشده است.</p>
  </section>
</div>
<style>
  .course-tabs { display: flex; flex-wrap: wrap; gap: .5rem; }
  .course-tab {
    padding: .55rem .9rem;
    border-radius: .65rem;
    border: 1px solid var(--line);
    color: var(--muted);
    font-size: .9rem;
    background: #fff;
  }
  .course-tab:hover { border-color: var(--primary); color: var(--primary); }
  .course-tab.active {
    background: var(--primary);
    border-color: var(--primary);
    color: #fff;
  }
</style>
<?php
render_patient_page('دوره‌های من', ob_get_clean());
