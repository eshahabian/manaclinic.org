<?php
declare(strict_types=1);

/**
 * پیامک‌های کارگاه (اجراکننده، نمونه برای عماد، پایان جلسه، فایل جدید).
 * قالب‌های نوبت/OTP را تغییر نمی‌دهد.
 */

function workshop_sms_emad_user(PDO $pdo): ?array
{
    $row = $pdo->query("
      SELECT id, name, phone FROM users
      WHERE LOWER(username)='eshahabian' AND COALESCE(is_disabled,0)=0
      LIMIT 1
    ")->fetch();
    return is_array($row) ? $row : null;
}

function workshop_sms_emad_phone(PDO $pdo): ?string
{
    $user = workshop_sms_emad_user($pdo);
    if ($user === null) {
        return null;
    }

    return sms_iran_mobile((string) ($user['phone'] ?? ''));
}

/** یک نمونه از همان متن برای عماد شهابیان (فوری در صورت امکان). */
function workshop_sms_send_emad_sample(
    PDO $pdo,
    string $body,
    string $kind,
    string $dedup,
    array $context = [],
    bool $immediate = true
): void {
    require_once __DIR__ . '/sms_service.php';
    $emad = workshop_sms_emad_user($pdo);
    $phone = $emad ? sms_iran_mobile((string) ($emad['phone'] ?? '')) : null;
    if ($phone === null) {
        return;
    }
    $recipient = sms_iran_mobile((string) ($context['recipient_phone'] ?? ''));
    if ($recipient !== null && $recipient === $phone) {
        return;
    }
    $ctx = array_merge($context, [
        'entity' => 'workshop_copy',
        'copy_for' => (string) ($emad['id'] ?? ''),
        'sample' => 1,
    ]);
    $expires = date('Y-m-d H:i:s', time() + 86400);
    $copyKind = str_ends_with($kind, '_copy') ? $kind : ($kind . '_copy');
    if ($immediate && function_exists('sms_send_immediate')) {
        try {
            sms_send_immediate($pdo, $phone, $body, $copyKind, $dedup, $expires, $ctx);
        } catch (Throwable $e) {
            error_log('Workshop Emad sample SMS: ' . $e->getMessage());
        }
        return;
    }
    sms_queue_extended($pdo, $phone, $body, $copyKind, $dedup, null, $expires, $ctx);
}

/** اعضای تأییدشده کارگاه با موبایل معتبر. */
function workshop_sms_active_members(PDO $pdo, string $workshopId): array
{
    $stmt = $pdo->prepare("
      SELECT e.id AS enrollment_id, e.patient_id, u.name, u.phone, w.title, w.starts_at, w.ends_at, w.location, w.type
      FROM workshop_enrollments e
      JOIN workshops w ON w.id = e.workshop_id
      JOIN users u ON u.id = e.patient_id
      WHERE e.workshop_id = ?
        AND e.status IN ('CONFIRMED','COMPLETED')
        AND COALESCE(u.is_disabled,0)=0
    ");
    $stmt->execute([$workshopId]);
    $out = [];
    foreach ($stmt->fetchAll() ?: [] as $row) {
        $phone = sms_iran_mobile((string) ($row['phone'] ?? ''));
        if ($phone === null) {
            continue;
        }
        $row['phone'] = $phone;
        $out[] = $row;
    }

    return $out;
}

function workshop_sms_short_title(string $title, int $max = 28): string
{
    $title = preg_replace('/\s+/u', ' ', trim($title)) ?? '';
    if (mb_strlen($title) <= $max) {
        return $title;
    }

    return mb_substr($title, 0, $max - 1) . '…';
}

function workshop_sms_session_reflection_body(string $workshopTitle, string $sessionTitle = ''): string
{
    $title = workshop_sms_short_title($workshopTitle, 24);
    $session = preg_replace('/\s+/u', ' ', trim($sessionTitle)) ?? '';
    if ($session !== '' && mb_strlen($session) > 20) {
        $session = mb_substr($session, 0, 19) . '…';
    }
    if ($session !== '') {
        return 'مانا کلینیک: «' . $session . '» برگزار شد. برداشت خود را در پنل مانا بنویسید.';
    }

    return 'مانا کلینیک: جلسه «' . $title . '» برگزار شد. برداشت خود را در پنل مانا بنویسید.';
}

function workshop_sms_media_added_body(string $workshopTitle): string
{
    return 'مانا کلینیک: فایل جدیدی به کارگاه «' . workshop_sms_short_title($workshopTitle, 26) . '» اضافه شد.';
}

/**
 * پس از پایان زمان جلسه، به اعضا اطلاع بده که می‌توانند برداشت بنویسند.
 * از کرون sms_run_worker صدا زده می‌شود.
 */
function workshop_sms_queue_session_reflections(PDO $pdo): int
{
    require_once __DIR__ . '/sms_service.php';
    require_once __DIR__ . '/workshop_sessions.php';
    if (!sms_connection_ready($pdo)) {
        return 0;
    }
    ensure_workshop_sessions_schema($pdo);
    ensure_outreach_schema($pdo);

    $now = time();
    $today = date('Y-m-d');
    $stmt = $pdo->query("
      SELECT ws.id AS session_id, ws.workshop_id, ws.session_date, ws.title AS session_title,
             w.title, w.starts_at, w.ends_at, w.status AS workshop_status, w.type
      FROM workshop_sessions ws
      JOIN workshops w ON w.id = ws.workshop_id
      WHERE w.status NOT IN ('DRAFT','CANCELLED')
        AND ws.session_date <= CURDATE()
        AND ws.session_date >= DATE_SUB(CURDATE(), INTERVAL 3 DAY)
      ORDER BY ws.session_date ASC
      LIMIT 200
    ");
    $queued = 0;
    $emadBodySent = [];
    foreach ($stmt->fetchAll() ?: [] as $session) {
        $sessionDate = (string) ($session['session_date'] ?? '');
        if ($sessionDate === '') {
            continue;
        }
        $endTime = date('H:i:s', strtotime((string) ($session['ends_at'] ?? $session['starts_at'] ?? '20:00:00')));
        $doneAt = strtotime($sessionDate . ' ' . $endTime);
        if (!$doneAt || $now < $doneAt) {
            // همان روز قبل از پایان ساعت جلسه — هنوز نه
            if ($sessionDate >= $today) {
                continue;
            }
        }
        $workshopId = (string) $session['workshop_id'];
        $sessionId = (string) $session['session_id'];
        $body = workshop_sms_session_reflection_body(
            (string) $session['title'],
            (string) ($session['session_title'] ?? '')
        );
        $members = workshop_sms_active_members($pdo, $workshopId);
        foreach ($members as $member) {
            $key = 'workshop-reflect:' . $member['enrollment_id'] . ':' . $sessionId;
            $context = [
                'entity' => 'workshop_session_reflect',
                'id' => $member['enrollment_id'],
                'workshop_id' => $workshopId,
                'session_id' => $sessionId,
                'starts_at' => $member['starts_at'],
                'details_hash' => hash('sha256', json_encode([
                    $member['starts_at'],
                    $member['location'],
                    $member['type'],
                    $member['title'],
                ])),
            ];
            if (sms_queue_extended(
                $pdo,
                (string) $member['phone'],
                $body,
                'workshop_session_reflect',
                $key,
                date('Y-m-d H:i:s'),
                date('Y-m-d H:i:s', $doneAt + 3 * 86400),
                $context
            )) {
                $queued++;
            }
        }
        if ($members !== [] && !isset($emadBodySent[$sessionId])) {
            workshop_sms_send_emad_sample(
                $pdo,
                $body,
                'workshop_session_reflect',
                'emad-reflect:' . $sessionId,
                ['workshop_id' => $workshopId, 'session_id' => $sessionId],
                false
            );
            $emadBodySent[$sessionId] = true;
        }
    }

    return $queued;
}

/** اطلاع به اعضا + نمونه عماد وقتی فایل جدید اضافه شد. */
function workshop_sms_notify_media_added(PDO $pdo, string $workshopId, string $mediaId = '', string $batchKey = ''): int
{
    require_once __DIR__ . '/sms_service.php';
    if (!sms_connection_ready($pdo)) {
        return 0;
    }
    $members = workshop_sms_active_members($pdo, $workshopId);
    if ($members === []) {
        return 0;
    }
    $title = (string) ($members[0]['title'] ?? 'کارگاه');
    $body = workshop_sms_media_added_body($title);
    $dedupSeed = $mediaId !== '' ? $mediaId : ($batchKey !== '' ? $batchKey : ('batch:' . $workshopId . ':' . date('YmdHi')));
    $sent = 0;
    foreach ($members as $member) {
        $key = 'workshop-media:' . $dedupSeed . ':' . $member['enrollment_id'];
        $context = [
            'entity' => 'workshop_media',
            'id' => $member['enrollment_id'],
            'workshop_id' => $workshopId,
            'media_id' => $mediaId,
            'starts_at' => $member['starts_at'],
            'details_hash' => hash('sha256', json_encode([
                $member['starts_at'],
                $member['location'],
                $member['type'],
                $member['title'],
            ])),
            'recipient_phone' => $member['phone'],
        ];
        $expires = date('Y-m-d H:i:s', time() + 86400);
        try {
            $result = sms_send_immediate(
                $pdo,
                (string) $member['phone'],
                $body,
                'workshop_media_added',
                $key,
                $expires,
                $context
            );
            if (!empty($result['ok'])) {
                $sent++;
            }
            usleep(400_000);
        } catch (Throwable $e) {
            error_log('Workshop media SMS: ' . $e->getMessage());
        }
    }
    workshop_sms_send_emad_sample(
        $pdo,
        $body,
        'workshop_media_added',
        'emad-media:' . $dedupSeed,
        ['workshop_id' => $workshopId, 'media_id' => $mediaId]
    );

    return $sent;
}
