<?php
declare(strict_types=1);

/**
 * Directory filters, issue landings, and article→therapist matching.
 */

function clinic_public_doctors(PDO $pdo): array
{
    static $rows = null;
    if ($rows !== null) {
        return $rows;
    }
    ensure_doctor_profile_schema($pdo);
    $rows = $pdo->query("
      SELECT dp.*, u.name
      FROM doctor_profiles dp
      JOIN users u ON u.id = dp.user_id
      WHERE dp.is_active = 1 AND dp.is_approved = 1
      ORDER BY CASE WHEN u.name LIKE '%گرانمایه%' THEN 0 ELSE 1 END, dp.created_at ASC
    ")->fetchAll() ?: [];

    return $rows;
}

function clinic_doctor_keys(array $doc, string $kind): array
{
    if ($kind === 'domain') {
        return doctor_profile_filter_keys(doctor_profile_json_list($doc['domains_json'] ?? ''), doctor_domain_options());
    }
    if ($kind === 'approach') {
        return doctor_profile_filter_keys(doctor_profile_json_list($doc['approaches_json'] ?? ''), doctor_approach_options());
    }

    return doctor_profile_filter_keys(doctor_profile_json_list($doc['focus_json'] ?? ''), doctor_focus_options());
}

function clinic_normalize_filter(?string $value, array $options): string
{
    $value = trim((string) $value);
    if ($value === '' || !isset($options[$value])) {
        return '';
    }

    return $value;
}

function clinic_read_filters(): array
{
    return [
        'q' => trim((string) ($_GET['q'] ?? '')),
        'domain' => clinic_normalize_filter((string) ($_GET['domain'] ?? ''), doctor_domain_options()),
        'focus' => clinic_normalize_filter((string) ($_GET['focus'] ?? ''), doctor_focus_options()),
        'approach' => clinic_normalize_filter((string) ($_GET['approach'] ?? ''), doctor_approach_options()),
    ];
}

function clinic_doctors_href(array $filters = []): string
{
    $query = [];
    foreach (['q', 'domain', 'focus', 'approach'] as $key) {
        $val = trim((string) ($filters[$key] ?? ''));
        if ($val !== '') {
            $query[$key] = $val;
        }
    }
    $path = '/doctors';
    if ($query !== []) {
        $path .= '?' . http_build_query($query);
    }

    return url($path);
}

function clinic_doctor_matches(array $doc, array $filters): bool
{
    $q = trim((string) ($filters['q'] ?? ''));
    $domain = (string) ($filters['domain'] ?? '');
    $focus = (string) ($filters['focus'] ?? '');
    $approach = (string) ($filters['approach'] ?? '');

    if ($domain !== '' && !in_array($domain, clinic_doctor_keys($doc, 'domain'), true)) {
        return false;
    }
    if ($focus !== '' && !in_array($focus, clinic_doctor_keys($doc, 'focus'), true)) {
        return false;
    }
    if ($approach !== '' && !in_array($approach, clinic_doctor_keys($doc, 'approach'), true)) {
        return false;
    }
    if ($q === '') {
        return true;
    }
    $hay = mb_strtolower(
        ((string) ($doc['name'] ?? '')) . ' ' .
        ((string) ($doc['specialty'] ?? '')) . ' ' .
        ((string) ($doc['bio'] ?? '')) . ' ' .
        implode(' ', clinic_doctor_keys($doc, 'domain')) . ' ' .
        implode(' ', clinic_doctor_keys($doc, 'focus')) . ' ' .
        implode(' ', clinic_doctor_keys($doc, 'approach')),
        'UTF-8'
    );
    $needles = preg_split('/\s+/u', mb_strtolower($q, 'UTF-8')) ?: [];
    foreach ($needles as $word) {
        if ($word !== '' && !str_contains($hay, $word)) {
            return false;
        }
    }

    return true;
}

function clinic_filter_doctors(PDO $pdo, array $filters): array
{
    $out = [];
    foreach (clinic_public_doctors($pdo) as $doc) {
        if (clinic_doctor_matches($doc, $filters)) {
            $out[] = $doc;
        }
    }

    return $out;
}

function clinic_doctors_by_focus(PDO $pdo, string $focusKey): array
{
    $focusKey = clinic_normalize_filter($focusKey, doctor_focus_options());
    if ($focusKey === '') {
        return [];
    }

    return clinic_filter_doctors($pdo, ['focus' => $focusKey]);
}

function clinic_issue_topics(): array
{
    return [
        'anxiety' => [
            'label' => 'اضطراب',
            'h1' => 'درمان اضطراب در سعادت‌آباد',
            'title' => 'روانشناس اضطراب سعادت‌آباد | مانا کلینیک',
            'description' => 'درمان اضطراب و نگرانی در مانا کلینیک سعادت‌آباد؛ آشنایی با رویکردها، مقالات و روانشناسانی که روی اضطراب کار می‌کنند. نوبت حضوری و آنلاین.',
            'keywords' => 'روانشناس اضطراب, درمان اضطراب سعادت آباد, مشاوره نگرانی, مانا کلینیک',
            'lead' => 'اضطراب گاهی هشدار مفید است و گاهی زندگی روزمره را تنگ می‌کند. در کلینیک می‌توانی با درمانگر مناسب، الگوی نگرانی را بشناسی و قدم‌های عملی برداری.',
            'service' => '/services/individual',
            'words' => ['اضطراب', 'نگرانی', 'حملات اضطراب', 'اضطرابی'],
        ],
        'depression' => [
            'label' => 'افسردگی',
            'h1' => 'افسردگی و افت حال',
            'title' => 'روانشناس افسردگی سعادت‌آباد | مانا کلینیک',
            'description' => 'همراهی برای افسردگی، بی‌انگیزگی و افت خلق در مانا کلینیک سعادت‌آباد. مقالات، متخصصان مرتبط و رزرو نوبت حضوری یا آنلاین.',
            'keywords' => 'روانشناس افسردگی, مشاوره خلق, مانا کلینیک سعادت آباد',
            'lead' => 'افت انرژی، بی‌علاقگی یا سنگینی خلق جای سرزنش نیست. مسیر درمان از شناخت حال فعلی و انتخاب درمانگر مناسب شروع می‌شود.',
            'service' => '/services/individual',
            'words' => ['افسردگی', 'افسرده', 'بی‌انگیزگی', 'افت خلق'],
        ],
        'ocd' => [
            'label' => 'وسواس',
            'h1' => 'وسواس فکری و عملی',
            'title' => 'درمان وسواس OCD سعادت‌آباد | مانا کلینیک',
            'description' => 'درمان وسواس فکری‌عملی در مانا کلینیک سعادت‌آباد؛ آشنایی با متخصصان این حوزه و رزرو جلسه حضوری یا آنلاین.',
            'keywords' => 'وسواس, OCD, روانشناس وسواس, مانا کلینیک',
            'lead' => 'وسواس اغلب با تردید و آیین‌های تکراری همراه است. کار بالینی روی کاهش اجبار و تحمل ابهام تمرکز دارد، نه جنگیدن با هر فکر.',
            'service' => '/services/individual',
            'words' => ['وسواس', 'OCD', 'اجبار'],
        ],
        'trauma' => [
            'label' => 'تروما',
            'h1' => 'تروما و تجربه‌های سخت',
            'title' => 'درمان تروما سعادت‌آباد | مانا کلینیک',
            'description' => 'همراهی برای تروما و تجربه‌های سخت در مانا کلینیک سعادت‌آباد؛ انتخاب روانشناس مرتبط و نوبت حضوری یا آنلاین.',
            'keywords' => 'تروما, PTSD, روانشناس تروما, مانا کلینیک سعادت آباد',
            'lead' => 'پس از رویداد سخت، بدن و ذهن ممکن است در حالت آماده‌باش بمانند. درمان تروما با ایمنی، سرعت مناسب تو و درمانگر آشنا به این حوزه پیش می‌رود.',
            'service' => '/services/individual',
            'words' => ['تروما', 'ضربه روانی', 'PTSD', 'حادثه'],
        ],
        'panic' => [
            'label' => 'حملات پانیک',
            'h1' => 'حملات پانیک',
            'title' => 'درمان حمله پانیک سعادت‌آباد | مانا کلینیک',
            'description' => 'حملات پانیک را در مانا کلینیک سعادت‌آباد با روانشناس مرتبط بررسی کنید؛ مقالات، متخصصان و رزرو نوبت حضوری یا آنلاین.',
            'keywords' => 'حمله پانیک, پانیک اتک, روانشناس سعادت آباد, مانا کلینیک',
            'lead' => 'حمله پانیک ترسناک است، اما قابل کار کردن است. شناخت علائم بدنی و تمرین تنظیم، معمولاً بخشی از مسیر است.',
            'service' => '/services/individual',
            'words' => ['پانیک', 'حمله قلب', 'تپش ناگهانی'],
        ],
        'grief' => [
            'label' => 'سوگ و فقدان',
            'h1' => 'سوگ و فقدان',
            'title' => 'مشاوره سوگ سعادت‌آباد | مانا کلینیک',
            'description' => 'مشاوره سوگ و فقدان در مانا کلینیک سعادت‌آباد؛ همراهی با درمانگر آشنا به سوگ و امکان رزرو حضوری یا آنلاین.',
            'keywords' => 'مشاوره سوگ, فقدان, ماتم, مانا کلینیک سعادت آباد',
            'lead' => 'سوگ زمان‌بندی تقویمی ندارد. فضای امن برای حرف زدن از فقدان، بدون عجله برای «جمع کردن» حال، اینجا ممکن است.',
            'service' => '/services/individual',
            'words' => ['سوگ', 'فقدان', 'از دست دادن', 'ماتم'],
        ],
        'relationships' => [
            'label' => 'روابط عاطفی',
            'h1' => 'مشکلات رابطه عاطفی',
            'title' => 'مشاوره رابطه عاطفی سعادت‌آباد | مانا کلینیک',
            'description' => 'مشاوره روابط عاطفی در مانا کلینیک سعادت‌آباد؛ درمانگران حوزه رابطه، مقالات و رزرو نوبت زوج یا فردی.',
            'keywords' => 'مشاوره رابطه, مشکلات عاطفی, زوج درمانی سعادت آباد, مانا کلینیک',
            'lead' => 'الگوی تکرارشونده در رابطه اغلب از یک نفر شروع نمی‌شود. می‌توانی فردی یا به‌صورت زوج، درمانگر مناسب این حوزه را انتخاب کنی.',
            'service' => '/services/couples',
            'words' => ['رابطه عاطفی', 'روابط عاطفی', 'تعارض رابطه', 'شریک زندگی'],
        ],
        'growth' => [
            'label' => 'رشد فردی',
            'h1' => 'رشد فردی و خودشناسی',
            'title' => 'مشاوره رشد فردی سعادت‌آباد | مانا کلینیک',
            'description' => 'مشاوره رشد فردی و خودشناسی در مانا کلینیک سعادت‌آباد؛ انتخاب روانشناس و رزرو نوبت حضوری یا آنلاین.',
            'keywords' => 'رشد فردی, خودشناسی, مشاوره فردی سعادت آباد, مانا کلینیک',
            'lead' => 'گاهی هدف درمان «بیماری» نیست؛ روشن‌تر دیدن انتخاب‌ها، مرزها و مسیر زندگی است. جلسه فردی این فضا را می‌سازد.',
            'service' => '/services/individual',
            'words' => ['رشد فردی', 'خودشناسی', 'مهارت زندگی'],
        ],
        'eating' => [
            'label' => 'اختلال خوردن',
            'h1' => 'رابطه با غذا و بدن',
            'title' => 'مشاوره اختلال خوردن سعادت‌آباد | مانا کلینیک',
            'description' => 'همراهی برای مشکلات خوردن و تصویر بدن در مانا کلینیک سعادت‌آباد با درمانگر مرتبط و نوبت حضوری یا آنلاین.',
            'keywords' => 'اختلال خوردن, تصویر بدن, مانا کلینیک سعادت آباد',
            'lead' => 'رابطه سخت با غذا و بدن نیاز به نگاه بالینی دارد، نه رژیم تنبیهی. درمانگر مرتبط می‌تواند مسیر ایمن‌تری پیشنهاد دهد.',
            'service' => '/services/individual',
            'words' => ['اختلال خوردن', 'تصویر بدن', 'پرخوری', 'بی‌اشتهایی'],
        ],
        'personality' => [
            'label' => 'الگوهای شخصیت',
            'h1' => 'الگوهای پایدار شخصیت',
            'title' => 'مشاوره اختلال شخصیت سعادت‌آباد | مانا کلینیک',
            'description' => 'کار روی الگوهای پایدار فکر، هیجان و رابطه در مانا کلینیک سعادت‌آباد؛ متخصصان مرتبط و رزرو نوبت.',
            'keywords' => 'الگوی شخصیت, روان‌درمانی بلندمدت, مانا کلینیک سعادت آباد',
            'lead' => 'وقتی الگوهای رابطه و هیجان سال‌ها تکرار می‌شوند، کار عمیق‌تر روی شخصیت و طرحواره‌ها می‌تواند کمک کند.',
            'service' => '/services/individual',
            'words' => ['اختلال شخصیت', 'طرحواره', 'الگوی شخصیت'],
        ],
        'sexual' => [
            'label' => 'مشکلات جنسی',
            'h1' => 'مشاوره مشکلات جنسی',
            'title' => 'مشاوره جنسی سعادت‌آباد | مانا کلینیک',
            'description' => 'مشاوره محرمانه مشکلات جنسی در مانا کلینیک سعادت‌آباد؛ انتخاب درمانگر مرتبط و رزرو حضوری یا آنلاین.',
            'keywords' => 'مشاوره جنسی, مشکلات جنسی, مانا کلینیک سعادت آباد',
            'lead' => 'این موضوع در فضای محرمانه کلینیک قابل گفتگو است. درمانگر مرتبط، بدون قضاوت، به فهم مسئله و قدم بعدی کمک می‌کند.',
            'service' => '/services/individual',
            'words' => ['مشکلات جنسی', 'سکس‌تراپی', 'مشاوره جنسی'],
        ],
    ];
}

function clinic_issue_copy(string $key): array
{
    $all = [
        'anxiety' => [
            'signs' => ['نگرانی مداوم که آرام نمی‌شود', 'تنش بدن، تپش یا بی‌خوابی', 'اجتناب از موقعیت‌های عادی', 'ذهن در حالت «اگر… آن‌وقت»'],
            'when' => 'اگر نگرانی چند هفته کار، خواب یا رابطه را محدود کرده، یا مدام خودت را برای آرام شدن سرزنش می‌کنی، وقت یک جلسه ارزیابی است. این صفحه تشخیص نمی‌گذارد؛ فقط مسیر مراجعه را روشن می‌کند.',
            'how' => 'در مانا کلینیک سعادت‌آباد جلسه اول برای شناخت الگوی اضطراب و هدف توست. بعد درمانگر رویکرد مناسب (معمولاً شناختی‌رفتاری یا پذیرش و تعهد) و تمرین‌های بین جلسه را پیشنهاد می‌دهد. حضوری و آنلاین ممکن است.',
            'faqs' => [
                ['q' => 'اضطراب با استرس روزمره چه فرقی دارد؟', 'a' => 'استرس معمولاً به یک موقعیت مشخص وصل است و با تمام شدن آن کم می‌شود. اضطراب گاهی بدون علت واضح می‌ماند و زندگی را تنگ می‌کند.'],
                ['q' => 'اولین جلسه اضطراب چطور است؟', 'a' => 'لازم نیست داستان کامل را از بر باشی. درمانگر کمک می‌کند محرک‌ها، بدن و افکار را مرتب کنید و یک قدم کوچک برای هفته بعد بگذارید.'],
                ['q' => 'جلسه آنلاین برای اضطراب کافی است؟', 'a' => 'برای بسیاری از افراد بله. اگر ترجیح می‌دهی حضوری باشی، کلینیک در سعادت‌آباد وقت می‌دهد. انتخاب با تو و درمانگر است.'],
            ],
        ],
        'depression' => [
            'signs' => ['افت انرژی و علاقه', 'خواب یا اشتهای به‌هم‌ریخته', 'خودسرزنشی و ناامیدی', 'کند شدن کار و رابطه'],
            'when' => 'اگر سنگینی خلق بیشتر از دو هفته مانده، یا انجام کارهای ساده سخت شده، مراجعه کمک می‌کند مسیر را از سرزنش جدا کنی. اگر فکر آسیب به خود داری، فوری با اورژانس تماس بگیر.',
            'how' => 'جلسه اول روی حال فعلی، حمایت‌ها و ایمنی تمرکز دارد. درمانگر ممکن است کار روی فعالیت، فکر و رابطه را با هم پیش ببرد. در سعادت‌آباد حضوری یا آنلاین رزرو می‌شود.',
            'faqs' => [
                ['q' => 'غم معمولی همان افسردگی است؟', 'a' => 'نه. غم واکنشی طبیعی است. وقتی خلق پایین پایدار می‌شود و عملکرد را می‌گیرد، ارزیابی حرفه‌ای لازم است.'],
                ['q' => 'باید دارو مصرف کنم؟', 'a' => 'این را روان‌پزشک یا پزشک تعیین می‌کند. روان‌درمانی می‌تواند مستقل یا در کنار درمان دارویی باشد؛ کلینیک تشخیص دارویی نمی‌دهد.'],
                ['q' => 'اگر حال خیلی پایین باشد چه کنم؟', 'a' => 'اورژانس و خطوط بحران اولویت دارند. بعد می‌توانی برای ادامه مسیر از همین صفحه درمانگر رزرو کنی.'],
            ],
        ],
        'ocd' => [
            'signs' => ['فکرهای مزاحم تکراری', 'آیین وارسی، شست‌وشو یا شمارش', 'تردید که آرام نمی‌شود', 'زمان زیاد برای «درست شدن» حس'],
            'when' => 'اگر اجبارها وقت روز را می‌گیرند یا بدون انجام‌شان اضطراب شدید می‌شود، کار تخصصی وسواس مفید است. جنگیدن با هر فکر معمولاً آن را سفت‌تر می‌کند.',
            'how' => 'درمانگر آشنا به وسواس، روی مواجهه تدریجی و تحمل ابهام کار می‌کند نه متقاعد کردن ذهن. جلسه اول نقشه اجبارها و هدف واقع‌بینانه را می‌سازد.',
            'faqs' => [
                ['q' => 'وسواس همان مرتب بودن است؟', 'a' => 'مرتب بودن سلیقه است. وسواس وقتی است که آیین‌ها اجباری‌اند و اگر انجام نشوند اضطراب شدید می‌آید.'],
                ['q' => 'باید افکار را متوقف کنم؟', 'a' => 'هدف معمولاً توقف فکر نیست؛ کم کردن پاسخ اجباری و زندگی کردن با تردید است.'],
                ['q' => 'درمان چقدر طول می‌کشد؟', 'a' => 'بسته به شدت و تمرین بین جلسه فرق دارد. درمانگر از همان ابتدا چارچوب را با تو هماهنگ می‌کند.'],
            ],
        ],
        'trauma' => [
            'signs' => ['یادآوری ناخواسته رویداد', 'بدن در آماده‌باش', 'اجتناب از یادآورها', 'اختلال خواب یا خشم ناگهانی'],
            'when' => 'اگر بعد از رویداد سخت هنوز ایمنی برنگشته، یا رابطه‌ات با بدن و دیگران به‌هم ریخته، درمانگر آشنا به تروما مناسب است. عجله برای «تعریف کامل» لازم نیست.',
            'how' => 'اول ایمنی و سرعت تو مهم است. در سعادت‌آباد می‌توانی حضوری یا آنلاین شروع کنی. روایت جزئیات فقط وقتی انجام می‌شود که آمادگی و توافق باشد.',
            'faqs' => [
                ['q' => 'باید همه جزئیات را بگویم؟', 'a' => 'نه. درمان تروما با رضایت و گام‌بندی پیش می‌رود. می‌توانی مرز بگذاری.'],
                ['q' => 'تروما فقط جنگ و تصادف است؟', 'a' => 'هر رویدادی که حس کنترل و ایمنی را بشکند ممکن است اثر تروماتیک بگذارد؛ تشخیص با متخصص است.'],
                ['q' => 'اگر وسط جلسه به‌هم بریزم؟', 'a' => 'درمانگر باید بتواند سرعت را کم کند و مهارت تنظیم را وسط بگذارد. این بخشی از کار است، نه شکست تو.'],
            ],
        ],
        'panic' => [
            'signs' => ['موج ناگهانی ترس بدنی', 'تپش، تنگی نفس یا گیجی', 'ترس از حمله بعدی', 'اجتناب از مکان‌هایی مثل مترو'],
            'when' => 'اگر حمله‌ها تکرار می‌شوند یا زندگی را دور آن‌ها می‌چینی، جلسه تخصصی کمک می‌کند بدن را بشناسی نه دشمن. درد قفسه سینه را ابتدا پزشکی رد کن.',
            'how' => 'کار معمولاً روی فهم علائم بدنی، تنفس و مواجهه تدریجی با موقعیت‌های اجتنابی است. رزرو حضوری سعادت‌آباد یا آنلاین از صفحه درمانگر است.',
            'faqs' => [
                ['q' => 'حمله پانیک خطرناک است؟', 'a' => 'بسیار ترسناک است اما معمولاً از نظر قلبی تهدید فوری نیست. علائم جدید را پزشک بررسی کند.'],
                ['q' => 'چرا در مکان شلوغ بدتر می‌شود؟', 'a' => 'ذهن مکان را با خطر گره می‌زند. اجتناب کوتاه‌مدت آرام می‌کند و بلندمدت ترس را بزرگ‌تر.'],
                ['q' => 'می‌توانم تنها آنلاین کار کنم؟', 'a' => 'بله؛ خیلی‌ها همین‌طور شروع می‌کنند. اگر نیاز به حضور باشد درمانگر می‌گوید.'],
            ],
        ],
        'grief' => [
            'signs' => ['موج غم یا خلأ', 'احساس گناه یا خشم', 'سختی بازگشت به روال', 'تنهایی بعد از فقدان'],
            'when' => 'سوگ زمان اجباری ندارد. اگر تنهایی سنگین است یا اطرافیان می‌گویند «دیگر باید تمام شود»، فضای بدون عجله کمک می‌کند.',
            'how' => 'جلسات سوگ برای شنیدن داستان فقدان و رابطه باقی‌مانده است، نه پاک کردن غم. در کلینیک سعادت‌آباد حضوری یا آنلاین ممکن است.',
            'faqs' => [
                ['q' => 'سوگ تا کی طبیعی است؟', 'a' => 'تقویم ثابتی ندارد. وقتی زندگی کاملاً قفل شده یا ایمنی به خطر افتاده، مراجعه لازم است.'],
                ['q' => 'فقط مرگ عزیز سوگ است؟', 'a' => 'جدایی، مهاجرت و از دست دادن نقش هم می‌توانند سوگ باشند.'],
                ['q' => 'باید گریه کنم تا درمان شود؟', 'a' => 'نه. هر کس زبان هیجانی خودش را دارد؛ فشار برای گریه کمکی نیست.'],
            ],
        ],
        'relationships' => [
            'signs' => ['بحث‌های تکراری بدون نتیجه', 'فاصله یا حس تنهایی در رابطه', 'سختی اعتماد یا مرز', 'چرخه قهر و آشتی'],
            'when' => 'اگر الگو سال‌ها تکرار می‌شود، یا تصمیم‌های مهم زندگی گیر کرده‌اند، جلسه فردی یا زوجی روشن‌تر می‌کند سهم هر کس چیست.',
            'how' => 'می‌توانی تنها بیایی یا با شریک. درمانگر الگوی ارتباط را می‌بیند و مهارت گفتگو را تمرین می‌دهد. زوج‌درمانی جدا از مشاوره فردی در خدمات کلینیک است.',
            'faqs' => [
                ['q' => 'باید دو نفری بیاییم؟', 'a' => 'لازم نیست. کار فردی روی سهم تو هم اثر دارد. اگر هر دو آماده باشید زوج‌درمانی جداگانه رزرو می‌شود.'],
                ['q' => 'درمانگر طرف کسی را می‌گیرد؟', 'a' => 'نباید. کار روی الگو است نه داوری اینکه چه کسی مقصر است.'],
                ['q' => 'اگر جدایی در میان باشد؟', 'a' => 'می‌توان درباره تصمیم سخت حرف زد بدون اینکه کلینیک جدا شدن را تجویز کند.'],
            ],
        ],
        'growth' => [
            'signs' => ['گیجی در انتخاب مسیر', 'سختی گفتن نه', 'تکرار الگوی شغلی یا تحصیلی', 'خواستن خودشناسی بدون بحران حاد'],
            'when' => 'اگر هدف «بیماری» نیست و می‌خواهی انتخاب‌ها و مرزهایت روشن‌تر شود، مشاوره رشد فردی مناسب است.',
            'how' => 'جلسات روی ارزش‌ها، تصمیم و مهارت زندگی تمرکز دارند. در سعادت‌آباد حضوری یا آنلاین رزرو می‌کنی؛ هزینه فقط هنگام انتخاب ساعت دیده می‌شود.',
            'faqs' => [
                ['q' => 'این همان کوچینگ است؟', 'a' => 'اینجا روان‌درمانی کلینیکی است. اگر مسئله بالینی پیدا شود مسیر تنظیم می‌شود.'],
                ['q' => 'چند جلسه لازم است؟', 'a' => 'گاهی کوتاه و هدف‌مند است. درمانگر بعد از شناخت، پیشنهاد تعداد می‌دهد.'],
                ['q' => 'باید مسئله بزرگی داشته باشم؟', 'a' => 'نه. خودشناسی و مهارت هم دلیل معتبر مراجعه است.'],
            ],
        ],
        'eating' => [
            'signs' => ['کنترل سخت غذا یا پرهیز شدید', 'احساس شرم از بدن', 'چرخه پرخوری و محدودیت', 'غذا به‌جای تنظیم هیجان'],
            'when' => 'اگر فکر غذا و وزن روز را پر کرده، یا سلامتی جسمی در خطر است، همزمان پزشکی و روان‌درمانی لازم است. رژیم تنبیهی جای درمان نیست.',
            'how' => 'درمانگر مرتبط، رابطه با غذا و بدن را بدون تحقیر بررسی می‌کند. در موارد پزشکی حاد اول پزشک یا تغذیه بالینی اولویت دارد.',
            'faqs' => [
                ['q' => 'فقط لاغری شدید اختلال است؟', 'a' => 'نه. پرخوری، درگیر بودن دائمی با وزن و شرم بدن هم می‌تواند نیاز به کمک داشته باشد.'],
                ['q' => 'باید وزن کم کنم تا درمان شوم؟', 'a' => 'هدف اول ایمنی و رابطه مهربان‌تر با بدن است، نه عدد روی ترازو.'],
                ['q' => 'خانواده باید بیاید؟', 'a' => 'گاهی بله؛ درمانگر می‌گوید چه زمانی حضور خانواده کمک است.'],
            ],
        ],
        'personality' => [
            'signs' => ['الگوی پایدار در رابطه که سال‌ها تکرار می‌شود', 'نوسان شدید خودتصویر یا هیجان', 'سختی اعتماد یا رها شدن', 'واکنش‌هایی که بعداً پشیمان می‌شوی'],
            'when' => 'اگر درمان‌های کوتاه فقط موقتی بوده‌اند و الگو از نوجوانی همراه است، کار عمیق‌تر روی شخصیت و طرحواره می‌تواند مناسب باشد.',
            'how' => 'جلسات معمولاً منظم‌تر و بلندمدت‌ترند. درمانگر مرز، ایمنی و رابطه درمانی را جدی می‌گیرد. تشخیص رسمی فقط در ارزیابی بالینی است.',
            'faqs' => [
                ['q' => 'اختلال شخصیت یعنی آدم بد؟', 'a' => 'نه. توصیف الگوی پایدار رنج است، نه قضاوت اخلاقی.'],
                ['q' => 'آیا قابل تغییر است؟', 'a' => 'الگوها سفت‌اند اما با کار منظم می‌توان رابطه با خود و دیگران را نرم‌تر کرد.'],
                ['q' => 'باید تست شخصیت بدهم؟', 'a' => 'آزمون ممکن است بخشی از ارزیابی باشد؛ جایگزین گفتگو و مشاهده بالینی نیست.'],
            ],
        ],
        'sexual' => [
            'signs' => ['درد، اجتناب یا افت میل', 'اضطراب عملکرد', 'سختی حرف زدن با شریک', 'شرم که مانع کمک شده'],
            'when' => 'اگر مسئله جنسی رابطه یا حال روزمره را گرفته، مشاوره محرمانه ممکن است. علل جسمی را پزشک رد کند.',
            'how' => 'فضای جلسه بدون قضاوت است. می‌توانی فردی یا زوجی بیایی. جزئیات فقط به اندازه رضایت تو مطرح می‌شود.',
            'faqs' => [
                ['q' => 'محرمانگی چطور است؟', 'a' => 'پرونده کلینیکی محرمانه است مگر خطر جدی برای جان. جزئیات را در قوانین کلینیک ببین.'],
                ['q' => 'شریک باید بداند؟', 'a' => 'نه لزوماً. اگر کار زوجی باشد هر دو توافق می‌کنند.'],
                ['q' => 'این موضوع شرم‌آور است؟', 'a' => 'برای درمانگر موضوع بالینی است. شرم دلیل نیامدن نیست.'],
            ],
        ],
    ];

    return $all[$key] ?? ['signs' => [], 'when' => '', 'how' => '', 'faqs' => []];
}

function clinic_issue_topic(string $key): ?array
{
    $topics = clinic_issue_topics();
    if (!isset($topics[$key])) {
        return null;
    }
    $row = array_merge(clinic_issue_copy($key), $topics[$key]);
    $row['key'] = $key;

    return $row;
}

function clinic_text_has_word(string $haystack, string $word): bool
{
    $haystack = mb_strtolower($haystack, 'UTF-8');
    $word = mb_strtolower(trim($word), 'UTF-8');
    if ($word === '') {
        return false;
    }

    return mb_strpos($haystack, $word) !== false;
}

function clinic_article_topics(array $article): array
{
    $stored = doctor_profile_filter_keys(
        doctor_profile_json_list($article['topics_json'] ?? ''),
        doctor_focus_options()
    );
    if ($stored !== []) {
        return $stored;
    }
    $blob = ((string) ($article['title'] ?? '')) . ' ' .
        ((string) ($article['excerpt'] ?? '')) . ' ' .
        strip_tags((string) ($article['content'] ?? ''));
    $found = [];
    foreach (clinic_issue_topics() as $key => $topic) {
        foreach ($topic['words'] as $word) {
            if (clinic_text_has_word($blob, (string) $word)) {
                $found[] = $key;
                break;
            }
        }
    }

    return $found;
}

function clinic_articles_for_topic(PDO $pdo, string $topicKey, int $limit = 8): array
{
    $topicKey = clinic_normalize_filter($topicKey, doctor_focus_options());
    if ($topicKey === '') {
        return [];
    }
    try {
        $rows = $pdo->query("
          SELECT a.*, u.name AS author_name
          FROM articles a
          JOIN users u ON u.id = a.author_id
          WHERE a.published = 1
          ORDER BY a.published_at DESC
        ")->fetchAll() ?: [];
    } catch (Throwable $e) {
        return [];
    }
    $out = [];
    foreach ($rows as $row) {
        if (in_array($topicKey, clinic_article_topics($row), true)) {
            $out[] = $row;
            if (count($out) >= $limit) {
                break;
            }
        }
    }

    return $out;
}

function clinic_ymd_fa(string $ymd): string
{
    $ymd = substr($ymd, 0, 10);
    $p = explode('-', $ymd);
    if (count($p) !== 3) {
        return $ymd;
    }
    [$jy, $jm, $jd] = gregorian_to_jalali((int) $p[0], (int) $p[1], (int) $p[2]);
    $months = function_exists('jalali_month_names') ? jalali_month_names() : [];
    $month = (string) ($months[(int) $jm] ?? $jm);

    return to_fa_digits((string) $jd) . ' ' . $month . ' ' . to_fa_digits((string) $jy);
}

function clinic_filter_chip_row(string $param, array $options, string $current, array $baseFilters, string $legend): string
{
    $all = $baseFilters;
    $all[$param] = '';
    ob_start();
    ?>
    <div class="dir-filter-row">
      <span class="dir-filter-legend"><?= e($legend) ?></span>
      <div class="dir-filter-chips">
        <a class="dir-chip<?= $current === '' ? ' is-on' : '' ?>" href="<?= e(clinic_doctors_href($all)) ?>">همه</a>
        <?php foreach ($options as $key => $label): ?>
          <?php
            $next = $baseFilters;
            $next[$param] = $key;
          ?>
          <a class="dir-chip<?= $current === $key ? ' is-on' : '' ?>" href="<?= e(clinic_doctors_href($next)) ?>"><?= e($label) ?></a>
        <?php endforeach; ?>
      </div>
    </div>
    <?php

    return (string) ob_get_clean();
}

function clinic_funnel_cta_html(string $bookHref, ?array $topic = null): string
{
    $issueHref = $topic ? url('/issues/' . $topic['key']) : url('/issues');
    $serviceHref = $topic ? url((string) $topic['service']) : url('/services');
    $label = $topic['label'] ?? 'مسئله‌ات';
    ob_start();
    ?>
    <aside class="article-funnel" aria-label="قدم بعدی">
      <p>اگر این نوشته به «<?= e($label) ?>» نزدیک است، می‌توانی درمانگر همان حوزه را ببینی و نوبت حضوری سعادت‌آباد یا آنلاین بگیری. هزینه فقط بعد از انتخاب ساعت رزرو دیده می‌شود.</p>
      <p class="service-detail-cta" style="margin:.75rem 0 0">
        <a class="btn btn-primary" href="<?= e($bookHref) ?>">رزرو درمانگر مرتبط</a>
        <a class="btn btn-outline" href="<?= e($issueHref) ?>">صفحه این موضوع</a>
        <a class="btn btn-outline" href="<?= e($serviceHref) ?>">خدمات کلینیک</a>
      </p>
    </aside>
    <?php

    return (string) ob_get_clean();
}

function clinic_article_card_html(array $article): string
{
    $topics = clinic_article_topics($article);
    $first = $topics[0] ?? '';
    $meta = $first !== '' ? clinic_issue_topic($first) : null;
    $href = url('/articles/' . (string) ($article['slug'] ?? ''));
    ob_start();
    ?>
    <a class="panel card-link article-card article-funnel-card" href="<?= e($href) ?>">
      <?php if (!empty($article['cover_url'])): ?>
        <img class="article-card-cover" src="<?= e(url((string) $article['cover_url'])) ?>" alt="<?= e((string) $article['title']) ?>">
      <?php endif; ?>
      <?php if ($meta): ?>
        <span class="badge"><?= e($meta['label']) ?></span>
      <?php endif; ?>
      <span class="muted" style="font-size:.82rem"><?= e((string) ($article['author_name'] ?? '')) ?></span>
      <h2 class="article-card-title" style="margin:.45rem 0 0;font-size:1.15rem"><?= e((string) $article['title']) ?></h2>
      <?php if (!empty($article['excerpt'])): ?>
        <p class="muted line-clamp-3" style="margin-top:.65rem;font-size:.9rem;line-height:1.8"><?= e((string) $article['excerpt']) ?></p>
      <?php endif; ?>
      <span class="doctor-card-cta">خواندن و رزرو درمانگر</span>
    </a>
    <?php

    return (string) ob_get_clean();
}

function clinic_related_block_html(array $doctors, string $heading, string $moreHref, string $moreLabel = 'مشاهده همه متخصصان مرتبط'): string
{
    ob_start();
    ?>
    <section class="clinic-match" aria-labelledby="clinic-match-h">
      <h2 id="clinic-match-h"><?= e($heading) ?></h2>
      <p class="muted">این متخصصان در مانا کلینیک سعادت‌آباد روی همین موضوع کار می‌کنند. نوبت حضوری و آنلاین از صفحه هر درمانگر رزرو می‌شود.</p>
      <?php if ($doctors): ?>
        <div class="doctors-directory service-detail-doctors">
          <?php foreach ($doctors as $doc): ?>
            <?= doctor_card_html($doc) ?>
          <?php endforeach; ?>
        </div>
        <p class="service-detail-related"><a href="<?= e($moreHref) ?>"><?= e($moreLabel) ?></a></p>
      <?php else: ?>
        <p class="muted">در حال حاضر درمانگر فعالی با این برچسب ثبت نشده. <a href="<?= e(url('/doctors')) ?>">فهرست همه متخصصان</a></p>
      <?php endif; ?>
    </section>
    <?php

    return (string) ob_get_clean();
}
