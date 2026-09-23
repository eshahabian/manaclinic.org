<?php
declare(strict_types=1);

$rawName = trim((string) ($user['name'] ?? 'مهمان'));
$parts = preg_split('/\s+/u', $rawName) ?: [];
$first = trim((string) ($parts[0] ?? $rawName));
$unread = 0;
if (function_exists('count_unread_notifications') && isset($pdo)) {
    $unread = (int) count_unread_notifications($pdo, (string) $user['id']);
}

$now = time();
$future = [];
foreach ($appointments as $row) {
    $st = strtotime((string) ($row['starts_at'] ?? '')) ?: 0;
    $status = strtoupper((string) ($row['status'] ?? ''));
    if ($st < $now || $status === 'CANCELLED') {
        continue;
    }
    $future[] = $row;
}
usort($future, static fn($a, $b) => strcmp((string) $a['starts_at'], (string) $b['starts_at']));
$upcoming = $future[0] ?? null;
$list = array_slice($future, 0, 2);

$todayJ = gregorian_to_jalali((int) date('Y'), (int) date('n'), (int) date('j'));
$jy = $todayJ[0];
$jm = $todayJ[1];
$calYm = trim((string) ($_GET['ym'] ?? ''));
if (preg_match('/^(\d{4})-(\d{1,2})$/', $calYm, $mmatch)) {
    $jy = (int) $mmatch[1];
    $jm = max(1, min(12, (int) $mmatch[2]));
}
$monthLen = jalali_month_length($jy, $jm);
$monthName = jalali_month_names()[$jm] ?? '';
[$gy1, $gm1, $gd1] = jalali_to_gregorian($jy, $jm, 1);
$w = (int) date('w', mktime(12, 0, 0, $gm1, $gd1, $gy1));
$lead = ($w + 1) % 7;
$prevM = $jm === 1 ? 12 : $jm - 1;
$prevY = $jm === 1 ? $jy - 1 : $jy;
$nextM = $jm === 12 ? 1 : $jm + 1;
$nextY = $jm === 12 ? $jy + 1 : $jy;

$daysWith = [];
foreach ($appointments as $row) {
    if (strtoupper((string) ($row['status'] ?? '')) === 'CANCELLED') {
        continue;
    }
    $ts = strtotime((string) ($row['starts_at'] ?? '')) ?: 0;
    if (!$ts) {
        continue;
    }
    [$ay, $am, $ad] = gregorian_to_jalali((int) date('Y', $ts), (int) date('n', $ts), (int) date('j', $ts));
    if ($ay === $jy && $am === $jm) {
        $daysWith[$ad] = true;
    }
}

$barMonths = [];
for ($i = 5; $i >= 0; $i--) {
    $mm = $todayJ[1] - $i;
    $yy = $todayJ[0];
    while ($mm < 1) {
        $mm += 12;
        $yy--;
    }
    $barMonths[] = ['y' => $yy, 'm' => $mm, 'n' => 0, 'label' => jalali_month_names()[$mm] ?? ''];
}
foreach ($appointments as $row) {
    if (strtoupper((string) ($row['status'] ?? '')) === 'CANCELLED') {
        continue;
    }
    $ts = strtotime((string) ($row['starts_at'] ?? '')) ?: 0;
    if (!$ts) {
        continue;
    }
    [$ay, $am] = gregorian_to_jalali((int) date('Y', $ts), (int) date('n', $ts), (int) date('j', $ts));
    foreach ($barMonths as &$bm) {
        if ($bm['y'] === $ay && $bm['m'] === $am) {
            $bm['n']++;
        }
    }
    unset($bm);
}
$maxBar = 1;
foreach ($barMonths as $bm) {
    $maxBar = max($maxBar, (int) $bm['n']);
}
$doneThisMonth = 0;
foreach ($appointments as $row) {
    $ts = strtotime((string) ($row['starts_at'] ?? '')) ?: 0;
    if (!$ts) {
        continue;
    }
    [$ay, $am] = gregorian_to_jalali((int) date('Y', $ts), (int) date('n', $ts), (int) date('j', $ts));
    if ($ay === $todayJ[0] && $am === $todayJ[1] && in_array(strtoupper((string) ($row['status'] ?? '')), ['COMPLETED', 'CONFIRMED'], true)) {
        $doneThisMonth++;
    }
}
$progress = min(100, $doneThisMonth * 20 + ($upcoming ? 15 : 0) + 10);
$weekDays = ['ش', 'ی', 'د', 'س', 'چ', 'پ', 'ج'];
$view = (($_GET['cal'] ?? 'month') === 'day') ? 'day' : 'month';
$art = url('/assets/img/well/counsel.png');
$clinicImg = url('/assets/img/well/clinic.png');
$dash = url('/dashboard');
$apptsUrl = url('/dashboard/appointments');
$journalUrl = url('/dashboard/journal');
$msgUrl = url('/dashboard/messages');
$profileUrl = url('/dashboard/profile');
$doctorsUrl = url('/doctors');

$GLOBALS['pageHead'] = trim((string) ($GLOBALS['pageHead'] ?? '') . '<link rel="stylesheet" href="' . e(url('/assets/css/well-dash.css')) . '?v=20260923a">');
$GLOBALS['wellDash'] = true;
$GLOBALS['pageBodyClass'] = trim((string) ($GLOBALS['pageBodyClass'] ?? '') . ' well-dash');
?>
<div class="wd">
  <aside class="wd-rail" aria-label="میانبر پنل">
    <span class="wd-mark">م</span>
    <a class="is-on" href="<?= e($dash) ?>" title="خلاصه">▣</a>
    <a href="<?= e($apptsUrl) ?>" title="نوبت‌ها">📅</a>
    <a href="<?= e(url('/dashboard/workshops/mine')) ?>" title="کارگاه‌ها">▦</a>
    <a href="<?= e(url('/dashboard/wallet')) ?>" title="کیف پول">◈</a>
    <a href="<?= e($profileUrl) ?>" title="پروفایل">⚙</a>
    <span class="wd-spacer"></span>
    <a href="<?= e(url('/')) ?>" title="سایت">؟</a>
  </aside>
  <aside class="wd-side">
    <img class="wd-avatar" src="<?= e($art) ?>" alt="">
    <h2>وضعیتت را بسنج</h2>
    <p>حال روزانه و فعالیت‌هایت را همین‌جا دنبال کن.</p>
    <a class="wd-check" href="<?= e($journalUrl) ?>">الان بسنج</a>
    <img class="wd-art" src="<?= e($art) ?>" alt="جلسه مشاوره در مانا کلینیک">
  </aside>
  <div class="wd-main">
    <header class="wd-top">
      <div>
        <h1>سلام، <?= e($first) ?></h1>
        <small>بیا حال خوب را هر روز با هم دنبال کنیم.</small>
      </div>
      <div class="wd-tools">
        <a href="<?= e($doctorsUrl) ?>" title="جستجو">🔍</a>
        <a href="<?= e($msgUrl) ?>" title="پیام‌ها">🔔<?php if ($unread > 0): ?><span class="wd-badge"><?= (int) $unread ?></span><?php endif; ?></a>
        <a href="<?= e($profileUrl) ?>"><img src="<?= e($art) ?>" alt=""></a>
      </div>
    </header>
    <div class="wd-body">
      <div>
        <section class="wd-card">
          <h2>نوبت نزدیک</h2>
          <?php if ($upcoming): ?>
            <?php
              $uts = strtotime((string) $upcoming['starts_at']) ?: 0;
              $kind = (string) ($upcoming['session_mode'] ?? $upcoming['mode'] ?? '');
              $online = stripos($kind, 'ONLINE') !== false;
            ?>
            <div class="wd-up">
              <img src="<?= e($clinicImg) ?>" alt="">
              <div>
                <strong><?= e((string) ($upcoming['doctor_name'] ?? 'درمانگر')) ?></strong>
                <div class="muted"><?= e((string) ($upcoming['specialty'] ?? 'روان‌شناسی')) ?></div>
                <div class="muted">مانا کلینیک · سعادت‌آباد</div>
                <div>
                  <span class="wd-chip">📅 <?= e(format_fa_datetime((string) $upcoming['starts_at'])) ?></span>
                </div>
              </div>
              <a class="wd-video" href="<?= e($online ? url('/video-call') : $apptsUrl) ?>"><?= $online ? 'تماس تصویری' : 'جزئیات نوبت' ?></a>
            </div>
          <?php else: ?>
            <p class="wd-empty">نوبت نزدیکی ثبت نشده.</p>
            <a class="wd-video" href="<?= e($doctorsUrl) ?>">رزرو نوبت</a>
          <?php endif; ?>
        </section>
        <div class="wd-split">
          <section class="wd-card">
            <h2>فعالیت‌های من</h2>
            <p class="muted" style="margin:0 0 .4rem;font-size:.8rem">امروز <?= e(to_fa_digits((string) $todayJ[2]) . ' ' . jalali_month_names()[$todayJ[1]] . ' ' . to_fa_digits((string) $todayJ[0])) ?></p>
            <div class="wd-bars">
              <?php foreach ($barMonths as $i => $bm): ?>
                <?php $h = 18 + (int) round(90 * ((int) $bm['n'] / $maxBar)); ?>
                <span class="<?= $i === 5 ? 'is-now' : '' ?>" style="height:<?= $h ?>px"><small><?= e(mb_substr((string) $bm['label'], 0, 3)) ?></small></span>
              <?php endforeach; ?>
            </div>
            <a class="wd-soft" href="<?= e($journalUrl) ?>"><span>💙 حال خوب<br><small class="muted">اضطراب و آرامش</small></span><span>‹</span></a>
          </section>
          <section class="wd-ring">
            <h2>پیشرفت امروز</h2>
            <p class="muted" style="font-size:.82rem;margin:.2rem 0">قدم‌های کوچک کیفیت درمان را می‌سازند.</p>
            <b><?= e(to_fa_digits((string) $progress)) ?>٪</b>
          </section>
        </div>
      </div>
      <aside class="wd-card wd-cal">
        <h2>فهرست نوبت‌ها</h2>
        <div class="wd-cal-tabs">
          <a class="<?= $view === 'month' ? 'is-on' : '' ?>" href="<?= e($dash . '?ym=' . sprintf('%04d-%02d', $jy, $jm) . '&cal=month') ?>">ماهانه</a>
          <a class="<?= $view === 'day' ? 'is-on' : '' ?>" href="<?= e($dash . '?cal=day') ?>">روزانه</a>
        </div>
        <?php if ($view === 'month'): ?>
          <div class="wd-cal-nav">
            <a href="<?= e($dash . '?ym=' . sprintf('%04d-%02d', $nextY, $nextM)) ?>">‹</a>
            <strong><?= e($monthName . ' ' . to_fa_digits((string) $jy)) ?></strong>
            <a href="<?= e($dash . '?ym=' . sprintf('%04d-%02d', $prevY, $prevM)) ?>">›</a>
          </div>
          <div class="wd-grid">
            <?php foreach ($weekDays as $d): ?><b><?= e($d) ?></b><?php endforeach; ?>
            <?php for ($i = 0; $i < $lead; $i++): ?><span></span><?php endfor; ?>
            <?php for ($d = 1; $d <= $monthLen; $d++): ?>
              <?php
                $cls = 'is-day';
                if (!empty($daysWith[$d])) {
                    $cls .= ' has';
                }
                if ($jy === $todayJ[0] && $jm === $todayJ[1] && $d === $todayJ[2]) {
                    $cls .= ' is-on';
                }
              ?>
              <span class="<?= e($cls) ?>"><?= e(to_fa_digits((string) $d)) ?></span>
            <?php endfor; ?>
          </div>
        <?php endif; ?>
        <?php if ($list === []): ?>
          <p class="wd-empty" style="margin-top:.8rem">نوبت آینده‌ای در فهرست نیست.</p>
        <?php else: ?>
          <?php foreach ($list as $row): ?>
            <?php $ts = strtotime((string) $row['starts_at']) ?: 0; ?>
            <a class="wd-event" href="<?= e($apptsUrl) ?>">
              <i>◎</i>
              <span>
                <strong><?= e((string) ($row['doctor_name'] ?? 'جلسه')) ?></strong>
                <small><?= e($ts ? to_fa_digits(date('H:i', $ts)) : '') ?></small>
              </span>
            </a>
          <?php endforeach; ?>
        <?php endif; ?>
        <a class="wd-more" href="<?= e($apptsUrl) ?>">دیدن برنامه کامل ‹</a>
      </aside>
    </div>
  </div>
</div>
