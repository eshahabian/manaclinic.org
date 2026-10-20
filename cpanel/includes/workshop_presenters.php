<?php
declare(strict_types=1);

/**
 * متن تأیید اجراکننده — تک‌خطی کوتاه.
 * وب‌وان متن چندخطی/بلند را با کد ۸ («اعتبار کافی نیست») رد می‌کند؛
 * همان پنل برای پیامک کوتاه عضویت کارگاه SENT می‌دهد.
 */
function workshop_presenter_message(array $workshop, string $name): string
{
    $start = strtotime((string) $workshop['starts_at']);
    $end = strtotime((string) $workshop['ends_at']);
    if (!$start || !$end) {
        throw new RuntimeException('زمان کارگاه نامعتبر است.');
    }
    $days = ['یکشنبه', 'دوشنبه', 'سه‌شنبه', 'چهارشنبه', 'پنجشنبه', 'جمعه', 'شنبه'];
    $when = ($workshop['session_interval'] ?? '') === 'WEEKLY'
        ? $days[(int) date('w', $start)] . '‌ها'
        : format_fa_day_long((string) $workshop['starts_at']);
    $title = preg_replace('/\s+/u', ' ', trim((string) ($workshop['title'] ?? ''))) ?? '';
    if (mb_strlen($title) > 20) {
        $title = mb_substr($title, 0, 19) . '…';
    }
    $clock = to_fa_digits(date('H:i', $start)) . ' تا ' . to_fa_digits(date('H:i', $end));
    $body = 'مانا کلینیک: اجراکننده «' . $title . '» تأیید شد. ' . $when . '، ' . $clock;
    if (mb_strlen($body) > 70) {
        $body = 'مانا کلینیک: اجرا «' . $title . '» تأیید شد. ' . $when . ' ' . to_fa_digits(date('H:i', $start));
    }

    return $body;
}

function workshop_presenter_names(PDO $pdo, string $workshopId): array
{
    $q = $pdo->prepare('SELECT u.name FROM workshop_presenters wp JOIN doctor_profiles dp ON dp.id=wp.doctor_id JOIN users u ON u.id=dp.user_id WHERE wp.workshop_id=? AND dp.is_active=1 AND dp.is_approved=1 AND COALESCE(u.is_disabled,0)=0 ORDER BY u.name');
    $q->execute([$workshopId]);

    return $q->fetchAll(PDO::FETCH_COLUMN);
}

function workshop_presenter_sms_was_sent(PDO $pdo, string $workshopId, string $doctorId): bool
{
    $q = $pdo->prepare("
      SELECT status FROM sms_outbox
      WHERE kind='workshop_presenter' AND status='SENT'
        AND JSON_UNQUOTE(JSON_EXTRACT(context_json,'$.workshop_id'))=?
        AND JSON_UNQUOTE(JSON_EXTRACT(context_json,'$.doctor_id'))=?
      ORDER BY created_at DESC LIMIT 1
    ");
    $q->execute([$workshopId, $doctorId]);

    return (string) ($q->fetchColumn() ?: '') === 'SENT';
}

/**
 * ارسال فوری پیامک تأیید اجراکننده (+ نمونه عماد) برای کسانی که هنوز SENT نگرفته‌اند.
 * @return array{sent:int,failed:int,skipped:int,errors:list<string>}
 */
function workshop_presenters_notify(PDO $pdo, string $workshopId): array
{
    require_once __DIR__ . '/sms_service.php';
    require_once __DIR__ . '/workshop_sms.php';
    $result = ['sent' => 0, 'failed' => 0, 'skipped' => 0, 'errors' => []];
    if (!sms_connection_ready($pdo)) {
        $result['errors'][] = 'اتصال پیامک آماده نیست.';
        return $result;
    }
    $q = $pdo->prepare('SELECT * FROM workshops WHERE id=?');
    $q->execute([$workshopId]);
    $workshop = $q->fetch();
    if (!$workshop) {
        $result['errors'][] = 'کارگاه پیدا نشد.';
        return $result;
    }
    $q = $pdo->prepare("
      SELECT dp.id, u.name, u.phone FROM workshop_presenters wp
      JOIN doctor_profiles dp ON dp.id=wp.doctor_id
      JOIN users u ON u.id=dp.user_id
      WHERE wp.workshop_id=? AND dp.is_active=1 AND dp.is_approved=1 AND COALESCE(u.is_disabled,0)=0
      ORDER BY u.name
    ");
    $q->execute([$workshopId]);
    $doctors = $q->fetchAll() ?: [];
    $detailsHash = hash('sha256', json_encode([
        $workshop['title'], $workshop['starts_at'], $workshop['ends_at'], $workshop['location'], $workshop['type'],
    ]));
    $body = workshop_presenter_message($workshop, 'اجراکننده');
    $index = 0;
    $emadSampleSent = false;
    foreach ($doctors as $doctor) {
        $doctorId = (string) $doctor['id'];
        if (workshop_presenter_sms_was_sent($pdo, $workshopId, $doctorId)) {
            $result['skipped']++;
            continue;
        }
        $phone = sms_iran_mobile((string) $doctor['phone']);
        if ($phone === null) {
            $result['failed']++;
            $result['errors'][] = ((string) $doctor['name'] ?: 'درمانگر') . ': موبایل نامعتبر';
            continue;
        }
        if ($index > 0) {
            usleep(700_000);
        }
        $index++;
        $ctx = [
            'entity' => 'workshop_presenter',
            'workshop_id' => $workshopId,
            'doctor_id' => $doctorId,
            'details_hash' => $detailsHash,
            'recipient_phone' => $phone,
        ];
        $key = 'workshop-presenter:' . $workshopId . ':' . $doctorId . ':' . date('YmdHis') . ':' . cuid();
        $send = sms_send_immediate(
            $pdo,
            $phone,
            $body,
            'workshop_presenter',
            $key,
            date('Y-m-d H:i:s', time() + 86400),
            $ctx
        );
        if (!empty($send['ok'])) {
            $result['sent']++;
        } else {
            $result['failed']++;
            $err = trim((string) ($send['error'] ?? ''));
            $result['errors'][] = ((string) $doctor['name'] ?: 'درمانگر') . ($err !== '' ? (': ' . $err) : '');
        }
        if (!$emadSampleSent) {
            workshop_sms_send_emad_sample(
                $pdo,
                $body,
                'workshop_presenter',
                'workshop-presenter-emad:' . $workshopId . ':' . cuid(),
                $ctx,
                true
            );
            $emadSampleSent = true;
            usleep(500_000);
        }
    }

    return $result;
}

/** @deprecated باقی برای سازگاری؛ از workshop_presenters_notify استفاده شود. */
function workshop_presenters_dispatch(PDO $pdo, string $workshopId): array
{
    $r = workshop_presenters_notify($pdo, $workshopId);

    return [
        'SENT' => (int) $r['sent'],
        'FAILED' => (int) $r['failed'],
        'UNKNOWN' => 0,
        'SKIPPED' => (int) $r['skipped'],
    ];
}

function workshop_presenters_schema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS workshop_presenters (
      workshop_id VARCHAR(32) NOT NULL, doctor_id VARCHAR(32) NOT NULL,
      assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY(workshop_id,doctor_id),
      FOREIGN KEY(workshop_id) REFERENCES workshops(id) ON DELETE CASCADE,
      FOREIGN KEY(doctor_id) REFERENCES doctor_profiles(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $ready = true;
}

function workshop_presenters_save(PDO $pdo, string $workshopId, array $ids): void
{
    if (!$pdo->inTransaction()) {
        workshop_presenters_schema($pdo);
    }
    $ids = array_values(array_unique(array_filter(array_map('strval', $ids))));
    if (!$ids) {
        throw new RuntimeException('حداقل یک اجراکننده انتخاب کنید.');
    }
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $q = $pdo->prepare("SELECT dp.id,u.name,u.phone FROM doctor_profiles dp JOIN users u ON u.id=dp.user_id WHERE dp.id IN ($marks) AND dp.is_active=1 AND dp.is_approved=1 AND COALESCE(u.is_disabled,0)=0");
    $q->execute($ids);
    $doctors = $q->fetchAll();
    if (count($doctors) !== count($ids)) {
        throw new RuntimeException('درمانگر انتخاب‌شده معتبر نیست.');
    }
    foreach ($doctors as $doctor) {
        if (sms_iran_mobile((string) $doctor['phone']) === null) {
            throw new RuntimeException('شماره موبایل یکی از اجراکنندگان معتبر نیست.');
        }
    }
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        ensure_outreach_schema($pdo);
        $pdo->beginTransaction();
    }
    try {
        $q = $pdo->prepare('SELECT id FROM workshops WHERE id=? FOR UPDATE');
        $q->execute([$workshopId]);
        if (!$q->fetch()) {
            throw new RuntimeException('کارگاه پیدا نشد.');
        }
        $q = $pdo->prepare('SELECT doctor_id FROM workshop_presenters WHERE workshop_id=?');
        $q->execute([$workshopId]);
        $old = $q->fetchAll(PDO::FETCH_COLUMN);
        foreach ($doctors as $doctor) {
            if (in_array($doctor['id'], $old, true)) {
                continue;
            }
            $pdo->prepare('INSERT INTO workshop_presenters(workshop_id,doctor_id) VALUES (?,?)')
                ->execute([$workshopId, $doctor['id']]);
        }
        $pdo->prepare("DELETE FROM workshop_presenters WHERE workshop_id=? AND doctor_id NOT IN ($marks)")
            ->execute(array_merge([$workshopId], $ids));
        if ($ownsTransaction) {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
