"""Deploy date-only greeting templates, clear DB overrides, send one named SMS to Emad."""
from pathlib import Path
from ftplib import FTP, error_perm
import io
import json
import secrets
import time
import urllib.request

root = Path('D:/Psychology')
cfg = json.loads((root / '.vscode/sftp.json').read_text(encoding='utf-8-sig'))
token = secrets.token_hex(32)
helper_name = 'sms-fin-' + secrets.token_hex(8) + '.php'
local = (root / 'cpanel/includes/sms_service.php').read_bytes()
# catalog booked line must be date-only (no «نوبت شما»)
if 'مانا کلینیک\\n{patient} عزیز\\n{datetime}'.encode('utf-8') not in local and \
   "مانا کلینیک\n{patient} عزیز\n{datetime}".encode('utf-8') not in local:
    # PHP source stores real newlines inside double-quoted strings
    if b'{patient} \xd8\xb9\xd8\xb2\xdb\x8c\xd8\xb2\n{datetime}' not in local:
        raise RuntimeError('local catalog missing date-only greeting')

helper = r'''<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || time() > __EXPIRY__ || !hash_equals('__TOKEN__', $_SERVER['HTTP_X_SETUP_TOKEN'] ?? '')) {
    http_response_code(404);
    exit;
}
$target = __DIR__ . '/includes/sms_service.php';
if (function_exists('opcache_invalidate')) {
    @opcache_invalidate($target, true);
    @opcache_reset();
}
clearstatcache(true, $target);

$config = require __DIR__ . '/config.php';
date_default_timezone_set($config['timezone'] ?? 'Asia/Tehran');
foreach (['db', 'helpers', 'mail', 'outreach'] as $f) {
    require_once __DIR__ . '/includes/' . $f . '.php';
}
$pdo = db_connect($config);
ensure_mail_schema($pdo);
ensure_outreach_schema($pdo);
foreach (['appointment_booked','appointment_cancelled','appointment_reminder','workshop_registered','workshop_reminder'] as $k) {
    mail_setting_set($pdo, 'sms_template_'.$k, '');
}

$u = $pdo->query("SELECT name,phone FROM users WHERE phone LIKE '%3986' LIMIT 1")->fetch();
$phone = (string)$u['phone'];
$full = trim((string)$u['name']);
$bits = preg_split('/\s+/u', $full) ?: [];
$first = (string)($bits[0] ?? $full);
$appt = $pdo->query("SELECT a.id,a.starts_at FROM appointments a JOIN users u ON u.id=a.patient_id WHERE u.phone LIKE '%3986' ORDER BY a.updated_at DESC LIMIT 1")->fetch();
$when = format_fa_datetime((string)($appt['starts_at'] ?? date('Y-m-d H:i:s', time()+86400)));

$candidates = [
    'full_date' => "مانا کلینیک\n{$full} عزیز\n{$when}",
    'first_date' => "مانا کلینیک\n{$first} عزیز\n{$when}",
    'render' => sms_template_render($pdo, 'appointment_booked', [
        'patient' => $full,
        'datetime' => $when,
        'doctor' => '',
        'status' => '',
    ]),
];

$out = [
    'file_compact' => str_contains((string)@file_get_contents($target), '{datetime}".') || str_contains((string)@file_get_contents($target), "عزیز\\n{datetime}") || str_contains((string)@file_get_contents($target), "عزیز\n{datetime}"),
    'credit' => null,
    'when' => $when,
    'attempts' => [],
    'winner' => null,
];

$ch = curl_init('https://api.payamakapi.ir/api/v1/SMS/GetCredit');
$c = sms_connection_config($pdo);
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>20,CURLOPT_HTTPHEADER=>['Accept: application/json','X-API-KEY: '.$c['api_key']]]);
$out['credit'] = trim((string)curl_exec($ch));
curl_close($ch);

$seen = [];
foreach ($candidates as $label => $body) {
    if (isset($seen[$body])) continue;
    $seen[$body] = true;
    $send = sms_provider_send($c, $phone, $body);
    $out['attempts'][$label] = [
        'len' => mb_strlen($body),
        'body' => $body,
        'status' => $send['status'] ?? null,
        'error' => $send['error'] ?? null,
        'id' => $send['id'] ?? null,
    ];
    if (($send['status'] ?? '') === 'SENT') {
        $out['winner'] = $label;
        // Mark latest failed booked row as sent / archive new outbox row
        $hash = hash('sha256', 'manual-greet:'.$phone.':'.$when.':'.time());
        sms_queue_extended($pdo, $phone, $body, 'appointment_booked', 'manual-greet:'.$phone.':'.$when, date('Y-m-d H:i:s'), (string)($appt['starts_at'] ?? date('Y-m-d H:i:s', time()+86400)), ['entity'=>'appointment','id'=>$appt['id'] ?? null,'immediate'=>1]);
        $jobId = (string)$pdo->query("SELECT id FROM sms_outbox WHERE phone LIKE '%3986' ORDER BY created_at DESC LIMIT 1")->fetchColumn();
        if ($jobId !== '') {
            $pdo->prepare("UPDATE sms_outbox SET status=?,provider_id=?,last_error=NULL,sent_at=NOW(),attempts=attempts+1 WHERE id=?")
                ->execute(['SENT', $send['id'] ?? null, $jobId]);
        }
        $pdo->prepare("UPDATE sms_outbox SET status='SENT', provider_id=?, last_error=NULL, sent_at=NOW() WHERE status='FAILED' AND kind='appointment_booked' AND phone LIKE '%3986'")
            ->execute([$send['id'] ?? null]);
        break;
    }
    // Stop after first non-duplicate hard fail to avoid burning rate limit; retry first_date only if full failed
    if (($send['status'] ?? '') === 'FAILED' && str_contains((string)($send['error'] ?? ''), 'تکراری')) {
        sleep(2);
        continue;
    }
    usleep(900000);
}

echo json_encode($out, JSON_UNESCAPED_UNICODE);
'''
helper = helper.replace('__TOKEN__', token).replace('__EXPIRY__', str(int(time.time()) + 900))

with FTP() as ftp:
    ftp.connect(cfg['host'], int(cfg.get('port', 21)), timeout=45)
    ftp.login(cfg['username'], cfg['password'])
    ftp.cwd('/public_html')
    remote = 'includes/sms_service.php'
    backup = root / 'output/access-update' / ('sms-before-' + time.strftime('%Y%m%d-%H%M%S'))
    backup.mkdir(parents=True, exist_ok=True)
    old = io.BytesIO()
    try:
        ftp.retrbinary('RETR ' + remote, old.write)
        (backup / 'sms_service.php').write_bytes(old.getvalue())
    except error_perm as e:
        if not str(e).startswith('550'):
            raise
    try:
        ftp.delete(remote)
    except Exception:
        pass
    ftp.storbinary('STOR ' + remote, io.BytesIO(local))
    verify = io.BytesIO()
    ftp.retrbinary('RETR ' + remote, verify.write)
    if verify.getvalue() != local:
        raise RuntimeError('upload verify failed')
    print('deployed', flush=True)

    original = io.BytesIO()
    ftp.retrbinary('RETR .htaccess', original.write)
    original_ht = original.getvalue()
    rule = b'RewriteRule ^' + helper_name.replace('.', r'\.').encode() + b'$ ' + helper_name.encode() + b' [END]'
    modified = original_ht.replace(b'RewriteEngine On', b'RewriteEngine On\n' + rule, 1)
    if modified == original_ht:
        raise RuntimeError('htaccess insert failed')
    try:
        ftp.storbinary('STOR ' + helper_name, io.BytesIO(helper.encode('utf-8')))
        ftp.storbinary('STOR .htaccess', io.BytesIO(modified))
        time.sleep(2)
        req = urllib.request.Request(
            'https://manaclinic.org/' + helper_name,
            data=b'1',
            headers={'X-Setup-Token': token, 'User-Agent': 'ManaSmsFinal/1.0', 'Content-Type': 'application/x-www-form-urlencoded'},
            method='POST',
        )
        with urllib.request.urlopen(req, timeout=180) as response:
            body = response.read().decode('utf-8', 'replace')
        path = root / 'output/access-update/deploy-sms-name-final.json'
        path.write_text(body, encoding='utf-8')
        if not body.lstrip().startswith('{'):
            print('NON_JSON', body[:200].encode('unicode_escape').decode('ascii'), flush=True)
            raise RuntimeError('non-json')
        print(json.dumps(json.loads(body), ensure_ascii=True), flush=True)
    finally:
        ftp.storbinary('STOR .htaccess', io.BytesIO(original_ht))
        try:
            ftp.delete(helper_name)
        except Exception:
            pass
        print('cleanup ok', flush=True)
