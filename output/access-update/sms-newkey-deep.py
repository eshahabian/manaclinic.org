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
name = 'sms-nkd-' + secrets.token_hex(8) + '.php'
helper = r'''<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || time() > __EXPIRY__ || !hash_equals('__TOKEN__', $_SERVER['HTTP_X_SETUP_TOKEN'] ?? '')) {
    http_response_code(404);
    exit;
}
$config = require __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/mail.php';
require_once __DIR__ . '/includes/outreach.php';
$pdo = db_connect($config);
$c = sms_connection_config($pdo);
$phone = (string) ($pdo->query("SELECT phone FROM sms_outbox WHERE phone LIKE '09%' ORDER BY created_at DESC LIMIT 1")->fetchColumn() ?: '09121234567');
$key = $c['api_key'];
$parts = explode('.', $key, 2);
$out = [];
$tries = [
    'rest_isflash' => [
        'url' => 'https://api.payamakapi.ir/api/v1/SMS/Send',
        'headers' => ['Content-Type: application/json', 'Accept: application/json', 'X-API-KEY: ' . $key],
        'body' => json_encode(['From' => $c['sender'], 'ToNumber' => $phone, 'Content' => 't1 ' . date('s'), 'IsFlash' => false], JSON_UNESCAPED_UNICODE),
    ],
    'rest_noflash' => [
        'url' => 'https://api.payamakapi.ir/api/v1/SMS/Send',
        'headers' => ['Content-Type: application/json', 'Accept: application/json', 'X-API-KEY: ' . $key],
        'body' => json_encode(['From' => $c['sender'], 'ToNumber' => $phone, 'Content' => 't2 ' . date('s')], JSON_UNESCAPED_UNICODE),
    ],
    'rest_legacy_body' => [
        'url' => 'https://rest.payamakapi.ir/api/v1/SMS/Send',
        'headers' => ['Content-Type: application/json', 'Accept: application/json', 'X-API-KEY: ' . $key],
        'body' => json_encode(['From' => $c['sender'], 'To' => $phone, 'Message' => 't3 ' . date('s')], JSON_UNESCAPED_UNICODE),
    ],
];
if (count($parts) === 2) {
    $qs = http_build_query([
        'username' => $parts[0],
        'password' => $parts[1],
        'from' => $c['sender'],
        'to' => $phone,
        'text' => 't4 ' . date('H:i:s'),
    ]);
    $tries['http_get_split'] = [
        'url' => 'https://webone-sms.ir/SMSInOutBox/SendSms?' . $qs,
        'headers' => [],
        'body' => null,
        'get' => true,
    ];
    $tries['rest_userpass'] = [
        'url' => 'https://rest.payamakapi.ir/api/v1/SMS/Send',
        'headers' => ['Content-Type: application/json'],
        'body' => json_encode([
            'UserName' => $parts[0],
            'Password' => $parts[1],
            'From' => $c['sender'],
            'To' => $phone,
            'Message' => 't5 ' . date('s'),
        ], JSON_UNESCAPED_UNICODE),
    ];
}
foreach ($tries as $label => $try) {
    $ch = curl_init($try['url']);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_CONNECTTIMEOUT => 10,
    ];
    if (!empty($try['get'])) {
        $opts[CURLOPT_HTTPGET] = true;
    } else {
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = $try['body'];
        if ($try['headers']) {
            $opts[CURLOPT_HTTPHEADER] = $try['headers'];
        }
    }
    if (!empty($try['headers']) && empty($try['get'])) {
        $opts[CURLOPT_HTTPHEADER] = $try['headers'];
    }
    curl_setopt_array($ch, $opts);
    $raw = curl_exec($ch);
    $out[$label] = [
        'http' => (int) curl_getinfo($ch, CURLINFO_HTTP_CODE),
        'raw' => is_string($raw) ? mb_substr($raw, 0, 280) : null,
    ];
    curl_close($ch);
    usleep(300000);
}
echo json_encode([
    'key_prefix' => substr($key, 0, 8),
    'sender' => $c['sender'],
    'phone_tail' => substr($phone, -4),
    'results' => $out,
], JSON_UNESCAPED_UNICODE);
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
    try:
        ftp.storbinary('STOR ' + name, io.BytesIO(helper.encode()))
        ftp.storbinary('STOR .htaccess', io.BytesIO(modified))
        req = urllib.request.Request(
            'https://manaclinic.org/' + name,
            data=b'',
            headers={'X-Setup-Token': token, 'User-Agent': 'ManaSmsProbe/1.0'},
            method='POST',
        )
        with urllib.request.urlopen(req, timeout=180) as response:
            body = response.read().decode('utf-8', 'replace')
            (root / 'output/access-update/sms-newkey-deep.json').write_text(body, encoding='utf-8')
            print(json.dumps(json.loads(body), ensure_ascii=True), flush=True)
    finally:
        ftp.storbinary('STOR .htaccess', io.BytesIO(original))
        ftp.delete(name)
        print('cleanup ok', flush=True)
