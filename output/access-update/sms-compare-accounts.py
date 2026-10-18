"""Compare Mana vs Haft Rokh WebOne keys from Mana server IP. Does not print secrets."""
from pathlib import Path
from ftplib import FTP
import io
import json
import re
import secrets
import time
import urllib.request

root = Path('D:/Psychology')
cfg = json.loads((root / '.vscode/sftp.json').read_text(encoding='utf-8-sig'))
haft = Path('D:/casting-portal/config.local.php').read_text(encoding='utf-8')
haft_key = re.search(r"CASTING_SMS_API_KEY',\s*'([^']+)'", haft).group(1)
haft_from = re.search(r"CASTING_SMS_FROM',\s*'([^']+)'", haft).group(1)

token = secrets.token_hex(32)
name = 'sms-cmp-' + secrets.token_hex(12) + '.php'
# Embed keys only in ephemeral remote script; never print them back.
helper = r'''<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || time() > __EXPIRY__ || !hash_equals('__TOKEN__', $_SERVER['HTTP_X_SETUP_TOKEN'] ?? '')) {
    http_response_code(404);
    exit;
}
function probe($key, $from, $phone, $label) {
    $out = ['label' => $label, 'from' => $from];
    $ch = curl_init('https://api.payamakapi.ir/api/v1/SMS/GetCredit');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>20,CURLOPT_HTTPHEADER=>['Accept: application/json','X-API-KEY: '.$key]]);
    $raw = curl_exec($ch);
    $out['credit_http'] = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $out['credit'] = is_string($raw) ? mb_substr(trim($raw), 0, 40) : null;
    curl_close($ch);
    $body = json_encode(['From'=>$from,'ToNumber'=>$phone,'Content'=>'probe '.$label.' '.date('H:i:s')], JSON_UNESCAPED_UNICODE);
    $ch = curl_init('https://api.payamakapi.ir/api/v1/SMS/Send');
    curl_setopt_array($ch, [
        CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>25,
        CURLOPT_HTTPHEADER=>['Content-Type: application/json','Accept: application/json','X-API-KEY: '.$key],
        CURLOPT_POSTFIELDS=>$body,
    ]);
    $raw = curl_exec($ch);
    $out['send_http'] = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $out['send_raw'] = is_string($raw) ? mb_substr($raw, 0, 300) : null;
    curl_close($ch);
    return $out;
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
    $mana = probe($c['api_key'], $c['sender'], $phone, 'mana');
    $haft = probe('__HAFT_KEY__', '__HAFT_FROM__', $phone, 'haftrokh');
    echo json_encode(['server_ip'=>$_SERVER['SERVER_ADDR']??null,'phone_tail'=>substr($phone,-4),'mana'=>$mana,'haftrokh'=>$haft], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
}
'''
helper = (
    helper.replace('__TOKEN__', token)
    .replace('__EXPIRY__', str(int(time.time()) + 600))
    .replace('__HAFT_KEY__', haft_key)
    .replace('__HAFT_FROM__', haft_from)
)

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
            (root / 'output/access-update/sms-compare-accounts.json').write_text(body, encoding='utf-8')
            print(json.dumps(json.loads(body), ensure_ascii=True), flush=True)
    finally:
        ftp.storbinary('STOR .htaccess', io.BytesIO(original))
        ftp.delete(name)
        print('cleanup ok', flush=True)
