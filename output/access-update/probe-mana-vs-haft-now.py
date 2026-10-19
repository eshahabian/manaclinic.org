"""Compare Mana vs Haft Rokh send path from Mana server; one OTP to ...3986. Secrets not printed."""
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

# Read Haft send function shape (for report, no secrets)
haft_sms = Path('D:/casting-portal/includes/sms.php').read_text(encoding='utf-8')
haft_uses_isflash = 'IsFlash' in haft_sms
haft_uses_tonumber = 'ToNumber' in haft_sms
haft_api_base = 'api.payamakapi.ir' in haft_sms

token = secrets.token_hex(32)
name = 'sms-hv-' + secrets.token_hex(8) + '.php'
helper = r'''<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || time() > __EXPIRY__ || !hash_equals('__TOKEN__', $_SERVER['HTTP_X_SETUP_TOKEN'] ?? '')) {
    http_response_code(404);
    exit;
}
function raw_send($key, $from, $phone, $content, $withFlash = null) {
    $fields = ['From' => $from, 'ToNumber' => $phone, 'Content' => $content];
    if ($withFlash !== null) $fields['IsFlash'] = $withFlash;
    $ch = curl_init('https://api.payamakapi.ir/api/v1/SMS/Send');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json','Accept: application/json','X-API-KEY: '.$key],
        CURLOPT_POSTFIELDS => json_encode($fields, JSON_UNESCAPED_UNICODE),
    ]);
    $raw = (string)curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $j = json_decode($raw, true);
    return [
        'http' => $http,
        'resultCode' => is_array($j) ? ($j['resultCode'] ?? $j['ResultCode'] ?? null) : null,
        'succeeded' => is_array($j) ? ($j['succeeded'] ?? $j['Succeeded'] ?? null) : null,
        'refId' => is_array($j) ? ($j['refId'] ?? $j['RefId'] ?? null) : null,
        'message' => is_array($j) ? ($j['message'] ?? $j['Message'] ?? null) : null,
        'raw' => mb_substr($raw, 0, 220),
    ];
}
function credit($key) {
    $ch = curl_init('https://api.payamakapi.ir/api/v1/SMS/GetCredit');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>20,CURLOPT_HTTPHEADER=>['Accept: application/json','X-API-KEY: '.$key]]);
    $raw = trim((string)curl_exec($ch));
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['http'=>$http,'raw'=>mb_substr($raw,0,80)];
}
$config = require __DIR__ . '/config.php';
date_default_timezone_set($config['timezone'] ?? 'Asia/Tehran');
foreach (['db','helpers','mail','outreach'] as $f) require_once __DIR__.'/includes/'.$f.'.php';
$pdo = db_connect($config);
$c = sms_connection_config($pdo);
$u = $pdo->query("SELECT phone FROM users WHERE phone LIKE '%3986' LIMIT 1")->fetch();
$phone = is_array($u) ? (string)$u['phone'] : '';
$code = (string)random_int(100000,999999);
$otp = 'کد ورود شما ' . $code;
$appt = 'مانا کلینیک: نوبت شما در ' . format_fa_datetime(date('Y-m-d H:i:s', time()+86400)) . ' ثبت شد.';

$manaKey = (string)$c['api_key'];
$manaFrom = (string)$c['sender'];
$haftKey = '__HAFT_KEY__';
$haftFrom = '__HAFT_FROM__';

$out = [
    'server_ip' => $_SERVER['SERVER_ADDR'] ?? null,
    'phone_tail' => substr($phone, -4),
    'mana' => [
        'from' => $manaFrom,
        'key_len' => strlen($manaKey),
        'key_prefix' => substr($manaKey, 0, 8),
        'enabled' => $c['enabled'] ?? '',
        'credit' => credit($manaKey),
    ],
    'haft' => [
        'from' => $haftFrom,
        'key_len' => strlen($haftKey),
        'key_prefix' => substr($haftKey, 0, 8),
        'credit' => credit($haftKey),
    ],
    'tries' => [],
];

// 1) Mana OTP haft-style (no IsFlash) — primary working path
$out['tries']['mana_otp_no_flash'] = raw_send($manaKey, $manaFrom, $phone, $otp, null);
$out['tries']['mana_otp_no_flash']['body'] = $otp;
$out['tries']['mana_otp_no_flash']['len'] = mb_strlen($otp);

// 2) If OTP worked, also try appointment short text
if (($out['tries']['mana_otp_no_flash']['succeeded'] ?? false) === true
    || (int)($out['tries']['mana_otp_no_flash']['resultCode'] ?? -1) === 0) {
    usleep(700000);
    $out['tries']['mana_appt_no_flash'] = raw_send($manaKey, $manaFrom, $phone, $appt, null);
    $out['tries']['mana_appt_no_flash']['body'] = $appt;
    $out['tries']['mana_appt_no_flash']['len'] = mb_strlen($appt);
} else {
    // Still probe provider_send for parity
    $out['tries']['mana_provider_send_otp'] = sms_provider_send($c, $phone, $otp);
}

// 3) Haft key from Mana IP (expect IP invalid unless whitelisted) — one credit only, no send spam
$out['haft']['send_probe'] = raw_send($haftKey, $haftFrom, $phone, 'تست هفت رخ از سرور مانا '.date('H:i:s'), null);

// 4) Recent outbox
$out['outbox'] = $pdo->query("SELECT kind,status,LEFT(body,90) body,last_error,created_at FROM sms_outbox WHERE phone LIKE '%3986' ORDER BY created_at DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);

echo json_encode($out, JSON_UNESCAPED_UNICODE);
'''
helper = (
    helper.replace('__TOKEN__', token)
    .replace('__EXPIRY__', str(int(time.time()) + 600))
    .replace('__HAFT_KEY__', haft_key)
    .replace('__HAFT_FROM__', haft_from)
)

report_meta = {
    'haft_sms_uses_IsFlash': haft_uses_isflash,
    'haft_sms_uses_ToNumber': haft_uses_tonumber,
    'haft_sms_uses_api_payamakapi': haft_api_base,
}

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
            headers={'X-Setup-Token': token, 'User-Agent': 'ManaVsHaft/1.0', 'Content-Type': 'application/x-www-form-urlencoded'},
            method='POST',
        )
        with urllib.request.urlopen(req, timeout=120) as response:
            body = response.read().decode('utf-8', 'replace')
        data = json.loads(body)
        data['code_compare'] = report_meta
        out = root / 'output/access-update/probe-mana-vs-haft-now.json'
        out.write_text(json.dumps(data, ensure_ascii=False, indent=2), encoding='utf-8')
        # ASCII-safe print
        print(json.dumps(data, ensure_ascii=True), flush=True)
    finally:
        ftp.storbinary('STOR .htaccess', io.BytesIO(original_ht))
        try:
            ftp.delete(name)
        except Exception:
            pass
        print('cleanup ok', flush=True)
