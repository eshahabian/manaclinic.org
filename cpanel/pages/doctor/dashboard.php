<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/doctor_panel.php';
require_once __DIR__ . '/../../includes/assistant.php';
require_once __DIR__ . '/../../includes/workshops.php';

$ctx = require_doctor_profile($pdo);
$doctorId = (string) $ctx['profile']['id'];
$userId = (string) $ctx['user']['id'];

ensure_assistant_schema($pdo);
ensure_workshop_schema($pdo);

// نوبت‌های پیش‌رو
$apptStmt = $pdo->prepare("
  SELECT a.*, u.name AS patient_name
  FROM appointments a
  JOIN users u ON u.id = a.patient_id
  WHERE a.doctor_id=? AND a.status IN ('CONFIRMED','PENDING_PAYMENT') AND a.starts_at >= NOW()
  ORDER BY a.starts_at ASC
  LIMIT 6
");
$apptStmt->execute([$doctorId]);
$appointments = $apptStmt->fetchAll();

$apptCountStmt = $pdo->prepare("
  SELECT COUNT(*) FROM appointments
  WHERE doctor_id=? AND status IN ('CONFIRMED','PENDING_PAYMENT') AND starts_at >= NOW()
");
$apptCountStmt->execute([$doctorId]);
$apptTotal = (int) $apptCountStmt->fetchColumn();

// گفتگوهای دستیار
$intakeRows = $pdo->query("
  SELECT s.id, s.sent_at, s.ai_summary, s.intake_text, s.patient_id, u.name AS patient_name
  FROM assistant_sessions s
  LEFT JOIN users u ON u.id = s.patient_id
  WHERE s.status = 'SENT' AND s.sent_at IS NOT NULL
  ORDER BY s.sent_at DESC
  LIMIT 5
")->fetchAll();

$intakeTotal = (int) $pdo->query("
  SELECT COUNT(*) FROM assistant_sessions WHERE status='SENT' AND sent_at IS NOT NULL
")->fetchColumn();

// اعلان‌ها — جداسازی دستیار از بقیه
$allNotifs = fetch_notifications($pdo, $userId, 20);
$aiNotifs = [];
$otherNotifs = [];
foreach ($allNotifs as $n) {
    $blob = ((string) ($n['title'] ?? '')) . ' ' . ((string) ($n['body'] ?? '')) . ' ' . ((string) ($n['link'] ?? ''));
    $isAi = (mb_stripos($blob, 'دستیار') !== false)
        || (mb_stripos($blob, 'گفتگو') !== false)
        || (mb_stripos((string) ($n['link'] ?? ''), '/doctor/intakes') !== false)
        || (mb_stripos((string) ($n['link'] ?? ''), '/assistant') !== false);
    if ($isAi) {
        $aiNotifs[] = $n;
    } else {
        $otherNotifs[] = $n;
    }
}
$unreadCount = count_unread_notifications($pdo, $userId);

// کارگاه‌های خودم
$wsStmt = $pdo->prepare("
  SELECT w.id, w.title, w.type, w.is_published, w.status, w.starts_at,
    (SELECT COUNT(*) FROM workshop_enrollments e
     WHERE e.workshop_id = w.id AND e.status IN ('PENDING_PAYMENT','CONFIRMED','COMPLETED')) AS enrolled_count
  FROM workshops w
  WHERE w.doctor_id = ?
  ORDER BY w.created_at DESC
  LIMIT 5
");
$wsStmt->execute([$doctorId]);
$workshops = $wsStmt->fetchAll();

$wsCountStmt = $pdo->prepare("
  SELECT COUNT(*) FROM workshops
  WHERE doctor_id=? AND is_published=1 AND status NOT IN ('CANCELLED','COMPLETED')
");
$wsCountStmt->execute([$doctorId]);
$wsActive = (int) $wsCountStmt->fetchColumn();

ob_start();
?>
<div class="doctor-dash">
  <header class="doctor-dash-head">
    <div>
      <h1>سلام، <?= e($ctx['user']['name']) ?></h1>
      <p class="muted">خلاصه کار امروز — گفتگوهای دستیار، نوبت‌ها و کارگاه‌ها جدا از هم هستند.</p>
    </div>
    <?php if ($unreadCount > 0): ?>
      <span class="badge doctor-dash-badge"><?= (int) $unreadCount ?> پیام خوانده‌نشده</span>
    <?php endif; ?>
  </header>

  <div class="doctor-dash-grid">

    <!-- ۱) پیام‌ها و هوش مصنوعی -->
    <section class="panel doctor-dash-card doctor-dash-card--ai">
      <div class="doctor-dash-card-head">
        <div>
          <p class="doctor-dash-kicker">هوش مصنوعی</p>
          <h2>گفتگوها و پیام‌های دستیار</h2>
        </div>
        <a class="btn btn-outline btn-sm" href="<?= e(url('/doctor/intakes')) ?>">همه گفتگوها</a>
      </div>

      <div class="doctor-dash-stats">
        <div class="doctor-dash-stat">
          <strong><?= (int) $intakeTotal ?></strong>
          <span>گفتگوی ارسال‌شده</span>
        </div>
        <div class="doctor-dash-stat">
          <strong><?= count($aiNotifs) ?></strong>
          <span>اعلان دستیار</span>
        </div>
      </div>

      <h3 class="doctor-dash-sub">آخرین گفتگوها</h3>
      <?php if (!$intakeRows): ?>
        <p class="muted doctor-dash-empty">هنوز گفتگویی از دستیار نرسیده است.</p>
      <?php else: ?>
        <ul class="doctor-dash-list">
          <?php foreach ($intakeRows as $row): ?>
            <?php
              $summary = trim((string) ($row['ai_summary'] ?? ''));
              if ($summary === '') {
                  $summary = mb_substr(trim((string) ($row['intake_text'] ?? '')), 0, 120);
              }
              $who = empty($row['patient_id']) ? 'مراجع مهمان' : (string) ($row['patient_name'] ?? 'مراجع');
            ?>
            <li>
              <a href="<?= e(url('/doctor/intakes/' . $row['id'])) ?>">
                <strong><?= e($who) ?></strong>
                <span class="muted"><?= e(format_fa_datetime((string) ($row['sent_at'] ?? ''))) ?></span>
                <p><?= e($summary !== '' ? $summary : 'بدون خلاصه') ?><?= mb_strlen($summary) >= 120 ? '…' : '' ?></p>
              </a>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>

      <?php if ($aiNotifs): ?>
        <h3 class="doctor-dash-sub">اعلان‌های دستیار</h3>
        <ul class="doctor-dash-list doctor-dash-list--compact">
          <?php foreach (array_slice($aiNotifs, 0, 4) as $n): ?>
            <li class="<?= !(int) $n['is_read'] ? 'is-unread' : '' ?>">
              <?php if (!empty($n['link'])): ?>
                <a href="<?= e(url((string) $n['link'])) ?>">
                  <strong><?= e((string) $n['title']) ?></strong>
                  <span class="muted"><?= e(format_fa_datetime((string) $n['created_at'])) ?></span>
                </a>
              <?php else: ?>
                <div>
                  <strong><?= e((string) $n['title']) ?></strong>
                  <span class="muted"><?= e(format_fa_datetime((string) $n['created_at'])) ?></span>
                </div>
              <?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>

      <?php if ($otherNotifs): ?>
        <details class="doctor-dash-other">
          <summary>سایر پیام‌های سیستم (<?= count($otherNotifs) ?>)</summary>
          <ul class="doctor-dash-list doctor-dash-list--compact">
            <?php foreach (array_slice($otherNotifs, 0, 5) as $n): ?>
              <li>
                <?php if (!empty($n['link'])): ?>
                  <a href="<?= e(url((string) $n['link'])) ?>"><strong><?= e((string) $n['title']) ?></strong></a>
                <?php else: ?>
                  <strong><?= e((string) $n['title']) ?></strong>
                <?php endif; ?>
              </li>
            <?php endforeach; ?>
          </ul>
          <form method="post" action="<?= e(url('/doctor/notifications/read')) ?>" style="margin-top:.75rem">
            <input type="hidden" name="mark_all" value="1">
            <button type="submit" class="btn btn-outline btn-sm">خواندن همه پیام‌ها</button>
          </form>
        </details>
      <?php elseif ($aiNotifs || $unreadCount): ?>
        <form method="post" action="<?= e(url('/doctor/notifications/read')) ?>" style="margin-top:1rem">
          <input type="hidden" name="mark_all" value="1">
          <button type="submit" class="btn btn-outline btn-sm">خواندن همه پیام‌ها</button>
        </form>
      <?php endif; ?>
    </section>

    <!-- ۲) نوبت‌ها -->
    <section class="panel doctor-dash-card doctor-dash-card--appts">
      <div class="doctor-dash-card-head">
        <div>
          <p class="doctor-dash-kicker">زمان‌بندی</p>
          <h2>نوبت‌ها</h2>
        </div>
        <a class="btn btn-outline btn-sm" href="<?= e(url('/doctor/appointments')) ?>">همه نوبت‌ها</a>
      </div>

      <div class="doctor-dash-stats">
        <div class="doctor-dash-stat">
          <strong><?= (int) $apptTotal ?></strong>
          <span>نوبت پیش‌رو</span>
        </div>
        <div class="doctor-dash-stat">
          <a href="<?= e(url('/doctor/availability')) ?>">روزهای خالی</a>
          <span>مدیریت تقویم</span>
        </div>
      </div>

      <h3 class="doctor-dash-sub">نوبت‌های نزدیک</h3>
      <?php if (!$appointments): ?>
        <p class="muted doctor-dash-empty">نوبت پیش‌رویی نیست.</p>
      <?php else: ?>
        <ul class="doctor-dash-list">
          <?php foreach ($appointments as $a): ?>
            <li>
              <div class="doctor-dash-row">
                <div>
                  <strong><?= e((string) $a['patient_name']) ?></strong>
                  <span class="muted"><?= e(format_fa_datetime((string) $a['starts_at'])) ?></span>
                </div>
                <div class="doctor-dash-row-actions">
                  <span class="badge"><?= e(appointment_status_label((string) $a['status'])) ?></span>
                  <a class="btn btn-outline btn-sm" href="<?= e(url('/doctor/patients/' . $a['patient_id'])) ?>">پرونده</a>
                </div>
              </div>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
      <p style="margin-top:1rem">
        <a class="btn btn-primary btn-sm" href="<?= e(url('/doctor/patients')) ?>">پرونده بیماران</a>
      </p>
    </section>

    <!-- ۳) کارگاه‌ها -->
    <section class="panel doctor-dash-card doctor-dash-card--workshops">
      <div class="doctor-dash-card-head">
        <div>
          <p class="doctor-dash-kicker">آموزش گروهی</p>
          <h2>کارگاه‌ها</h2>
        </div>
        <a class="btn btn-outline btn-sm" href="<?= e(url('/doctor/workshops')) ?>">مدیریت کارگاه‌ها</a>
      </div>

      <div class="doctor-dash-stats">
        <div class="doctor-dash-stat">
          <strong><?= (int) $wsActive ?></strong>
          <span>کارگاه فعال</span>
        </div>
        <div class="doctor-dash-stat">
          <a href="<?= e(url('/doctor/workshops')) ?>">+ کارگاه جدید</a>
          <span>ایجاد / ویرایش</span>
        </div>
      </div>

      <h3 class="doctor-dash-sub">آخرین کارگاه‌های شما</h3>
      <?php if (!$workshops): ?>
        <p class="muted doctor-dash-empty">هنوز کارگاهی نساخته‌اید.</p>
      <?php else: ?>
        <ul class="doctor-dash-list">
          <?php foreach ($workshops as $w): ?>
            <li>
              <a href="<?= e(url('/doctor/workshops?edit=' . urlencode((string) $w['id']))) ?>">
                <strong><?= e((string) $w['title']) ?></strong>
                <span class="muted">
                  <?= e(workshop_type_label((string) $w['type'])) ?>
                  · <?= (int) ($w['enrolled_count'] ?? 0) ?> نفر
                  · <?= !empty($w['is_published']) && !in_array((string) ($w['status'] ?? ''), ['CANCELLED', 'COMPLETED'], true) ? 'منتشرشده' : 'پیش‌نویس/غیرفعال' ?>
                </span>
              </a>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </section>

  </div>
</div>
<?php
render_doctor_page('پنل دکتر', ob_get_clean());
