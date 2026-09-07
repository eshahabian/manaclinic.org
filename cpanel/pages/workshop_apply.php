<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/workshops.php';

$workshopId = trim((string) ($_GET['id'] ?? ''));
$applyPath = $workshopId !== '' ? '/workshops/apply?id=' . rawurlencode($workshopId) : '/';
$user = current_user();
if (!$user) {
    redirect('/register?next=' . rawurlencode($applyPath));
}
if (($user['role'] ?? '') !== 'PATIENT') {
    flash_set('error', 'ثبت‌نام دوره فقط با حساب مراجعه‌کننده ممکن است.');
    redirect('/');
}
if ($workshopId === '') {
    flash_set('error', 'دوره یافت نشد.');
    redirect('/dashboard/workshops');
}

ensure_workshop_schema($pdo);
$stmt = $pdo->prepare('
  SELECT w.*, u.name AS doctor_name
  FROM workshops w
  ' . workshop_active_doctor_join('w') . '
  JOIN users u ON u.id = dp.user_id
  WHERE w.id = ? AND w.is_published = 1 AND w.status <> \'CANCELLED\'
  LIMIT 1
');
$stmt->execute([$workshopId]);
$workshop = $stmt->fetch();
if (!$workshop) {
    flash_set('error', 'این دوره در دسترس نیست.');
    redirect('/dashboard/workshops');
}

$enroll = $pdo->prepare('
  SELECT id, status FROM workshop_enrollments
  WHERE workshop_id=? AND patient_id=?
  ORDER BY enrolled_at DESC
  LIMIT 1
');
$enroll->execute([$workshopId, $user['id']]);
$enrollment = $enroll->fetch() ?: null;
$canEnroll = workshop_can_enroll($workshop)
    && !workshop_is_archived($workshop)
    && (string) ($workshop['status'] ?? '') === 'PUBLISHED'
    && (!$enrollment || in_array((string) ($enrollment['status'] ?? ''), ['CANCELLED', 'REFUNDED'], true));

$phase = workshop_promo_phase($workshop);
$title = (string) ($workshop['title'] ?? 'دوره');
$pageTitle = 'ثبت‌نام «' . $title . '»';
$pageDescription = 'ثبت‌نام دوره «' . $title . '» در مانا کلینیک سعادت‌آباد.';
$pageCanonical = url($applyPath);
$GLOBALS['pageRobots'] = 'noindex,nofollow';
$GLOBALS['pageDescription'] = $pageDescription;
$GLOBALS['pageCanonical'] = $pageCanonical;

ob_start();
?>
<div class="stack workshop-apply-page" data-patient-courses data-enroll-url="<?= e(url('/enroll-workshop')) ?>" data-after-enroll-url="<?= e(url('/dashboard/workshops/requested')) ?>">
    <a href="<?= e(url('/dashboard/workshops')) ?>" style="color:var(--primary);font-size:.9rem">← بازگشت به دوره‌ها</a>
    <h1><?= e($title) ?></h1>
    <p class="muted" style="margin:0">درمانگر: <?= e((string) ($workshop['doctor_name'] ?? '')) ?> · <?= e(workshop_promo_phase_label($phase)) ?></p>
    <p id="course-msg" class="course-flash" style="display:none" role="status"></p>
    <?php if (trim((string) ($workshop['description'] ?? '')) !== ''): ?>
      <div class="panel" style="line-height:1.9"><?= nl2br(e((string) $workshop['description'])) ?></div>
    <?php endif; ?>
    <?php if ($canEnroll): ?>
      <button type="button" class="btn btn-primary enroll-btn" data-id="<?= e($workshopId) ?>">ثبت‌نام در این دوره</button>
    <?php elseif ($enrollment && (string) ($enrollment['status'] ?? '') === 'PENDING_PAYMENT'): ?>
      <p class="muted">درخواست شما ثبت شده و منتظر تأیید است.</p>
      <a class="btn btn-primary" href="<?= e(url('/dashboard/workshops/requested')) ?>">مشاهده درخواست</a>
    <?php elseif ($enrollment && in_array((string) ($enrollment['status'] ?? ''), ['CONFIRMED', 'COMPLETED'], true)): ?>
      <a class="btn btn-primary" href="<?= e(url('/dashboard/workshops/mine')) ?>">ورود به دوره من</a>
    <?php else: ?>
      <p class="muted">ثبت‌نام این دوره فعلاً باز نیست.</p>
    <?php endif; ?>
  </div>
<?php
$content = ob_get_clean();
$GLOBALS['pageScripts'] = '<script src="' . e(url('/assets/js/patient-courses.js')) . '?v=20260908c"></script>';
require_once __DIR__ . '/../includes/patient_panel.php';
finish_patient_or_public_page($pageTitle, $content);
