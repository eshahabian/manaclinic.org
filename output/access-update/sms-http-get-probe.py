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
name = 'sms-httpget-' + secrets.token_hex(12) + '.php'
helper = r'''<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || time() > __EXPIRY__ || !hash_equals('__TOKEN__', $_SERVER['HTTP_X_SETUP_TOKEN'] ?? '')) {
    http_response_code(404);
    exit;
}
try {
    $config = require __DIR__ . '/config.php';
    date_default_timezone_set($config['timezone'] ?? 'Asia/Tehran');
    require_once __DIR__ . '/includes/db.php';
    require_once __DIR__ . '/includes/helpers.php';
    require_once __DIR__ . '/includes/mail.php';
    require_once __DIR__ . '/includes/outreach.php';
    $pdo = db_connect($config);
    $c = sms_connection_config($pdo);
    $phone = (string) $pdo->query("SELECT phone FROM sms_outbox WHERE phone LIKE '09%' ORDER BY created_at DESC LIMIT 1")->fetchColumn();
    $parts = explode('.', $c['api_key'], 2);
    $user = $parts[0] ?? '';
    $pass = $parts[1] ?? '';
    $text = 'مانا تست HTTP ' . date('H:i:s');
    $qs = http_build_query([
        'username' => $user,
        'password' => $pass,
        'from' => $c['sender'],
        'to' => $phone,
        'text' => $text,
    ]);
    $url = 'https://webone-sms.ir/SMSInOutBox/SendSms?' . $qs;
    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 25,
    ]);
    $raw = curl_exec($curl);
    $http = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $err = curl_error($curl);
    curl_close($curl);

    // REST again with IsFlash true/false/0 and exact previous working text
    $rest = [];
    foreach ([
        'flash_false' => ['From' => $c['sender'], 'ToNumber' => $phone, 'Content' => 'تست مانا ' . date('s'), 'IsFlash' => false],
        'flash_0' => ['From' => $c['sender'], 'ToNumber' => $phone, 'Content' => 'تست مانا0 ' . date('s'), 'IsFlash' => 0],
        'no_flash' => ['From' => $c['sender'], 'ToNumber' => $phone, 'Content' => 'تست ماناN ' . date('s')],
        'isflash_lower' => ['From' => $c['sender'], 'ToNumber' => $phone, 'Content' => 'تست ماناL ' . date('s'), 'isFlash' => false],
    ] as $label => $body) {
        $ch = curl_init('https://api.payamakapi.ir/api/v1/SMS/Send');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
                'X-API-KEY: ' . $c['api_key'],
            ],
            CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
        ]);
        $r = curl_exec($ch);
        $rest[$label] = [
            'http' => (int) curl_getinfo($ch, CURLINFO_HTTP_CODE),
            'raw' => is_string($r) ? mb_substr($r, 0, 250) : null,
        ];
        curl_close($ch);
        usleep(400000);
    }

    echo json_encode([
        'phone_tail' => substr($phone, -4),
        'http_get' => ['http' => $http, 'error' => $err, 'raw' => is_string($raw) ? mb_substr($raw, 0, 300) : null],
        'rest' => $rest,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
'''
helper = helper.replace('__TOKEN__', token).replace('__EXPIRY__', str(int(time.time()) + 600))

with FTP() as ftp:
    ftp.connect(cfg['host'], int(cfg.get('port', 21)), timeout=45)
    ftp.login(cfg['username'], cfg['password'])
    ftp.cwd('/public_html')
    original = io.BytesIO()
    ftp.retrbinary('RETR .htaccess', original.write)
    original = original.getvalue()
    modified = original.replace(
        b'RewriteEngine On',
        b'RewriteEngine On\nRewriteRule ^' + name.replace('.', r'\.').encode() + b'$ ' + name.encode() + b' [END]',
        1,
    )
    if modified == original:
        raise RuntimeError('htaccess insert failed')
    try:
        ftp.storbinary('STOR ' + name, io.BytesIO(helper.encode()))
        ftp.storbinary('STOR .htaccess', io.BytesIO(modified))
        req = urllib.request.Request(
            'https://manaclinic.org/' + name,
            data=b'',
            headers={'X-Setup-Token': token},
            method='POST',
        )
        with urllib.request.urlopen(req, timeout=180) as response:
            body = response.read().decode('utf-8', 'replace')
            (root / 'output/access-update/sms-http-get-probe.json').write_text(body, encoding='utf-8')
            print(json.dumps(json.loads(body), ensure_ascii=True), flush=True)
    finally:
        ftp.storbinary('STOR .htaccess', io.BytesIO(original))
        ftp.delete(name)
        print('cleanup ok', flush=True)
