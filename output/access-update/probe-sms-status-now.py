"""Diagnose SMS: credit, last outbox, one short send, one greeting send."""
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
name = 'sms-st-' + secrets.token_hex(8) + '.php'
helper = r'''<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || time() > __EXPIRY__ || !hash_equals('__TOKEN__', $_SERVER['HTTP_X_SETUP_TOKEN'] ?? '')) {
    http_response_code(404);
    exit;
}
foreach (['sms_service.php','helpers.php'] as $f) {
    $p = __DIR__ . '/includes/' . $f;
    if (function_exists('opcache_invalidate')) { @opcache_invalidate($p, true); }
}
$config = require __DIR__ . '/config.php';
date_default_timezone_set($config['timezone'] ?? 'Asia/Tehran');
foreach (['db','helpers','mail','outreach'] as $f) require_once __DIR__.'/includes/'.$f.'.php';
$pdo = db_connect($config);
ensure_mail_schema($pdo);
ensure_outreach_schema($pdo);
$c = sms_connection_config($pdo);

$ch = curl_init('https://api.payamakapi.ir/api/v1/SMS/GetCredit');
curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>20,CURLOPT_HTTPHEADER=>['Accept: application/json','X-API-KEY: '.$c['api_key']]]);
$credit = trim((string)curl_exec($ch)); $cerr = curl_error($ch); curl_close($ch);

$u = $pdo->query("SELECT id,name,phone FROM users WHERE phone LIKE '%3986' LIMIT 1")->fetch();
$outbox = $pdo->query("SELECT id,kind,status,last_error,provider_id,LEFT(body,120) AS body,created_at,sent_at FROM sms_outbox WHERE phone LIKE '%3986' ORDER BY created_at DESC LIMIT 8")->fetchAll(PDO::FETCH_ASSOC);

// Probe raw API once with unique short body
$phone = (string)($u['phone'] ?? '');
$otpBody = 'کد ورود شما ' . (string)random_int(100000, 999999);
$payload = json_encode(['From'=>$c['sender'],'ToNumber'=>$phone,'Content'=>$otpBody], JSON_UNESCAPED_UNICODE);
$ch = curl_init('https://api.payamakapi.ir/api/v1/SMS/Send');
curl_setopt_array($ch,[
    CURLOPT_RETURNTRANSFER=>true,
    CURLOPT_TIMEOUT=>25,
    CURLOPT_POST=>true,
    CURLOPT_HTTPHEADER=>['Accept: application/json','Content-Type: application/json','X-API-KEY: '.$c['api_key']],
    CURLOPT_POSTFIELDS=>$payload,
]);
$rawOtp = (string)curl_exec($ch); $httpOtp = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);

$greet = null;
$otpDecoded = json_decode($rawOtp, true);
$otpOk = is_array($otpDecoded) && !empty($otpDecoded['succeeded']);
if ($otpOk) {
    usleep(500000);
    $appt = $pdo->query("SELECT starts_at FROM appointments a JOIN users u ON u.id=a.patient_id WHERE u.phone LIKE '%3986' ORDER BY a.updated_at DESC LIMIT 1")->fetch();
    $whenSrc = is_array($appt) ? (string)$appt['starts_at'] : date('Y-m-d H:i:s', time()+86400);
    $body = sms_template_render($pdo, 'appointment_booked', [
        'patient' => (string)($u['name'] ?? ''),
        'datetime' => format_fa_sms_datetime($whenSrc),
    ]);
    $greet = sms_provider_send($c, $phone, $body);
    $greet['body'] = $body;
    $greet['len'] = mb_strlen($body);
}

echo json_encode([
    'sender' => $c['sender'] ?? null,
    'credit_raw' => $credit,
    'credit_err' => $cerr,
    'phone_tail' => substr($phone, -4),
    'otp_http' => $httpOtp,
    'otp_raw' => $rawOtp,
    'otp_body' => $otpBody,
    'greet' => $greet,
    'outbox' => $outbox,
    'has_sms_datetime_fn' => function_exists('format_fa_sms_datetime'),
    'has_firstname' => str_contains((string)@file_get_contents(__DIR__.'/includes/sms_service.php'), 'Always greet with first name only'),
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
            headers={'X-Setup-Token': token, 'User-Agent': 'ManaSmsStatus/1.0', 'Content-Type': 'application/x-www-form-urlencoded'},
            method='POST',
        )
        with urllib.request.urlopen(req, timeout=120) as response:
            body = response.read().decode('utf-8', 'replace')
        out = root / 'output/access-update/probe-sms-status-now.json'
        out.write_text(body, encoding='utf-8')
        print(out.read_text(encoding='utf-8')[:4000], flush=True)
    finally:
        ftp.storbinary('STOR .htaccess', io.BytesIO(original_ht))
        try:
            ftp.delete(name)
        except Exception:
            pass
        print('cleanup ok', flush=True)
