<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/doctor_panel.php';
require_once __DIR__ . '/../../includes/doctor_clinical.php';
require_once __DIR__ . '/../../includes/assistant.php';
require_once __DIR__ . '/../../includes/appointment_cancel.php';
require_once __DIR__ . '/../../includes/workshops.php';
require_once __DIR__ . '/../../includes/workshop_qa.php';
require_once __DIR__ . '/../../includes/workshop_path.php';
require_once __DIR__ . '/../../includes/patient_journal.php';

$ctx = require_doctor_profile($pdo);
$patientId = (string) ($_GET['id'] ?? '');
if ($patientId === '') {
    redirect('/doctor/patients');
}

$access = require_doctor_patient_access($pdo, $ctx, $patientId);
$patient = $access['patient'];
$appointments = $access['appointments'];
$doctorId = $ctx['profile']['id'];
$doctorName = doctor_ctx_user_name($ctx);
$isPreferred = (string) ($patient['preferred_doctor_id'] ?? '') === (string) $doctorId;

$chart = get_or_create_patient_chart($pdo, $doctorId, $patientId);
$historyExtraIds = extract_assistant_session_ids_from_history((string) ($chart['history_text'] ?? ''));
$historyClean = doctor_chart_detach_assistant_history($pdo, $chart, $patientId);
$historyHtml = history_html_for_editor($historyClean);

$notesStmt = $pdo->prepare('SELECT * FROM doctor_session_notes WHERE doctor_id=? AND patient_id=?');
$notesStmt->execute([$doctorId, $patientId]);
$notesByApp = [];
foreach ($notesStmt->fetchAll() as $n) {
    $notesByApp[$n['appointment_id']] = $n;
}

$sessionYmd = group_appointments_by_jalali_ymd($appointments, 'sess', 'latest', ['fill' => 'none']);
$intakes = doctor_patient_assistant_sessions($pdo, $patientId, $historyExtraIds);
$intakeMapped = [];
foreach ($intakes as $session) {
    $session['starts_at'] = (string) (($session['sent_at'] ?? '') ?: ($session['created_at'] ?? ''));
    $intakeMapped[] = $session;
}
$intakeYmdPack = group_appointments_by_jalali_ymd($intakeMapped, 'intk', 'latest', ['fill' => 'none']);

$enrollments = doctor_patient_enrollments_for_doctor($pdo, $doctorId, $patientId);
$activeEnrollments = [];
$archivedEnrollments = [];
foreach ($enrollments as $en) {
    if (workshop_is_archived($en)) {
        $archivedEnrollments[] = $en;
    } else {
        $activeEnrollments[] = $en;
    }
}
$privateQa = doctor_patient_private_qa_for_doctor($pdo, $doctorId, $patientId);
$pathNotes = doctor_patient_path_notes_for_doctor($pdo, $doctorId, $patientId);
$callStats = doctor_patient_call_stats($pdo, doctor_ctx_user_id($ctx), $patientId);
$journalEntries = patient_journal_fetch_all($pdo, $patientId, 60);
$journalMoods = patient_journal_moods();

$noteCount = 0;
foreach ($notesByApp as $n) {
    if (trim((string) ($n['note_text'] ?? '')) !== '') {
        $noteCount++;
    }
}

$tabs = ['overview', 'chart', 'sessions', 'intakes', 'workshops', 'messages', 'journal'];
$tabParam = trim((string) ($_GET['tab'] ?? 'overview'));
if (!in_array($tabParam, $tabs, true)) {
    $tabParam = 'overview';
}
$chartBase = url('/doctor/patients/' . $patientId);
$tabUrl = static function (string $tab) use ($chartBase): string {
    return $chartBase . ($tab === 'overview' ? '' : ('?tab=' . rawurlencode($tab)));
};
$initial = function_exists('mb_substr') ? mb_substr((string) $patient['name'], 0, 1) : substr((string) $patient['name'], 0, 1);
$lastVisit = $appointments[0]['starts_at'] ?? null;
$historySnippet = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($historyClean), ENT_QUOTES, 'UTF-8')) ?? '');
if (function_exists('mb_strlen') && mb_strlen($historySnippet) > 220) {
    $historySnippet = mb_substr($historySnippet, 0, 217) . '…';
}

ob_start();
?>
<div class="ehr-shell">
  <p class="ehr-back"><a href="<?= e(url('/doctor/patients')) ?>">← همهٔ مراجعه‌کنندگان</a></p>

  <header class="ehr-header">
    <div class="ehr-identity">
      <span class="ehr-avatar" aria-hidden="true"><?= e($initial) ?></span>
      <div class="ehr-identity-text">
        <div class="ehr-title-row">
          <h1><?= e($patient['name']) ?></h1>
          <span class="ehr-lock" title="فقط درمانگر مسئول این پرونده">محرمانه — فقط درمانگر مسئول</span>
        </div>
        <p class="ehr-meta" dir="ltr">
          <span>@<?= e((string) $patient['username']) ?></span>
          <?php if (!empty($patient['phone'])): ?>
            <span><?= e((string) $patient['phone']) ?></span>
          <?php endif; ?>
          <?php if (!empty($patient['email'])): ?>
            <span><?= e((string) $patient['email']) ?></span>
          <?php endif; ?>
        </p>
        <p class="ehr-clinician">
          درمانگر مسئول: <strong><?= e($doctorName) ?></strong>
          <?php if ($isPreferred): ?>
            <span class="ehr-pill">درمانگر ترجیحی</span>
          <?php else: ?>
            <span class="ehr-pill ehr-pill-mute">نوبت یا کارگاه مشترک</span>
          <?php endif; ?>
        </p>
      </div>
    </div>
    <div class="ehr-header-actions">
      <a class="btn btn-outline btn-sm" href="<?= e(url('/video-call?peer=' . rawurlencode($patientId))) ?>">تماس مانا</a>
      <a class="btn btn-primary btn-sm" href="<?= e($tabUrl('sessions')) ?>">یادداشت جلسه</a>
    </div>
  </header>

  <nav class="ehr-tabs" role="tablist" aria-label="بخش‌های پرونده بالینی">
    <a class="<?= $tabParam === 'overview' ? 'is-on' : '' ?>" href="<?= e($tabUrl('overview')) ?>">نمای کلی</a>
    <a class="<?= $tabParam === 'chart' ? 'is-on' : '' ?>" href="<?= e($tabUrl('chart')) ?>">شرح حال</a>
    <a class="<?= $tabParam === 'sessions' ? 'is-on' : '' ?>" href="<?= e($tabUrl('sessions')) ?>">جلسات <span><?= to_fa_digits((string) count($appointments)) ?></span></a>
    <a class="<?= $tabParam === 'intakes' ? 'is-on' : '' ?>" href="<?= e($tabUrl('intakes')) ?>">دستیار هوشمند <span><?= to_fa_digits((string) count($intakes)) ?></span></a>
    <a class="<?= $tabParam === 'workshops' ? 'is-on' : '' ?>" href="<?= e($tabUrl('workshops')) ?>">کارگاه و مسیر <span><?= to_fa_digits((string) count($enrollments)) ?></span></a>
    <a class="<?= $tabParam === 'messages' ? 'is-on' : '' ?>" href="<?= e($tabUrl('messages')) ?>">پیام خصوصی <span><?= to_fa_digits((string) count($privateQa)) ?></span></a>
    <a class="<?= $tabParam === 'journal' ? 'is-on' : '' ?>" href="<?= e($tabUrl('journal')) ?>">دفتر یادداشت <span><?= to_fa_digits((string) count($journalEntries)) ?></span></a>
  </nav>

  <?php if ($tabParam === 'overview'): ?>
    <section class="ehr-grid-stats">
      <article class="ehr-stat">
        <span>نوبت‌ها</span>
        <strong><?= to_fa_digits((string) count($appointments)) ?></strong>
        <small><?= $lastVisit ? 'آخرین: ' . e(format_fa_datetime((string) $lastVisit)) : 'هنوز نوبتی نیست' ?></small>
      </article>
      <article class="ehr-stat">
        <span>یادداشت جلسه</span>
        <strong><?= to_fa_digits((string) $noteCount) ?></strong>
        <small>فقط یادداشت‌های شما</small>
      </article>
      <article class="ehr-stat">
        <span>گفتگوی دستیار</span>
        <strong><?= to_fa_digits((string) count($intakes)) ?></strong>
        <small>ارسال‌شده یا کامل‌شده</small>
      </article>
      <article class="ehr-stat">
        <span>کارگاه</span>
        <strong><?= to_fa_digits((string) count($enrollments)) ?></strong>
        <small><?= to_fa_digits((string) count($pathNotes)) ?> یادداشت مسیر</small>
      </article>
      <article class="ehr-stat">
        <span>پیام خصوصی کارگاه</span>
        <strong><?= to_fa_digits((string) count($privateQa)) ?></strong>
        <small>فقط بین شما و این فرد</small>
      </article>
      <article class="ehr-stat">
        <span>تماس مانا</span>
        <strong><?= to_fa_digits((string) (int) ($callStats['call_count'] ?? 0)) ?></strong>
        <small><?= !empty($callStats['last_at']) ? 'آخرین: ' . e(format_fa_datetime((string) $callStats['last_at'])) : 'هنوز تماسی ثبت نشده' ?></small>
      </article>
      <article class="ehr-stat">
        <span>دفتر یادداشت</span>
        <strong><?= to_fa_digits((string) count($journalEntries)) ?></strong>
        <small>ثبت‌های روزانه مراجع</small>
      </article>
    </section>
    <div class="ehr-split">
      <article class="ehr-card">
        <header class="ehr-card-head">
          <h2>شرح حال</h2>
          <a href="<?= e($tabUrl('chart')) ?>">ویرایش</a>
        </header>
        <?php if ($historySnippet !== ''): ?>
          <p class="ehr-preview"><?= e($historySnippet) ?></p>
        <?php else: ?>
          <p class="muted">هنوز شرح حالی نوشته نشده. از تب شرح حال مثل چارت Jane / SimplePractice یادداشت بالینی را شروع کنید.</p>
        <?php endif; ?>
      </article>
      <article class="ehr-card">
        <header class="ehr-card-head">
          <h2>فعالیت اخیر</h2>
        </header>
        <ul class="ehr-timeline">
          <?php
          $recent = [];
          foreach (array_slice($appointments, 0, 4) as $a) {
              $recent[] = ['t' => (string) $a['starts_at'], 'label' => 'نوبت · ' . appointment_row_status_label($a)];
          }
          foreach (array_slice($intakes, 0, 3) as $s) {
              $recent[] = ['t' => (string) (($s['sent_at'] ?? '') ?: ($s['created_at'] ?? '')), 'label' => 'دستیار هوشمند'];
          }
          foreach (array_slice($privateQa, 0, 3) as $q) {
              $recent[] = ['t' => (string) $q['created_at'], 'label' => 'پیام خصوصی کارگاه'];
          }
          usort($recent, static fn ($x, $y) => strcmp((string) ($y['t'] ?? ''), (string) ($x['t'] ?? '')));
          $recent = array_slice($recent, 0, 8);
          ?>
          <?php if (!$recent): ?>
            <li class="muted">هنوز رویدادی برای این پرونده نیست.</li>
          <?php else: ?>
            <?php foreach ($recent as $ev): ?>
              <li>
                <time><?= $ev['t'] !== '' ? e(format_fa_datetime($ev['t'])) : '—' ?></time>
                <span><?= e($ev['label']) ?></span>
              </li>
            <?php endforeach; ?>
          <?php endif; ?>
        </ul>
      </article>
    </div>
  <?php endif; ?>

  <?php if ($tabParam === 'chart'): ?>
    <section class="ehr-card clinical-board">
      <header class="ehr-card-head">
        <div>
          <h2>شرح حال بالینی</h2>
          <p class="muted" style="margin:.3rem 0 0;font-size:.85rem">مثل psychotherapy note جدا از لیست جلسات است؛ فقط شما می‌بینید.</p>
        </div>
      </header>
      <form method="post" action="<?= e(url('/doctor/patients/' . $patientId . '/history')) ?>" id="history-form">
        <div class="clinical-toolbar" id="clinical-toolbar">
          <button type="button" class="tool-btn bold" data-cmd="bold" title="ضخیم">B</button>
          <span class="tool-sep"></span>
          <button type="button" class="tool-btn" data-fontsize="14">۱۴</button>
          <button type="button" class="tool-btn" data-fontsize="16">۱۶</button>
          <button type="button" class="tool-btn" data-fontsize="18">۱۸</button>
          <button type="button" class="tool-btn" data-fontsize="22">۲۲</button>
          <span class="tool-sep"></span>
          <span class="muted" style="font-size:.8rem;margin-inline-end:.25rem">هایلایت</span>
          <button type="button" class="swatch yellow" data-hl="#ffe566" title="زرد"></button>
          <button type="button" class="swatch green" data-hl="#8fd6a8" title="سبز"></button>
          <button type="button" class="swatch pink" data-hl="#f5a3c0" title="صورتی"></button>
          <button type="button" class="swatch blue" data-hl="#8eb7e8" title="آبی"></button>
          <button type="button" class="tool-btn" data-cmd="removeFormat" title="پاک کردن فرمت">پاک‌کردن رنگ</button>
        </div>
        <div id="clinical-editor" class="clinical-editor" contenteditable="true" role="textbox" aria-label="شرح حال" data-placeholder="شرح حال مراجعه‌کننده را اینجا بنویسید..."><?= $historyHtml ?></div>
        <textarea name="history_text" id="history_text" hidden></textarea>
        <div style="margin-top:.85rem;display:flex;gap:.5rem;flex-wrap:wrap;align-items:center">
          <button class="btn btn-primary" type="submit">ذخیره شرح حال</button>
          <?php if (!empty($chart['updated_at'])): ?>
            <span class="muted" style="font-size:.8rem">آخرین ویرایش: <?= e(format_fa_datetime($chart['updated_at'])) ?></span>
          <?php endif; ?>
        </div>
      </form>
    </section>
  <?php endif; ?>

  <?php if ($tabParam === 'sessions'): ?>
    <section class="ehr-card">
      <header class="ehr-card-head">
        <div>
          <h2>جلسات و progress note</h2>
          <p class="muted" style="margin:.3rem 0 0;font-size:.85rem">هر نوبت یک یادداشت جلسه دارد؛ سال و ماه را مثل پرونده‌های کلینیکی فیلتر کنید.</p>
        </div>
      </header>
      <?php
        $ymdPack = $sessionYmd;
        $ymdEmpty = 'هنوز جلسه‌ای ثبت نشده.';
        $ymdNoun = 'جلسه';
        $ymdAllPrefix = 'جلسات';
        $ymdShowPeople = false;
        $ymdClass = 'session-ymd';
        $ymdRenderItems = function (array $list) use ($notesByApp, $patientId): void {
            if (!$list) {
                echo '<p class="muted" style="margin:0">در این بازه مراجعه‌ای ثبت نشده.</p>';
                return;
            }
            echo '<div class="clinical-session-grid" data-session-note-grid>';
            foreach ($list as $a) {
                if (!is_array($a)) {
                    continue;
                }
                $note = $notesByApp[$a['id']] ?? null;
                $hasNote = $note && trim((string) ($note['note_text'] ?? '')) !== '';
                $day = jalali_day_parts((string) $a['starts_at']);
                ?>
                <div class="session-note-box<?= $hasNote ? ' has-note' : '' ?>" data-box>
                  <button type="button" class="session-note-toggle" data-toggle>
                    <span class="sn-date"><?= e($day['label'] ?? format_fa_datetime((string) $a['starts_at'])) ?></span>
                    <span class="sn-meta">
                      <?= $day ? 'ساعت ' . e($day['time_fa']) . ' · ' : '' ?>
                      <?= e(appointment_row_status_label($a)) ?>
                      · <?= $hasNote ? 'دارای یادداشت' : 'بدون یادداشت' ?>
                    </span>
                  </button>
                  <div class="session-note-panel" data-panel>
                    <form method="post" action="<?= e(url('/doctor/patients/' . $patientId . '/session-note')) ?>">
                      <input type="hidden" name="appointment_id" value="<?= e($a['id']) ?>">
                      <label class="label">یادداشت این جلسه</label>
                      <textarea class="input" name="note_text" rows="5" placeholder="مشاهدات، مداخلات، تکالیف..."><?= e((string) ($note['note_text'] ?? '')) ?></textarea>
                      <?= appointment_notes_html($a) ?>
                      <div style="margin-top:.75rem;display:flex;gap:.5rem;flex-wrap:wrap">
                        <button class="btn btn-primary btn-sm" type="submit">ذخیره</button>
                        <button class="btn btn-outline btn-sm" type="button" data-close>بستن</button>
                        <?= appointment_cancel_form((string) $a['id'], (string) $a['status'], '/doctor/appointments', '/doctor/patients/' . $patientId . '?tab=sessions') ?>
                        <?= function_exists('admin_appointment_delete_form') ? admin_appointment_delete_form((string) $a['id'], '/doctor/patients/' . $patientId . '?tab=sessions') : '' ?>
                      </div>
                    </form>
                  </div>
                </div>
                <?php
            }
            echo '</div>';
        };
        require __DIR__ . '/../../includes/appointment_ymd_binder.php';
      ?>
    </section>
  <?php endif; ?>

  <?php if ($tabParam === 'intakes'): ?>
    <section class="ehr-card">
      <header class="ehr-card-head">
        <div>
          <h2>گفتگو با دستیار هوشمند</h2>
          <p class="muted" style="margin:.3rem 0 0;font-size:.85rem">هر گفتگویی که این مراجعه‌کننده با دستیار داشته و به کلینیک رسیده، فقط در پرونده خودش برای شماست.</p>
        </div>
      </header>
      <?php
        $intakeMonthEmpty = 'هنوز گفتگویی از دستیار برای این مراجعه‌کننده نیست.';
        require __DIR__ . '/../../includes/doctor_intake_month_binder.php';
      ?>
    </section>
  <?php endif; ?>

  <?php if ($tabParam === 'workshops'): ?>
    <section class="ehr-stack">
      <article class="ehr-card">
        <header class="ehr-card-head"><h2>ثبت‌نام کارگاه‌های شما</h2></header>
        <?php if (!$enrollments): ?>
          <p class="muted" style="margin:0">این فرد در کارگاه شما ثبت‌نام نکرده است.</p>
        <?php else: ?>
          <?php
            $chartEnrollmentBlocks = [
                ['rows' => $activeEnrollments, 'archived' => false, 'heading' => ''],
                ['rows' => $archivedEnrollments, 'archived' => true, 'heading' => 'آرشیو'],
            ];
          ?>
          <?php if (!$activeEnrollments): ?>
            <p class="muted" style="margin:0">کارگاه فعالی برای این فرد نیست؛ موارد تمام‌شده در آرشیو هستند.</p>
          <?php endif; ?>
          <?php foreach ($chartEnrollmentBlocks as $block): ?>
            <?php if (!$block['rows']) { continue; } ?>
            <?php if ($block['heading'] !== ''): ?>
              <h3 class="muted" style="font-size:.9rem;margin:1rem 0 .35rem"><?= e($block['heading']) ?></h3>
            <?php endif; ?>
            <ul class="ehr-list">
              <?php foreach ($block['rows'] as $en): ?>
                <?php
                  $enType = (string) ($en['type'] ?? '');
                  $intervalLabel = $enType !== 'OFFLINE' && function_exists('workshop_session_interval_label')
                      ? workshop_session_interval_label((string) ($en['session_interval'] ?? 'WEEKLY'))
                      : '';
                  $sessionCount = (int) ($en['session_count'] ?? 0);
                  $enrolledAt = (string) (($en['enrolled_at'] ?? '') ?: ($en['created_at'] ?? ''));
                  $editUrl = url('/doctor/workshops?edit=' . rawurlencode((string) $en['workshop_id']));
                  $archivedRow = !empty($block['archived']);
                ?>
                <li>
                  <div>
                    <strong><?= e((string) $en['title']) ?></strong>
                    <span class="ehr-pill<?= $archivedRow ? ' ehr-pill-mute' : '' ?>"><?= $archivedRow ? 'آرشیو' : 'فعال' ?></span>
                    <p class="muted">
                      <?= e(workshop_type_label($enType)) ?>
                      <?php if ($intervalLabel !== ''): ?> · <?= e($intervalLabel) ?><?php endif; ?>
                      <?php if ($sessionCount > 0): ?> · <?= to_fa_digits((string) $sessionCount) ?> جلسه<?php endif; ?>
                      · <?= e(enrollment_status_label((string) $en['status'])) ?>
                      · <?= $enrolledAt !== '' ? e(format_fa_datetime($enrolledAt)) : '—' ?>
                    </p>
                  </div>
                  <div class="ehr-list-actions">
                    <a class="btn btn-outline btn-sm" href="<?= e(workshop_path_doctor_url((string) $en['id'])) ?>">مسیر دوره</a>
                    <a class="btn btn-outline btn-sm" href="<?= e(workshop_qa_url_doctor((string) $en['workshop_id'])) ?>">تالار</a>
                    <a class="btn btn-outline btn-sm" href="<?= e($editUrl) ?>">ویرایش</a>
                  </div>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php endforeach; ?>
        <?php endif; ?>
      </article>
      <article class="ehr-card">
        <header class="ehr-card-head"><h2>یادداشت مسیر دوره</h2></header>
        <?php if (!$pathNotes): ?>
          <p class="muted" style="margin:0">یادداشت مربی یا شرکت‌کننده برای این فرد ثبت نشده.</p>
        <?php else: ?>
          <ul class="ehr-feed">
            <?php foreach ($pathNotes as $pn): ?>
              <li>
                <p class="ehr-feed-meta">
                  <?= (string) ($pn['kind'] ?? '') === 'instructor' ? 'یادداشت درمانگر' : 'یادداشت شرکت‌کننده' ?>
                  · <?= e((string) ($pn['workshop_title'] ?? '')) ?>
                  <?php if (!empty($pn['session_title'])): ?> · <?= e((string) $pn['session_title']) ?><?php endif; ?>
                  · <?= e(format_fa_datetime((string) (($pn['updated_at'] ?? '') ?: ($pn['created_at'] ?? '')))) ?>
                </p>
                <p><?= nl2br(e((string) $pn['body'])) ?></p>
                <a href="<?= e(workshop_path_doctor_url((string) $pn['enrollment_id'])) ?>">مدیریت در مسیر دوره</a>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </article>
    </section>
  <?php endif; ?>

  <?php if ($tabParam === 'messages'): ?>
    <section class="ehr-card">
      <header class="ehr-card-head">
        <div>
          <h2>پیام خصوصی کارگاه</h2>
          <p class="muted" style="margin:.3rem 0 0;font-size:.85rem">پیام‌هایی که در تالار خصوصی کارگاه برای این فرد گذاشته‌اید یا او برای شما فرستاده.</p>
        </div>
      </header>
      <?php if (!$privateQa): ?>
        <p class="muted" style="margin:0">پیام خصوصی‌ای بین شما و این فرد در کارگاه نیست.</p>
      <?php else: ?>
        <ul class="ehr-feed">
          <?php foreach ($privateQa as $qa): ?>
            <li>
              <p class="ehr-feed-meta">
                <?= (string) ($qa['author_kind'] ?? '') === 'instructor' ? 'از درمانگر' : 'از مراجعه‌کننده' ?>
                · <?= e((string) ($qa['author_name'] ?? '')) ?>
                · <?= e((string) ($qa['workshop_title'] ?? '')) ?>
                · <?= e(format_fa_datetime((string) $qa['created_at'])) ?>
              </p>
              <p><?= nl2br(e((string) $qa['body'])) ?></p>
              <a href="<?= e(workshop_qa_url_doctor((string) $qa['workshop_id'])) ?>">باز کردن تالار کارگاه</a>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </section>
  <?php endif; ?>

  <?php if ($tabParam === 'journal'): ?>
    <section class="ehr-card">
      <header class="ehr-card-head">
        <div>
          <h2>دفتر یادداشت مراجع</h2>
          <p class="muted" style="margin:.3rem 0 0;font-size:.85rem">فقط مشاهده؛ خلق روزانه، متن و عکس‌هایی که مراجع ثبت کرده است.</p>
        </div>
      </header>
      <?php if (!$journalEntries): ?>
        <p class="muted" style="margin:0">هنوز یادداشتی در دفتر این فرد نیست.</p>
      <?php else: ?>
        <ul class="ehr-journal-feed">
          <?php foreach ($journalEntries as $je): ?>
            <?php
              $jm = (int) ($je['mood'] ?? 0);
              $jLabel = trim((string) ($je['mood_label'] ?? ''));
              if ($jLabel === '' && $jm >= 1 && $jm <= 5) {
                  $jLabel = (string) ($journalMoods[$jm]['label'] ?? '');
              }
            ?>
            <li>
              <p class="ehr-feed-meta"><?= e(to_jalali_label((string) $je['entry_date'])) ?></p>
              <?php if ($jm >= 1 && $jm <= 5): ?>
                <div class="ehr-journal-mood">
                  <span aria-hidden="true"><?= e($journalMoods[$jm]['emoji'] ?? '') ?></span>
                  <?= e($jLabel !== '' ? $jLabel : 'خلق ' . to_fa_digits((string) $jm)) ?>
                </div>
              <?php endif; ?>
              <?php if (trim((string) ($je['body'] ?? '')) !== ''): ?>
                <p style="margin:0;line-height:1.85;white-space:pre-wrap"><?= e((string) $je['body']) ?></p>
              <?php endif; ?>
              <?php if (!empty($je['photo_path'])): ?>
                <img class="ehr-journal-photo" src="<?= e(url((string) $je['photo_path'])) ?>" alt="عکس یادداشت <?= e(to_jalali_label((string) $je['entry_date'])) ?>">
              <?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </section>
  <?php endif; ?>
</div>
<?php
$inner = ob_get_clean();

$pageScripts = '<script src="' . e(url('/assets/js/binder-tabs.js')) . '?v=20260905c"></script>
<script src="' . e(url('/assets/js/ymd-cascade.js')) . '?v=20260910r"></script>
<script src="' . e(url('/assets/js/rich-editor.js')) . '"></script>
<script>
if (document.querySelector("#clinical-editor")) {
  initRichEditor({
    editor: "#clinical-editor",
    toolbar: "#clinical-toolbar",
    form: "#history-form",
    hidden: "#history_text"
  });
}
(function(){
  document.addEventListener("click", function(e){
    var closeBtn = e.target.closest("[data-close]");
    var toggle = e.target.closest("[data-toggle]");
    var box = e.target.closest("[data-box]");
    var grids = document.querySelectorAll("[data-session-note-grid]");
    if (closeBtn && box) {
      box.classList.remove("open");
      return;
    }
    if (toggle && box) {
      var wasOpen = box.classList.contains("open");
      grids.forEach(function(grid){
        grid.querySelectorAll("[data-box].open").forEach(function(b){ b.classList.remove("open"); });
      });
      if (!wasOpen) box.classList.add("open");
      return;
    }
    if (!box) {
      grids.forEach(function(grid){
        grid.querySelectorAll("[data-box].open").forEach(function(b){ b.classList.remove("open"); });
      });
    }
  });
})();
</script>
';

render_doctor_page('پرونده ' . $patient['name'], $inner);
