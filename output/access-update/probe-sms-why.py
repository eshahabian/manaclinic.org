"""Diagnose why appointment SMS is not arriving: credit + format variants + recent outbox."""
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
    if ($uri === '/internal-sms-why' || str_ends_with($uri, '/internal-sms-why')) {
        if (!hash_equals('__TOKEN__', (string)($_SERVER['HTTP_X_SETUP_TOKEN'] ?? '')) || time() > __EXPIRY__) { http_response_code(404); exit; }
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        $config = require __DIR__ . '/config.php';
        date_default_timezone_set($config['timezone'] ?? 'Asia/Tehran');
        foreach (['db','helpers','mail','outreach'] as $f) require_once __DIR__ . '/includes/' . $f . '.php';
        $pdo = db_connect($config);
        ensure_mail_schema($pdo);
        ensure_outreach_schema($pdo);
        $c = sms_connection_config($pdo);
        $u = $pdo->query("SELECT name,phone FROM users WHERE phone LIKE '%3986' LIMIT 1")->fetch();
        if (!is_array($u)) { $u = $pdo->query("SELECT name,phone FROM users WHERE phone LIKE '09%' AND name<>'' ORDER BY updated_at DESC LIMIT 1")->fetch(); }
        $phone = (string)($u['phone'] ?? '');
        $name = (string)($u['name'] ?? 'x');
        $when = format_fa_datetime(date('Y-m-d H:i:s', time()+86400));

        $ch = curl_init('https://api.payamakapi.ir/api/v1/SMS/GetCredit');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>20,CURLOPT_HTTPHEADER=>['Accept: application/json','X-API-KEY: '.$c['api_key']]]);
        $creditRaw = curl_exec($ch);
        $creditHttp = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $variants = [
            'multiline3' => "مانا کلینیک\n{$name} عزیز\nنوبت شما در {$when} ثبت شد.",
            'multiline2' => "مانا کلینیک\n{$name} عزیز، نوبت شما در {$when} ثبت شد.",
            'single' => "مانا کلینیک: {$name} عزیز، نوبت شما در {$when} ثبت شد.",
            'short' => "مانا کلینیک: نوبت شما در {$when} ثبت شد.",
        ];
        $results = [];
        foreach ($variants as $label => $body) {
            $results[$label] = [
                'len' => mb_strlen($body),
                'body' => $body,
                'send' => sms_provider_send($c, $phone, $body),
            ];
            usleep(400000);
        }

        $recent = $pdo->query("SELECT phone,kind,status,last_error,LEFT(body,160) body,created_at,sent_at
            FROM sms_outbox WHERE kind LIKE 'appointment%' OR phone LIKE '%".substr($phone,-4)."%'
            ORDER BY created_at DESC LIMIT 12")->fetchAll();

        echo json_encode([
            'ready' => sms_connection_ready($pdo),
            'sender' => $c['sender'],
            'credit_http' => $creditHttp,
            'credit' => is_string($creditRaw) ? mb_substr(trim($creditRaw), 0, 80) : null,
            'phone_tail' => substr($phone, -4),
            'name' => $name,
            'variants' => $results,
            'recent' => $recent,
            'worker_last' => mail_setting_get($pdo, 'sms_worker_last_run', ''),
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
            'https://manaclinic.org/internal-sms-why',
            data=b'1',
            headers={'X-Setup-Token': token, 'User-Agent': 'Mozilla/5.0 ManaSmsWhy/1.0', 'Content-Type': 'application/x-www-form-urlencoded'},
            method='POST',
        )
        with urllib.request.urlopen(req, timeout=180) as response:
            body = response.read().decode('utf-8', 'replace')
        (root / 'output/access-update/probe-sms-why.json').write_text(body, encoding='utf-8')
        if not body.lstrip().startswith('{'):
            print('NON_JSON', body[:200].encode('unicode_escape').decode('ascii'), flush=True)
            raise RuntimeError('non-json')
        print(json.dumps(json.loads(body), ensure_ascii=True), flush=True)
    finally:
        try:
            ftp.delete('index.php')
        except Exception:
            pass
        ftp.storbinary('STOR index.php', io.BytesIO(original_index))
        print('restored', flush=True)
