<?php
declare(strict_types=1);

/** @var array $workshopList */
/** @var string $workshopEmpty */
/** @var string $workshopYmdKind */

$workshopList = is_array($workshopList ?? null) ? $workshopList : [];
$workshopEmpty = (string) ($workshopEmpty ?? 'کارگاهی در این بخش نیست.');
$workshopYmdKind = (string) ($workshopYmdKind ?? 'manage');
$workshopRole = (string) ($workshopRole ?? 'doctor');
$archiveView = !empty($archiveView);
$workshopEnrollmentsById = $workshopEnrollmentsById ?? [];
$sessionsByWorkshop = $sessionsByWorkshop ?? [];
$openDoctorPathId = $openDoctorPathId ?? '';
$doctorPathBoardById = $doctorPathBoardById ?? [];
$enrollByWorkshop = $enrollByWorkshop ?? [];
$wallet = $wallet ?? ['balance' => 0];

$list = $workshopList;

if ($workshopYmdKind === 'catalog') {
    $workshopList = $list;
    $emptyAvailable = $workshopEmpty;
    require __DIR__ . '/patient_workshop_available.php';
    return;
}

if ($workshopYmdKind === 'enroll') {
    $enrollmentList = $list;
    $emptyEnrollments = $workshopEmpty;
    require __DIR__ . '/patient_workshop_enrollments.php';
    return;
}

if ($workshopYmdKind === 'dash') {
    if (!$list) {
        echo '<p class="muted doctor-dash-empty">' . e($workshopEmpty) . '</p>';
        return;
    }
    echo '<ul class="doctor-dash-list">';
    foreach (array_slice($list, 0, 12) as $w) {
        if (!is_array($w)) {
            continue;
        }
        echo '<li><a href="' . e(url('/doctor/workshops?edit=' . rawurlencode((string) ($w['id'] ?? '')))) . '">';
        echo '<strong>' . e((string) ($w['title'] ?? '')) . '</strong>';
        echo '<span class="muted"> · ' . e(workshop_type_label((string) ($w['type'] ?? ''))) . ' · ' . (int) ($w['enrolled_count'] ?? 0) . ' نفر</span>';
        echo '</a></li>';
    }
    echo '</ul>';
    return;
}

$workshopList = $list;
require __DIR__ . '/workshop_manage_cards.php';
