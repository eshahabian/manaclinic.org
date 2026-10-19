"""Find Emad appointment SMS status and test short appointment text send."""
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
name = 'sms-diag-' + secrets.token_hex(8) + '.php'
# Hook via index.php early pattern that worked for backup
hook_token = token
helper_note = 'using index patch'
# Simpler: patch index briefly like backup-db-via-index
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
    $users = $pdo->query("SELECT id,name,phone FROM users WHERE name LIKE '%عماد%' AND name LIKE '%شهابیان%'")->fetchAll();
    $appts = [];
    if ($users) {
        $ids = array_column($users, 'id');
        $in = implode(',', array_fill(0, count($ids), '?'));
        $st = $pdo->prepare("SELECT a.id,a.status,a.starts_at,a.created_at,a.updated_at,u.name patient,u.phone,d.name doctor
            FROM appointments a JOIN users u ON u.id=a.patient_id
            JOIN doctor_profiles dp ON dp.id=a.doctor_id JOIN users d ON d.id=dp.user_id
            WHERE a.patient_id IN ($in) ORDER BY a.created_at DESC LIMIT 8");
        $st->execute($ids);
        $appts = $st->fetchAll();
    }
    $outbox = $pdo->query("SELECT phone,kind,status,due_at,sent_at,last_error,LEFT(body,120) body,created_at
        FROM sms_outbox WHERE phone IN (SELECT phone FROM users WHERE name LIKE '%عماد%' AND name LIKE '%شهابیان%')
        OR body LIKE '%شهابیان%' OR kind='appointment_booked'
        ORDER BY created_at DESC LIMIT 20")->fetchAll();
    $c = sms_connection_config($pdo);
    $phone = null;
    foreach ($users as $u) { if (preg_match('/^09\\d{9}$/', (string)$u['phone'])) { $phone=$u['phone']; break; } }
    if (!$phone && $appts) $phone = sms_iran_mobile((string)$appts[0]['phone']);
    $send = null;
    if ($phone) {
        $body = 'مانا کلینیک: نوبت شما ' . date('Y-m-d H:i') . ' ثبت شد.';
        if ($appts) {
            $a = $appts[0];
            $body = 'مانا کلینیک: نوبت شما با ' . $a['doctor'] . ' در ' . format_fa_datetime($a['starts_at']) . ' ثبت شد.';
        }
        $send = ['body'=>$body,'result'=>sms_provider_send($c,$phone,$body)];
    }
    echo json_encode([
        'ready'=>sms_connection_ready($pdo),
        'worker_last'=>mail_setting_get($pdo,'sms_worker_last_run',''),
        'cron'=>mail_setting_get($pdo,'sms_cron_installed',''),
        'events_started'=>mail_setting_get($pdo,'sms_events_started_at',''),
        'users'=>$users,
        'appts'=>$appts,
        'outbox'=>$outbox,
        'pending'=>(int)$pdo->query("SELECT COUNT(*) FROM sms_outbox WHERE status='PENDING'")->fetchColumn(),
        'failed_recent'=>$pdo->query("SELECT phone,kind,status,last_error,LEFT(body,80) body FROM sms_outbox WHERE kind LIKE 'appointment%' ORDER BY created_at DESC LIMIT 10")->fetchAll(),
        'send'=>$send,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
'''
inject = inject.replace('__TOKEN__', token).replace('__EXPIRY__', str(int(time.time()) + 600))

with FTP() as ftp:
    ftp.connect(cfg['host'], int(cfg.get('port', 21)), timeout=90)
    ftp.login(cfg['username'], cfg['password'])
    ftp.set_pasv(True)
    ftp.cwd('/public_html')
    original = io.BytesIO()
    ftp.retrbinary('RETR index.php', original.write)
    original_index = original.getvalue()
    text = original_index.decode('utf-8')
    needle = 'declare(strict_types=1);'
    patched = text.replace(needle, needle + "\n" + inject, 1)
    try:
        try:
            ftp.delete('index.php')
        except Exception:
            pass
        ftp.storbinary('STOR index.php', io.BytesIO(patched.encode('utf-8')))
        time.sleep(1)
        req = urllib.request.Request(
            'https://manaclinic.org/internal-sms-probe',
            data=b'1',
            headers={'X-Setup-Token': token, 'User-Agent': 'Mozilla/5.0 ManaProbe/1.0', 'Content-Type': 'application/x-www-form-urlencoded'},
            method='POST',
        )
        with urllib.request.urlopen(req, timeout=120) as response:
            body = response.read().decode('utf-8', 'replace')
        (root / 'output/access-update/probe-appt-sms.json').write_text(body, encoding='utf-8')
        print(json.dumps(json.loads(body), ensure_ascii=True)[:4000], flush=True)
    finally:
        try:
            ftp.delete('index.php')
        except Exception:
            pass
        ftp.storbinary('STOR index.php', io.BytesIO(original_index))
        print('restored', flush=True)
