"""Deep Mana WebOne account check: lines, credit, DB vs file key, one unique OTP."""
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
name = 'sms-deep-' + secrets.token_hex(8) + '.php'
helper = r'''<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || time() > __EXPIRY__ || !hash_equals('__TOKEN__', $_SERVER['HTTP_X_SETUP_TOKEN'] ?? '')) {
    http_response_code(404);
    exit;
}
$config = require __DIR__ . '/config.php';
date_default_timezone_set($config['timezone'] ?? 'Asia/Tehran');
foreach (['db','helpers','mail','outreach'] as $f) require_once __DIR__.'/includes/'.$f.'.php';
$pdo = db_connect($config);
$c = sms_connection_config($pdo);
$file = is_file(__DIR__.'/config.sms.php') ? (require __DIR__.'/config.sms.php') : [];
$key = (string)$c['api_key'];
$headers = ['Accept: application/json', 'X-API-KEY: '.$key];

function get_ep($url, $headers) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>25,CURLOPT_HTTPHEADER=>$headers]);
    $raw = (string)curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['http'=>$http,'raw'=>mb_substr($raw,0,500)];
}

$phone = (string)$pdo->query("SELECT phone FROM users WHERE phone LIKE '%3986' LIMIT 1")->fetchColumn();
$uniq = substr((string)time(), -5);
$content = 'کد ورود شما ' . $uniq; // unique short like Haft

$payload = json_encode(['From'=>$c['sender'],'ToNumber'=>$phone,'Content'=>$content], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
$ch = curl_init('https://api.payamakapi.ir/api/v1/SMS/Send');
curl_setopt_array($ch,[
    CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>25,
    CURLOPT_HTTPHEADER=>['Content-Type: application/json','Accept: application/json','X-API-KEY: '.$key],
    CURLOPT_POSTFIELDS=>$payload,
]);
$sendRaw = (string)curl_exec($ch);
$sendHttp = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo json_encode([
    'server_ip' => $_SERVER['SERVER_ADDR'] ?? null,
    'enabled' => $c['enabled'] ?? '',
    'sender' => $c['sender'] ?? '',
    'db_key_prefix' => substr((string)mail_setting_get($pdo,'sms_api_key',''),0,8),
    'db_key_len' => strlen((string)mail_setting_get($pdo,'sms_api_key','')),
    'file_key_prefix' => substr((string)($file['api_key']??''),0,8),
    'file_key_len' => strlen((string)($file['api_key']??'')),
    'effective_key_prefix' => substr($key,0,8),
    'effective_key_len' => strlen($key),
    'keys_match_file' => hash_equals((string)($file['api_key']??''), $key),
    'GetCredit' => get_ep('https://api.payamakapi.ir/api/v1/SMS/GetCredit', $headers),
    'GetUserLines' => get_ep('https://api.payamakapi.ir/api/v1/SMS/GetUserLines', $headers),
    'GetLines' => get_ep('https://api.payamakapi.ir/api/v1/SMS/GetLines', $headers),
    'send' => [
        'http' => $sendHttp,
        'raw' => mb_substr($sendRaw,0,300),
        'body' => $content,
        'len' => mb_strlen($content),
    ],
    'catalog_booked' => sms_template_catalog()['appointment_booked'][1] ?? null,
], JSON_UNESCAPED_UNICODE);
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
    if modified == original_ht:
        raise RuntimeError('htaccess insert failed')
    try:
        ftp.storbinary('STOR ' + name, io.BytesIO(helper.encode('utf-8')))
        ftp.storbinary('STOR .htaccess', io.BytesIO(modified))
        time.sleep(2)
        req = urllib.request.Request(
            'https://manaclinic.org/' + name,
            data=b'1',
            headers={'X-Setup-Token': token, 'User-Agent': 'ManaSmsDeep/1.0', 'Content-Type': 'application/x-www-form-urlencoded'},
            method='POST',
        )
        with urllib.request.urlopen(req, timeout=120) as response:
            body = response.read().decode('utf-8', 'replace')
        (root / 'output/access-update/probe-mana-account-deep.json').write_text(body, encoding='utf-8')
        print(json.dumps(json.loads(body), ensure_ascii=True), flush=True)
    finally:
        ftp.storbinary('STOR .htaccess', io.BytesIO(original_ht))
        try:
            ftp.delete(name)
        except Exception:
            pass
        print('cleanup ok', flush=True)
