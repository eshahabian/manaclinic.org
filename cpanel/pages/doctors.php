<?php
declare(strict_types=1);

$q = trim((string) ($_GET['q'] ?? ''));
if ($q !== '') {
    $stmt = $pdo->prepare("
      SELECT dp.*, u.name
      FROM doctor_profiles dp
      JOIN users u ON u.id = dp.user_id
      WHERE dp.is_active = 1 AND dp.is_approved = 1 AND (dp.specialty LIKE ? OR dp.bio LIKE ? OR u.name LIKE ?)
      ORDER BY dp.created_at ASC
    ");
    $like = '%' . $q . '%';
    $stmt->execute([$like, $like, $like]);
    $doctors = $stmt->fetchAll();
} else {
    $doctors = $pdo->query("
      SELECT dp.*, u.name
      FROM doctor_profiles dp
      JOIN users u ON u.id = dp.user_id
      WHERE dp.is_active = 1 AND dp.is_approved = 1
      ORDER BY dp.created_at ASC
    ")->fetchAll();
}

$pageTitle = 'متخصصان';
$currentUser = current_user();
$isPatientViewer = $currentUser && ($currentUser['role'] ?? '') === 'PATIENT';
ob_start();
?>
<div class="<?= $isPatientViewer ? 'patient-panel-inner' : 'container-page section' ?>">
  <h1>متخصصان</h1>
  <p class="muted">متخصص مناسب خود را پیدا کنید و نوبت بگیرید</p>
  <form class="auth-box" style="margin-top:1.5rem;width:min(560px,100%)" method="get">
    <input class="input" name="q" value="<?= e($q) ?>" placeholder="جستجو بر اساس نام یا تخصص...">
  </form>
  <div class="grid-2" style="margin-top:2rem">
    <?php foreach ($doctors as $doc): ?>
      <a class="panel card-link" href="<?= e(url('/doctors/' . $doc['id'])) ?>" style="display:flex;gap:1rem">
        <div class="avatar" style="margin:0;width:64px;height:64px;flex-shrink:0"><?= e(mb_substr($doc['name'], 0, 1)) ?></div>
        <div>
          <h2 style="margin:0;font-size:1.25rem"><?= e($doc['name']) ?></h2>
          <p style="color:var(--primary);margin:.35rem 0 0;font-size:.9rem"><?= e($doc['specialty']) ?></p>
          <p class="muted line-clamp-3 whitespace-pre" style="font-size:.9rem;line-height:1.8;margin-top:.5rem"><?= e($doc['bio']) ?></p>
        </div>
      </a>
    <?php endforeach; ?>
    <?php if (!$doctors): ?><p class="muted">نتیجه‌ای یافت نشد.</p><?php endif; ?>
  </div>
</div>
<?php
$content = ob_get_clean();
require_once __DIR__ . '/../includes/patient_panel.php';
finish_patient_or_public_page($pageTitle, $content);
