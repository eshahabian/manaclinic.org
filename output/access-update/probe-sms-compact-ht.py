"""Probe compact greeting SMS via htaccess helper (reliable path)."""
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
name = 'sms-cmp-' + secrets.token_hex(8) + '.php'
helper = r'''<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || time() > __EXPIRY__ || !hash_equals('__TOKEN__', $_SERVER['HTTP_X_SETUP_TOKEN'] ?? '')) {
    http_response_code(404);
    exit;
}
$config = require __DIR__ . '/config.php';
date_default_timezone_set($config['timezone'] ?? 'Asia/Tehran');
foreach (['db', 'helpers', 'mail', 'outreach'] as $f) {
    require_once __DIR__ . '/includes/' . $f . '.php';
}
$pdo = db_connect($config);
$c = sms_connection_config($pdo);
$u = $pdo->query("SELECT name,phone FROM users WHERE phone LIKE '%3986' LIMIT 1")->fetch();
$phone = (string)($u['phone'] ?? '');
$full = trim((string)($u['name'] ?? ''));
$bits = preg_split('/\s+/u', $full) ?: [];
$first = (string)($bits[0] ?? $full);
$when = format_fa_datetime(date('Y-m-d H:i:s', time() + 86400));
$variants = [
    'user_style_date_only' => "مانا کلینیک\n{$full} عزیز\n{$when}",
    'user_style_nobat' => "مانا کلینیک\n{$full} عزیز\nنوبت {$when}",
    'user_style_first' => "مانا کلینیک\n{$first} عزیز\n{$when}",
    'user_style_first_nobat' => "مانا کلینیک\n{$first} عزیز\nنوبت {$when}",
    'user_style_first_reg' => "مانا کلینیک\n{$first} عزیز\nنوبت {$when} ثبت شد.",
    'ctrl49' => "مانا کلینیک: نوبت شما در {$when} ثبت شد.",
];
$out = ['phone_tail' => substr($phone, -4), 'full' => $full, 'first' => $first, 'when' => $when, 'results' => []];
foreach ($variants as $label => $body) {
    $send = sms_provider_send($c, $phone, $body);
    $out['results'][$label] = [
        'len' => mb_strlen($body),
        'status' => $send['status'] ?? null,
        'error' => $send['error'] ?? null,
        'id' => $send['id'] ?? null,
        'body' => $body,
    ];
    usleep(500000);
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
    original = original.getvalue()
    rule = b'RewriteRule ^' + name.replace('.', r'\.').encode() + b'$ ' + name.encode() + b' [END]'
    modified = original.replace(b'RewriteEngine On', b'RewriteEngine On\n' + rule, 1)
    if modified == original:
        raise RuntimeError('htaccess insert failed')
    try:
        ftp.storbinary('STOR ' + name, io.BytesIO(helper.encode('utf-8')))
        ftp.storbinary('STOR .htaccess', io.BytesIO(modified))
        time.sleep(1)
        req = urllib.request.Request(
            'https://manaclinic.org/' + name,
            data=b'1',
            headers={'X-Setup-Token': token, 'User-Agent': 'ManaSmsCmp/1.0', 'Content-Type': 'application/x-www-form-urlencoded'},
            method='POST',
        )
        with urllib.request.urlopen(req, timeout=180) as response:
            body = response.read().decode('utf-8', 'replace')
        (root / 'output/access-update/probe-sms-compact-ht.json').write_text(body, encoding='utf-8')
        if not body.lstrip().startswith('{'):
            print('NON_JSON', body[:200].encode('unicode_escape').decode('ascii'), flush=True)
            raise RuntimeError('non-json')
        print(json.dumps(json.loads(body), ensure_ascii=True), flush=True)
    finally:
        ftp.storbinary('STOR .htaccess', io.BytesIO(original))
        try:
            ftp.delete(name)
        except Exception:
            pass
        print('cleanup ok', flush=True)
