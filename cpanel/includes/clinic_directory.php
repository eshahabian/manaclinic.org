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

function clinic_issue_topic(string $key): ?array
{
    $topics = clinic_issue_topics();
    if (!isset($topics[$key])) {
        return null;
    }
    $row = $topics[$key];
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
