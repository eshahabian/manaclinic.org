<?php
declare(strict_types=1);

/**
 * Active, approved doctors whose domains_json includes the given domain key.
 *
 * @return list<array<string, mixed>>
 */
function service_doctors_by_domain(PDO $pdo, string $domainKey): array
{
    if (function_exists('clinic_filter_doctors')) {
        return clinic_filter_doctors($pdo, ['domain' => $domainKey]);
    }
    ensure_doctor_profile_schema($pdo);

    $allDoctors = $pdo->query("
      SELECT dp.*, u.name
      FROM doctor_profiles dp
      JOIN users u ON u.id = dp.user_id
      WHERE dp.is_active = 1 AND dp.is_approved = 1
      ORDER BY CASE WHEN u.name LIKE '%گرانمایه%' THEN 0 ELSE 1 END, dp.created_at ASC
    ")->fetchAll();

    $filtered = [];
    foreach ($allDoctors as $doc) {
        $domains = doctor_profile_filter_keys(
            doctor_profile_json_list($doc['domains_json'] ?? ''),
            doctor_domain_options()
        );
        if (in_array($domainKey, $domains, true)) {
            $filtered[] = $doc;
        }
    }

    return $filtered;
}

/**
 * Published articles explicitly tagged for a clinic service page.
 *
 * @return list<array<string, mixed>>
 */
function service_articles_for_key(PDO $pdo, string $serviceKey, int $limit = 6): array
{
    if (!function_exists('article_service_tag_options') || !isset(article_service_tag_options()[$serviceKey])) {
        return [];
    }
    try {
        if (function_exists('ensure_articles_schema')) {
            ensure_articles_schema($pdo);
        }
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
        if (!in_array($serviceKey, article_service_tags($row), true)) {
            continue;
        }
        $out[] = $row;
        if (count($out) >= $limit) {
            break;
        }
    }

    return $out;
}

function service_related_articles_html(PDO $pdo, string $serviceKey): string
{
    $articles = service_articles_for_key($pdo, $serviceKey);
    if ($articles === [] || !function_exists('clinic_article_card_html')) {
        return '';
    }
    $label = article_service_tag_options()[$serviceKey] ?? 'این خدمت';
    ob_start();
    ?>
    <section class="service-detail-articles" aria-labelledby="service-articles-heading">
      <h2 id="service-articles-heading">مقالات <?= e($label) ?></h2>
      <p class="muted">نوشته‌های مرتبط با این خدمت</p>
      <div class="articles-showcase service-detail-articles-grid">
        <?php foreach ($articles as $article): ?>
          <?= clinic_article_card_html($article) ?>
        <?php endforeach; ?>
      </div>
      <p class="service-detail-related">
        <a href="<?= e(url('/articles')) ?>">همه مقالات</a>
      </p>
    </section>
    <?php

    return (string) ob_get_clean();
}

function service_doctors_search_href(string $label): string
{
    $map = [
        'مشاوره فردی' => ['domain' => 'individual'],
        'زوج درمانی' => ['domain' => 'couples'],
        'کودک و نوجوان' => ['domain' => 'child'],
        'پیش از ازدواج' => ['domain' => 'premarital'],
        'خانواده درمانی' => ['domain' => 'family'],
    ];
    if (function_exists('clinic_doctors_href') && isset($map[$label])) {
        return clinic_doctors_href($map[$label]);
    }

    return url('/doctors?q=' . rawurlencode($label));
}

/**
 * @return array<string, mixed>
 */
function service_detail_json_ld(
    string $title,
    string $path,
    string $description,
    string $therapyName,
    ?string $imageUrl = null
): array {
    $pageNode = [
        '@type' => 'MedicalWebPage',
        'name' => $title,
        'description' => $description,
        'url' => seo_absolute_url($path),
        'isPartOf' => ['@type' => 'WebSite', 'name' => 'مانا کلینیک', 'url' => seo_absolute_url('/')],
        'about' => [
            '@type' => 'MedicalTherapy',
            'name' => $therapyName,
        ],
    ];
    if ($imageUrl !== null && $imageUrl !== '') {
        $pageNode['primaryImageOfPage'] = [
            '@type' => 'ImageObject',
            'url' => seo_absolute_url($imageUrl),
        ];
    }

    return [
        '@context' => 'https://schema.org',
        '@graph' => [
            [
                '@type' => 'BreadcrumbList',
                'itemListElement' => [
                    ['@type' => 'ListItem', 'position' => 1, 'name' => 'صفحه اصلی', 'item' => seo_absolute_url('/')],
                    ['@type' => 'ListItem', 'position' => 2, 'name' => 'خدمات', 'item' => seo_absolute_url('/services')],
                    ['@type' => 'ListItem', 'position' => 3, 'name' => $title, 'item' => seo_absolute_url($path)],
                ],
            ],
            $pageNode,
        ],
    ];
}

/**
 * Published workshops that are still upcoming or in progress (ended ones excluded).
 * Newest registrations first so new workshops appear at the top.
 *
 * @return list<array<string, mixed>>
 */
function service_upcoming_workshops(PDO $pdo): array
{
    try {
        ensure_workshop_schema($pdo);
        if (function_exists('workshop_archive_expired')) {
            workshop_archive_expired($pdo);
        }

        $sql = "
          SELECT w.*, u.name AS doctor_name
          FROM workshops w
          " . workshop_active_doctor_join('w') . "
          JOIN users u ON u.id = dp.user_id
          WHERE " . workshop_patient_list_sql('w') . "
          ORDER BY w.created_at DESC, w.id DESC
        ";
        $stmt = $pdo->query($sql);

        return $stmt ? $stmt->fetchAll() : [];
    } catch (Throwable $e) {
        return [];
    }
}

function service_workshop_when_label(array $workshop): string
{
    if (workshop_is_offline((string) ($workshop['type'] ?? ''))) {
        return 'دوره آفلاین — دسترسی پس از ثبت‌نام';
    }

    $start = (string) ($workshop['starts_at'] ?? '');
    $end = (string) ($workshop['ends_at'] ?? '');
    if ($start === '' || $end === '') {
        return 'زمان به‌زودی اعلام می‌شود';
    }

    return format_workshop_datetime_fa($start) . ' تا ' . format_workshop_datetime_fa($end);
}
