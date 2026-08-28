<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/doctor_panel.php';
$ctx = require_doctor_profile($pdo);
$items = $pdo->prepare('SELECT * FROM availabilities WHERE doctor_id=? ORDER BY date ASC');
$items->execute([$ctx['profile']['id']]);
$items = $items->fetchAll();
ob_start();
?>
<h1>روزهای خالی</h1>
<p class="muted">تاریخ‌هایی که بیماران می‌توانند نوبت بگیرند را مشخص کنید.</p>
<form class="panel form-stack" method="post" action="<?= e(url('/doctor/availability')) ?>" style="margin-top:1rem;max-width:40rem">
  <input type="hidden" name="action" value="save">
  <div><label class="label">تاریخ</label><input class="input" type="date" name="date" required dir="ltr"></div>
  <div class="grid-2">
    <div><label class="label">از ساعت</label><input class="input" type="time" name="start_time" value="10:00" required></div>
    <div><label class="label">تا ساعت</label><input class="input" type="time" name="end_time" value="14:00" required></div>
  </div>
  <div><label class="label">مدت هر جلسه (دقیقه)</label><input class="input" type="number" name="slot_minutes" value="50" min="20" max="180" required></div>
  <button class="btn btn-primary" type="submit">افزودن / به‌روزرسانی</button>
</form>
<div class="stack" style="margin-top:1.5rem">
  <?php foreach ($items as $item): ?>
    <div class="panel row-between">
      <div>
        <strong dir="ltr"><?= e($item['date']) ?></strong>
        <div class="muted" style="font-size:.85rem"><?= e($item['start_time']) ?> تا <?= e($item['end_time']) ?> — هر اسلات <?= e((string)$item['slot_minutes']) ?> دقیقه</div>
      </div>
      <form method="post" action="<?= e(url('/doctor/availability')) ?>">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="id" value="<?= e($item['id']) ?>">
        <button class="btn btn-danger btn-sm" type="submit">حذف</button>
      </form>
    </div>
  <?php endforeach; ?>
</div>
<?php
render_doctor_page('روزهای خالی', ob_get_clean());
