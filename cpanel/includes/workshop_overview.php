<?php
declare(strict_types=1);

function workshop_member_can_see_files(?array $enrollment): bool
{
    $status = (string) ($enrollment['status'] ?? '');
    return in_array($status, ['CONFIRMED', 'COMPLETED'], true);
}

function workshop_overview_people_lists(array $enrollments): array
{
    $approved = [];
    $pending = [];
    foreach ($enrollments as $enr) {
        $name = trim((string) ($enr['patient_name'] ?? ''));
        if ($name === '') {
            continue;
        }
        $status = (string) ($enr['status'] ?? '');
        if (in_array($status, ['CONFIRMED', 'COMPLETED'], true)) {
            $approved[] = $name;
        } elseif ($status === 'PENDING_PAYMENT') {
            $pending[] = $name;
        }
    }
    return ['approved' => $approved, 'pending' => $pending];
}

function workshop_overview_payload(array $workshop, ?array $enrollment, array $sessions, bool $canSeeFiles, ?string $mediaUrl = null): array
{
    $days = [];
    foreach ($sessions as $session) {
        $sessionDate = (string) ($session['session_date'] ?? '');
        $day = [
            'title' => (string) ($session['title'] ?? ''),
            'date' => $sessionDate,
            'date_fa' => $sessionDate !== '' ? to_jalali_label($sessionDate) : '',
        ];
        if ($canSeeFiles) {
            $files = [];
            foreach (['PDF' => 'پی‌دی‌اف', 'AUDIO' => 'صوت', 'VIDEO' => 'ویدیو'] as $kind => $label) {
                $file = $session['files'][$kind] ?? null;
                if ($file) {
                    $files[] = $label;
                }
            }
            $day['files'] = $files;
        }
        $days[] = $day;
    }

    return [
        'id' => (string) ($workshop['id'] ?? ''),
        'title' => (string) ($workshop['title'] ?? ''),
        'type' => workshop_type_label((string) ($workshop['type'] ?? '')),
        'interval' => (($workshop['type'] ?? '') === 'OFFLINE')
            ? ''
            : workshop_session_interval_label((string) ($workshop['session_interval'] ?? 'DAILY')),
        'doctor' => (string) ($workshop['doctor_name'] ?? ''),
        'price' => format_price((int) ($workshop['price'] ?? 0)),
        'offline' => ($workshop['type'] ?? '') === 'OFFLINE',
        'when' => ($workshop['type'] ?? '') === 'OFFLINE'
            ? 'دوره آفلاین — بدون زمان‌بندی حضوری'
            : (format_workshop_datetime_fa((string) ($workshop['starts_at'] ?? '')) . ' تا ' . format_workshop_datetime_fa((string) ($workshop['ends_at'] ?? ''))),
        'items' => (string) ($workshop['items_to_bring'] ?? ''),
        'description' => (string) ($workshop['description'] ?? ''),
        'location' => (string) ($workshop['location'] ?? ''),
        'member' => $canSeeFiles,
        'staff' => false,
        'pending' => in_array((string) ($enrollment['status'] ?? ''), ['PENDING_PAYMENT'], true),
        'mediaUrl' => $canSeeFiles && $mediaUrl ? $mediaUrl : '',
        'pathUrl' => '',
        'editUrl' => '',
        'approvedPeople' => [],
        'pendingPeople' => [],
        'days' => $days,
    ];
}

function workshop_overview_data_script(array $payload): string
{
    $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP;
    if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
        $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
    }
    $json = json_encode($payload, $flags);
    if (!is_string($json) || $json === '') {
        $json = '{}';
    }
    return '<script type="application/json" class="js-workshop-payload">' . $json . '</script>';
}

function workshop_overview_modal_html(): string
{
    return '
    <div id="workshop-overview-modal" class="workshop-overview" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="workshop-overview-title">
      <div class="workshop-overview-backdrop" data-workshop-close tabindex="-1"></div>
      <div class="workshop-overview-panel">
        <div class="workshop-overview-header">
          <h2 id="workshop-overview-title" style="margin:0;font-size:1.1rem"></h2>
          <button type="button" class="workshop-overview-close" data-workshop-close aria-label="بستن">×</button>
        </div>
        <div class="workshop-overview-body" id="workshop-overview-body"></div>
      </div>
    </div>';
}
