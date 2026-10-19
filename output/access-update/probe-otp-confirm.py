"""Confirm long SMS vs short OTP without IsFlash. No secrets printed."""
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
name = 'otp-cnf-' + secrets.token_hex(8) + '.php'
helper = r'''<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || time() > __EXPIRY__ || !hash_equals('__TOKEN__', $_SERVER['HTTP_X_SETUP_TOKEN'] ?? '')) { http_response_code(404); exit; }
$config = require __DIR__ . '/config.php';
foreach (['db','helpers','mail','outreach'] as $f) require_once __DIR__ . '/includes/' . $f . '.php';
$pdo = db_connect($config);
$c = sms_connection_config($pdo);
$phone = (string) ($pdo->query("SELECT phone FROM users WHERE phone LIKE '09%' ORDER BY updated_at DESC LIMIT 1")->fetchColumn() ?: '09121234567');
$code = (string) random_int(100000, 999999);
$uniq = substr((string) time(), -4);
$tries = [
  'otp_short' => ['From'=>$c['sender'],'ToNumber'=>$phone,'Content'=>'کد ورود شما '.$code],
  'otp_multiline_noflash' => ['From'=>$c['sender'],'ToNumber'=>$phone,'Content'=>"مانا کلینیک\nکد ورود: $code\nاعتبار: ۳ دقیقه"],
  'appt_long' => ['From'=>$c['sender'],'ToNumber'=>$phone,'Content'=>'مانا کلینیک: نوبت شما با دکتر تست در ۱۴۰۵/۰۷/۲۷ ۱۵:۰۰ ثبت شد. '.$uniq],
];
$out = [];
foreach ($tries as $label => $body) {
  $ch = curl_init('https://api.payamakapi.ir/api/v1/SMS/Send');
  curl_setopt_array($ch, [CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>25,
    CURLOPT_HTTPHEADER=>['Content-Type: application/json','Accept: application/json','X-API-KEY: '.$c['api_key']],
    CURLOPT_POSTFIELDS=>json_encode($body, JSON_UNESCAPED_UNICODE)]);
  $raw = curl_exec($ch);
  $out[$label] = ['http'=>(int)curl_getinfo($ch,CURLINFO_HTTP_CODE),'raw'=>is_string($raw)?mb_substr($raw,0,280):null];
  curl_close($ch);
  usleep(500000);
}
echo json_encode(['phone_tail'=>substr($phone,-4),'results'=>$out], JSON_UNESCAPED_UNICODE);
'''
helper = helper.replace('__TOKEN__', token).replace('__EXPIRY__', str(int(time.time()) + 600))
with FTP() as ftp:
    ftp.connect(cfg['host'], int(cfg.get('port', 21)), timeout=45)
    ftp.login(cfg['username'], cfg['password'])
    ftp.cwd('/public_html')
    original = io.BytesIO(); ftp.retrbinary('RETR .htaccess', original.write); original = original.getvalue()
    rule = b'RewriteRule ^' + name.replace('.', r'\.').encode() + b'$ ' + name.encode() + b' [END]'
    modified = original.replace(b'RewriteEngine On', b'RewriteEngine On\n' + rule, 1)
    try:
        ftp.storbinary('STOR ' + name, io.BytesIO(helper.encode()))
        ftp.storbinary('STOR .htaccess', io.BytesIO(modified))
        req = urllib.request.Request('https://manaclinic.org/' + name, data=b'', headers={'X-Setup-Token': token}, method='POST')
        with urllib.request.urlopen(req, timeout=120) as response:
            body = response.read().decode('utf-8', 'replace')
            print(json.dumps(json.loads(body), ensure_ascii=True), flush=True)
    finally:
        ftp.storbinary('STOR .htaccess', io.BytesIO(original))
        ftp.delete(name)
        print('cleanup ok', flush=True)
