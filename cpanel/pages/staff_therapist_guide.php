<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/therapist_guide_results.php';
if (!therapist_guide_can_read($pdo, $user)) {
    http_response_code(403);
    header('Cache-Control: no-store');
    exit('دسترسی به پاسخ‌های راهنما برای این حساب فعال نیست.');
}
if ($method !== 'GET') { http_response_code(405); header('Allow: GET'); exit; }
header('Cache-Control: no-store');
$page = max(1, min(100000, (int) ($_GET['page'] ?? 1)));
$rows = [];
$failed = false;
try {
    therapist_guide_schema($pdo);
    $offset = ($page - 1) * 20;
    $rows = $pdo->query("SELECT r.*, u.name AS visitor_name FROM therapist_guide_results r LEFT JOIN users u ON u.id=r.user_id ORDER BY r.created_at DESC,r.id DESC LIMIT 21 OFFSET {$offset}")->fetchAll();
} catch (Throwable $e) {
    $failed = true;
    error_log('therapist guide list failed: ' . get_class($e));
}
$more = count($rows) > 20;
$rows = array_slice($rows, 0, 20);
ob_start();
?>
<section class="card">
  <h1>پاسخ‌های راهنمای انتخاب درمانگر</h1>
  <p class="muted">دسترسی اختصاصی عماد شهابیان، عطیه گارسچی و شیوا گرانمایه‌پور. این جمع‌بندی‌ها تشخیص بالینی نیستند.</p>
  <?php if ($failed): ?><p role="alert">دریافت پاسخ‌ها انجام نشد؛ صفحه را دوباره باز کنید.</p>
  <?php elseif (!$rows): ?><p>هنوز پاسخی ثبت نشده است.</p><?php endif; ?>
  <?php foreach ($rows as $row): $snapshot = json_decode((string) $row['snapshot_json'], true); if (!is_array($snapshot)) continue; ?>
    <details class="card">
      <summary><?= e((string) ($row['visitor_name'] ?: 'بازدیدکننده بدون ورود')) ?> · <?= e((string) ($snapshot['route_label'] ?? '')) ?> · <?= e((string) $row['created_at']) ?></summary>
      <h2>برداشت کوتاه از پاسخ‌ها</h2>
      <?php foreach ($snapshot['summary'] ?? [] as $line): ?><p><?= e((string) $line) ?></p><?php endforeach; ?>
      <h3>درمانگران پیشنهادی هنگام ثبت</h3>
      <?php if (empty($snapshot['doctors'])): ?><p>درمانگر مرتبطی در فهرست ثبت نشده بود.</p><?php endif; ?>
      <?php foreach ($snapshot['doctors'] ?? [] as $doc): ?><p><?= e((string) $doc['name']) ?></p><?php endforeach; ?>
      <h3>سؤال‌ها و پاسخ‌ها</h3>
      <ol><?php foreach ($snapshot['answers'] ?? [] as $answer): ?><li><p><strong><?= e((string) $answer['question']) ?></strong><br><?= e((string) $answer['answer']) ?></p></li><?php endforeach; ?></ol>
      <p class="muted">نسخه راهنما: <?= e((string) $row['version']) ?> · ترجیح جنسیت صرفاً برای هماهنگی ثبت شده و در فیلتر درمانگران اعمال نشده است.</p>
    </details>
  <?php endforeach; ?>
  <nav aria-label="صفحه‌های پاسخ‌ها">
    <?php if ($page > 1): ?><a class="btn btn-outline" href="<?= e(url('/app/therapist-guide?page=' . ($page - 1))) ?>">صفحه قبل</a><?php endif; ?>
    <?php if ($more): ?><a class="btn btn-outline" href="<?= e(url('/app/therapist-guide?page=' . ($page + 1))) ?>">صفحه بعد</a><?php endif; ?>
  </nav>
</section>
<?php staff_app_render('home', 'راهنمای انتخاب درمانگر', 'پاسخ‌ها و نتیجه راهنمای انتخاب درمانگر', ob_get_clean());
