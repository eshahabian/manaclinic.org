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
<link rel="stylesheet" href="<?= e(url('/assets/css/therapist-guide.css')) ?>?v=20261021a">
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
      <svg viewBox="0 0 480 280" fill="none"><rect x="18" y="74" width="444" height="186" rx="50" fill="#dceee7"/><path d="M54 220c-20-90 18-140 66-134s68 56 42 134" fill="#254f45"/><circle cx="112" cy="119" r="36" fill="#edb994"/><path d="M77 114c-4-54 76-59 73 5-20-3-37-20-41-31-4 17-17 23-32 26" fill="#35463e"/><path d="M53 252c0-66 12-99 60-99s68 37 68 99" fill="#2c8b70"/><path d="m141 183 30 44 41-35" stroke="#edb994" stroke-width="18" stroke-linecap="round"/><path d="m177 192 42-9 20 43-42 9z" fill="#fffaf0"/><path d="M306 215c-20-91-5-142 47-143 57-1 72 72 47 143" fill="#665447"/><circle cx="353" cy="121" r="35" fill="#edb994"/><path d="M320 115c18-9 24-21 27-32 11 14 25 23 40 26-1-45-70-50-67 6" fill="#665447"/><path d="M290 252c3-68 18-99 64-99 43 0 64 36 66 99" fill="#dd956e"/><path d="m317 201 16-32 14-25" stroke="#edb994" stroke-width="17" stroke-linecap="round"/><path d="m242 111 5 10m-5 13h.1" stroke="#2c8b70" stroke-width="7" stroke-linecap="round"/><circle cx="245" cy="122" r="28" stroke="#2c8b70" stroke-width="2" stroke-dasharray="4 7"/></svg>
    </div>
  </div>
  <div class="mana-guide-row mana-guide-row--second">
    <div class="mana-guide-copy">
      <p class="eyebrow">از نیاز تو تا مسیر مناسب تو</p>
      <h2>آگاهانه تراپیست رو انتخاب کن</h2>
      <p>با پاسخ به چند سؤال، حوزهٔ مناسب برای شروع را پیدا کن و درمانگرانی را ببین که در همان حوزه فعالیت می‌کنند. اگر هدفت یادگیری و رشد باشد، مسیر دوره‌ها و کارگاه‌ها را هم می‌بینی.</p>
      <button class="btn btn-primary" type="button" data-guide-start hidden>شروع راهنمای انتخاب درمانگر <span aria-hidden="true">←</span></button>
      <p class="mana-guide-small">بدون ثبت‌نام · پاسخ‌ها ذخیره یا ارسال نمی‌شوند</p>
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
