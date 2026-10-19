"""Reuse working probe pattern; test compact greeting formats under ~50 chars."""
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
    $uri = (string)(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
    if ($uri === '/internal-sms-why2' || str_ends_with($uri, '/internal-sms-why2')) {
        if (!hash_equals('__TOKEN__', (string)($_SERVER['HTTP_X_SETUP_TOKEN'] ?? '')) || time() > __EXPIRY__) { http_response_code(404); exit; }
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        $config = require __DIR__ . '/config.php';
        date_default_timezone_set($config['timezone'] ?? 'Asia/Tehran');
        foreach (['db','helpers','mail','outreach'] as $f) require_once __DIR__ . '/includes/' . $f . '.php';
        $pdo = db_connect($config);
        $c = sms_connection_config($pdo);
        $u = $pdo->query("SELECT name,phone FROM users WHERE phone LIKE '%3986' LIMIT 1")->fetch();
        $phone = (string)($u['phone'] ?? '');
        $name = trim((string)($u['name'] ?? 'x'));
        $bits = preg_split('/\s+/u', $name) ?: [];
        $first = (string)($bits[0] ?? $name);
        $when = format_fa_datetime(date('Y-m-d H:i:s', time()+86400));
        $variants = [
            'first_3line' => "مانا کلینیک\n{$first} عزیز\nنوبت {$when}",
            'first_2line' => "مانا کلینیک\n{$first} عزیز، نوبت {$when}",
            'first_1line' => "مانا کلینیک: {$first} عزیز، نوبت {$when}",
            'full_compact' => "مانا کلینیک\n{$name}\n{$when}",
            'brand_nospace' => "ماناکلینیک\n{$first} عزیز\n{$when}",
            'ctrl' => "مانا کلینیک: نوبت {$when}",
        ];
        $results = [];
        foreach ($variants as $label => $body) {
            $results[$label] = [
                'len' => mb_strlen($body),
                'body' => $body,
                'send' => sms_provider_send($c, $phone, $body),
            ];
            usleep(450000);
        }
        echo json_encode(['phone_tail'=>substr($phone,-4),'first'=>$first,'results'=>$results], JSON_UNESCAPED_UNICODE);
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
    patched = original_index.decode('utf-8').replace(
        'declare(strict_types=1);',
        'declare(strict_types=1);\n' + inject,
        1,
    )
    try:
        try:
            ftp.delete('index.php')
        except Exception:
            pass
        ftp.storbinary('STOR index.php', io.BytesIO(patched.encode('utf-8')))
        time.sleep(2)
        req = urllib.request.Request(
            'https://manaclinic.org/internal-sms-why2',
            data=b'1',
            headers={'X-Setup-Token': token, 'User-Agent': 'Mozilla/5.0 ManaSmsWhy2/1.0', 'Content-Type': 'application/x-www-form-urlencoded'},
            method='POST',
        )
        with urllib.request.urlopen(req, timeout=180) as response:
            body = response.read().decode('utf-8', 'replace')
        (root / 'output/access-update/probe-sms-why2.json').write_text(body, encoding='utf-8')
        if not body.lstrip().startswith('{'):
            print('NON_JSON', body[:160].encode('unicode_escape').decode('ascii'), flush=True)
            raise RuntimeError('non-json')
        print(json.dumps(json.loads(body), ensure_ascii=True), flush=True)
    finally:
        try:
            ftp.delete('index.php')
        except Exception:
            pass
        ftp.storbinary('STOR index.php', io.BytesIO(original_index))
        print('restored', flush=True)
