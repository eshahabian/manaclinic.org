<?php
declare(strict_types=1);

$filters = clinic_read_filters();
$doctors = clinic_filter_doctors($pdo, $filters);
$q = $filters['q'];
$domain = $filters['domain'];
$focus = $filters['focus'];
$approach = $filters['approach'];
$hasFilter = $q !== '' || $domain !== '' || $focus !== '' || $approach !== '';

$pageTitle = 'متخصصان';
$pageDescription = 'روانشناسان مانا کلینیک سعادت‌آباد؛ انتخاب درمانگر بر اساس حوزه درمان و رزرو نوبت حضوری یا آنلاین.';
$pageCanonical = url('/doctors');
$pageKeywords = 'روانشناس, متخصص روانشناسی, رزرو نوبت, مانا کلینیک سعادت آباد';

if ($q !== '') {
    $GLOBALS['pageRobots'] = 'noindex,follow';
} elseif ($focus !== '' && $domain === '' && $approach === '') {
    $topic = clinic_issue_topic($focus);
    if ($topic) {
        $pageTitle = 'روانشناس ' . $topic['label'] . ' | مانا کلینیک';
        $pageDescription = $topic['description'];
        $pageKeywords = $topic['keywords'];
        $pageCanonical = url('/issues/' . $focus);
    }
} elseif ($domain !== '' && $focus === '' && $approach === '') {
    $label = doctor_domain_options()[$domain];
    $pageTitle = $label . ' — متخصصان مانا کلینیک';
    $pageDescription = 'روانشناسان حوزه «' . $label . '» در مانا کلینیک سعادت‌آباد؛ رزرو نوبت حضوری و آنلاین.';
    $pageKeywords = $label . ', روانشناس سعادت آباد, مانا کلینیک';
    $map = [
        'individual' => '/services/individual',
        'couples' => '/services/couples',
        'child' => '/services/child',
        'premarital' => '/services/premarital',
        'family' => '/services/family',
    ];
    if (isset($map[$domain])) {
        $pageCanonical = url($map[$domain]);
    }
} elseif ($approach !== '' && $focus === '' && $domain === '') {
    $label = doctor_approach_options()[$approach];
    $pageTitle = $label . ' — متخصصان مانا کلینیک';
    $pageDescription = 'درمانگرانی در مانا کلینیک سعادت‌آباد که با رویکرد «' . $label . '» کار می‌کنند. رزرو نوبت حضوری و آنلاین.';
    $pageKeywords = $label . ', روانشناس سعادت آباد, مانا کلینیک';
    $pageCanonical = clinic_doctors_href(['approach' => $approach]);
} elseif ($hasFilter) {
    $GLOBALS['pageRobots'] = 'noindex,follow';
}

$GLOBALS['pageTitle'] = $pageTitle;
$GLOBALS['pageDescription'] = $pageDescription;
$GLOBALS['pageCanonical'] = $pageCanonical;
$GLOBALS['pageKeywords'] = $pageKeywords;

$currentUser = current_user();
$isPatientViewer = $currentUser && ($currentUser['role'] ?? '') === 'PATIENT';
$baseFilters = ['q' => $q, 'domain' => $domain, 'focus' => $focus, 'approach' => $approach];
$countFa = to_fa_digits((string) count($doctors));

ob_start();
?>
<div class="<?= $isPatientViewer ? 'patient-panel-inner' : 'container-page section' ?>">
  <h1>متخصصان</h1>
  <p class="muted">حوزه، مسئله و رویکرد را فیلتر کن؛ از کارت هر درمانگر مستقیم به رزرو می‌روی. جلسه‌ها حضوری در سعادت‌آباد یا آنلاین‌اند.</p>

  <form class="dir-search" method="get" action="<?= e(url('/doctors')) ?>">
    <?php if ($domain !== ''): ?><input type="hidden" name="domain" value="<?= e($domain) ?>"><?php endif; ?>
    <?php if ($focus !== ''): ?><input type="hidden" name="focus" value="<?= e($focus) ?>"><?php endif; ?>
    <?php if ($approach !== ''): ?><input type="hidden" name="approach" value="<?= e($approach) ?>"><?php endif; ?>
    <label class="label" for="dir-q">جستجو</label>
    <div class="dir-search-row">
      <input class="input" id="dir-q" name="q" value="<?= e($q) ?>" placeholder="نام درمانگر یا موضوع...">
      <button class="btn btn-primary" type="submit">جستن</button>
    </div>
  </form>

  <div class="dir-filters">
    <?= clinic_filter_chip_row('domain', doctor_domain_options(), $domain, $baseFilters, 'حوزه') ?>
    <?= clinic_filter_chip_row('focus', doctor_focus_options(), $focus, $baseFilters, 'مسئله') ?>
    <?= clinic_filter_chip_row('approach', doctor_approach_options(), $approach, $baseFilters, 'رویکرد') ?>
  </div>

  <?php if ($hasFilter): ?>
    <p class="dir-count"><?= e($countFa) ?> متخصص<?= $focus !== '' ? ' برای «' . e(doctor_focus_options()[$focus]) . '»' : '' ?></p>
    <p class="dir-clear"><a href="<?= e(url('/doctors')) ?>">پاک کردن فیلترها</a></p>
  <?php endif; ?>

  <div class="doctors-directory" style="margin-top:1.25rem">
    <?php foreach ($doctors as $doc): ?>
      <?= doctor_card_html($doc) ?>
    <?php endforeach; ?>
    <?php if (!$doctors): ?><p class="muted">نتیجه‌ای یافت نشد. فیلتر را عوض کن یا فهرست کامل را ببین.</p><?php endif; ?>
  </div>
</div>
<?php
$content = ob_get_clean();
require_once __DIR__ . '/../includes/patient_panel.php';
finish_patient_or_public_page($pageTitle, $content);
