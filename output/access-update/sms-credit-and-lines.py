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
name = 'sms-lines-' + secrets.token_hex(12) + '.php'
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
    $key = $c['api_key'];
    $endpoints = [
        'GetCredit' => ['GET', 'https://api.payamakapi.ir/api/v1/SMS/GetCredit', null],
        'GetUserLines' => ['GET', 'https://api.payamakapi.ir/api/v1/SMS/GetUserLines', null],
        'GetLines' => ['GET', 'https://api.payamakapi.ir/api/v1/SMS/GetLines', null],
        'UserLines' => ['GET', 'https://api.payamakapi.ir/api/v1/User/GetLines', null],
    ];
    $out = [];
    foreach ($endpoints as $label => [$method, $url, $body]) {
        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => ['Accept: application/json', 'X-API-KEY: ' . $key],
            CURLOPT_CUSTOMREQUEST => $method,
        ];
        if ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE);
            $opts[CURLOPT_HTTPHEADER][] = 'Content-Type: application/json';
        }
        curl_setopt_array($ch, $opts);
        $raw = curl_exec($ch);
        $out[$label] = [
            'http' => (int) curl_getinfo($ch, CURLINFO_HTTP_CODE),
            'raw' => is_string($raw) ? mb_substr($raw, 0, 600) : null,
        ];
        curl_close($ch);
    }
    // Try send with Auto from / SmartOTP
    $phone = (string) $pdo->query("SELECT phone FROM sms_outbox WHERE phone LIKE '09%' ORDER BY created_at DESC LIMIT 1")->fetchColumn();
    $sendTries = [
        'send_auto_from' => ['url' => 'https://api.payamakapi.ir/api/v1/SMS/Send', 'body' => ['From' => 'Auto', 'ToNumber' => $phone, 'Content' => 'mana auto ' . date('H:i:s')]],
        'smart_otp' => ['url' => 'https://api.payamakapi.ir/api/v1/SMS/SmartOTP', 'body' => ['OTPSender' => 'Auto', 'ToNumber' => $phone, 'Content' => 'کد ورود شما 123456']],
        'send_panel_line' => ['url' => 'https://api.payamakapi.ir/api/v1/SMS/Send', 'body' => ['From' => $c['sender'], 'ToNumber' => $phone, 'Content' => 'mana line ' . date('H:i:s'), 'IsFlash' => false]],
    ];
    foreach ($sendTries as $label => $try) {
        $ch = curl_init($try['url']);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
                'X-API-KEY: ' . $key,
            ],
            CURLOPT_POSTFIELDS => json_encode($try['body'], JSON_UNESCAPED_UNICODE),
        ]);
        $raw = curl_exec($ch);
        $out[$label] = [
            'http' => (int) curl_getinfo($ch, CURLINFO_HTTP_CODE),
            'raw' => is_string($raw) ? mb_substr($raw, 0, 400) : null,
        ];
        curl_close($ch);
        usleep(300000);
    }
    echo json_encode($out, JSON_UNESCAPED_UNICODE);
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
            (root / 'output/access-update/sms-credit-and-lines.json').write_text(body, encoding='utf-8')
            print(json.dumps(json.loads(body), ensure_ascii=True), flush=True)
    finally:
        ftp.storbinary('STOR .htaccess', io.BytesIO(original))
        ftp.delete(name)
        print('cleanup ok', flush=True)
