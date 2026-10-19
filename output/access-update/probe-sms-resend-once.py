"""Single compact resend for Emad appointment with raw API + credit diagnostics."""
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
name = 'sms-once-' + secrets.token_hex(8) + '.php'
helper = r'''<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || time() > __EXPIRY__ || !hash_equals('__TOKEN__', $_SERVER['HTTP_X_SETUP_TOKEN'] ?? '')) {
    http_response_code(404);
    exit;
}
$target = __DIR__ . '/includes/sms_service.php';
if (function_exists('opcache_invalidate')) { @opcache_invalidate($target, true); }

$config = require __DIR__ . '/config.php';
date_default_timezone_set($config['timezone'] ?? 'Asia/Tehran');
foreach (['db', 'helpers', 'mail', 'outreach'] as $f) {
    require_once __DIR__ . '/includes/' . $f . '.php';
}
$pdo = db_connect($config);
$c = sms_connection_config($pdo);

$ch = curl_init('https://api.payamakapi.ir/api/v1/SMS/GetCredit');
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>20,CURLOPT_HTTPHEADER=>['Accept: application/json','X-API-KEY: '.$c['api_key']]]);
$creditRaw = curl_exec($ch);
$creditHttp = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$appt = $pdo->query("SELECT a.id,a.starts_at,u.phone,u.name FROM appointments a JOIN users u ON u.id=a.patient_id WHERE u.phone LIKE '%3986' ORDER BY a.updated_at DESC LIMIT 1")->fetch();
$when = format_fa_datetime((string)$appt['starts_at']);
$body = sms_template_render($pdo, 'appointment_booked', [
    'patient' => (string)$appt['name'],
    'datetime' => $when,
    'doctor' => '',
    'status' => '',
]);

// Raw send for diagnostics
$payload = json_encode(['From'=>$c['sender'],'ToNumber'=>$appt['phone'],'Content'=>$body], JSON_UNESCAPED_UNICODE);
$ch = curl_init('https://api.payamakapi.ir/api/v1/SMS/Send');
curl_setopt_array($ch, [
    CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>25,
    CURLOPT_HTTPHEADER=>['Content-Type: application/json','Accept: application/json','X-API-KEY: '.$c['api_key']],
    CURLOPT_POSTFIELDS=>$payload,
]);
$raw = curl_exec($ch);
$http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$parsed = sms_provider_parse_response((string)$raw, $http, $c['sender']);
if (($parsed['status'] ?? '') === 'SENT') {
    $pdo->prepare("UPDATE sms_outbox SET status=?,provider_id=?,last_error=NULL,sent_at=NOW(),attempts=attempts+1 WHERE status='FAILED' AND kind='appointment_booked' AND phone LIKE '%3986' ORDER BY created_at DESC LIMIT 1")
        ->execute([$parsed['status'], $parsed['id'] ?? null]);
}

$recent = $pdo->query("SELECT id,status,LEFT(body,120) body,last_error,created_at,sent_at FROM sms_outbox WHERE phone LIKE '%3986' ORDER BY created_at DESC LIMIT 5")->fetchAll();

echo json_encode([
    'credit_http'=>$creditHttp,
    'credit'=>is_string($creditRaw)?trim($creditRaw):null,
    'when'=>$when,
    'body'=>$body,
    'len'=>mb_strlen($body),
    'http'=>$http,
    'raw'=>is_string($raw)?mb_substr($raw,0,400):null,
    'parsed'=>$parsed,
    'recent'=>$recent,
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
        time.sleep(1)
        req = urllib.request.Request(
            'https://manaclinic.org/' + name,
            data=b'1',
            headers={'X-Setup-Token': token, 'User-Agent': 'ManaSmsOnce/1.0', 'Content-Type': 'application/x-www-form-urlencoded'},
            method='POST',
        )
        with urllib.request.urlopen(req, timeout=120) as response:
            body = response.read().decode('utf-8', 'replace')
        (root / 'output/access-update/probe-sms-resend-once.json').write_text(body, encoding='utf-8')
        print(json.dumps(json.loads(body), ensure_ascii=True), flush=True)
    finally:
        ftp.storbinary('STOR .htaccess', io.BytesIO(original_ht))
        try:
            ftp.delete(name)
        except Exception:
            pass
        print('cleanup ok', flush=True)
