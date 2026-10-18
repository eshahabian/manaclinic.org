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
name = 'sms-fromfmt-' + secrets.token_hex(12) + '.php'
helper = r'''<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || time() > __EXPIRY__ || !hash_equals('__TOKEN__', $_SERVER['HTTP_X_SETUP_TOKEN'] ?? '')) { http_response_code(404); exit; }
$config = require __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/mail.php';
require_once __DIR__ . '/includes/outreach.php';
$pdo = db_connect($config);
$c = sms_connection_config($pdo);
$phone = (string) $pdo->query("SELECT phone FROM sms_outbox WHERE phone LIKE '09%' ORDER BY created_at DESC LIMIT 1")->fetchColumn();
$payloads = [];
// Build raw JSON strings to control number vs string From
$payloads['from_string'] = '{"From":"9998621381","ToNumber":"'.$phone.'","Content":"fmt string '.date('s').'"}';
$payloads['from_number'] = '{"From":9998621381,"ToNumber":"'.$phone.'","Content":"fmt number '.date('s').'"}';
$payloads['from_with_zero'] = '{"From":"09998621381","ToNumber":"'.$phone.'","Content":"fmt zero '.date('s').'"}';
$results = [];
foreach ($payloads as $label => $payload) {
  $ch = curl_init('https://api.payamakapi.ir/api/v1/SMS/Send');
  curl_setopt_array($ch, [
    CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>25,
    CURLOPT_HTTPHEADER=>['Content-Type: application/json','Accept: application/json','X-API-KEY: '.$c['api_key']],
    CURLOPT_POSTFIELDS=>$payload,
  ]);
  $raw = curl_exec($ch);
  $results[$label] = ['http'=>(int)curl_getinfo($ch,CURLINFO_HTTP_CODE),'raw'=>is_string($raw)?mb_substr($raw,0,250):null];
  curl_close($ch);
  usleep(350000);
}
echo json_encode(['results'=>$results], JSON_UNESCAPED_UNICODE);
'''
helper = helper.replace('__TOKEN__', token).replace('__EXPIRY__', str(int(time.time()) + 600))
with FTP() as ftp:
    ftp.connect(cfg['host'], int(cfg.get('port', 21)), timeout=45)
    ftp.login(cfg['username'], cfg['password'])
    ftp.cwd('/public_html')
    original = io.BytesIO(); ftp.retrbinary('RETR .htaccess', original.write); original = original.getvalue()
    modified = original.replace(b'RewriteEngine On', b'RewriteEngine On\nRewriteRule ^' + name.replace('.', r'\.').encode() + b'$ ' + name.encode() + b' [END]', 1)
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
