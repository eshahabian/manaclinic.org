from pathlib import Path
from ftplib import FTP
import io
import json
import secrets
time_mod = __import__('time')
import urllib.request

root = Path('D:/Psychology')
cfg = json.loads((root / '.vscode/sftp.json').read_text(encoding='utf-8-sig'))
token = secrets.token_hex(32)
name = 'sms-var-' + secrets.token_hex(12) + '.php'
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
    $phoneRow = $pdo->query("SELECT phone FROM sms_outbox WHERE phone LIKE '09%' ORDER BY created_at DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $phone = $phoneRow['phone'] ?? '09121234567';
    $variants = [
        'toNumber_content' => ['From' => $c['sender'], 'ToNumber' => $phone, 'Content' => 'تست مانا'],
        'to_message' => ['From' => $c['sender'], 'To' => $phone, 'Message' => 'تست مانا'],
        'toNumbers_array' => ['From' => $c['sender'], 'ToNumbers' => [$phone], 'Content' => 'تست مانا'],
        'isFlash_false' => ['From' => $c['sender'], 'ToNumber' => $phone, 'Content' => 'تست مانا', 'IsFlash' => false],
        'haftrokh_line' => ['From' => '9998624065', 'ToNumber' => $phone, 'Content' => 'تست خط هفت رخ'],
        'ascii_only' => ['From' => $c['sender'], 'ToNumber' => $phone, 'Content' => 'mana test'],
    ];
    $results = [];
    foreach ($variants as $label => $body) {
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
            CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
        ]);
        $raw = curl_exec($curl);
        $results[$label] = [
            'http' => (int) curl_getinfo($curl, CURLINFO_HTTP_CODE),
            'raw' => is_string($raw) ? mb_substr($raw, 0, 300) : null,
        ];
        curl_close($curl);
    }
    echo json_encode([
        'phone_used_tail' => substr($phone, -4),
        'sender' => $c['sender'],
        'server_ip' => $_SERVER['SERVER_ADDR'] ?? null,
        'results' => $results,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
'''
helper = helper.replace('__TOKEN__', token).replace('__EXPIRY__', str(int(time_mod.time()) + 600))

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
            (root / 'output/access-update/sms-send-variants.json').write_text(body, encoding='utf-8')
            print(json.dumps(json.loads(body), ensure_ascii=True), flush=True)
    finally:
        ftp.storbinary('STOR .htaccess', io.BytesIO(original))
        ftp.delete(name)
        print('cleanup ok', flush=True)
