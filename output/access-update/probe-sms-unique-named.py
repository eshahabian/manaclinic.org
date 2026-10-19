"""Send uniquely-worded named greeting; ensure helpers load."""
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
name = 'uq2-' + secrets.token_hex(6) + '.php'
helper = r'''<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || time() > __EXPIRY__ || !hash_equals('__TOKEN__', $_SERVER['HTTP_X_SETUP_TOKEN'] ?? '')) { http_response_code(404); exit; }
$config = require __DIR__ . '/config.php';
date_default_timezone_set($config['timezone'] ?? 'Asia/Tehran');
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/mail.php';
require_once __DIR__ . '/includes/outreach.php';
$pdo = db_connect($config);
$c = sms_connection_config($pdo);
$u = $pdo->query("SELECT name,phone FROM users WHERE phone LIKE '%3986' LIMIT 1")->fetch();
$phone = (string)$u['phone'];
$full = trim((string)$u['name']);
$first = (preg_split('/\s+/u', $full) ?: [$full])[0];
$appt = $pdo->query("SELECT starts_at FROM appointments a JOIN users u ON u.id=a.patient_id WHERE u.phone LIKE '%3986' ORDER BY a.updated_at DESC LIMIT 1")->fetch();
$when = function_exists('format_fa_datetime')
  ? format_fa_datetime((string)($appt['starts_at'] ?? date('Y-m-d H:i:s', time()+86400)))
  : '۱۴۰۵/۰۷/۲۹ ۱۴:۰۰';
$tag = substr((string)time(), -3);
$variants = [
  'zaman' => "مانا کلینیک\n{$full} عزیز\nزمان {$when}",
  'first_zaman' => "مانا کلینیک\n{$first} عزیز\nزمان {$when}",
  'std' => "مانا کلینیک\n{$full} عزیز\n{$when}",
  'tag' => "مانا کلینیک\n{$first} عزیز\n{$when} #{$tag}",
];
$out=['credit'=>null,'tries'=>[],'winner'=>null,'has_fmt'=>function_exists('format_fa_datetime')];
$ch=curl_init('https://api.payamakapi.ir/api/v1/SMS/GetCredit');
curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>20,CURLOPT_HTTPHEADER=>['Accept: application/json','X-API-KEY: '.$c['api_key']]]);
$out['credit']=trim((string)curl_exec($ch)); curl_close($ch);
foreach ($variants as $label=>$body) {
  $send = sms_provider_send($c,$phone,$body);
  $out['tries'][$label]=['len'=>mb_strlen($body),'body'=>$body,'status'=>$send['status']??null,'error'=>$send['error']??null,'id'=>$send['id']??null];
  if (($send['status']??'')==='SENT') { $out['winner']=$label; break; }
  sleep(3);
}
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
        (root / 'output/access-update/probe-sms-unique-named.json').write_text(body, encoding='utf-8')
        if not body.lstrip().startswith('{'):
            print('NON_JSON', body[:300].encode('unicode_escape').decode('ascii'), flush=True)
            raise RuntimeError('non-json')
        print(json.dumps(json.loads(body), ensure_ascii=True), flush=True)
    finally:
        ftp.storbinary('STOR .htaccess', io.BytesIO(original_ht))
        try:
            ftp.delete(name)
        except Exception:
            pass
        print('cleanup ok', flush=True)
