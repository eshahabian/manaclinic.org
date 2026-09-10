<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/doctor_panel.php';
require_once __DIR__ . '/../../includes/assistant.php';
require_once __DIR__ . '/../../includes/workshops.php';

$ctx = require_doctor_profile($pdo);
$doctorId = (string) $ctx['profile']['id'];
$userId = doctor_ctx_user_id($ctx);

ensure_assistant_schema($pdo);
ensure_workshop_schema($pdo);

$apptStmt = $pdo->prepare("
  SELECT a.*, u.name AS patient_name, u.phone,
         cu.name AS actor_name, cu.username AS actor_username
  FROM appointments a
  JOIN users u ON u.id = a.patient_id
  LEFT JOIN users cu ON cu.id = a.created_by_user_id
  WHERE a.doctor_id=?
  ORDER BY a.starts_at ASC
");
$apptStmt->execute([$doctorId]);
$allAppointments = $apptStmt->fetchAll();

$now = time();
$upcomingAppointments = [];
foreach ($allAppointments as $row) {
    $status = (string) ($row['status'] ?? '');
    $start = strtotime((string) ($row['starts_at'] ?? '')) ?: 0;
    if (!in_array($status, ['CANCELLED', 'COMPLETED'], true) && $start >= $now) {
        $upcomingAppointments[] = $row;
    }
}
$apptTotal = count($upcomingAppointments);
$dashApptYmd = group_appointments_by_jalali_ymd($allAppointments, 'dash-ap', 'current');

$jalaliNow = jalali_current_month_meta();
$curJy = (int) ($jalaliNow['year'] ?? 0);
$curJm = (int) ($jalaliNow['month'] ?? 1);
[$prevJy, $prevJm] = jalali_shift_month($curJy, $curJm, -1);
$thisMonthItems = appointments_in_jalali_month($allAppointments, $curJy, $curJm);
$prevMonthItems = appointments_in_jalali_month($allAppointments, $prevJy, $prevJm);
$thisMonthPeople = appointment_unique_patient_count($thisMonthItems);
$prevMonthPeople = appointment_unique_patient_count($prevMonthItems);
$prevMonthMeta = jalali_month_meta_from_parts($prevJy, $prevJm);
$thisMonthMeta = jalali_month_meta_from_parts($curJy, $curJm);

$ymdRenderDash = static function (array $list): void {
    if (!$list) {
        echo '<p class="muted binder-empty">نوبتی در این بازه نیست.</p>';
        return;
    }
    echo '<ul class="doctor-dash-list">';
    foreach ($list as $a) {
        if (!is_array($a)) {
            continue;
        }
        echo '<li><div class="doctor-dash-row"><div>';
        echo '<strong>' . e((string) ($a['patient_name'] ?? '')) . '</strong>';
        echo '<span class="muted">' . e(format_fa_datetime((string) ($a['starts_at'] ?? ''))) . '</span>';
        echo staff_sign_html(['name' => $a['actor_name'] ?? '', 'username' => $a['actor_username'] ?? ''], 'ثبت نوبت');
        echo '</div><div class="doctor-dash-row-actions">';
        echo '<span class="badge">' . e(appointment_row_status_label($a)) . '</span>';
        if (!empty($a['patient_id'])) {
            echo '<a class="btn btn-outline btn-sm" href="' . e(url('/doctor/patients/' . $a['patient_id'])) . '">پرونده</a>';
        }
        echo '</div></div></li>';
    }
    echo '</ul>';
};

// گفتگوهای دستیار
$intakeRows = $pdo->query("
  SELECT s.id, s.sent_at, s.ai_summary, s.intake_text, s.patient_id, u.name AS patient_name
  FROM assistant_sessions s
  LEFT JOIN users u ON u.id = s.patient_id
  WHERE s.status = 'SENT'
    AND s.sent_at IS NOT NULL
    AND (s.patient_id IS NULL OR s.patient_id = '')
  ORDER BY s.sent_at DESC
  LIMIT 5
")->fetchAll();

$intakeTotal = (int) $pdo->query("
  SELECT COUNT(*) FROM assistant_sessions
  WHERE status='SENT' AND sent_at IS NOT NULL
    AND (patient_id IS NULL OR patient_id = '')
")->fetchColumn();

// اعلان‌ها — جداسازی دستیار از بقیه
$allNotifs = fetch_notifications($pdo, $userId, 20);
$aiNotifs = [];
$otherNotifs = [];
foreach ($allNotifs as $n) {
    if (notification_is_assistant($n)) {
        $aiNotifs[] = $n;
    } elseif (!notification_is_staff_copy($n) && notification_kind($n) !== 'handover') {
        $otherNotifs[] = $n;
    }
}
$unreadCount = count_unread_notifications($pdo, $userId);

// کارگاه‌های خودم
$wsStmt = $pdo->prepare("
  SELECT w.id, w.title, w.type, w.is_published, w.status, w.starts_at, w.ends_at,
    (SELECT COUNT(*) FROM workshop_enrollments e
     WHERE e.workshop_id = w.id AND e.status IN ('PENDING_PAYMENT','CONFIRMED','COMPLETED')) AS enrolled_count
  FROM workshops w
  WHERE w.doctor_id = ?
  ORDER BY w.created_at DESC
");
$wsStmt->execute([$doctorId]);
$workshops = $wsStmt->fetchAll();
$wsGrouped = workshop_group_for_tabs($workshops);

$wsCountStmt = $pdo->prepare("
  SELECT COUNT(*) FROM workshops
  WHERE doctor_id=? AND is_published=1 AND status NOT IN ('CANCELLED','COMPLETED')
");
$wsCountStmt->execute([$doctorId]);
$wsActive = (int) $wsCountStmt->fetchColumn();

$wsArchiveCount = count($wsGrouped['archive']);

ob_start();
?>
<div class="doctor-dash">
  <header class="doctor-dash-head">
    <div>
      <h1>سلام، <?= e(doctor_ctx_user_name($ctx)) ?></h1>
      <p class="muted">خلاصه کار — نوبت منشی و رزرو آنلاین اینجاست. از تب نوبت‌ها سال، ماه و روز را جدا کنید.</p>
    </div>
    <?php if ($unreadCount > 0): ?>
      <span class="badge doctor-dash-badge"><?= (int) $unreadCount ?> پیام خوانده‌نشده</span>
    <?php endif; ?>
  </header>

  <div class="panel doctor-dash-tile" data-dash-tabs>
    <div class="doctor-dash-tabs" role="tablist" aria-label="بخش‌های پنل">
      <button type="button" class="doctor-dash-tab is-active" role="tab" id="dash-tab-ai" aria-controls="dash-panel-ai" aria-selected="true" data-tab="ai">
        گفتگوها و پیام‌ها
        <span class="doctor-dash-tab-count"><?= (int) $intakeTotal ?></span>
      </button>
      <button type="button" class="doctor-dash-tab" role="tab" id="dash-tab-appts" aria-controls="dash-panel-appts" aria-selected="false" data-tab="appts">
        نوبت‌ها
        <span class="doctor-dash-tab-count"><?= (int) $apptTotal ?></span>
      </button>
      <button type="button" class="doctor-dash-tab" role="tab" id="dash-tab-workshops" aria-controls="dash-panel-workshops" aria-selected="false" data-tab="workshops">
        کارگاه‌ها
        <span class="doctor-dash-tab-count"><?= (int) $wsActive ?></span>
      </button>
    </div>

    <div class="doctor-dash-panels">
    <!-- ۱) پیام‌ها و هوش مصنوعی -->
    <section class="doctor-dash-panel is-active" id="dash-panel-ai" role="tabpanel" aria-labelledby="dash-tab-ai" data-panel="ai">
      <div class="doctor-dash-card-head">
        <div>
          <p class="doctor-dash-kicker">هوش مصنوعی</p>
          <h2>گفتگوها و پیام‌های دستیار</h2>
        </div>
        <div class="doctor-dash-card-actions">
          <a class="btn btn-outline btn-sm" href="<?= e(url('/doctor/notifications?kind=assistant')) ?>">اعلان دستیار</a>
        </div>
      </div>

      <div class="doctor-dash-stats">
        <a class="doctor-dash-stat" href="<?= e(url('/doctor/notifications?kind=assistant')) ?>">
          <strong><?= (int) $intakeTotal ?></strong>
          <span>گفتگوی ارسال‌شده</span>
        </a>
        <a class="doctor-dash-stat" href="<?= e(url('/doctor/notifications?kind=assistant')) ?>">
          <strong><?= count($aiNotifs) ?></strong>
          <span>اعلان دستیار</span>
        </a>
      </div>

      <h3 class="doctor-dash-sub">آخرین گفتگوها</h3>
      <?php if (!$intakeRows): ?>
        <p class="muted doctor-dash-empty">هنوز گفتگوی مهمانی از دستیار نرسیده است.</p>
      <?php else: ?>
        <ul class="doctor-dash-list">
          <?php foreach ($intakeRows as $row): ?>
            <?php
              $summary = trim((string) ($row['ai_summary'] ?? ''));
              if ($summary === '') {
                  $summary = mb_substr(trim((string) ($row['intake_text'] ?? '')), 0, 120);
              }
              $who = empty($row['patient_id']) ? 'مراجعه‌کننده مهمان' : (string) ($row['patient_name'] ?? 'مراجعه‌کننده');
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
        <p class="muted" style="margin:0 0 .55rem;font-size:.85rem">
          <a href="<?= e(url('/doctor/notifications?kind=assistant')) ?>">مشاهده اعلان دستیار و گفتگوها</a>
        </p>
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
          <p style="margin-top:.75rem">
            <a class="btn btn-outline btn-sm" href="<?= e(url('/doctor/notifications?kind=other')) ?>">همه پیام‌های سیستم</a>
          </p>
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
    <section class="doctor-dash-panel" id="dash-panel-appts" role="tabpanel" aria-labelledby="dash-tab-appts" data-panel="appts" hidden>
      <div class="doctor-dash-card-head">
        <div>
          <p class="doctor-dash-kicker">زمان‌بندی</p>
          <h2>نوبت‌ها</h2>
        </div>
        <a class="btn btn-outline btn-sm" href="<?= e(url('/doctor/appointments')) ?>">همه نوبت‌ها</a>
      </div>

      <div class="doctor-dash-stats">
        <a class="doctor-dash-stat" href="<?= e(url('/doctor/appointments')) ?>">
          <strong><?= (int) $apptTotal ?></strong>
          <span>نوبت پیش‌رو</span>
        </a>
        <a class="doctor-dash-stat" href="<?= e(url('/doctor/appointments')) ?>">
          <strong><?= (int) $thisMonthPeople ?></strong>
          <span><?= e((string) ($thisMonthMeta['short'] ?? 'این ماه')) ?> · <?= e(to_fa_digits((string) count($thisMonthItems))) ?> نوبت</span>
        </a>
        <a class="doctor-dash-stat" href="<?= e(url('/doctor/appointments?tab=done')) ?>">
          <strong><?= (int) $prevMonthPeople ?></strong>
          <span><?= e((string) ($prevMonthMeta['short'] ?? 'ماه پیش')) ?> · <?= e(to_fa_digits((string) count($prevMonthItems))) ?> نوبت</span>
        </a>
        <div class="doctor-dash-stat">
          <a href="<?= e(url('/doctor/availability')) ?>">روزهای خالی</a>
          <span>مدیریت تقویم</span>
        </div>
      </div>

      <h3 class="doctor-dash-sub">همه نوبت‌ها بر اساس تاریخ</h3>
      <?php
        $ymdPack = $dashApptYmd;
        $ymdEmpty = 'هنوز نوبتی برای شما ثبت نشده است.';
        $ymdRenderItems = $ymdRenderDash;
        require __DIR__ . '/../../includes/appointment_ymd_binder.php';
      ?>
      <p style="margin-top:1rem">
        <a class="btn btn-primary btn-sm" href="<?= e(url('/doctor/patients')) ?>">پرونده مراجعه‌کنندگان</a>
      </p>
    </section>

    <!-- ۳) کارگاه‌ها -->
    <section class="doctor-dash-panel" id="dash-panel-workshops" role="tabpanel" aria-labelledby="dash-tab-workshops" data-panel="workshops" hidden>
      <div class="doctor-dash-card-head">
        <div>
          <p class="doctor-dash-kicker">آموزش گروهی</p>
          <h2>کارگاه‌ها</h2>
        </div>
        <a class="btn btn-outline btn-sm" href="<?= e(url('/doctor/workshops')) ?>">مدیریت کارگاه‌ها</a>
      </div>

      <div class="doctor-dash-stats">
        <a class="doctor-dash-stat" href="<?= e(url('/doctor/workshops')) ?>">
          <strong><?= (int) $wsActive ?></strong>
          <span>کارگاه فعال</span>
        </a>
        <a class="doctor-dash-stat" href="<?= e(url('/doctor/workshops?tab=archive')) ?>">
          <strong><?= (int) $wsArchiveCount ?></strong>
          <span>آرشیو کارگاه‌ها</span>
        </a>
      </div>

      <div class="binder-tile binder-tile--nested" data-binder-tabs data-binder-hash="0" data-binder-tone="in-person">
        <div class="binder-tabs" role="tablist" aria-label="کارگاه‌های شما">
          <button type="button" class="binder-tab binder-tab-in-person is-active" role="tab" data-binder-tab="ws-in-person" aria-selected="true">
            حضوری <span class="binder-tab-count"><?= count($wsGrouped['in-person']) ?></span>
          </button>
          <button type="button" class="binder-tab binder-tab-online" role="tab" data-binder-tab="ws-online" aria-selected="false">
            آنلاین <span class="binder-tab-count"><?= count($wsGrouped['online']) ?></span>
          </button>
          <button type="button" class="binder-tab binder-tab-offline" role="tab" data-binder-tab="ws-offline" aria-selected="false">
            آفلاین <span class="binder-tab-count"><?= count($wsGrouped['offline']) ?></span>
          </button>
          <button type="button" class="binder-tab binder-tab-archive" role="tab" data-binder-tab="ws-archive" aria-selected="false">
            آرشیو <span class="binder-tab-count"><?= count($wsGrouped['archive']) ?></span>
          </button>
        </div>
        <div class="binder-body">
          <?php
            $dashWsTabs = [
              'ws-in-person' => ['list' => $wsGrouped['in-person'], 'empty' => 'کارگاه حضوری فعالی ندارید.', 'active' => true],
              'ws-online' => ['list' => $wsGrouped['online'], 'empty' => 'کارگاه آنلاین فعالی ندارید.', 'active' => false],
              'ws-offline' => ['list' => $wsGrouped['offline'], 'empty' => 'دوره آفلاین فعالی ندارید.', 'active' => false],
              'ws-archive' => ['list' => $wsGrouped['archive'], 'empty' => 'هنوز کارگاهی در آرشیو نیست.', 'active' => false],
            ];
          ?>
          <?php foreach ($dashWsTabs as $tabId => $tabData): ?>
            <section class="binder-panel<?= !empty($tabData['active']) ? ' is-active' : '' ?>" data-binder-panel="<?= e($tabId) ?>" role="tabpanel"<?= empty($tabData['active']) ? ' hidden' : '' ?>>
              <?php
                $workshopList = $tabData['list'];
                $workshopEmpty = $tabData['empty'];
                $workshopYmdPrefix = 'dws-' . preg_replace('/[^a-z]/', '', (string) $tabId);
                $workshopYmdKind = 'dash';
                require __DIR__ . '/../../includes/workshop_ymd_list.php';
              ?>
            </section>
          <?php endforeach; ?>
        </div>
      </div>
    </section>
    </div>
  </div>
</div>
<script src="<?= e(url('/assets/js/binder-tabs.js')) ?>?v=20260910p"></script>
<script src="<?= e(url('/assets/js/ymd-cascade.js')) ?>?v=20260910r"></script>
<script>
(function () {
  var root = document.querySelector('[data-dash-tabs]');
  if (!root) return;
  var tabs = root.querySelectorAll('.doctor-dash-tabs [role="tab"]');
  var panels = root.querySelectorAll('.doctor-dash-panels > [data-panel]');
  function activate(id) {
    tabs.forEach(function (tab) {
      var on = tab.getAttribute('data-tab') === id;
      tab.classList.toggle('is-active', on);
      tab.setAttribute('aria-selected', on ? 'true' : 'false');
    });
    panels.forEach(function (panel) {
      var on = panel.getAttribute('data-panel') === id;
      panel.classList.toggle('is-active', on);
      if (on) panel.removeAttribute('hidden');
      else panel.setAttribute('hidden', '');
    });
    if (history.replaceState) history.replaceState(null, '', '#' + id);
  }
  tabs.forEach(function (tab) {
    tab.addEventListener('click', function () { activate(tab.getAttribute('data-tab')); });
  });
  var fromHash = (location.hash || '').replace('#', '');
  var valid = false;
  panels.forEach(function (panel) {
    if (panel.getAttribute('data-panel') === fromHash) valid = true;
  });
  if (valid) activate(fromHash);
})();
</script>
<?php
render_doctor_page('پنل دکتر', ob_get_clean());
