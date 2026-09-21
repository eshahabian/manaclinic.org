<?php
declare(strict_types=1);

/**
 * Active, approved doctors whose domains_json includes the given domain key.
 *
 * @return list<array<string, mixed>>
 */
function service_doctors_by_domain(PDO $pdo, string $domainKey): array
{
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

function service_doctors_search_href(string $label): string
{
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
