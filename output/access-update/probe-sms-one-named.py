"""Minimal: clear templates + one named date-only SMS to Emad."""
from pathlib import Path
from ftplib import FTP
import io
import json
import secrets
import time
import urllib.error
import urllib.request

root = Path('D:/Psychology')
cfg = json.loads((root / '.vscode/sftp.json').read_text(encoding='utf-8-sig'))
token = secrets.token_hex(32)
name = 'one-' + secrets.token_hex(6) + '.php'
helper = r'''<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || time() > __EXPIRY__ || !hash_equals('__TOKEN__', $_SERVER['HTTP_X_SETUP_TOKEN'] ?? '')) { http_response_code(404); exit; }
$f = __DIR__ . '/includes/sms_service.php';
if (function_exists('opcache_invalidate')) { @opcache_invalidate($f, true); }
$config = require __DIR__ . '/config.php';
date_default_timezone_set($config['timezone'] ?? 'Asia/Tehran');
foreach (['db','helpers','mail','outreach'] as $x) require_once __DIR__ . '/includes/' . $x . '.php';
$pdo = db_connect($config);
ensure_mail_schema($pdo);
foreach (['appointment_booked','appointment_cancelled','appointment_reminder'] as $k) mail_setting_set($pdo,'sms_template_'.$k,'');
$c = sms_connection_config($pdo);
$u = $pdo->query("SELECT name,phone FROM users WHERE phone LIKE '%3986' LIMIT 1")->fetch();
$phone = (string)$u['phone'];
$full = trim((string)$u['name']);
$first = preg_split('/\s+/u', $full)[0] ?? $full;
$appt = $pdo->query("SELECT starts_at FROM appointments a JOIN users u ON u.id=a.patient_id WHERE u.phone LIKE '%3986' ORDER BY a.updated_at DESC LIMIT 1")->fetch();
$when = format_fa_datetime((string)($appt['starts_at'] ?? date('Y-m-d H:i:s', time()+90000)));
$bodies = [
  "مانا کلینیک\n{$full} عزیز\n{$when}",
  "مانا کلینیک\n{$first} عزیز\n{$when}",
];
$out = ['credit'=>null,'tries'=>[],'winner'=>null];
$ch = curl_init('https://api.payamakapi.ir/api/v1/SMS/GetCredit');
curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>20,CURLOPT_HTTPHEADER=>['Accept: application/json','X-API-KEY: '.$c['api_key']]]);
$out['credit']=trim((string)curl_exec($ch)); curl_close($ch);
foreach ($bodies as $i => $body) {
  $send = sms_provider_send($c, $phone, $body);
  $out['tries'][] = ['len'=>mb_strlen($body),'body'=>$body,'status'=>$send['status']??null,'error'=>$send['error']??null,'id'=>$send['id']??null];
  if (($send['status']??'')==='SENT') { $out['winner']=$i; break; }
  sleep(1);
}
$out['sample_render']=sms_template_render($pdo,'appointment_booked',['patient'=>$full,'datetime'=>$when,'doctor'=>'','status'=>'']);
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
    if modified == original_ht:
        raise RuntimeError('htaccess insert failed')
    try:
        ftp.storbinary('STOR ' + name, io.BytesIO(helper.encode('utf-8')))
        ftp.storbinary('STOR .htaccess', io.BytesIO(modified))
        time.sleep(2)
        req = urllib.request.Request(
            'https://manaclinic.org/' + name,
            data=b'1',
            headers={'X-Setup-Token': token, 'User-Agent': 'Mozilla/5.0', 'Content-Type': 'application/x-www-form-urlencoded'},
            method='POST',
        )
        try:
            with urllib.request.urlopen(req, timeout=120) as response:
                body = response.read().decode('utf-8', 'replace')
        except urllib.error.HTTPError as e:
            body = e.read().decode('utf-8', 'replace')
            print('HTTP', e.code, body[:200].encode('unicode_escape').decode('ascii'), flush=True)
            raise
        (root / 'output/access-update/probe-sms-one-named.json').write_text(body, encoding='utf-8')
        print(json.dumps(json.loads(body), ensure_ascii=True), flush=True)
    finally:
        ftp.storbinary('STOR .htaccess', io.BytesIO(original_ht))
        try:
            ftp.delete(name)
        except Exception:
            pass
        print('cleanup ok', flush=True)
