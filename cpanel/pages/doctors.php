<?php
declare(strict_types=1);

$q = trim((string) ($_GET['q'] ?? ''));
if ($q !== '') {
    $stmt = $pdo->prepare("
      SELECT dp.*, u.name
      FROM doctor_profiles dp
      JOIN users u ON u.id = dp.user_id
      WHERE dp.is_active = 1 AND dp.is_approved = 1 AND (dp.specialty LIKE ? OR dp.bio LIKE ? OR u.name LIKE ? OR dp.domains_json LIKE ? OR dp.focus_json LIKE ?)
      ORDER BY CASE WHEN u.name LIKE '%گرانمایه%' THEN 0 ELSE 1 END, dp.created_at ASC
    ");
    $like = '%' . $q . '%';
    $stmt->execute([$like, $like, $like, $like, $like]);
    $doctors = $stmt->fetchAll();
} else {
    $doctors = $pdo->query("
      SELECT dp.*, u.name
      FROM doctor_profiles dp
      JOIN users u ON u.id = dp.user_id
      WHERE dp.is_active = 1 AND dp.is_approved = 1
      ORDER BY CASE WHEN u.name LIKE '%گرانمایه%' THEN 0 ELSE 1 END, dp.created_at ASC
    ")->fetchAll();
}

$pageTitle = 'متخصصان';
$pageDescription = 'روانشناسان مانا کلینیک سعادت‌آباد؛ انتخاب درمانگر بر اساس حوزه درمان و رزرو نوبت آنلاین.';
$pageCanonical = url('/doctors');
$pageKeywords = 'روانشناس, متخصص روانشناسی, رزرو نوبت, مانا کلینیک';
$currentUser = current_user();
$isPatientViewer = $currentUser && ($currentUser['role'] ?? '') === 'PATIENT';
ob_start();
?>
<div class="<?= $isPatientViewer ? 'patient-panel-inner' : 'container-page section' ?>">
  <h1>متخصصان</h1>
  <p class="muted">متخصص مناسب خود را پیدا کنید و نوبت بگیرید</p>
  <form class="auth-box" style="margin-top:1.5rem;width:min(560px,100%)" method="get">
    <input class="input" name="q" value="<?= e($q) ?>" placeholder="جستجو بر اساس نام یا حوزه درمان...">
  </form>
  <div class="doctors-directory" style="margin-top:2rem">
    <?php foreach ($doctors as $doc): ?>
      <?= doctor_card_html($doc) ?>
    <?php endforeach; ?>
    <?php if (!$doctors): ?><p class="muted">نتیجه‌ای یافت نشد.</p><?php endif; ?>
  </div>
</div>
<?php
$content = ob_get_clean();
require_once __DIR__ . '/../includes/patient_panel.php';
finish_patient_or_public_page($pageTitle, $content);
