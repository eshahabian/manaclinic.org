<?php
declare(strict_types=1);
// Only the existing public directory fields leave the server; answers stay in memory.
$guideDoctors = array_map(static fn(array $doc): array => [
    'name' => (string) $doc['name'],
    'href' => url('/doctors/' . rawurlencode((string) $doc['id'])),
    'domains' => clinic_doctor_keys($doc, 'domain'),
    'focus' => clinic_doctor_keys($doc, 'focus'),
], clinic_public_doctors($pdo));
$guideWorkshops = array_values(array_map(static fn(array $workshop): array => [
    'title' => (string) $workshop['title'],
], array_filter(workshop_home_banners($pdo, null), static fn(array $workshop): bool => workshop_can_enroll($workshop) && workshop_promo_phase($workshop) !== 'done')));
?>
<link rel="stylesheet" href="<?= e(url('/assets/css/therapist-guide.css')) ?>?v=20261021b">
<section class="mana-guide" aria-labelledby="mana-guide-title">
  <div class="mana-guide-row">
    <div class="mana-guide-copy">
      <p class="eyebrow">لازم نیست از همان اول همه‌چیز را بدانی</p>
      <h2 id="mana-guide-title">تو هم چالش انتخاب تراپیست داری؟</h2>
      <p>وقتی نمی‌دانی از کجا شروع کنی، انتخاب درمانگر می‌تواند سخت باشد. شاید بخواهی دربارهٔ حال خودت حرف بزنی، برای رابطه‌ات کمک بگیری یا فرزندت را بهتر درک کنی. بیایید از چیزی شروع کنیم که برای تو مهم است.</p>
      <span class="mana-guide-note">چند سؤال کوتاه، یک قدم روشن‌تر</span>
    </div>
    <div class="mana-guide-art" aria-hidden="true">
      <span class="mana-guide-bubble">نمی‌دونم کدوم درمانگر برای من مناسب‌تره…</span>
      <img src="<?= e(url('/assets/img/therapist-guide-cartoon-v2.webp')) ?>" alt="" width="1200" height="800" loading="lazy" decoding="async">
    </div>
  </div>
  <div class="mana-guide-row mana-guide-row--second">
    <div class="mana-guide-copy">
      <p class="eyebrow">از نیاز تو تا مسیر مناسب تو</p>
      <h2>آگاهانه تراپیست رو انتخاب کن</h2>
      <p>با پاسخ به چند سؤال، حوزهٔ مناسب برای شروع را پیدا کن و درمانگرانی را ببین که در همان حوزه فعالیت می‌کنند. اگر هدفت یادگیری و رشد باشد، مسیر دوره‌ها و کارگاه‌ها را هم می‌بینی.</p>
      <button class="btn btn-primary" type="button" data-guide-start hidden>شروع راهنمای انتخاب درمانگر <span aria-hidden="true">←</span></button>
      <noscript><a href="<?= e(url('/doctors')) ?>">مشاهده و انتخاب درمانگران</a></noscript>
    </div>
    <div class="mana-guide-path" aria-hidden="true"><span class="mana-guide-bubble">حالا می‌دونم از کجا شروع کنم.</span><div><b>۱</b><span>از نیازت بگو</span></div><i></i><div><b>۲</b><span>مسیر پیشنهادی‌ات را ببین</span></div><i></i><div><b>۳</b><span>درمانگرت را انتخاب کن</span></div></div>
  </div>
</section>
<dialog id="mana-guide-dialog" class="mana-guide-dialog" aria-labelledby="mana-guide-dialog-title">
  <div class="mana-guide-dialog-head"><span class="eyebrow">راهنمای انتخاب درمانگر مانا</span><button type="button" class="mana-guide-close" data-guide-close aria-label="بستن راهنما">×</button></div>
  <div id="mana-guide-content"></div>
</dialog>
<script type="application/json" id="mana-guide-data"><?= json_encode(['doctors' => $guideDoctors, 'courses' => $guideWorkshops, 'domains' => doctor_domain_options(), 'focus' => doctor_focus_options(), 'directory' => url('/doctors'), 'workshops' => url('/workshops'), 'contact' => url('/contact')], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<script src="<?= e(url('/assets/js/therapist-guide.js')) ?>?v=20261021a" defer></script>
