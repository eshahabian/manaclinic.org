"""Force-deploy sms_service.php with opcache invalidate, verify catalog, resend Emad SMS."""
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
helper_name = 'sms-bust-' + secrets.token_hex(8) + '.php'
local = (root / 'cpanel/includes/sms_service.php').read_bytes()
if 'نوبت {datetime}'.encode('utf-8') not in local:
    raise RuntimeError('local file missing compact template')
if 'نوبت شما در {datetime} ثبت شد'.encode('utf-8') in local:
    raise RuntimeError('local file still has long template')

helper = r'''<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || time() > __EXPIRY__ || !hash_equals('__TOKEN__', $_SERVER['HTTP_X_SETUP_TOKEN'] ?? '')) {
    http_response_code(404);
    exit;
}
$target = __DIR__ . '/includes/sms_service.php';
$raw = is_file($target) ? file_get_contents($target) : '';
$hasCompact = is_string($raw) && str_contains($raw, 'نوبت {datetime}');
$hasLong = is_string($raw) && str_contains($raw, 'نوبت شما در {datetime} ثبت شد');
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
foreach (['appointment_booked', 'appointment_cancelled', 'appointment_reminder', 'workshop_registered', 'workshop_reminder'] as $kind) {
    mail_setting_set($pdo, 'sms_template_' . $kind, '');
}

$sample = sms_template_render($pdo, 'appointment_booked', [
    'patient' => 'عماد شهابیان',
    'datetime' => format_fa_datetime(date('Y-m-d H:i:s', time() + 86400)),
    'doctor' => '',
    'status' => '',
]);

$failed = $pdo->query("SELECT id FROM sms_outbox WHERE status='FAILED' AND kind='appointment_booked' AND phone LIKE '%3986' ORDER BY created_at DESC LIMIT 1")->fetchColumn();
$appt = $pdo->query("SELECT a.id, a.starts_at FROM appointments a JOIN users u ON u.id=a.patient_id WHERE u.phone LIKE '%3986' ORDER BY a.updated_at DESC LIMIT 1")->fetch();
$resend = null;
if (is_array($appt)) {
    $resend = sms_notify_appointment_now($pdo, (string)$appt['id'], 'booked');
}

echo json_encode([
    'file_has_compact' => $hasCompact,
    'file_has_long' => $hasLong,
    'file_mtime' => @filemtime($target),
    'sample_body' => $sample,
    'sample_len' => mb_strlen($sample),
    'failed_id' => $failed ?: null,
    'appt' => $appt ?: null,
    'resend' => $resend,
], JSON_UNESCAPED_UNICODE);
'''
helper = helper.replace('__TOKEN__', token).replace('__EXPIRY__', str(int(time.time()) + 600))

with FTP() as ftp:
    ftp.connect(cfg['host'], int(cfg.get('port', 21)), timeout=45)
    ftp.login(cfg['username'], cfg['password'])
    ftp.cwd('/public_html')

    # overwrite with delete + stor + alternate name swap to defeat inode/opcache sticky
    remote = 'includes/sms_service.php'
    tmp = 'includes/sms_service.' + secrets.token_hex(4) + '.php'
    try:
        ftp.delete(remote)
    except Exception:
        pass
    ftp.storbinary('STOR ' + tmp, io.BytesIO(local))
    # copy tmp -> remote by re-upload (rename may not work on all hosts)
    buf = io.BytesIO()
    ftp.retrbinary('RETR ' + tmp, buf.write)
    ftp.storbinary('STOR ' + remote, io.BytesIO(buf.getvalue()))
    try:
        ftp.delete(tmp)
    except Exception:
        pass
    verify = io.BytesIO()
    ftp.retrbinary('RETR ' + remote, verify.write)
    if verify.getvalue() != local:
        raise RuntimeError('verify mismatch after deploy')
    print('deployed+verified', flush=True)

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
        time.sleep(1.5)
        req = urllib.request.Request(
            'https://manaclinic.org/' + helper_name,
            data=b'1',
            headers={'X-Setup-Token': token, 'User-Agent': 'ManaSmsBust/1.0', 'Content-Type': 'application/x-www-form-urlencoded'},
            method='POST',
        )
        with urllib.request.urlopen(req, timeout=120) as response:
            body = response.read().decode('utf-8', 'replace')
        (root / 'output/access-update/deploy-sms-opcache-bust.json').write_text(body, encoding='utf-8')
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
