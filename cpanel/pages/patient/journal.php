<?php
declare(strict_types=1);

$user = require_login(['PATIENT']);
require_once __DIR__ . '/../../includes/patient_panel.php';
require_once __DIR__ . '/../../includes/patient_journal.php';

$patientId = (string) $user['id'];
ensure_patient_journal_schema($pdo);

$today = date('Y-m-d');
$selected = trim((string) ($_GET['day'] ?? $today));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selected)) {
    $selected = $today;
}
if ($selected > $today) {
    $selected = $today;
}

$strip = patient_journal_day_strip(14);
$from = $strip[0]['ymd'] ?? $today;
$recent = patient_journal_fetch_range($pdo, $patientId, $from, $today);
$byDate = [];
foreach ($recent as $row) {
    $byDate[(string) $row['entry_date']] = $row;
}

$entry = patient_journal_for_date($pdo, $patientId, $selected);
$streak = patient_journal_streak($pdo, $patientId);
$moods = patient_journal_moods();

ob_start();
?>
<div class="journal-shell">
  <header class="journal-hero">
    <div class="journal-hero-copy">
      <p class="journal-kicker">مانا کلینیک</p>
      <h1>دفتر یادداشت</h1>
      <p class="journal-lead">هر روز چند خط درباره حال‌تان بنویسید. درمانگر شما می‌تواند این دفتر را ببیند.</p>
    </div>
    <?php if ($streak > 0): ?>
      <div class="journal-streak" aria-label="استریک نوشتن">
        <span class="journal-streak-num"><?= to_fa_digits((string) $streak) ?></span>
        <span class="journal-streak-label"><?= $streak === 1 ? 'روز نوشته‌اید' : 'روز پشت‌سرهم نوشته‌اید' ?></span>
      </div>
    <?php endif; ?>
  </header>

  <nav class="journal-strip" aria-label="روزهای اخیر">
    <?php foreach ($strip as $day): ?>
      <?php
        $has = isset($byDate[$day['ymd']]);
        $mood = isset($byDate[$day['ymd']]) ? (int) ($byDate[$day['ymd']]['mood'] ?? 0) : 0;
        $isOn = $day['ymd'] === $selected;
      ?>
      <a class="journal-day<?= $isOn ? ' is-on' : '' ?><?= $has ? ' has-entry' : '' ?><?= !empty($day['is_today']) ? ' is-today' : '' ?>"
         href="<?= e(url('/dashboard/journal?day=' . rawurlencode($day['ymd']))) ?>"
         title="<?= e($day['label']) ?>">
        <span class="journal-day-label"><?= e(preg_replace('/^\d{4}\//', '', $day['label']) ?: $day['label']) ?></span>
        <?php if ($mood >= 1 && $mood <= 5): ?>
          <span class="journal-day-mood mood-<?= (int) $mood ?>" aria-hidden="true"><?= e($moods[$mood]['emoji']) ?></span>
        <?php else: ?>
          <span class="journal-day-dot" aria-hidden="true"></span>
        <?php endif; ?>
      </a>
    <?php endforeach; ?>
  </nav>

  <section class="journal-card">
    <header class="journal-card-head">
      <h2><?= $selected === $today ? 'ثبت امروز' : 'ویرایش ' . e(to_jalali_label($selected)) ?></h2>
      <p class="muted"><?= e(to_jalali_label($selected)) ?></p>
    </header>

    <form method="post" action="<?= e(url('/dashboard/journal')) ?>" enctype="multipart/form-data" class="journal-form">
      <?= csrf_field() ?>
      <input type="hidden" name="entry_date" value="<?= e($selected) ?>">

      <fieldset class="journal-moods">
        <legend>امروز چه حالی دارید؟</legend>
        <div class="journal-mood-grid" role="radiogroup" aria-label="خلق روزانه">
          <?php foreach ($moods as $value => $meta): ?>
            <?php $checked = $entry && (int) ($entry['mood'] ?? 0) === $value; ?>
            <label class="journal-mood-option mood-<?= (int) $value ?>">
              <input type="radio" name="mood" value="<?= (int) $value ?>"<?= $checked ? ' checked' : '' ?>>
              <span class="journal-mood-face" aria-hidden="true"><?= e($meta['emoji']) ?></span>
              <span class="journal-mood-name"><?= e($meta['label']) ?></span>
            </label>
          <?php endforeach; ?>
        </div>
      </fieldset>

      <div>
        <label class="label" for="journal_body">یادداشت آزاد</label>
        <textarea class="input journal-textarea" id="journal_body" name="body" rows="5" placeholder="چه چیزی امروز برایتان مهم بود؟"><?= e((string) ($entry['body'] ?? '')) ?></textarea>
      </div>

      <div class="journal-photo-field">
        <label class="label" for="journal_photo">عکس (اختیاری)</label>
        <?php if (!empty($entry['photo_path'])): ?>
          <div class="journal-photo-preview">
            <img src="<?= e(url((string) $entry['photo_path'])) ?>" alt="عکس یادداشت">
            <label class="journal-remove-photo">
              <input type="checkbox" name="remove_photo" value="1">
              حذف عکس فعلی
            </label>
          </div>
        <?php endif; ?>
        <input class="input" type="file" id="journal_photo" name="photo" accept="image/jpeg,image/png,image/webp">
        <p class="muted" style="margin:.35rem 0 0;font-size:.8rem">jpg، png یا webp · حداکثر ۵ مگابایت</p>
      </div>

      <button type="submit" class="btn btn-primary journal-save">ذخیره یادداشت</button>
    </form>
  </section>

  <?php
    $history = array_values(array_filter($recent, static fn ($r) => (string) ($r['entry_date'] ?? '') !== $selected));
  ?>
  <?php if ($history): ?>
    <section class="journal-history">
      <h2>روزهای اخیر</h2>
      <ul class="journal-history-list">
        <?php foreach (array_slice($history, 0, 7) as $row): ?>
          <?php $m = (int) ($row['mood'] ?? 0); ?>
          <li>
            <a href="<?= e(url('/dashboard/journal?day=' . rawurlencode((string) $row['entry_date']))) ?>">
              <span class="journal-history-date"><?= e(to_jalali_label((string) $row['entry_date'])) ?></span>
              <?php if ($m >= 1 && $m <= 5): ?>
                <span class="journal-history-mood"><?= e($moods[$m]['emoji'] . ' ' . $moods[$m]['label']) ?></span>
              <?php endif; ?>
              <?php if (trim((string) ($row['body'] ?? '')) !== ''): ?>
                <span class="journal-history-snip"><?= e(mb_substr(trim((string) $row['body']), 0, 80)) ?><?= mb_strlen(trim((string) $row['body'])) > 80 ? '…' : '' ?></span>
              <?php endif; ?>
            </a>
          </li>
        <?php endforeach; ?>
      </ul>
    </section>
  <?php endif; ?>
</div>
<?php
render_patient_page('دفتر یادداشت', ob_get_clean());
