"""Deploy sms_service fix, reset stuck UNKNOWN/FAILED recent jobs, run worker once."""
from pathlib import Path
from ftplib import FTP
import io
import json
import secrets
import time
import urllib.request

root = Path('D:/Psychology')
cfg = json.loads((root / '.vscode/sftp.json').read_text(encoding='utf-8-sig'))

# 1) Upload fixed sms_service.php
service = (root / 'cpanel/includes/sms_service.php').read_bytes()
with FTP() as ftp:
    ftp.connect(cfg['host'], int(cfg.get('port', 21)), timeout=45)
    ftp.login(cfg['username'], cfg['password'])
    ftp.cwd('/public_html')
    backup = root / 'output/access-update' / ('sms-before-' + time.strftime('%Y%m%d-%H%M%S'))
    backup.mkdir(parents=True, exist_ok=True)
    old = io.BytesIO()
    ftp.retrbinary('RETR includes/sms_service.php', old.write)
    (backup / 'includes').mkdir(parents=True, exist_ok=True)
    (backup / 'includes/sms_service.php').write_bytes(old.getvalue())
    ftp.storbinary('STOR includes/sms_service.php', io.BytesIO(service))
    verify = io.BytesIO()
    ftp.retrbinary('RETR includes/sms_service.php', verify.write)
    if verify.getvalue() != service:
        raise RuntimeError('upload verify failed')
    print('uploaded sms_service.php', flush=True)

# 2) Temporary helper: reset stuck jobs + send unique probe + run worker
token = secrets.token_hex(32)
name = 'sms-retry-' + secrets.token_hex(12) + '.php'
helper = r'''<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || time() > __EXPIRY__ || !hash_equals('__TOKEN__', $_SERVER['HTTP_X_SETUP_TOKEN'] ?? '')) {
    http_response_code(404);
    exit;
}
try {
    $config = require __DIR__ . '/config.php';
    date_default_timezone_set($config['timezone'] ?? 'Asia/Tehran');
    foreach (['db', 'helpers', 'mail', 'outreach', 'workshop_sessions'] as $f) {
        require_once __DIR__ . '/includes/' . $f . '.php';
    }
    $pdo = db_connect($config);
    ensure_mail_schema($pdo);
    ensure_outreach_schema($pdo);
    // Re-queue ambiguous UNKNOWN from last 2 days so the IsFlash fix can retry once.
    $reset = $pdo->exec("UPDATE sms_outbox SET status='PENDING', claimed_at=NULL, last_error=NULL WHERE status='UNKNOWN' AND created_at>=DATE_SUB(NOW(), INTERVAL 2 DAY)");
    $phone = $pdo->query("SELECT phone FROM sms_outbox WHERE phone LIKE '09%' ORDER BY created_at DESC LIMIT 1")->fetchColumn();
    $probeBody = 'مانا کلینیک: تست ارسال ' . date('H:i:s');
    $probe = null;
    if ($phone) {
        $probe = sms_provider_send(sms_connection_config($pdo), (string) $phone, $probeBody);
    }
    $sent = sms_dispatch_pending($pdo, 30);
    mail_setting_set($pdo, 'sms_worker_last_run', date('Y-m-d H:i:s'));
    $recent = $pdo->query("SELECT status, kind, last_error, provider_id FROM sms_outbox ORDER BY created_at DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
    $counts = $pdo->query("SELECT status, COUNT(*) c FROM sms_outbox GROUP BY status")->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode([
        'reset_unknown' => $reset,
        'probe_phone_tail' => $phone ? substr((string) $phone, -4) : null,
        'probe' => $probe,
        'dispatched' => $sent,
        'counts' => $counts,
        'recent' => $recent,
        'ready' => sms_connection_ready($pdo),
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
'''
helper = helper.replace('__TOKEN__', token).replace('__EXPIRY__', str(int(time.time()) + 600))

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
            (root / 'output/access-update/sms-fix-retry.json').write_text(body, encoding='utf-8')
            print(json.dumps(json.loads(body), ensure_ascii=True), flush=True)
    finally:
        ftp.storbinary('STOR .htaccess', io.BytesIO(original))
        ftp.delete(name)
        print('cleanup ok', flush=True)
