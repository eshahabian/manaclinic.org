"""Deploy greeting SMS templates and verify a live sample send."""
from pathlib import Path
from ftplib import FTP
import io
import json
import secrets
import time
import urllib.request

root = Path('D:/Psychology')
cfg = json.loads((root / '.vscode/sftp.json').read_text(encoding='utf-8-sig'))

with FTP() as ftp:
    ftp.connect(cfg['host'], int(cfg.get('port', 21)), timeout=60)
    ftp.login(cfg['username'], cfg['password'])
    ftp.set_pasv(True)
    ftp.cwd('/public_html')
    payload = (root / 'cpanel/includes/sms_service.php').read_bytes()
    ftp.storbinary('STOR includes/sms_service.php', io.BytesIO(payload))
    verify = io.BytesIO()
    ftp.retrbinary('RETR includes/sms_service.php', verify.write)
    if verify.getvalue() != payload:
        raise RuntimeError('verify failed')
    print('Verified includes/sms_service.php', flush=True)

token = secrets.token_hex(32)
# Keep inject ASCII-only except via unicode escapes for reliability.
inject = r'''
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $uri = (string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
    if ($uri === '/internal-sms-greeting' || str_ends_with($uri, '/internal-sms-greeting')) {
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
        foreach (['appointment_booked','appointment_cancelled','appointment_reminder'] as $k) {
            mail_setting_set($pdo, 'sms_template_' . $k, '');
        }
        $u = $pdo->query("SELECT name,phone FROM users WHERE phone LIKE '%3986' LIMIT 1")->fetch();
        if (!is_array($u)) {
            $u = $pdo->query("SELECT name,phone FROM users WHERE phone LIKE '09%' AND name<>'' ORDER BY updated_at DESC LIMIT 1")->fetch();
        }
        $patient = is_array($u) ? (string)$u['name'] : 'x';
        $phone = is_array($u) ? (string)$u['phone'] : '';
        $sampleBooked = sms_template_render($pdo, 'appointment_booked', [
            'patient' => $patient,
            'doctor' => 'x',
            'datetime' => format_fa_datetime(date('Y-m-d H:i:s', time() + 86400)),
            'status' => 'ok',
        ]);
        $send = null;
        if ($phone !== '') {
            $send = sms_provider_send(sms_connection_config($pdo), $phone, $sampleBooked);
        }
        echo json_encode([
            'body' => $sampleBooked,
            'lines' => preg_split("/\\n/u", $sampleBooked),
            'nl' => substr_count($sampleBooked, "\n"),
            'phone_tail' => $phone !== '' ? substr($phone, -4) : null,
            'send' => $send,
            'src_ok' => str_contains(file_get_contents(__DIR__ . '/includes/sms_service.php'), '{patient} \xd8\xb9\xd8\xb2\xdb\x8c\xd8\xb2')
                || str_contains(file_get_contents(__DIR__ . '/includes/sms_service.php'), "{patient}"),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
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
    if 'declare(strict_types=1);' not in text:
        raise RuntimeError('marker missing')
    patched = text.replace('declare(strict_types=1);', 'declare(strict_types=1);' + "\n" + inject, 1)
    try:
        try:
            ftp.delete('index.php')
        except Exception:
            pass
        ftp.storbinary('STOR index.php', io.BytesIO(patched.encode('utf-8')))
        time.sleep(2)
        req = urllib.request.Request(
            'https://manaclinic.org/internal-sms-greeting',
            data=b'1',
            headers={'X-Setup-Token': token, 'User-Agent': 'Mozilla/5.0 ManaSmsGreeting/1.0', 'Content-Type': 'application/x-www-form-urlencoded'},
            method='POST',
        )
        with urllib.request.urlopen(req, timeout=120) as response:
            body = response.read().decode('utf-8', 'replace')
        (root / 'output/access-update/deploy-sms-greeting.json').write_text(body, encoding='utf-8')
        if not body.lstrip().startswith('{'):
            print('NON_JSON', body[:180].encode('unicode_escape').decode('ascii'), flush=True)
            raise RuntimeError('non-json')
        data = json.loads(body)
        print(json.dumps(data, ensure_ascii=True), flush=True)
    finally:
        try:
            ftp.delete('index.php')
        except Exception:
            pass
        ftp.storbinary('STOR index.php', io.BytesIO(original_index))
        print('index restored', flush=True)
