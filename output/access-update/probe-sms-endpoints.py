"""Compare API-key Send vs SmartOTP vs rest.payamakapi username path."""
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
name = 'sms-ep-' + secrets.token_hex(8) + '.php'
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
$phone = (string)$pdo->query("SELECT phone FROM users WHERE phone LIKE '%3986' LIMIT 1")->fetchColumn();
$code = (string)random_int(100000, 999999);
$body = 'کد ورود شما ' . $code;
$key = $c['api_key'];
$from = $c['sender'];
$out = ['sender'=>$from,'phone_tail'=>substr($phone,-4),'tries'=>[]];

$attempts = [
    'api_send' => [
        'url' => 'https://api.payamakapi.ir/api/v1/SMS/Send',
        'headers' => ['Content-Type: application/json','Accept: application/json','X-API-KEY: '.$key],
        'payload' => ['From'=>$from,'ToNumber'=>$phone,'Content'=>$body],
    ],
    'api_send_flash' => [
        'url' => 'https://api.payamakapi.ir/api/v1/SMS/Send',
        'headers' => ['Content-Type: application/json','Accept: application/json','X-API-KEY: '.$key],
        'payload' => ['From'=>$from,'ToNumber'=>$phone,'Content'=>$body,'IsFlash'=>false],
    ],
    'smart_otp_auto' => [
        'url' => 'https://api.payamakapi.ir/api/v1/SMS/SmartOTP',
        'headers' => ['Content-Type: application/json','Accept: application/json','X-API-KEY: '.$key],
        'payload' => ['OTPSender'=>'Auto','ToNumber'=>$phone,'Content'=>$body],
    ],
    'smart_otp_line' => [
        'url' => 'https://api.payamakapi.ir/api/v1/SMS/SmartOTP',
        'headers' => ['Content-Type: application/json','Accept: application/json','X-API-KEY: '.$key],
        'payload' => ['OTPSender'=>$from,'ToNumber'=>$phone,'Content'=>$body],
    ],
];

// Username/password path if available
$user = trim((string)($c['username'] ?? ''));
$pass = trim((string)($c['password'] ?? ''));
if ($user !== '' && $pass !== '') {
    $attempts['rest_user'] = [
        'url' => 'https://rest.payamakapi.ir/api/v1/SMS/Send',
        'headers' => ['Content-Type: application/json','Accept: application/json'],
        'payload' => ['UserName'=>$user,'Password'=>$pass,'From'=>$from,'To'=>$phone,'Message'=>$body],
    ];
}

foreach ($attempts as $label => $try) {
    $ch = curl_init($try['url']);
    curl_setopt_array($ch, [
        CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>25,
        CURLOPT_HTTPHEADER=>$try['headers'],
        CURLOPT_POSTFIELDS=>json_encode($try['payload'], JSON_UNESCAPED_UNICODE),
    ]);
    $raw = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $out['tries'][$label] = ['http'=>$http,'raw'=>is_string($raw)?mb_substr($raw,0,300):null];
    usleep(600000);
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
    original_ht = original.getvalue()
    rule = b'RewriteRule ^' + name.replace('.', r'\.').encode() + b'$ ' + name.encode() + b' [END]'
    modified = original_ht.replace(b'RewriteEngine On', b'RewriteEngine On\n' + rule, 1)
    try:
        ftp.storbinary('STOR ' + name, io.BytesIO(helper.encode('utf-8')))
        ftp.storbinary('STOR .htaccess', io.BytesIO(modified))
        time.sleep(1)
        req = urllib.request.Request(
            'https://manaclinic.org/' + name,
            data=b'1',
            headers={'X-Setup-Token': token, 'User-Agent': 'ManaSmsEp/1.0', 'Content-Type': 'application/x-www-form-urlencoded'},
            method='POST',
        )
        with urllib.request.urlopen(req, timeout=180) as response:
            body = response.read().decode('utf-8', 'replace')
        (root / 'output/access-update/probe-sms-endpoints.json').write_text(body, encoding='utf-8')
        print(json.dumps(json.loads(body), ensure_ascii=True), flush=True)
    finally:
        ftp.storbinary('STOR .htaccess', io.BytesIO(original_ht))
        try:
            ftp.delete(name)
        except Exception:
            pass
        print('cleanup ok', flush=True)
