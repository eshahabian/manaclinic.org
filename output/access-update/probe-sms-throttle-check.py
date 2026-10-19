"""Check if WebOne is throttling: OTP-style vs appointment, credit, same phone."""
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
name = 'sms-thr-' + secrets.token_hex(8) + '.php'
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
$tries = [
    'otp' => 'کد ورود شما ' . $code,
    'ctrl_short' => 'مانا کلینیک: تست ' . $code,
];
$out = ['phone_tail'=>substr($phone,-4), 'credit_before'=>null, 'tries'=>[]];
$ch = curl_init('https://api.payamakapi.ir/api/v1/SMS/GetCredit');
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>20,CURLOPT_HTTPHEADER=>['Accept: application/json','X-API-KEY: '.$c['api_key']]]);
$out['credit_before'] = trim((string)curl_exec($ch));
curl_close($ch);

foreach ($tries as $label => $body) {
    $payload = json_encode(['From'=>$c['sender'],'ToNumber'=>$phone,'Content'=>$body], JSON_UNESCAPED_UNICODE);
    $ch = curl_init('https://api.payamakapi.ir/api/v1/SMS/Send');
    curl_setopt_array($ch, [
        CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>25,
        CURLOPT_HTTPHEADER=>['Content-Type: application/json','Accept: application/json','X-API-KEY: '.$c['api_key']],
        CURLOPT_POSTFIELDS=>$payload,
    ]);
    $raw = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $data = json_decode((string)$raw, true);
    $out['tries'][$label] = [
        'len'=>mb_strlen($body),
        'body'=>$body,
        'http'=>$http,
        'raw'=>is_string($raw)?mb_substr($raw,0,200):null,
        'resultCode'=>is_array($data)?($data['resultCode']??null):null,
    ];
    usleep(800000);
}
$ch = curl_init('https://api.payamakapi.ir/api/v1/SMS/GetCredit');
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>20,CURLOPT_HTTPHEADER=>['Accept: application/json','X-API-KEY: '.$c['api_key']]]);
$out['credit_after'] = trim((string)curl_exec($ch));
curl_close($ch);
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
            headers={'X-Setup-Token': token, 'User-Agent': 'ManaSmsThr/1.0', 'Content-Type': 'application/x-www-form-urlencoded'},
            method='POST',
        )
        with urllib.request.urlopen(req, timeout=120) as response:
            body = response.read().decode('utf-8', 'replace')
        (root / 'output/access-update/probe-sms-throttle-check.json').write_text(body, encoding='utf-8')
        print(json.dumps(json.loads(body), ensure_ascii=True), flush=True)
    finally:
        ftp.storbinary('STOR .htaccess', io.BytesIO(original_ht))
        try:
            ftp.delete(name)
        except Exception:
            pass
        print('cleanup ok', flush=True)
