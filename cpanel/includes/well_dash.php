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
$circ = 301.593;
$dashOff = $circ * (1 - $progress / 100);
$weekDays = ['ش', 'ی', 'د', 'س', 'چ', 'پ', 'ج'];
$view = (($_GET['cal'] ?? 'month') === 'day') ? 'day' : 'month';
$art = url('/assets/img/well/counsel.png');
$clinicImg = url('/assets/img/well/clinic.png');
$userImg = url('/assets/img/well/user.png');
$docImg = url('/assets/img/well/doctor.png');
$dash = url('/dashboard');
$apptsUrl = url('/dashboard/appointments');
$journalUrl = url('/dashboard/journal');
$msgUrl = url('/dashboard/messages');
$profileUrl = url('/dashboard/profile');
$doctorsUrl = url('/doctors');

$GLOBALS['pageHead'] = trim((string) ($GLOBALS['pageHead'] ?? '') . '<link rel="stylesheet" href="' . e(url('/assets/css/well-dash.css')) . '?v=20260923b">');
$GLOBALS['wellDash'] = true;
$GLOBALS['pageBodyClass'] = trim((string) ($GLOBALS['pageBodyClass'] ?? '') . ' well-dash');

$icon = static function (string $d): string {
    return '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="' . $d . '"/></svg>';
};
?>
<div class="wd-shell">
<div class="wd">
  <aside class="wd-rail" aria-label="میانبر پنل">
    <span class="wd-plus">+</span>
    <a class="is-on" href="<?= e($dash) ?>" title="خلاصه"><?= $icon('M4 10.5 12 4l8 6.5V20H4z') ?></a>
    <a href="<?= e($apptsUrl) ?>" title="نوبت‌ها"><?= $icon('M7 4v3M17 4v3M5 9h14v11H5z') ?></a>
    <a href="<?= e(url('/dashboard/workshops/mine')) ?>" title="کارگاه‌ها"><?= $icon('M4 7h16v12H4zM8 7V5h8v2') ?></a>
    <a href="<?= e(url('/dashboard/wallet')) ?>" title="کیف پول"><?= $icon('M5 8h14v10H5zM15 13h4') ?></a>
    <a href="<?= e($profileUrl) ?>" title="پروفایل"><?= $icon('M12 12a4 4 0 1 0-4-4 4 4 0 0 0 4 4zm-7 8a7 7 0 0 1 14 0') ?></a>
    <span class="wd-spacer"></span>
    <a href="<?= e(url('/')) ?>" title="سایت"><?= $icon('M12 5v14M5 12h14') ?></a>
  </aside>
  <aside class="wd-side">
    <img class="wd-avatar" src="<?= e($userImg) ?>" alt="">
    <h2>وضعیتت را بسنج</h2>
    <p>حال روزانه و فعالیت‌هایت را همین‌جا دنبال کن.</p>
    <a class="wd-check" href="<?= e($journalUrl) ?>">الان بسنج</a>
    <div class="wd-float wd-float--cal" aria-hidden="true"><b></b><i><em></em><em></em><em></em><em></em><em></em><em></em><em></em><em></em></i></div>
    <div class="wd-float wd-float--chart" aria-hidden="true"><span></span></div>
    <img class="wd-art" src="<?= e($art) ?>" alt="جلسه مشاوره در مانا کلینیک">
  </aside>
  <div class="wd-main">
    <header class="wd-top">
      <div>
        <h1>سلام، <?= e($first) ?></h1>
        <small>بیا حال خوب را هر روز با هم دنبال کنیم.</small>
      </div>
      <div class="wd-tools">
        <a class="wd-ico" href="<?= e($doctorsUrl) ?>" title="جستجو"><?= $icon('M11 5a6 6 0 1 1-6 6 6 6 0 0 1 6-6zm9 15-4.3-4.3') ?></a>
        <a class="wd-ico" href="<?= e($msgUrl) ?>" title="پیام‌ها"><?= $icon('M6 8h12v9H6zM8 8V6h8v2') ?><?php if ($unread > 0): ?><span class="n"><?= (int) $unread ?></span><?php endif; ?></a>
        <a href="<?= e($profileUrl) ?>"><img src="<?= e($userImg) ?>" alt=""></a>
      </div>
    </header>
    <div class="wd-cols">
      <div>
        <h2 class="wd-sec-h">نوبت نزدیک</h2>
        <?php if ($upcoming): ?>
          <?php
            $uts = strtotime((string) $upcoming['starts_at']) ?: 0;
            $kind = (string) ($upcoming['session_mode'] ?? $upcoming['mode'] ?? '');
            $online = stripos($kind, 'ONLINE') !== false;
            $jUp = $uts ? gregorian_to_jalali((int) date('Y', $uts), (int) date('n', $uts), (int) date('j', $uts)) : $todayJ;
          ?>
          <div class="wd-up">
            <img src="<?= e($clinicImg) ?>" alt="">
            <div>
              <div class="wd-doc">
                <img src="<?= e($docImg) ?>" alt="">
                <div>
                  <strong><?= e((string) ($upcoming['doctor_name'] ?? 'درمانگر')) ?></strong>
                  <em><?= e((string) ($upcoming['specialty'] ?? 'روان‌شناسی')) ?></em>
                </div>
              </div>
            </div>
            <a class="wd-video" href="<?= e($online ? url('/video-call') : $apptsUrl) ?>"><?= $online ? 'تماس تصویری' : 'جزئیات نوبت' ?></a>
          </div>
          <div class="wd-meta">
            <span>مانا کلینیک · سعادت‌آباد</span>
            <div class="wd-pills">
              <span class="wd-pill"><?= e(to_fa_digits((string) $jUp[2]) . ' ' . (jalali_month_names()[$jUp[1]] ?? '') . ' ' . to_fa_digits((string) $jUp[0])) ?></span>
              <span class="wd-pill"><?= e($uts ? to_fa_digits(date('H:i', $uts)) : '') ?></span>
            </div>
          </div>
        <?php else: ?>
          <div class="wd-up">
            <img src="<?= e($clinicImg) ?>" alt="">
            <div>
              <div class="wd-doc">
                <img src="<?= e($docImg) ?>" alt="">
                <div>
                  <strong>هنوز نوبتی ندارید</strong>
                  <em>رزرو جلسه با درمانگر مانا</em>
                </div>
              </div>
            </div>
            <a class="wd-video" href="<?= e($doctorsUrl) ?>">رزرو نوبت</a>
          </div>
        <?php endif; ?>
        <hr class="wd-hr">
        <div class="wd-act-h">
          <h2 class="wd-sec-h">فعالیت‌های من</h2>
          <select aria-label="ماه" onchange="location.href=this.value">
            <?php foreach ($barMonths as $bm): ?>
              <option value="<?= e($dash . '?ym=' . sprintf('%04d-%02d', $bm['y'], $bm['m'])) ?>" <?= ($bm['y'] === $jy && $bm['m'] === $jm) ? 'selected' : '' ?>><?= e($bm['label']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="wd-act">
          <div class="wd-bars">
            <?php foreach ($barMonths as $i => $bm): ?>
              <?php $h = 22 + (int) round(88 * ((int) $bm['n'] / $maxBar)); ?>
              <span class="<?= $i === 5 ? 'is-now' : '' ?>" style="height:<?= $h ?>px"><small><?= e(mb_substr((string) $bm['label'], 0, 1)) ?></small></span>
            <?php endforeach; ?>
          </div>
          <div class="wd-ring">
            <h3>پیشرفت امروز</h3>
            <p>قدم‌های کوچک کیفیت درمان را می‌سازند.</p>
            <svg class="wd-donut" viewBox="0 0 120 120" aria-label="<?= e(to_fa_digits((string) $progress)) ?> درصد">
              <circle cx="60" cy="60" r="48" fill="none" stroke="#d4edd2" stroke-width="12"/>
              <circle cx="60" cy="60" r="48" fill="none" stroke="#7dcf7a" stroke-width="12" stroke-linecap="round"
                stroke-dasharray="<?= e((string) $circ) ?>" stroke-dashoffset="<?= e((string) $dashOff) ?>" transform="rotate(-90 60 60)"/>
              <text x="60" y="66" text-anchor="middle" font-size="22" font-weight="800" fill="#16352f"><?= e(to_fa_digits((string) $progress)) ?>٪</text>
            </svg>
          </div>
        </div>
        <a class="wd-good" href="<?= e($journalUrl) ?>">
          <span><b>حال خوب</b><small>اضطراب و آرامش</small></span>
          <span>‹</span>
        </a>
      </div>
      <aside class="wd-cal">
        <h2 class="wd-sec-h">فهرست نوبت‌ها</h2>
        <div class="wd-tabs">
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
          <div class="wd-event is-a">
            <img src="<?= e($docImg) ?>" alt="">
            <span><strong>نوبت آینده‌ای نیست</strong><small>از فهرست درمانگران رزرو کنید</small></span>
            <span class="go">›</span>
          </div>
        <?php else: ?>
          <?php foreach ($list as $i => $row): ?>
            <?php $ts = strtotime((string) $row['starts_at']) ?: 0; ?>
            <a class="wd-event <?= $i === 0 ? 'is-a' : 'is-b' ?>" href="<?= e($apptsUrl) ?>">
              <img src="<?= e($docImg) ?>" alt="">
              <span>
                <strong><?= e((string) ($row['doctor_name'] ?? 'جلسه')) ?></strong>
                <small><?= e($ts ? to_fa_digits(date('H:i', $ts)) : '') ?></small>
              </span>
              <span class="go">›</span>
            </a>
          <?php endforeach; ?>
        <?php endif; ?>
        <a class="wd-more" href="<?= e($apptsUrl) ?>">دیدن برنامه کامل ‹</a>
      </aside>
    </div>
  </div>
</div>
</div>
