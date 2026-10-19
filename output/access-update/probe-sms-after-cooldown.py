"""After cooldown: control (no name) vs named date-only — one each."""
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
name = 'cd-' + secrets.token_hex(6) + '.php'
helper = r'''<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || time() > __EXPIRY__ || !hash_equals('__TOKEN__', $_SERVER['HTTP_X_SETUP_TOKEN'] ?? '')) { http_response_code(404); exit; }
$config = require __DIR__ . '/config.php';
date_default_timezone_set($config['timezone'] ?? 'Asia/Tehran');
foreach (['db','helpers','mail','outreach'] as $x) require_once __DIR__ . '/includes/' . $x . '.php';
$pdo = db_connect($config);
$c = sms_connection_config($pdo);
$u = $pdo->query("SELECT name,phone FROM users WHERE phone LIKE '%3986' LIMIT 1")->fetch();
$phone = (string)$u['phone'];
$full = trim((string)$u['name']);
$first = preg_split('/\s+/u', $full)[0] ?? $full;
$nonce = substr((string)time(), -4);
$when = format_fa_datetime(date('Y-m-d H:i:s', time()+87000));
$variants = [
  'ctrl_noname' => "مانا کلینیک: نوبت شما در {$when} ثبت شد.",
  'named_3line' => "مانا کلینیک\n{$full} عزیز\n{$when}",
  'named_first' => "مانا کلینیک\n{$first} عزیز\n{$when}",
  'named_1line' => "مانا کلینیک: {$first} عزیز، {$when}",
  'unique_short' => "مانا کلینیک تست {$nonce}",
];
$out = ['credit'=>null,'results'=>[]];
$ch = curl_init('https://api.payamakapi.ir/api/v1/SMS/GetCredit');
curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>20,CURLOPT_HTTPHEADER=>['Accept: application/json','X-API-KEY: '.$c['api_key']]]);
$out['credit']=trim((string)curl_exec($ch)); curl_close($ch);
foreach ($variants as $label=>$body) {
  $payload = json_encode(['From'=>$c['sender'],'ToNumber'=>$phone,'Content'=>$body], JSON_UNESCAPED_UNICODE);
  $ch = curl_init('https://api.payamakapi.ir/api/v1/SMS/Send');
  curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>25,CURLOPT_HTTPHEADER=>['Content-Type: application/json','Accept: application/json','X-API-KEY: '.$c['api_key']],CURLOPT_POSTFIELDS=>$payload]);
  $raw = curl_exec($ch); $http=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
  $data = json_decode((string)$raw, true);
  $out['results'][$label] = [
    'len'=>mb_strlen($body),'body'=>$body,'http'=>$http,
    'resultCode'=>is_array($data)?($data['resultCode']??null):null,
    'succeeded'=>is_array($data)?($data['succeeded']??null):null,
    'refId'=>is_array($data)?($data['refId']??null):null,
  ];
  if (is_array($data) && (($data['resultCode']??null)===0 || ($data['succeeded']??false)===true)) {
    $out['winner']=$label;
    break; // stop on first success to avoid throttle
  }
  sleep(2);
}
echo json_encode($out, JSON_UNESCAPED_UNICODE);
'''
helper = helper.replace('__TOKEN__', token).replace('__EXPIRY__', str(int(time.time()) + 600))

print('cooling 180s...', flush=True)
time.sleep(180)

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
        time.sleep(2)
        req = urllib.request.Request(
            'https://manaclinic.org/' + name,
            data=b'1',
            headers={'X-Setup-Token': token, 'User-Agent': 'Mozilla/5.0', 'Content-Type': 'application/x-www-form-urlencoded'},
            method='POST',
        )
        with urllib.request.urlopen(req, timeout=180) as response:
            body = response.read().decode('utf-8', 'replace')
        (root / 'output/access-update/probe-sms-after-cooldown.json').write_text(body, encoding='utf-8')
        print(json.dumps(json.loads(body), ensure_ascii=True), flush=True)
    finally:
        ftp.storbinary('STOR .htaccess', io.BytesIO(original_ht))
        try:
            ftp.delete(name)
        except Exception:
            pass
        print('cleanup ok', flush=True)
