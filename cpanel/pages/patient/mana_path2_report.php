<?php
declare(strict_types=1);

$user = require_login(['PATIENT']);
require_once __DIR__ . '/../../includes/patient_panel.php';
require_once __DIR__ . '/../../includes/mana_path.php';

mana_path_require_user($user);
ensure_mana_path_schema($pdo);

$patientId = (string) $user['id'];
$profile = mana_path_load_profile($pdo, $patientId);
$report = mana_path2_report_data($pdo, $profile);
$path2Url = url('/dashboard/path');
$testsUrl = url('/tests');
$doctorsUrl = url('/doctors');
$score = (int) $report['score'];
$bandIdx = 0;
if ($score > 20) {
    $bandIdx = 1;
}
if ($score > 40) {
    $bandIdx = 2;
}
if ($score > 60) {
    $bandIdx = 3;
}
if ($score > 80) {
    $bandIdx = 4;
}
$circ = 2 * M_PI * 54;
$dashOff = $circ * (1 - $score / 100);
$cx = 170;
$cy = 170;
$rMax = 112;
$youVals = array_map(static fn($a) => (int) $a['you'], $report['axes']);
$avgVals = array_map(static fn($a) => (int) $a['avg'], $report['axes']);
$youPts = mana_path2_radar_points($youVals, $cx, $cy, $rMax);
$avgPts = mana_path2_radar_points($avgVals, $cx, $cy, $rMax);
$n = count($report['axes']);
$gridRings = [0.25, 0.5, 0.75, 1];

$GLOBALS['pageRobots'] = 'noindex,nofollow';
$GLOBALS['pageTitle'] = (string) $report['title'];
$GLOBALS['pageHead'] = '<link rel="stylesheet" href="' . e(url('/assets/css/mana-path2-report.css')) . '?v=20260924c">';
$GLOBALS['pageBodyClass'] = trim((string) ($GLOBALS['pageBodyClass'] ?? '') . ' mp2r-page');
$GLOBALS['pageScripts'] = ($GLOBALS['pageScripts'] ?? '') . '<script>function mp2rPrint(){window.print();}</script>';

ob_start();
?>
<div class="mp2r">
  <header class="mp2r-hero">
    <div>
      <h1>
        <span class="mp2r-heart" aria-hidden="true">♡</span>
        نتیجه گزارش ماهانه <?= e((string) $report['primary_label']) ?>
      </h1>
      <p><?= e((string) $report['lead']) ?></p>
      <div class="mp2r-actions">
        <button type="button" class="mp2r-btn mp2r-btn-dark" onclick="mp2rPrint()">دریافت برگه کامل نتیجه</button>
        <a class="mp2r-btn mp2r-btn-ghost" href="<?= e($path2Url) ?>">تکرار مسیر</a>
      </div>
    </div>
    <div class="mp2r-art" aria-hidden="true">
      <svg viewBox="0 0 280 200" width="280" height="200">
        <ellipse cx="140" cy="168" rx="78" ry="14" fill="#e8dff8"/>
        <path d="M70 128c-18-28 6-70 40-74 12-28 58-28 72-2 38-8 72 28 52 64-8 28-40 44-82 44s-70-12-82-32z" fill="#d9c6f2"/>
        <path d="M96 86c8-22 48-28 62-4 22-10 48 8 40 32-18 8-40 6-62 10-18 2-46-10-40-38z" fill="#c7a8ea"/>
        <circle cx="118" cy="108" r="10" fill="#f4ecfc"/>
        <circle cx="158" cy="104" r="8" fill="#f4ecfc"/>
        <path d="M40 70c18-8 28 10 18 18-16 4-28-6-18-18zm180 8c16-10 30 8 16 18-18 2-28-10-16-18z" fill="#cfe8d8"/>
      </svg>
    </div>
  </header>

  <section class="mp2r-meta">
    <div><small>نام گزارش</small><strong><?= e((string) $report['test_name']) ?></strong></div>
    <div><small>تعداد فعالیت‌ها</small><strong><?= e(to_fa_digits((string) $report['questions'])) ?> مورد</strong></div>
    <div><small>مدت زمان</small><strong><?= e(to_fa_digits((string) $report['minutes'])) ?> دقیقه</strong></div>
    <div><small>تاریخ گزارش</small><strong><?= e((string) $report['date']) ?></strong></div>
  </section>

  <div class="mp2r-split">
    <section class="mp2r-card">
      <h2>خلاصه نتیجه</h2>
      <div class="mp2r-donut-wrap">
        <svg class="mp2r-donut" viewBox="0 0 140 140" aria-label="<?= e(to_fa_digits((string) $score)) ?> از ۱۰۰">
          <circle cx="70" cy="70" r="54" fill="none" stroke="#f3d4ea" stroke-width="14"/>
          <circle cx="70" cy="70" r="54" fill="none" stroke="#d46aa8" stroke-width="14" stroke-linecap="round"
            stroke-dasharray="<?= e((string) round($circ, 1)) ?>" stroke-dashoffset="<?= e((string) round($dashOff, 1)) ?>" transform="rotate(-90 70 70)"/>
          <text x="70" y="68" text-anchor="middle" font-size="28" font-weight="800" fill="#5b3d7a"><?= e(to_fa_digits((string) $score)) ?></text>
          <text x="70" y="88" text-anchor="middle" font-size="12" fill="#8a7a9a">از ۱۰۰</text>
        </svg>
      </div>
      <p class="mp2r-band">سطح <?= e((string) $report['primary_label']) ?> در محدوده <b><?= e((string) $report['band']) ?></b> قرار دارد.</p>
      <div class="mp2r-scale" aria-hidden="true">
        <?php for ($i = 0; $i < 5; $i++): ?><i class="<?= $i === $bandIdx ? 'is-on' : '' ?>"></i><?php endfor; ?>
        <span>خیلی کم</span><span>کم</span><span>متوسط</span><span>نسبتاً بالا</span><span>بسیار بالا</span>
      </div>
      <p class="mp2r-warn">توجه: این گزارش ابزار غربالگری مسیر درمان است و تشخیص قطعی توسط متخصص انجام می‌شود.</p>
    </section>

    <section class="mp2r-card">
      <div class="mp2r-chart-h">
        <h2>نمودار نتایج شما</h2>
        <button type="button" class="mp2r-dl" onclick="mp2rPrint()">دانلود نمودار</button>
      </div>
      <div class="mp2r-legend"><b></b> شما <i></i> میانگین افراد هم‌مسیر</div>
      <svg class="mp2r-radar" viewBox="0 0 340 340" role="img" aria-label="نمودار راداری مشکلات این ماه">
        <?php foreach ($gridRings as $g): ?>
          <polygon fill="none" stroke="#ece6f4" stroke-width="1" points="<?= e(mana_path2_radar_points(array_fill(0, $n, 100 * $g), $cx, $cy, $rMax)) ?>"/>
        <?php endforeach; ?>
        <?php for ($i = 0; $i < $n; $i++): ?>
          <?php
            $ang = deg2rad(-90 + ($i * (360 / $n)));
            $x2 = $cx + cos($ang) * $rMax;
            $y2 = $cy + sin($ang) * $rMax;
            $lx = $cx + cos($ang) * ($rMax + 36);
            $ly = $cy + sin($ang) * ($rMax + 28);
            $ax = $report['axes'][$i];
          ?>
          <line x1="<?= $cx ?>" y1="<?= $cy ?>" x2="<?= round($x2, 1) ?>" y2="<?= round($y2, 1) ?>" stroke="#ece6f4"/>
          <text x="<?= round($lx, 1) ?>" y="<?= round($ly, 1) ?>" text-anchor="middle" font-size="11" fill="#5b4a6e">
            <?= e((string) $ax['label']) ?>
            (<?= e(to_fa_digits((string) $ax['you'])) ?>٪)
          </text>
        <?php endfor; ?>
        <polygon points="<?= e($avgPts) ?>" fill="rgba(180,160,210,.18)" stroke="#b09ac8" stroke-width="1.6" stroke-dasharray="4 4"/>
        <polygon points="<?= e($youPts) ?>" fill="rgba(212,106,168,.22)" stroke="#c45b98" stroke-width="2"/>
      </svg>
    </section>
  </div>

  <section class="mp2r-stats">
    <div><small>امتیاز کل</small><strong><?= e(to_fa_digits((string) $score)) ?> از ۱۰۰</strong></div>
    <div><small>سطح <?= e((string) $report['primary_label']) ?></small><strong><?= e((string) $report['band']) ?></strong></div>
    <div><small>میانگین افراد هم‌مسیر</small><strong><?= e(to_fa_digits((string) $report['peer'])) ?> از ۱۰۰</strong></div>
    <div><small>کارهای این ماه</small><strong><?= e(to_fa_digits((string) $report['accuracy'])) ?>٪</strong><span class="mp2r-stat-sub"><?= e(to_fa_digits((string) ($report['accuracy_done'] ?? 0))) ?> از <?= e(to_fa_digits((string) ($report['accuracy_need'] ?? 0))) ?> کار روزانه</span></div>
  </section>

  <section class="mp2r-ai">
    <div>
      <h2>تحلیل هوش مصنوعی</h2>
      <p><?= e((string) $report['analysis']) ?></p>
      <p>پیشنهاد: تکنیک‌های مدیریت استرس، ثبت افکار، و فعالیت بدنی منظم را در برنامهٔ روزانه قرار دهید. در صورت تداوم این علائم، مشاوره با درمانگر توصیه می‌شود.</p>
      <div class="mp2r-ai-actions">
        <a class="mp2r-btn mp2r-btn-purple" href="<?= e($doctorsUrl) ?>">مشاوره تخصصی با درمانگر</a>
        <form method="post" action="<?= e(url('/dashboard/path/report/ai')) ?>">
          <?= csrf_field() ?>
          <button type="submit" class="mp2r-btn mp2r-btn-dark">تحلیل هوش مصنوعی مانا</button>
        </form>
      </div>
    </div>
    <div class="mp2r-bot" aria-hidden="true">
      <svg viewBox="0 0 120 120" width="110" height="110">
        <circle cx="60" cy="58" r="36" fill="#efe6fb"/>
        <rect x="34" y="40" width="52" height="40" rx="16" fill="#dcc8f4"/>
        <circle cx="48" cy="58" r="6" fill="#5b3d7a"/>
        <circle cx="72" cy="58" r="6" fill="#5b3d7a"/>
        <rect x="56" y="18" width="8" height="16" rx="4" fill="#c7a8ea"/>
        <circle cx="60" cy="16" r="5" fill="#d46aa8"/>
      </svg>
    </div>
  </section>

  <section>
    <h2 class="mp2r-sec">پیشنهادهای اختصاصی برای شما</h2>
    <div class="mp2r-recs">
      <?php foreach ($report['recs'] as $rec): ?>
        <article class="tone-<?= e((string) $rec['tone']) ?>">
          <h3><?= e((string) $rec['title']) ?></h3>
          <p><?= e((string) $rec['text']) ?></p>
        </article>
      <?php endforeach; ?>
    </div>
  </section>

  <section class="mp2r-more">
    <div>
      <h2>مایل به بررسی تست‌های دیگر هستید؟</h2>
      <p>تست‌های بیشتری برای شناخت بهتر خودتان انجام دهید.</p>
    </div>
    <a class="mp2r-btn mp2r-btn-ghost" href="<?= e($testsUrl) ?>">مشاهده همه تست‌ها</a>
  </section>
</div>
<?php
$inner = (string) ob_get_clean();
render_patient_page((string) $report['title'], $inner);
