"""Send one live appointment SMS using DB patient name and new template."""
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
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $uri = (string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
    if ($uri === '/internal-sms-greet-send' || str_ends_with($uri, '/internal-sms-greet-send')) {
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
        $u = $pdo->query("SELECT id,name,phone FROM users WHERE phone LIKE '09%' AND name <> '' ORDER BY (name LIKE '% %') DESC, updated_at DESC LIMIT 1")->fetch();
        // Prefer known Emad phone tail if present
        $emad = $pdo->query("SELECT id,name,phone FROM users WHERE phone LIKE '%3986' LIMIT 1")->fetch();
        if (is_array($emad)) { $u = $emad; }
        if (!is_array($u)) { echo json_encode(['error'=>'no user']); exit; }
        $body = sms_template_render($pdo, 'appointment_booked', [
            'patient' => (string)$u['name'],
            'doctor' => 'x',
            'datetime' => format_fa_datetime(date('Y-m-d H:i:s', time()+86400)),
            'status' => 'ok',
        ]);
        $send = sms_provider_send(sms_connection_config($pdo), (string)$u['phone'], $body);
        echo json_encode([
            'name' => $u['name'],
            'phone_tail' => substr((string)$u['phone'], -4),
            'body' => $body,
            'lines' => preg_split("/\n/u", $body),
            'send' => $send,
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
    original = io.BytesIO(); ftp.retrbinary('RETR index.php', original.write); original_index = original.getvalue()
    patched = original_index.decode('utf-8').replace('declare(strict_types=1);', 'declare(strict_types=1);'+"\n"+inject, 1)
    try:
        try: ftp.delete('index.php')
        except Exception: pass
        ftp.storbinary('STOR index.php', io.BytesIO(patched.encode('utf-8')))
        time.sleep(2)
        req = urllib.request.Request('https://manaclinic.org/internal-sms-greet-send', data=b'1', headers={'X-Setup-Token': token, 'User-Agent': 'Mozilla/5.0', 'Content-Type': 'application/x-www-form-urlencoded'}, method='POST')
        with urllib.request.urlopen(req, timeout=120) as response:
            body = response.read().decode('utf-8', 'replace')
        (root/'output/access-update/probe-sms-greeting-send.json').write_text(body, encoding='utf-8')
        print(json.dumps(json.loads(body), ensure_ascii=True), flush=True)
    finally:
        try: ftp.delete('index.php')
        except Exception: pass
        ftp.storbinary('STOR index.php', io.BytesIO(original_index))
        print('restored', flush=True)
