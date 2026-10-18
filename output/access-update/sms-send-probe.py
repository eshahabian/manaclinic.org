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
name = 'sms-sendprobe-' + secrets.token_hex(12) + '.php'
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
    $payload = json_encode([
        'From' => $c['sender'],
        'ToNumber' => '09120000000',
        'Content' => 'mana clinic send probe ' . date('H:i:s'),
    ], JSON_UNESCAPED_UNICODE);
    $curl = curl_init('https://api.payamakapi.ir/api/v1/SMS/Send');
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
            'X-API-KEY: ' . $c['api_key'],
        ],
        CURLOPT_POSTFIELDS => $payload,
    ]);
    $raw = curl_exec($curl);
    $errno = curl_errno($curl);
    $err = curl_error($curl);
    $http = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    // Also try rest endpoint with username/password split of api key if dotted
    $parts = explode('.', $c['api_key'], 2);
    $legacy = null;
    if (count($parts) === 2) {
        $legacyPayload = json_encode([
            'UserName' => $parts[0],
            'Password' => $parts[1],
            'From' => $c['sender'],
            'To' => '09120000000',
            'Message' => 'mana legacy probe',
        ], JSON_UNESCAPED_UNICODE);
        $c2 = curl_init('https://rest.payamakapi.ir/api/v1/SMS/Send');
        curl_setopt_array($c2, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => $legacyPayload,
        ]);
        $raw2 = curl_exec($c2);
        $legacy = [
            'http' => (int) curl_getinfo($c2, CURLINFO_HTTP_CODE),
            'errno' => curl_errno($c2),
            'error' => curl_error($c2),
            'raw' => is_string($raw2) ? mb_substr($raw2, 0, 500) : null,
        ];
        curl_close($c2);
    }
    echo json_encode([
        'sender' => $c['sender'],
        'api_http' => $http,
        'api_errno' => $errno,
        'api_error' => $err,
        'api_raw' => is_string($raw) ? mb_substr($raw, 0, 800) : null,
        'legacy' => $legacy,
        'server_ip_hint' => $_SERVER['SERVER_ADDR'] ?? null,
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
        with urllib.request.urlopen(req, timeout=120) as response:
            body = response.read().decode('utf-8', 'replace')
            path = root / 'output/access-update/sms-send-probe.json'
            path.write_text(body, encoding='utf-8')
            print(json.dumps(json.loads(body), ensure_ascii=True), flush=True)
    finally:
        ftp.storbinary('STOR .htaccess', io.BytesIO(original))
        ftp.delete(name)
        print('cleanup ok', flush=True)
