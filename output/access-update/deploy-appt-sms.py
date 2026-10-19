"""Deploy immediate appointment SMS + send catch-up for Emad's latest cancel."""
from pathlib import Path
from ftplib import FTP, error_perm
import io
import json
import secrets
import time
import urllib.request

root = Path('D:/Psychology')
cfg = json.loads((root / '.vscode/sftp.json').read_text(encoding='utf-8-sig'))
files = [
    'includes/sms_service.php',
    'actions/secretary_book.php',
    'includes/appointment_cancel.php',
    'includes/appointment_payment.php',
]
backup = root / 'output/access-update' / ('appt-sms-before-' + time.strftime('%Y%m%d-%H%M%S'))
backup.mkdir(parents=True, exist_ok=True)

with FTP() as ftp:
    ftp.connect(cfg['host'], int(cfg.get('port', 21)), timeout=60)
    ftp.login(cfg['username'], cfg['password'])
    ftp.set_pasv(True)
    ftp.cwd('/public_html')
    for name in files:
        old = io.BytesIO()
        try:
            ftp.retrbinary('RETR ' + name, old.write)
            dest = backup / name
            dest.parent.mkdir(parents=True, exist_ok=True)
            dest.write_bytes(old.getvalue())
        except error_perm as e:
            if not str(e).startswith('550'):
                raise
        payload = (root / 'cpanel' / name).read_bytes()
        ftp.storbinary('STOR ' + name, io.BytesIO(payload))
        verify = io.BytesIO()
        ftp.retrbinary('RETR ' + name, verify.write)
        if verify.getvalue() != payload:
            raise RuntimeError('verify failed ' + name)
        print('Verified', name, flush=True)

token = secrets.token_hex(32)
inject = r'''
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) === '/internal-appt-sms-fix')) {
    if (!hash_equals('__TOKEN__', (string)($_SERVER['HTTP_X_SETUP_TOKEN'] ?? '')) || time() > __EXPIRY__) { http_response_code(404); exit; }
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
    // Clear any long custom templates that WebOne rejects.
    foreach (['appointment_booked','appointment_cancelled','appointment_reminder'] as $k) {
        mail_setting_set($pdo, 'sms_template_' . $k, '');
    }
    $hasFn = function_exists('sms_notify_appointment_now');
    $appt = $pdo->query("SELECT a.id,a.status,a.starts_at,u.phone,u.name
        FROM appointments a JOIN users u ON u.id=a.patient_id
        WHERE u.name LIKE '%عماد%' AND u.name LIKE '%شهابیان%'
        ORDER BY a.created_at DESC LIMIT 1")->fetch();
    $send = null;
    if ($hasFn && is_array($appt)) {
        $event = ((string)$appt['status'] === 'CANCELLED') ? 'cancelled' : 'booked';
        $send = sms_notify_appointment_now($pdo, (string)$appt['id'], $event);
        $send['event'] = $event;
        $send['appointment_id'] = $appt['id'];
        $send['starts_at'] = $appt['starts_at'];
        $send['phone_tail'] = substr((string)$appt['phone'], -4);
        $send['body'] = sms_template_render($pdo, $event === 'cancelled' ? 'appointment_cancelled' : 'appointment_booked', [
            'datetime' => format_fa_datetime((string)$appt['starts_at']),
            'doctor' => '',
            'patient' => (string)$appt['name'],
            'status' => 'ثبت شد.',
        ]);
    }
    $src = file_get_contents(__DIR__ . '/includes/sms_service.php');
    echo json_encode([
        'has_fn' => $hasFn,
        'ready' => sms_connection_ready($pdo),
        'short_templates' => str_contains($src, 'نوبت شما در {datetime} ثبت شد'),
        'book_hook' => str_contains(file_get_contents(__DIR__ . '/actions/secretary_book.php'), 'sms_notify_appointment_now'),
        'cancel_hook' => str_contains(file_get_contents(__DIR__ . '/includes/appointment_cancel.php'), 'sms_notify_appointment_now'),
        'send' => $send,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
'''
inject = inject.replace('__TOKEN__', token).replace('__EXPIRY__', str(int(time.time()) + 600))

with FTP() as ftp:
    ftp.connect(cfg['host'], int(cfg.get('port', 21)), timeout=60)
    ftp.login(cfg['username'], cfg['password'])
    ftp.set_pasv(True)
    ftp.cwd('/public_html')
    original = io.BytesIO()
    ftp.retrbinary('RETR index.php', original.write)
    original_index = original.getvalue()
    text = original_index.decode('utf-8')
    patched = text.replace('declare(strict_types=1);', 'declare(strict_types=1);' + "\n" + inject, 1)
    try:
        try:
            ftp.delete('index.php')
        except Exception:
            pass
        ftp.storbinary('STOR index.php', io.BytesIO(patched.encode('utf-8')))
        time.sleep(1)
        req = urllib.request.Request(
            'https://manaclinic.org/internal-appt-sms-fix',
            data=b'1',
            headers={'X-Setup-Token': token, 'User-Agent': 'Mozilla/5.0 ManaApptSms/1.0', 'Content-Type': 'application/x-www-form-urlencoded'},
            method='POST',
        )
        with urllib.request.urlopen(req, timeout=120) as response:
            body = response.read().decode('utf-8', 'replace')
        (root / 'output/access-update/deploy-appt-sms.json').write_text(body, encoding='utf-8')
        print(json.dumps(json.loads(body), ensure_ascii=True), flush=True)
    finally:
        try:
            ftp.delete('index.php')
        except Exception:
            pass
        ftp.storbinary('STOR index.php', io.BytesIO(original_index))
        print('index restored', flush=True)
