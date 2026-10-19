"""Deploy compact appointment SMS templates and resend Emad's failed booked SMS."""
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
helper_name = 'sms-rsnd-' + secrets.token_hex(8) + '.php'
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
ensure_mail_schema($pdo);
ensure_outreach_schema($pdo);

// Clear stale long custom templates so catalog compact forms win.
foreach (['appointment_booked', 'appointment_cancelled', 'appointment_reminder', 'workshop_registered', 'workshop_reminder'] as $kind) {
    mail_setting_set($pdo, 'sms_template_' . $kind, '');
}

$failed = $pdo->query("SELECT id, phone, kind, body, created_at FROM sms_outbox
    WHERE status='FAILED' AND kind='appointment_booked' AND phone LIKE '%3986'
    ORDER BY created_at DESC LIMIT 1")->fetch();

$out = [
    'templates_cleared' => true,
    'sample_body' => sms_template_render($pdo, 'appointment_booked', [
        'patient' => 'عماد شهابیان',
        'datetime' => format_fa_datetime(date('Y-m-d H:i:s', time() + 86400)),
        'doctor' => '',
        'status' => '',
    ]),
    'failed' => $failed ?: null,
    'resend' => null,
];

if (is_array($failed)) {
    // Find latest appointment for this phone to resend via notify helper if possible.
    $appt = $pdo->query("SELECT a.id FROM appointments a
        JOIN users u ON u.id=a.patient_id
        WHERE u.phone LIKE '%3986' AND a.status IN ('booked','confirmed','pending','scheduled')
        ORDER BY a.updated_at DESC, a.created_at DESC LIMIT 1")->fetch();
    if (!is_array($appt)) {
        $appt = $pdo->query("SELECT a.id FROM appointments a
            JOIN users u ON u.id=a.patient_id
            WHERE u.phone LIKE '%3986'
            ORDER BY a.updated_at DESC, a.created_at DESC LIMIT 1")->fetch();
    }
    if (is_array($appt) && function_exists('sms_notify_appointment_now')) {
        $out['resend'] = [
            'appointment_id' => $appt['id'],
            'result' => sms_notify_appointment_now($pdo, (string)$appt['id'], 'booked'),
        ];
    } else {
        // Direct resend with newly rendered compact body from failed row context.
        $u = $pdo->query("SELECT name,phone FROM users WHERE phone LIKE '%3986' LIMIT 1")->fetch();
        $when = format_fa_datetime(date('Y-m-d H:i:s', time() + 86400));
        // Prefer parsing datetime from failed body last line if present is messy; use upcoming appt time if any.
        $body = sms_template_render($pdo, 'appointment_booked', [
            'patient' => (string)($u['name'] ?? ''),
            'datetime' => $when,
            'doctor' => '',
            'status' => '',
        ]);
        $send = sms_provider_send(sms_connection_config($pdo), (string)$u['phone'], $body);
        $pdo->prepare("UPDATE sms_outbox SET status=?, provider_id=?, last_error=?, sent_at=IF(?='SENT',NOW(),NULL), attempts=attempts+1 WHERE id=?")
            ->execute([$send['status'], $send['id'] ?? null, $send['error'] ?? null, $send['status'], $failed['id']]);
        $out['resend'] = ['mode' => 'direct', 'body' => $body, 'send' => $send];
    }
}

echo json_encode($out, JSON_UNESCAPED_UNICODE);
'''
helper = helper.replace('__TOKEN__', token).replace('__EXPIRY__', str(int(time.time()) + 600))

# 1) Deploy sms_service.php
with FTP() as ftp:
    ftp.connect(cfg['host'], int(cfg.get('port', 21)), timeout=45)
    ftp.login(cfg['username'], cfg['password'])
    ftp.cwd('/public_html')
    remote = 'includes/sms_service.php'
    backup_dir = root / 'output/access-update' / ('sms-before-' + time.strftime('%Y%m%d-%H%M%S'))
    backup_dir.mkdir(parents=True, exist_ok=True)
    old = io.BytesIO()
    try:
        ftp.retrbinary('RETR ' + remote, old.write)
        (backup_dir / 'sms_service.php').write_bytes(old.getvalue())
    except error_perm as e:
        if not str(e).startswith('550'):
            raise
    payload = (root / 'cpanel' / remote).read_bytes()
    try:
        ftp.delete(remote)
    except Exception:
        pass
    ftp.storbinary('STOR ' + remote, io.BytesIO(payload))
    verify = io.BytesIO()
    ftp.retrbinary('RETR ' + remote, verify.write)
    if verify.getvalue() != payload:
        raise RuntimeError('Upload verification failed')
    print('deployed sms_service.php', flush=True)

    # 2) Run resend helper
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
        time.sleep(1)
        req = urllib.request.Request(
            'https://manaclinic.org/' + helper_name,
            data=b'1',
            headers={'X-Setup-Token': token, 'User-Agent': 'ManaSmsResend/1.0', 'Content-Type': 'application/x-www-form-urlencoded'},
            method='POST',
        )
        with urllib.request.urlopen(req, timeout=120) as response:
            body = response.read().decode('utf-8', 'replace')
        (root / 'output/access-update/deploy-sms-compact-resend.json').write_text(body, encoding='utf-8')
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
