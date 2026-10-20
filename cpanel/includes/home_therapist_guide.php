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
<link rel="stylesheet" href="<?= e(url('/assets/css/therapist-guide.css')) ?>?v=20261021d">
<section class="mana-guide" aria-labelledby="mana-guide-title">
  <div class="mana-guide-row">
    <div class="mana-guide-copy">
      <p class="eyebrow">لازم نیست از همان اول همه‌چیز را بدانی</p>
      <h2 id="mana-guide-title">تو هم چالش انتخاب تراپیست داری؟</h2>
      <p>وقتی نمی‌دانی از کجا شروع کنی، انتخاب درمانگر می‌تواند سخت باشد. شاید بخواهی دربارهٔ حال خودت حرف بزنی، برای رابطه‌ات کمک بگیری یا فرزندت را بهتر درک کنی. بیایید از چیزی شروع کنیم که برای تو مهم است.</p>
      <span class="mana-guide-note">چند سؤال کوتاه، یک قدم روشن‌تر</span>
    </div>
    <div class="mana-guide-art" aria-hidden="true">
      <div class="mana-guide-question">
        <svg class="mana-guide-question-shape" viewBox="0 0 480 220" fill="none" focusable="false">
          <defs>
            <pattern id="mana-question-hatch" width="8" height="8" patternUnits="userSpaceOnUse" patternTransform="rotate(28)"><path d="M0 0V8" stroke="#7ba390" stroke-width="2"/></pattern>
          </defs>
          <path d="M52 112C29 81 58 49 99 53C115 16 163 9 195 31C230 4 283 12 301 37C349 23 389 49 389 76C433 78 456 111 436 141C437 171 387 191 340 184L308 212L305 181C273 195 228 194 202 182C166 197 124 191 109 178C66 185 29 157 52 112Z" fill="url(#mana-question-hatch)" stroke="#4d7965" stroke-width="2" stroke-linejoin="round"/>
          <path d="M52 103C29 72 58 40 99 44C115 7 163 0 195 22C230 -5 283 3 301 28C349 14 389 40 389 67C433 69 456 102 436 132C437 162 387 182 340 175L308 203L305 172C273 186 228 185 202 173C166 188 124 182 109 169C66 176 29 148 52 103Z" fill="#fffef9" stroke="#355f4e" stroke-width="2.2" stroke-linejoin="round"/>
          <path d="M68 101C49 77 72 54 109 59C121 25 163 16 195 37C228 12 273 17 295 43C336 29 374 48 375 79C415 80 436 105 421 130C422 151 385 167 339 162" stroke="#a1b7a7" stroke-width="1.5" stroke-linecap="round" stroke-dasharray="1 8"/>
          <path d="M34 58L23 49M118 13L114 3M399 39L408 28M455 88L467 83M23 140L11 144" stroke="#97ad9f" stroke-width="2" stroke-linecap="round"/>
          <path d="M451 164V177M445 170H457" stroke="#c5a86f" stroke-width="2" stroke-linecap="round"/>
        </svg>
        <span class="mana-guide-question-text">نمی‌دونم کدوم درمانگر<br>برای من مناسب‌تره…</span>
      </div>
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
