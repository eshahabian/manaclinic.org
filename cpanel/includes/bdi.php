<?php
declare(strict_types=1);

/** BDI-II only. Do not apply these thresholds to BDI-I or a rewritten form. */
function bdi_score(array $input): array
{
    if (count($input) !== 21 || array_keys($input) !== range(1, 21)) {
        throw new InvalidArgumentException('برای محاسبه معتبر، پاسخ هر ۲۱ سؤال لازم است.');
    }
    $answers = [];
    foreach ($input as $id => $value) {
        if (!(is_int($value) && $value >= 0 && $value <= 3)
            && !(is_string($value) && preg_match('/^[0-3]$/D', $value))) {
            throw new InvalidArgumentException('امتیاز هر سؤال باید یک عدد صحیح از صفر تا سه باشد.');
        }
        $answers[$id] = (int) $value;
    }
    $total = array_sum($answers);
    $band = $total <= 13 ? 'minimal' : ($total <= 19 ? 'mild' : ($total <= 28 ? 'moderate' : 'severe'));
    $labels = ['minimal' => 'حداقل', 'mild' => 'خفیف', 'moderate' => 'متوسط', 'severe' => 'شدید'];
    return ['version' => 'BDI-II', 'answers' => $answers, 'total' => $total, 'band' => $band,
        'label' => $labels[$band], 'safety_followup' => $answers[9] > 0];
}

/** Content is supplied by the clinic, never generated or translated by AI. */
function bdi_licensed_form(): ?array
{
    global $config;
    $form = $config['bdi_ii_form'] ?? null;
    if (!is_array($form) || ($form['online_use_authorized'] ?? false) !== true
        || ($form['version'] ?? '') !== 'BDI-II' || trim((string) ($form['source'] ?? '')) === ''
        || trim((string) ($form['instructions'] ?? '')) === ''
        || !is_array($form['items'] ?? null) || array_keys($form['items']) !== range(1, 21)) return null;
    foreach ($form['items'] as $item) {
        if (!is_array($item) || !is_string($item['text'] ?? null) || trim($item['text']) === ''
            || !is_array($item['options'] ?? null) || count($item['options']) < 4) return null;
        $scores = [];
        foreach ($item['options'] as $option) {
            if (!is_array($option) || !is_int($option['score'] ?? null) || $option['score'] < 0 || $option['score'] > 3
                || !is_string($option['text'] ?? null) || trim($option['text']) === '') return null;
            $scores[] = $option['score'];
        }
        $scores = array_values(array_unique($scores)); sort($scores);
        if ($scores !== [0, 1, 2, 3]) return null;
    }
    return $form;
}

function bdi_safety_message(): string
{
    return 'پاسخ شما به سؤال ۹ نیاز به گفت‌وگوی مستقیم و سریع با متخصص دارد، حتی اگر امتیاز کل پایین باشد. این پاسخ به‌تنهایی میزان خطر را مشخص نمی‌کند. اگر همین حالا احتمال می‌دهید به خودتان آسیب بزنید یا نمی‌توانید ایمن بمانید، تنها نمانید؛ از یک فرد مورد اعتماد بخواهید همراهتان باشد و با خدمات اضطراری محل یا نزدیک‌ترین اورژانس تماس بگیرید. برای کمک منتظر تحلیل هوش مصنوعی نمانید. این صفحه توسط تیم درمان به‌صورت زنده پایش نمی‌شود.';
}

function bdi_ai_messages(array $result): array
{
    // Recalculate: callers cannot supply a different total, label or safety flag.
    $result = bdi_score($result['answers']);
    if ($result['safety_followup']) throw new DomainException('نیاز به پیگیری انسانی؛ تحلیل خودکار ارائه نمی‌شود.');
    return [
        ['role' => 'system', 'content' => 'شما همراه آموزشی فارسی‌زبان مانا هستید. فقط JSON معتبر با کلیدهای summary (رشته)، observations (آرایه ۲ تا ۳ رشته)، next_steps (آرایه ۲ تا ۳ رشته) بده. ورودی امتیاز معتبر BDI-II برای دو هفته اخیر است. نمره و طبقه از سرور قطعی است؛ دوباره محاسبه یا تغییر نده. این غربالگری است، نه تشخیص. هیچ تشخیص، علت قطعی، درصد ابتلا، زیرمقیاس ساختگی، سابقه، مقایسه زمانی، دارو، دوز یا تغییر درمان پیشنهاد نکن. نمره پایین را نبود بیماری یا ایمنی قطعی ندان. شدت را شدت نشانه‌های گزارش‌شده بنام. فقط اطلاعات موجود را توضیح بده؛ درباره زندگی یا شخصیت فرد حدس نزن. شماره سؤال‌ها را به‌جای بازتولید متن پرسشنامه به کار ببر. پیشنهادهای ملایم و عملی بده؛ برای شدت متوسط یا شدید ارزیابی متخصص را پیشنهاد کن. هیچ سؤال یا گزینه آزمون را تولید نکن. لحن همدلانه، بدون برچسب و کوتاه باشد. در summary بگو خروجی هوش مصنوعی است و جای ارزیابی متخصص را نمی‌گیرد.'],
        ['role' => 'user', 'content' => json_encode(['instrument' => 'BDI-II', 'timeframe' => 'past two weeks including today',
            'total' => $result['total'], 'severity' => $result['band'], 'item_scores' => $result['answers']], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)],
    ];
}

function bdi_parse_ai(string $text): array
{
    $text = trim($text);
    $text = preg_replace('/^```(?:json)?\s*|\s*```$/u', '', $text) ?? $text;
    $data = json_decode($text, true);
    if (!is_array($data) || !is_string($data['summary'] ?? null) || trim($data['summary']) === ''
        || mb_strlen($data['summary']) > 1800) throw new RuntimeException('پاسخ تحلیل قابل نمایش نیست.');
    $clean = ['summary' => trim($data['summary'])];
    foreach (['observations', 'next_steps'] as $key) {
        if (!is_array($data[$key] ?? null) || count($data[$key]) < 2 || count($data[$key]) > 3) {
            throw new RuntimeException('پاسخ تحلیل کامل نیست.');
        }
        $clean[$key] = [];
        foreach ($data[$key] as $line) {
            if (!is_string($line) || trim($line) === '' || mb_strlen($line) > 800) throw new RuntimeException('پاسخ تحلیل نامعتبر است.');
            $clean[$key][] = trim($line);
        }
    }
    return $clean;
}
