<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bdi.php';
header('Cache-Control: private, no-store, max-age=0');
header('Referrer-Policy: no-referrer');
$form = bdi_licensed_form();
$result = null;
$error = '';
$posted = [];
$action = (string) ($_POST['bdi_action'] ?? '');
$isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
$isAi = $isPost && $action === 'ai';
$provider = (string) (parse_url(assistant_ai_config()['base_url'], PHP_URL_HOST) ?: 'سرویس هوش مصنوعی کلینیک');
if ($isPost) {
    try {
        if (($_POST['adult'] ?? '') !== '1') throw new InvalidArgumentException('این بخش خودارزیابی برای افراد ۱۸ سال به بالاست؛ افراد زیر ۱۸ سال با متخصص اقدام کنند.');
        if (!$form && ($_POST['existing_form'] ?? '') !== '1') throw new InvalidArgumentException('این ابزار فقط برای پاسخنامه تکمیل‌شده نسخه دوم بک است.');
        if (!is_array($_POST['answers'] ?? null)) throw new InvalidArgumentException('پاسخ‌ها را کامل وارد کنید.');
        $posted = $_POST['answers'];
        $result = bdi_score($posted);
        if ($isAi) {
            header('Content-Type: application/json; charset=utf-8');
            if (!current_user()) {
                http_response_code(401);
                echo json_encode(['error' => 'برای تحلیل هوش مصنوعی وارد حساب شوید.'], JSON_UNESCAPED_UNICODE); exit;
            }
            if (($_POST['ai_consent'] ?? '') !== '1') throw new InvalidArgumentException('برای ارسال امتیازها به سرویس هوش مصنوعی، رضایت شما لازم است.');
            if ($result['safety_followup']) {
                echo json_encode(['safety' => bdi_safety_message()], JSON_UNESCAPED_UNICODE); exit;
            }
            if (!assistant_enabled() || !assistant_ai_available()) {
                http_response_code(503);
                echo json_encode(['error' => 'تحلیل هوش مصنوعی فعلاً در دسترس نیست؛ امتیاز و نمودار شما مستقل از آن معتبرند.'], JSON_UNESCAPED_UNICODE); exit;
            }
            $bucket = 'bdi_ai_' . (string) current_user()['id'];
            throttle_guard_json($bucket, 3, 3600, 'حداکثر سه درخواست تحلیل در ساعت؛ کمی بعد دوباره تلاش کنید.');
            throttle_guard_json('bdi_ai', 10, 3600, 'درخواست‌های تحلیل زیاد است؛ کمی بعد دوباره تلاش کنید.');
            throttle_hit($bucket, 3600); throttle_hit('bdi_ai', 3600);
            try {
                $analysis = bdi_parse_ai(assistant_ai_chat(bdi_ai_messages($result), 1100));
                echo json_encode(['analysis' => $analysis], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            } catch (Throwable $ignored) {
                http_response_code(503);
                echo json_encode(['error' => 'تحلیل هوش مصنوعی تکمیل نشد. امتیاز و نمودار شما آماده است؛ کمی بعد دوباره امتحان کنید.'], JSON_UNESCAPED_UNICODE);
            }
            exit;
        }
    } catch (InvalidArgumentException $ex) {
        $error = $ex->getMessage();
        http_response_code(422);
        if ($isAi) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => $error], JSON_UNESCAPED_UNICODE); exit;
        }
    }
}
$pageHead = ($pageHead ?? '') . '<link rel="stylesheet" href="' . e(url('/assets/css/bdi.css?v=1')) . '">';
$pageScripts = ($pageScripts ?? '') . '<script src="' . e(url('/assets/js/bdi.js?v=1')) . '" defer></script>';
ob_start();
?>
<main class="bdi-room container-page section">
  <a class="bdi-back" href="<?= e(url('/tests')) ?>">← آزمون‌های روان‌شناسی</a>
  <section class="bdi-hero">
    <div><span class="bdi-tag">خودشناسی با مانا · BDI-II</span>
      <h1>نگاهی مهربان‌تر به حال این روزها</h1>
      <p>پرسشنامه افسردگی بک، نسخه دوم؛ بررسی شدت نشانه‌های گزارش‌شده در دو هفته اخیر، شامل امروز. نتیجه یک نقطه شروع برای گفت‌وگوست و تشخیص پزشکی نیست.</p>
      <a class="btn btn-primary" href="#<?= $result ? 'bdi-result' : 'bdi-form' ?>"><?= $result ? 'مشاهده گزارش من' : ($form ? 'شروع پرسشنامه' : 'تحلیل پاسخنامه موجود') ?></a>
    </div><div class="bdi-hero-art" aria-hidden="true"><span>✦</span><div>حال تو<br><strong>ارزش شنیدن دارد</strong></div><span>☾</span></div>
  </section>
  <div class="bdi-meta"><div><small>بازه بررسی</small><strong>دو هفته اخیر</strong></div><div><small>پرسشنامه</small><strong>۲۱ سؤال</strong></div><div><small>امتیاز کل</small><strong>صفر تا ۶۳</strong></div><div><small>همراه نتیجه</small><strong>نمودار و تحلیل اختیاری</strong></div></div>
  <?php if ($result): ?>
  <section id="bdi-result" class="bdi-report" aria-labelledby="bdi-result-title">
    <div class="bdi-section-title"><div><span class="bdi-tag">گزارش همین پاسخنامه</span><h2 id="bdi-result-title">تصویری از پاسخ‌های تو</h2></div><button type="button" class="btn btn-outline" data-bdi-print>چاپ گزارش</button></div>
    <?php if ($result['safety_followup']): ?><div class="bdi-safety" role="alert"><strong>همراهی انسانی در اولویت است</strong><p><?= e(bdi_safety_message()) ?></p></div><?php endif; ?>
    <div class="bdi-report-grid">
      <article class="bdi-card bdi-score-card"><h3>شدت نشانه‌های گزارش‌شده</h3>
        <?php $dash = round($result['total'] / 63 * 440, 2); ?>
        <svg class="bdi-donut" viewBox="0 0 180 180" role="img" aria-label="<?= e('امتیاز ' . $result['total'] . ' از ۶۳؛ شدت ' . $result['label']) ?>">
          <circle cx="90" cy="90" r="70" fill="none" stroke="#efe7f5" stroke-width="13"/>
          <circle cx="90" cy="90" r="70" fill="none" stroke="#9863b7" stroke-width="13" stroke-linecap="round" stroke-dasharray="<?= $dash ?> 440" transform="rotate(-90 90 90)"/>
          <text x="90" y="90" text-anchor="middle" class="bdi-total"><?= e(to_fa_digits((string) $result['total'])) ?></text><text x="90" y="118" text-anchor="middle" class="bdi-outof">از ۶۳ امتیاز</text>
        </svg>
        <strong class="bdi-band"><?= e($result['label']) ?></strong>
        <div class="bdi-scale"><?php foreach (['minimal'=>'حداقل','mild'=>'خفیف','moderate'=>'متوسط','severe'=>'شدید'] as $band=>$label): ?><div class="<?= $band === $result['band'] ? 'is-active' : '' ?>"><i></i><span><?= e($label) ?></span></div><?php endforeach; ?></div>
        <p class="bdi-note">این عدد درصد افسردگی یا تشخیص بیماری نیست. امتیاز پایین هم به‌تنهایی مشکلات روان‌شناختی یا خطر را رد نمی‌کند.</p>
      </article>
      <article class="bdi-card"><h3>نقشه پاسخ‌های تو</h3><p class="bdi-note">هر ستون امتیاز یک سؤال است؛ ستون بلندتر یعنی امتیاز بیشتر در همان سؤال. این نمودار زیرمقیاس تشخیصی یا روند بهبود نیست.</p>
        <div class="bdi-bars" role="list" aria-label="امتیاز هر یک از ۲۱ سؤال">
          <?php foreach ($result['answers'] as $id=>$score): ?><div class="bdi-bar" role="listitem" tabindex="0" aria-label="<?= e('سؤال ' . $id . ': ' . $score . ' از ۳') ?>" title="<?= e('سؤال ' . $id . ': ' . $score . ' از ۳') ?>"><span><?= e(to_fa_digits((string) $score)) ?></span><div class="bdi-bar-track"><i style="height:<?= $score / 3 * 100 ?>%"></i></div><small><?= e(to_fa_digits((string) $id)) ?></small></div><?php endforeach; ?>
        </div><p class="bdi-note">شماره سؤال · محور عمودی: صفر تا سه</p>
        <details><summary>مشاهده امتیازهای دقیق</summary><ol><?php foreach ($result['answers'] as $score): ?><li><?= e(to_fa_digits((string) $score)) ?> از ۳</li><?php endforeach; ?></ol></details>
      </article>
    </div>
    <article class="bdi-card bdi-ai"><span class="bdi-tag">✦ همراه هوشمند مانا</span><h3>از عددها به قدم بعدی</h3>
      <p>امتیاز و طبقه‌بندی با قواعد ثابت محاسبه شده‌اند. تحلیل زیر در صورت درخواست توسط هوش مصنوعی تولید می‌شود و ممکن است خطا داشته باشد؛ توسط متخصص بازبینی نشده است.</p>
      <?php if ($result['safety_followup']): ?><p><?= e(bdi_safety_message()) ?></p>
      <?php elseif (!current_user()): ?><p>برای درخواست تحلیل خصوصی وارد حساب شوید. پس از ورود، پاسخنامه را دوباره وارد کنید.</p><a class="btn btn-primary" href="<?= e(url('/login?next=%2Ftests%2Fbdi')) ?>">ورود برای تحلیل</a>
      <?php else: ?>
      <form method="post" action="<?= e(url('/tests/bdi')) ?>" data-bdi-ai>
        <?= csrf_field() ?><input type="hidden" name="bdi_action" value="ai"><input type="hidden" name="adult" value="1"><input type="hidden" name="existing_form" value="1">
        <?php foreach ($result['answers'] as $id=>$score): ?><input type="hidden" name="answers[<?= $id ?>]" value="<?= $score ?>"><?php endforeach; ?>
        <label class="bdi-check"><input type="checkbox" name="ai_consent" value="1" required> موافقم امتیازهای این پاسخنامه برای تولید توضیح به سرویس هوش مصنوعی <?= e($provider) ?> ارسال شود. نام، شماره تماس و اطلاعات پرونده من ارسال نمی‌شود.</label>
        <p class="bdi-note">این بخش پاسخ‌ها و تحلیل را در پرونده کلینیک ذخیره نمی‌کند. پردازش داده‌های ارسال‌شده در سرویس بیرونی تابع سیاست همان ارائه‌دهنده است.</p>
        <button class="btn btn-primary" type="submit">دریافت تحلیل هوش مصنوعی</button>
      </form><div data-bdi-ai-status role="status" aria-live="polite"></div><div data-bdi-ai-output></div>
      <?php endif; ?>
    </article>
    <article class="bdi-card"><h3>قدم بعدی، با توجه به حال تو</h3><p><?= in_array($result['band'], ['moderate','severe'], true) ? 'بهتر است برای ارزیابی دقیق‌تر با روان‌شناس یا روان‌پزشک گفت‌وگو کنید؛ اگر نشانه‌ها عملکرد روزانه را مختل کرده‌اند، پیگیری را زودتر انجام دهید.' : 'اگر این نشانه‌ها ادامه دارند، زندگی روزمره را مختل کرده‌اند یا نگران حال خود هستید، حتی با امتیاز پایین با متخصص گفت‌وگو کنید.' ?></p><a class="btn btn-outline" href="<?= e(url('/doctors')) ?>">گفت‌وگو با متخصص</a></article>
  </section>
  <?php endif; ?>
  <section class="bdi-card bdi-entry" id="bdi-form">
    <h2><?= $form ? 'پاسخ به پرسشنامه' : 'ورود امتیاز پاسخنامه تکمیل‌شده' ?></h2>
    <?php if ($form): ?><p><?= nl2br(e($form['instructions'])) ?></p>
    <?php else: ?><p>اگر نسخه معتبر <strong>BDI-II</strong> را قبلاً تکمیل کرده‌اید، امتیاز هر سؤال را از پاسخنامه وارد کنید. این فرم، خود پرسشنامه نیست؛ بدون متن سؤال‌ها امتیازها را حدس نزنید. انتشار سؤال‌های فارسی پس از آماده‌شدن نسخه معتبر و مجاز انجام می‌شود.</p><?php endif; ?>
    <p class="bdi-note">پاسخ خالی، صفر محسوب نمی‌شود. برای پرسشنامه کامل باید هر ۲۱ پاسخ مشخص باشد. این بخش برای خودارزیابی مستقل بزرگسالان است؛ افراد زیر ۱۸ سال با متخصص اقدام کنند.</p>
    <?php if ($error): ?><div class="bdi-safety" role="alert"><?= e($error) ?></div><?php endif; ?>
    <form method="post" action="<?= e(url('/tests/bdi')) ?>" data-bdi-score>
      <?= csrf_field() ?><input type="hidden" name="bdi_action" value="score">
      <div class="bdi-input-grid <?= $form ? 'bdi-full-form' : '' ?>">
        <?php for ($id=1; $id<=21; $id++): $selected = $posted[$id] ?? ''; ?>
        <div class="bdi-input-item"><label for="bdi-q<?= $id ?>"><?= $form ? e(to_fa_digits((string) $id) . '. ' . $form['items'][$id]['text']) : 'سؤال ' . e(to_fa_digits((string) $id)) ?></label>
          <select class="input" name="answers[<?= $id ?>]" id="bdi-q<?= $id ?>" required><option value="">انتخاب کنید</option>
          <?php $options = $form ? $form['items'][$id]['options'] : array_map(static fn($n)=>['score'=>$n,'text'=>to_fa_digits((string)$n)], range(0,3)); foreach ($options as $option): ?>
            <option value="<?= $option['score'] ?>" <?= (string)$selected === (string)$option['score'] ? 'selected' : '' ?>><?= e($option['text']) ?></option>
          <?php endforeach; ?></select></div>
        <?php endfor; ?>
      </div>
      <div class="bdi-safety" data-bdi-safety role="alert" <?= (isset($posted[9]) && is_scalar($posted[9]) && in_array((string)$posted[9], ['1','2','3'],true)) ? '' : 'hidden' ?>><?= e(bdi_safety_message()) ?></div>
      <label class="bdi-check"><input type="checkbox" name="adult" value="1" required <?= ($_POST['adult'] ?? '') === '1' ? 'checked' : '' ?>> ۱۸ سال یا بیشتر دارم و می‌دانم نتیجه، تشخیص پزشکی نیست.</label>
      <?php if (!$form): ?><label class="bdi-check"><input type="checkbox" name="existing_form" value="1" required <?= ($_POST['existing_form'] ?? '') === '1' ? 'checked' : '' ?>> این امتیازها از پاسخنامه تکمیل‌شده نسخه دوم بک (BDI-II) هستند.</label><?php endif; ?>
      <p class="bdi-note">با محاسبه نتیجه، هیچ داده‌ای به هوش مصنوعی ارسال نمی‌شود. تحلیل هوش مصنوعی یک مرحله جداگانه با رضایت شماست.</p>
      <button type="submit" class="btn btn-primary">محاسبه امتیاز و ساخت نمودار</button>
    </form>
  </section>
  <section class="bdi-card bdi-method"><h2>امتیازدهی چگونه انجام می‌شود؟</h2><p>۲۱ امتیاز صفر تا سه با هم جمع می‌شوند. راهنمای عمومی نسخه دوم: صفر تا ۱۳ حداقل، ۱۴ تا ۱۹ خفیف، ۲۰ تا ۲۸ متوسط و ۲۹ تا ۶۳ شدید. این طبقه‌ها شدت نشانه‌ها را توصیف می‌کنند؛ تفسیر نهایی به مصاحبه، شرایط فرد و نسخه معتبر آزمون وابسته است.</p>
    <p>BDI-II بازه دو هفته را می‌سنجد؛ با نسخه قدیمی بک یا پرسشنامه‌های بازنویسی‌شده قابل جایگزینی نیست.</p>
    <p class="bdi-note">منابع: <a href="https://www.pearsonassessments.com/en-us/en-us/Store/Professional-Assessments/Personality-%26-Biopsychosocial/Beck-Depression-Inventory/p/100000159" target="_blank" rel="noopener noreferrer">معرفی ناشر Pearson</a> · <a href="https://www.nctsn.org/measures/beck-depression-inventory-second-edition" target="_blank" rel="noopener noreferrer">راهنمای ساختار و تفسیر NCTSN</a></p>
  </section>
</main>
<?php
$content = ob_get_clean();
require __DIR__ . '/../includes/layout.php';
