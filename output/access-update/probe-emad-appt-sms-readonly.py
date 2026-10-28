"""Read-only: Emad appointment today ~17:15 and related SMS outbox/settings."""
from pathlib import Path
from ftplib import FTP
import io
import json
import secrets
import time
import urllib.request

root = Path('D:/Psychology')
cfg = json.loads((root / '.vscode/sftp.json').read_text(encoding='utf-8-sig'))
token = secrets.token_hex(32)
inject = r'''
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) === '/internal-sms-probe')) {
    if (!hash_equals('__TOKEN__', (string)($_SERVER['HTTP_X_SETUP_TOKEN'] ?? '')) || time() > __EXPIRY__) { http_response_code(404); exit; }
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    require_once __DIR__ . '/includes/db.php';
    require_once __DIR__ . '/includes/helpers.php';
    require_once __DIR__ . '/includes/mail.php';
    require_once __DIR__ . '/includes/outreach.php';
    $config = require __DIR__ . '/config.php';
    $pdo = db_connect($config);
    ensure_mail_schema($pdo);
    ensure_outreach_schema($pdo);

    $users = $pdo->query("SELECT id,username,name,phone,role FROM users WHERE (name LIKE '%عماد%' AND name LIKE '%شهابیان%') OR LOWER(username)='eshahabian'")->fetchAll();
    $userIds = array_values(array_filter(array_map(static fn($u) => (string)($u['id'] ?? ''), $users)));
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

    $outbox = [];
    $phones = [];
    foreach ($users as $u) {
        $p = sms_iran_mobile((string)($u['phone'] ?? ''));
        if ($p) $phones[$p] = true;
    }
    foreach ($appts as $a) {
        foreach (['patient_phone','doctor_phone'] as $k) {
            $p = sms_iran_mobile((string)($a[$k] ?? ''));
            if ($p) $phones[$p] = true;
        }
    }
    $apptIds = array_map(static fn($a) => (string)$a['id'], $appts);
    if ($phones || $apptIds) {
        $sql = "SELECT id,phone,kind,status,due_at,sent_at,last_error,LEFT(body,160) body,created_at,dedup_key,context_json
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
        $rowWhy = ['id'=>$a['id'],'starts_at'=>$a['starts_at'],'status'=>$a['status'],'as'=>'?'];
        foreach ($userIds as $uid) {
            if ((string)$a['patient_id'] === $uid) $rowWhy['as'] = 'patient';
            if ((string)$a['doctor_user_id'] === $uid) $rowWhy['as'] = ($rowWhy['as']==='patient'?'both':'doctor');
        }
        $patientPhone = sms_iran_mobile((string)$a['patient_phone']);
        $doctorPhone = sms_iran_mobile((string)$a['doctor_phone']);
        $rowWhy['patient_phone_ok'] = $patientPhone !== null;
        $rowWhy['doctor_phone_ok'] = $doctorPhone !== null;
        $rowWhy['same_phone'] = $patientPhone && $doctorPhone && $patientPhone === $doctorPhone;
        $rowWhy['sms_connection'] = sms_connection_ready($pdo);
        $rowWhy['kind_patient_enabled'] = $enabled['appointment_booked'];
        $rowWhy['kind_doctor_enabled'] = $enabled['doctor_appointment_booked'];
        $related = array_values(array_filter($outbox, static function ($o) use ($a, $patientPhone, $doctorPhone) {
            $ctx = (string)($o['context_json'] ?? '');
            return str_contains($ctx, (string)$a['id'])
                || ($patientPhone && $o['phone'] === $patientPhone && str_starts_with((string)$o['kind'], 'appointment'))
                || ($doctorPhone && $o['phone'] === $doctorPhone && str_contains((string)$o['kind'], 'doctor_appointment'));
        }));
        $rowWhy['outbox_count'] = count($related);
        $rowWhy['outbox'] = $related;
        if ((string)$a['status'] !== 'CONFIRMED' && (string)$a['status'] !== 'PENDING_APPROVAL') {
            $rowWhy['likely'] = 'وضعیت نوبت برای پیامک ثبت مناسب نیست: ' . $a['status'];
        } elseif (!$enabled['appointment_booked'] && $rowWhy['as'] !== 'doctor') {
            $rowWhy['likely'] = 'نوع appointment_booked در پنل پیامک خاموش است';
        } elseif ($patientPhone === null && in_array($rowWhy['as'], ['patient','both'], true)) {
            $rowWhy['likely'] = 'شماره موبایل مراجع معتبر ایران نیست';
        } elseif ($related === []) {
            $rowWhy['likely'] = 'هیچ ردیفی در sms_outbox برای این نوبت نیست؛ احتمالاً sms_notify موقع ثبت صدا زده نشده یا خطای silent رخ داده یا نوبت از مسیری بدون SMS آمده';
        } else {
            $stati = array_unique(array_map(static fn($o)=>(string)$o['status'], $related));
            $rowWhy['likely'] = 'وضعیت صف: ' . implode(',', $stati);
        }
        $why[] = $rowWhy;
    }

    echo json_encode([
        'now' => date('Y-m-d H:i:s'),
        'ready' => sms_connection_ready($pdo),
        'worker_last' => mail_setting_get($pdo, 'sms_worker_last_run', ''),
        'events_started' => mail_setting_get($pdo, 'sms_events_started_at', ''),
        'enabled' => $enabled,
        'users' => $users,
        'appts' => $appts,
        'why' => $why,
        'outbox' => $outbox,
        'pending_total' => (int)$pdo->query("SELECT COUNT(*) FROM sms_outbox WHERE status='PENDING'")->fetchColumn(),
    ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
'''
inject = inject.replace('__TOKEN__', token).replace('__EXPIRY__', str(int(time.time()) + 600))

local_backup = root / 'output' / 'access-update' / 'index-before-emad-sms-probe.php'
with FTP() as ftp:
    ftp.connect(cfg['host'], int(cfg.get('port', 21)), timeout=90)
    ftp.login(cfg['username'], cfg['password'])
    ftp.set_pasv(True)
    ftp.cwd('/public_html')
    buf = io.BytesIO()
    ftp.retrbinary('RETR index.php', buf.write)
    original = buf.getvalue()
    local_backup.write_bytes(original)
    text = original.decode('utf-8')
    if 'declare(strict_types=1);' not in text:
        raise SystemExit('unexpected index.php')
    patched = text.replace('declare(strict_types=1);', 'declare(strict_types=1);\n' + inject, 1)
    try:
        try:
            ftp.delete('index.php')
        except Exception:
            pass
        ftp.storbinary('STOR index.php', io.BytesIO(patched.encode('utf-8')))
        time.sleep(2)
        req = urllib.request.Request(
            'https://manaclinic.org/internal-sms-probe',
            data=b'1',
            headers={
                'X-Setup-Token': token,
                'Content-Type': 'application/x-www-form-urlencoded',
                'User-Agent': 'Mozilla/5.0 ManaSmsProbe/1.0',
            },
            method='POST',
        )
        with urllib.request.urlopen(req, timeout=90) as resp:
            body = resp.read().decode('utf-8', errors='replace')
        out = root / 'output' / 'access-update' / 'probe-emad-appt-sms-readonly.json'
        out.write_text(body, encoding='utf-8')
        print(body[:5000])
    finally:
        try:
            ftp.delete('index.php')
        except Exception:
            pass
        ftp.storbinary('STOR index.php', io.BytesIO(original))
        print('index restored')
