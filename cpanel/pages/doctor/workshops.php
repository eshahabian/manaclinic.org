<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/doctor_panel.php';
require_once __DIR__ . '/../../includes/workshops.php';

$ctx = require_doctor_profile($pdo);
ensure_workshop_schema($pdo);

$stmt = $pdo->prepare('
  SELECT w.*,
    (SELECT COUNT(*) FROM workshop_enrollments e
     WHERE e.workshop_id = w.id AND e.status IN ("PENDING_PAYMENT","CONFIRMED","COMPLETED")) AS enrolled_count
  FROM workshops w
  WHERE w.doctor_id = ?
  ORDER BY w.starts_at DESC
');
$stmt->execute([$ctx['profile']['id']]);
$workshops = $stmt->fetchAll();
$doctorWallet = ensure_wallet($pdo, $ctx['user']['id']);

ob_start();
?>
<h1>کارگاه‌ها و دوره‌ها</h1>
<p class="muted" style="margin-top:.35rem;font-size:.9rem">کارگاه برگزار کنید؛ مراجعان از بخش «دوره‌های من» ثبت‌نام می‌کنند.</p>
<div class="panel row-between" style="margin-top:1rem;font-size:.9rem">
  <div>
    <span class="muted">کیف پول شما — </span>
    <strong><?= e(format_price((int)$doctorWallet['balance'])) ?></strong>
    <span class="muted"> قابل برداشت · </span>
    <strong><?= e(format_price((int)$doctorWallet['held_balance'])) ?></strong>
    <span class="muted"> امانی (تا پایان کارگاه)</span>
  </div>
</div>

<form class="panel form-stack" method="post" action="<?= e(url('/doctor/workshops')) ?>" style="margin-top:1rem">
  <input type="hidden" name="action" value="create">
  <h2 style="margin:0;font-size:1.05rem">کارگاه جدید</h2>
  <div><label class="label">نام کارگاه</label><input class="input" name="title" required></div>
  <div>
    <label class="label">نوع</label>
    <select class="input" name="type" required>
      <option value="IN_PERSON">حضوری</option>
      <option value="ONLINE">آنلاین</option>
      <option value="OFFLINE">آفلاین (فایل/ویدیو)</option>
    </select>
  </div>
  <div class="grid-2">
    <div>
      <label class="label">شروع — تاریخ</label>
      <input class="input" type="date" name="start_date" required>
    </div>
    <div>
      <label class="label">شروع — ساعت</label>
      <input class="input" type="time" name="start_time" required>
    </div>
  </div>
  <div class="grid-2">
    <div>
      <label class="label">پایان — تاریخ</label>
      <input class="input" type="date" name="end_date" required>
    </div>
    <div>
      <label class="label">پایان — ساعت</label>
      <input class="input" type="time" name="end_time" required>
    </div>
  </div>
  <div class="grid-2">
    <div>
      <label class="label">هزینه (تومان)</label>
      <input class="input" name="price" type="number" min="0" step="1000" value="0" required>
    </div>
    <div>
      <label class="label">ظرفیت (خالی = نامحدود)</label>
      <input class="input" name="capacity" type="number" min="1">
    </div>
  </div>
  <div><label class="label">محل برگزاری (حضوری)</label><input class="input" name="location" placeholder="آدرس یا سالن"></div>
  <div><label class="label">لینک جلسه (آنلاین)</label><input class="input" name="meeting_url" dir="ltr" placeholder="https://..."></div>
  <div><label class="label">لینک محتوا (آفلاین)</label><input class="input" name="content_url" dir="ltr" placeholder="https://..."></div>
  <div>
    <label class="label">موارد همراه</label>
    <textarea class="input" name="items_to_bring" rows="3" placeholder="مثلاً: دفترچه، مداد، ..."></textarea>
  </div>
  <div>
    <label class="label">توضیح کوتاه</label>
    <textarea class="input" name="description" rows="2"></textarea>
  </div>
  <div>
    <label class="label">یادداشت</label>
    <textarea class="input" name="notes" rows="3" placeholder="یادداشت داخلی یا توضیحات تکمیلی برای شرکت‌کنندگان"></textarea>
  </div>
  <label style="display:flex;gap:.5rem;align-items:center;font-size:.9rem">
    <input type="checkbox" name="published" checked> انتشار برای مراجعان
  </label>
  <button class="btn btn-primary" type="submit">ایجاد کارگاه</button>
</form>

<div class="stack" style="margin-top:1.5rem">
  <?php foreach ($workshops as $w): ?>
    <div class="panel">
      <div class="row-between" style="align-items:flex-start">
        <div>
          <strong><?= e($w['title']) ?></strong>
          <span class="badge" style="margin-right:.5rem"><?= e(workshop_type_label($w['type'])) ?></span>
          <div class="muted" style="font-size:.85rem;margin-top:.35rem">
            <?= e(format_fa_datetime($w['starts_at'])) ?> — <?= e(format_fa_datetime($w['ends_at'])) ?>
          </div>
          <div class="muted" style="font-size:.85rem;margin-top:.25rem">
            <?= e(format_price((int)$w['price'])) ?>
            · ثبت‌نام: <?= (int)$w['enrolled_count'] ?><?= $w['capacity'] ? ' / ' . (int)$w['capacity'] : '' ?>
            · <?= $w['is_published'] ? 'منتشر شده' : 'پیش‌نویس' ?>
            · <?= $w['status'] === 'COMPLETED' ? 'برگزار شده' : ($w['status'] === 'CANCELLED' ? 'لغو شده' : 'فعال') ?>
          </div>
        </div>
        <div style="display:flex;flex-wrap:wrap;gap:.5rem;justify-content:flex-end">
          <?php if ($w['status'] === 'PUBLISHED' || ($w['is_published'] && $w['status'] !== 'COMPLETED' && $w['status'] !== 'CANCELLED')): ?>
            <form method="post" action="<?= e(url('/doctor/workshops')) ?>">
              <input type="hidden" name="action" value="complete">
              <input type="hidden" name="id" value="<?= e($w['id']) ?>">
              <button class="btn btn-outline btn-sm" type="submit">تسویه و پایان</button>
            </form>
          <?php endif; ?>
          <form method="post" action="<?= e(url('/doctor/workshops')) ?>">
            <input type="hidden" name="action" value="toggle">
            <input type="hidden" name="id" value="<?= e($w['id']) ?>">
            <button class="btn btn-outline btn-sm" type="submit"><?= $w['is_published'] ? 'لغو انتشار' : 'انتشار' ?></button>
          </form>
          <form method="post" action="<?= e(url('/doctor/workshops')) ?>" onsubmit="return confirm('حذف شود؟')">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= e($w['id']) ?>">
            <button class="btn btn-danger btn-sm" type="submit">حذف</button>
          </form>
        </div>
      </div>
      <?php if ($w['items_to_bring']): ?>
        <p style="font-size:.85rem;margin:.75rem 0 0"><strong>موارد همراه:</strong> <?= e($w['items_to_bring']) ?></p>
      <?php endif; ?>
      <?php if ($w['notes']): ?>
        <p class="muted" style="font-size:.85rem;margin:.5rem 0 0"><strong>یادداشت:</strong> <?= e($w['notes']) ?></p>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
  <?php if (!$workshops): ?><p class="muted">هنوز کارگاهی ثبت نشده است.</p><?php endif; ?>
</div>
<?php
render_doctor_page('کارگاه‌ها', ob_get_clean());
