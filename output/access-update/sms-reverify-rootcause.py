"""Re-verify Mana SMS root cause from live host. Does not print API secrets."""
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
name = 'sms-rv-' + secrets.token_hex(12) + '.php'
helper = r'''<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || time() > __EXPIRY__ || !hash_equals('__TOKEN__', $_SERVER['HTTP_X_SETUP_TOKEN'] ?? '')) {
    http_response_code(404);
    exit;
}
try {
    $config = require __DIR__ . '/config.php';
    require_once __DIR__ . '/includes/db.php';
    require_once __DIR__ . '/includes/helpers.php';
    require_once __DIR__ . '/includes/mail.php';
    require_once __DIR__ . '/includes/outreach.php';
    $pdo = db_connect($config);
    $c = sms_connection_config($pdo);
    $phone = (string) $pdo->query("SELECT phone FROM sms_outbox WHERE phone LIKE '09%' ORDER BY created_at DESC LIMIT 1")->fetchColumn();
    $uniq = 'mana rv ' . date('His') . ' ' . substr(bin2hex(random_bytes(2)), 0, 4);

    $creditCh = curl_init('https://api.payamakapi.ir/api/v1/SMS/GetCredit');
    curl_setopt_array($creditCh, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => ['Accept: application/json', 'X-API-KEY: ' . $c['api_key']],
    ]);
    $creditRaw = curl_exec($creditCh);
    $creditHttp = (int) curl_getinfo($creditCh, CURLINFO_HTTP_CODE);
    curl_close($creditCh);

    $payload = json_encode([
        'From' => (string) $c['sender'],
        'ToNumber' => $phone,
        'Content' => $uniq,
        'IsFlash' => false,
    ], JSON_UNESCAPED_UNICODE);
    $sendCh = curl_init('https://api.payamakapi.ir/api/v1/SMS/Send');
    curl_setopt_array($sendCh, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
            'X-API-KEY: ' . $c['api_key'],
        ],
        CURLOPT_POSTFIELDS => $payload,
    ]);
    $sendRaw = curl_exec($sendCh);
    $sendHttp = (int) curl_getinfo($sendCh, CURLINFO_HTTP_CODE);
    curl_close($sendCh);

    $via = sms_provider_send($c, $phone, $uniq . ' via');

    $ipInvalid = is_string($sendRaw) && stripos($sendRaw, 'IP') !== false;
    echo json_encode([
        'server_ip' => $_SERVER['SERVER_ADDR'] ?? null,
        'sender' => $c['sender'],
        'phone_tail' => substr($phone, -4),
        'credit_http' => $creditHttp,
        'credit' => is_string($creditRaw) ? mb_substr(trim($creditRaw), 0, 40) : null,
        'send_http' => $sendHttp,
        'send_raw' => is_string($sendRaw) ? mb_substr($sendRaw, 0, 300) : null,
        'send_looks_like_ip_block' => $ipInvalid,
        'via_provider_status' => $via['status'] ?? null,
        'via_provider_error' => isset($via['error']) ? mb_substr((string) $via['error'], 0, 220) : null,
        'verdict_hint' => ($creditHttp === 200 && !$ipInvalid)
            ? 'mana_ip_ok_send_issue_elsewhere'
            : (($ipInvalid) ? 'ip_block' : 'other'),
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
'''
helper = helper.replace('__TOKEN__', token).replace('__EXPIRY__', str(int(time.time()) + 600))

out = root / 'output/access-update/sms-reverify-rootcause.json'
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
            out.write_text(body, encoding='utf-8')
            print(json.dumps(json.loads(body), ensure_ascii=True), flush=True)
    finally:
        ftp.storbinary('STOR .htaccess', io.BytesIO(original))
        ftp.delete(name)
        print('cleanup ok', flush=True)
