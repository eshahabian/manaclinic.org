"""Probe OTP payloads like Haft Rokh vs current Mana style. Does not print API secrets."""
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
name = 'otp-haft-' + secrets.token_hex(8) + '.php'
helper = r'''<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || time() > __EXPIRY__ || !hash_equals('__TOKEN__', $_SERVER['HTTP_X_SETUP_TOKEN'] ?? '')) {
    http_response_code(404);
    exit;
}
$config = require __DIR__ . '/config.php';
date_default_timezone_set($config['timezone'] ?? 'Asia/Tehran');
foreach (['db', 'helpers', 'mail', 'outreach'] as $f) {
    require_once __DIR__ . '/includes/' . $f . '.php';
}
$pdo = db_connect($config);
$c = sms_connection_config($pdo);
$key = $c['api_key'];
$from = $c['sender'];
$phone = (string) ($pdo->query("SELECT phone FROM users WHERE phone LIKE '09%' ORDER BY updated_at DESC LIMIT 1")->fetchColumn()
    ?: $pdo->query("SELECT phone FROM sms_outbox WHERE phone LIKE '09%' ORDER BY created_at DESC LIMIT 1")->fetchColumn()
    ?: '09121234567');
$code = (string) random_int(100000, 999999);
$out = [
    'sender' => $from,
    'key_prefix' => substr($key, 0, 8),
    'key_len' => strlen($key),
    'phone_tail' => substr($phone, -4),
    'credit' => null,
    'tries' => [],
    'otp_last_status' => mail_setting_get($pdo, 'sms_otp_last_status', ''),
    'otp_last_error' => mail_setting_get($pdo, 'sms_otp_last_error', ''),
];
$ch = curl_init('https://api.payamakapi.ir/api/v1/SMS/GetCredit');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 20,
    CURLOPT_HTTPHEADER => ['Accept: application/json', 'X-API-KEY: ' . $key],
]);
$creditRaw = curl_exec($ch);
$out['credit_http'] = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$out['credit'] = is_string($creditRaw) ? mb_substr(trim($creditRaw), 0, 40) : null;
curl_close($ch);

$tries = [
    'haft_text_no_flash' => [
        'url' => 'https://api.payamakapi.ir/api/v1/SMS/Send',
        'body' => ['From' => $from, 'ToNumber' => $phone, 'Content' => 'کد ورود شما ' . $code],
    ],
    'haft_text_isflash' => [
        'url' => 'https://api.payamakapi.ir/api/v1/SMS/Send',
        'body' => ['From' => $from, 'ToNumber' => $phone, 'Content' => 'کد ورود شما ' . $code, 'IsFlash' => false],
    ],
    'mana_multiline_isflash' => [
        'url' => 'https://api.payamakapi.ir/api/v1/SMS/Send',
        'body' => [
            'From' => $from,
            'ToNumber' => $phone,
            'Content' => "مانا کلینیک\nکد ورود: " . $code . "\nاعتبار: ۳ دقیقه\nاین کد را در اختیار دیگران نگذارید.",
            'IsFlash' => false,
        ],
    ],
    'smart_otp_auto' => [
        'url' => 'https://api.payamakapi.ir/api/v1/SMS/SmartOTP',
        'body' => ['OTPSender' => 'Auto', 'ToNumber' => $phone, 'Content' => 'کد ورود شما ' . $code],
    ],
    'smart_otp_line' => [
        'url' => 'https://api.payamakapi.ir/api/v1/SMS/SmartOTP',
        'body' => ['OTPSender' => $from, 'ToNumber' => $phone, 'Content' => 'کد ورود شما ' . $code],
    ],
    'provider_send_current' => null,
];

foreach ($tries as $label => $try) {
    if ($label === 'provider_send_current') {
        $send = sms_provider_send($c, $phone, 'کد ورود شما ' . $code);
        $out['tries'][$label] = $send;
        continue;
    }
    $ch = curl_init($try['url']);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
            'X-API-KEY: ' . $key,
        ],
        CURLOPT_POSTFIELDS => json_encode($try['body'], JSON_UNESCAPED_UNICODE),
    ]);
    $raw = curl_exec($ch);
    $out['tries'][$label] = [
        'http' => (int) curl_getinfo($ch, CURLINFO_HTTP_CODE),
        'raw' => is_string($raw) ? mb_substr($raw, 0, 350) : null,
    ];
    curl_close($ch);
    usleep(400000);
}
echo json_encode($out, JSON_UNESCAPED_UNICODE);
'''
helper = helper.replace('__TOKEN__', token).replace('__EXPIRY__', str(int(time.time()) + 600))

with FTP() as ftp:
    ftp.connect(cfg['host'], int(cfg.get('port', 21)), timeout=45)
    ftp.login(cfg['username'], cfg['password'])
    ftp.cwd('/public_html')
    original = io.BytesIO()
    ftp.retrbinary('RETR .htaccess', original.write)
    original = original.getvalue()
    rule = b'RewriteRule ^' + name.replace('.', r'\.').encode() + b'$ ' + name.encode() + b' [END]'
    modified = original.replace(b'RewriteEngine On', b'RewriteEngine On\n' + rule, 1)
    if modified == original:
        raise RuntimeError('htaccess insert failed')
    try:
        ftp.storbinary('STOR ' + name, io.BytesIO(helper.encode()))
        ftp.storbinary('STOR .htaccess', io.BytesIO(modified))
        req = urllib.request.Request(
            'https://manaclinic.org/' + name,
            data=b'',
            headers={'X-Setup-Token': token, 'User-Agent': 'ManaOtpProbe/1.0'},
            method='POST',
        )
        with urllib.request.urlopen(req, timeout=180) as response:
            body = response.read().decode('utf-8', 'replace')
            (root / 'output/access-update/probe-otp-haftstyle.json').write_text(body, encoding='utf-8')
            print(json.dumps(json.loads(body), ensure_ascii=True), flush=True)
    finally:
        ftp.storbinary('STOR .htaccess', io.BytesIO(original))
        try:
            ftp.delete(name)
        except Exception:
            pass
        print('cleanup ok', flush=True)
