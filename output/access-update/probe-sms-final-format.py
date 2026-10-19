"""One live SMS with first-name + short date (no year)."""
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
name = 'sms-fmt-' + secrets.token_hex(8) + '.php'
helper = r'''<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || time() > __EXPIRY__ || !hash_equals('__TOKEN__', $_SERVER['HTTP_X_SETUP_TOKEN'] ?? '')) {
    http_response_code(404);
    exit;
}
$target = __DIR__ . '/includes/sms_service.php';
if (function_exists('opcache_invalidate')) { @opcache_invalidate($target, true); @opcache_invalidate(__DIR__.'/includes/helpers.php', true); }
$config = require __DIR__ . '/config.php';
date_default_timezone_set($config['timezone'] ?? 'Asia/Tehran');
foreach (['db','helpers','mail','outreach'] as $f) require_once __DIR__.'/includes/'.$f.'.php';
$pdo = db_connect($config);
ensure_mail_schema($pdo);
ensure_outreach_schema($pdo);
foreach (['appointment_booked','appointment_cancelled','appointment_reminder'] as $k) {
    mail_setting_set($pdo, 'sms_template_'.$k, '');
}
$c = sms_connection_config($pdo);
$u = $pdo->query("SELECT name,phone FROM users WHERE phone LIKE '%3986' LIMIT 1")->fetch();
$appt = $pdo->query("SELECT starts_at FROM appointments a JOIN users u ON u.id=a.patient_id WHERE u.phone LIKE '%3986' ORDER BY a.updated_at DESC LIMIT 1")->fetch();
$whenSrc = is_array($appt) ? (string)$appt['starts_at'] : date('Y-m-d H:i:s', time()+86400);
$body = sms_template_render($pdo, 'appointment_booked', [
    'patient' => (string)($u['name'] ?? ''),
    'datetime' => format_fa_sms_datetime($whenSrc),
]);
$send = sms_provider_send($c, (string)$u['phone'], $body);
$ch = curl_init('https://api.payamakapi.ir/api/v1/SMS/GetCredit');
curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>20,CURLOPT_HTTPHEADER=>['Accept: application/json','X-API-KEY: '.$c['api_key']]]);
$credit = trim((string)curl_exec($ch)); curl_close($ch);
echo json_encode([
    'credit'=>$credit,
    'body'=>$body,
    'len'=>mb_strlen($body),
    'send'=>$send,
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
            headers={'X-Setup-Token': token, 'User-Agent': 'ManaSmsFmt/1.0', 'Content-Type': 'application/x-www-form-urlencoded'},
            method='POST',
        )
        with urllib.request.urlopen(req, timeout=120) as response:
            body = response.read().decode('utf-8', 'replace')
        (root / 'output/access-update/probe-sms-final-format.json').write_text(body, encoding='utf-8')
        print(json.dumps(json.loads(body), ensure_ascii=False), flush=True)
    finally:
        ftp.storbinary('STOR .htaccess', io.BytesIO(original_ht))
        try:
            ftp.delete(name)
        except Exception:
            pass
        print('cleanup ok', flush=True)
