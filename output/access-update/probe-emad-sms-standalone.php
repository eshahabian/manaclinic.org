<?php
declare(strict_types=1);
if (!hash_equals('__TOKEN__', (string) ($_GET['t'] ?? ''))) {
    http_response_code(404);
    exit;
}
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$config = require __DIR__ . '/config.php';
date_default_timezone_set($config['timezone'] ?? 'Asia/Tehran');
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/mail.php';
require_once __DIR__ . '/includes/outreach.php';
$pdo = db_connect($config);
ensure_mail_schema($pdo);
ensure_outreach_schema($pdo);

$users = $pdo->query("SELECT id,username,name,phone,role FROM users WHERE (name LIKE '%عماد%' AND name LIKE '%شهابیان%') OR LOWER(username)='eshahabian'")->fetchAll();
$userIds = array_values(array_filter(array_map(static fn ($u) => (string) ($u['id'] ?? ''), $users)));
$appts = [];
if ($userIds) {
    $in = implode(',', array_fill(0, count($userIds), '?'));
    $st = $pdo->prepare("SELECT a.id,a.status,a.starts_at,a.created_at,a.updated_at,a.created_by_user_id,
        pu.id patient_id,pu.name patient,pu.phone patient_phone,pu.role patient_role,
        dp.id doctor_profile_id,du.id doctor_user_id,du.name doctor,du.phone doctor_phone
        FROM appointments a
        JOIN users pu ON pu.id=a.patient_id
        JOIN doctor_profiles dp ON dp.id=a.doctor_id
        JOIN users du ON du.id=dp.user_id
        WHERE (a.patient_id IN ($in) OR du.id IN ($in))
          AND DATE(a.starts_at)=CURDATE()
        ORDER BY a.starts_at ASC");
    $st->execute(array_merge($userIds, $userIds));
    $appts = $st->fetchAll();
}

$phones = [];
foreach ($users as $u) {
    $p = sms_iran_mobile((string) ($u['phone'] ?? ''));
    if ($p) {
        $phones[$p] = true;
    }
}
foreach ($appts as $a) {
    foreach (['patient_phone', 'doctor_phone'] as $k) {
        $p = sms_iran_mobile((string) ($a[$k] ?? ''));
        if ($p) {
            $phones[$p] = true;
        }
    }
}
$outbox = [];
$apptIds = array_map(static fn ($a) => (string) $a['id'], $appts);
if ($phones || $apptIds) {
    $sql = "SELECT id,phone,kind,status,due_at,sent_at,last_error,LEFT(body,160) body,created_at,context_json
        FROM sms_outbox WHERE created_at>=DATE_SUB(NOW(), INTERVAL 2 DAY) AND (";
    $parts = [];
    $args = [];
    if ($phones) {
        $parts[] = 'phone IN (' . implode(',', array_fill(0, count($phones), '?')) . ')';
        $args = array_merge($args, array_keys($phones));
    }
    if ($apptIds) {
        foreach ($apptIds as $aid) {
            $parts[] = 'context_json LIKE ?';
            $args[] = '%' . $aid . '%';
        }
    }
    $parts[] = "body LIKE '%شهابیان%'";
    $sql .= implode(' OR ', $parts) . ') ORDER BY created_at DESC LIMIT 40';
    $st = $pdo->prepare($sql);
    $st->execute($args);
    $outbox = $st->fetchAll();
}

$kinds = ['appointment_booked','appointment_cancelled','appointment_reminder','doctor_appointment_booked','doctor_appointment_cancelled','therapist_copy'];
$enabled = [];
foreach ($kinds as $k) {
    $enabled[$k] = sms_kind_enabled($pdo, $k);
}

$why = [];
foreach ($appts as $a) {
    $rowWhy = ['id' => $a['id'], 'starts_at' => $a['starts_at'], 'status' => $a['status'], 'as' => '?'];
    foreach ($userIds as $uid) {
        if ((string) $a['patient_id'] === $uid) {
            $rowWhy['as'] = 'patient';
        }
        if ((string) $a['doctor_user_id'] === $uid) {
            $rowWhy['as'] = ($rowWhy['as'] === 'patient') ? 'both' : 'doctor';
        }
    }
    $patientPhone = sms_iran_mobile((string) $a['patient_phone']);
    $doctorPhone = sms_iran_mobile((string) $a['doctor_phone']);
    $rowWhy['patient_phone_ok'] = $patientPhone !== null;
    $rowWhy['doctor_phone_ok'] = $doctorPhone !== null;
    $rowWhy['same_phone'] = (bool) ($patientPhone && $doctorPhone && $patientPhone === $doctorPhone);
    $related = array_values(array_filter($outbox, static function ($o) use ($a) {
        return str_contains((string) ($o['context_json'] ?? ''), (string) $a['id']);
    }));
    $rowWhy['outbox_count'] = count($related);
    $rowWhy['outbox'] = $related;
    if (!in_array((string) $a['status'], ['CONFIRMED', 'PENDING_APPROVAL'], true)) {
        $rowWhy['likely'] = 'status_not_sms_ready:' . $a['status'];
    } elseif (!$enabled['appointment_booked'] && $rowWhy['as'] !== 'doctor') {
        $rowWhy['likely'] = 'appointment_booked_disabled';
    } elseif ($patientPhone === null && in_array($rowWhy['as'], ['patient', 'both'], true)) {
        $rowWhy['likely'] = 'invalid_patient_phone';
    } elseif ($related === []) {
        $rowWhy['likely'] = 'no_outbox_row_sms_never_queued';
    } else {
        $rowWhy['likely'] = 'outbox:' . implode(',', array_unique(array_map(static fn ($o) => (string) $o['status'], $related)));
    }
    $why[] = $rowWhy;
}

echo json_encode([
    'now' => date('Y-m-d H:i:s'),
    'ready' => sms_connection_ready($pdo),
    'worker_last' => mail_setting_get($pdo, 'sms_worker_last_run', ''),
    'events_started' => mail_setting_get($pdo, 'sms_events_started_at', ''),
    'enabled' => $enabled,
    'users' => array_map(static function ($u) {
        $u['phone_tail'] = substr((string) ($u['phone'] ?? ''), -4);
        unset($u['phone']);
        return $u;
    }, $users),
    'appts' => array_map(static function ($a) {
        $a['patient_phone_tail'] = substr((string) ($a['patient_phone'] ?? ''), -4);
        $a['doctor_phone_tail'] = substr((string) ($a['doctor_phone'] ?? ''), -4);
        unset($a['patient_phone'], $a['doctor_phone']);
        return $a;
    }, $appts),
    'why' => $why,
    'outbox' => array_map(static function ($o) {
        $o['phone_tail'] = substr((string) ($o['phone'] ?? ''), -4);
        unset($o['phone']);
        return $o;
    }, $outbox),
    'pending_total' => (int) $pdo->query("SELECT COUNT(*) FROM sms_outbox WHERE status='PENDING'")->fetchColumn(),
], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
@unlink(__FILE__);
