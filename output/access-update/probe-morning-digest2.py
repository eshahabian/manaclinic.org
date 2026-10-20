"""Follow-up: appointments today + whether cron/worker path exists."""
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
name = 'md2-' + secrets.token_hex(8) + '.php'
helper = r'''<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || time() > __EXPIRY__ || !hash_equals('__TOKEN__', $_SERVER['HTTP_X_SETUP_TOKEN'] ?? '')) {
    http_response_code(404); exit;
}
$config = require __DIR__.'/config.php';
date_default_timezone_set($config['timezone'] ?? 'Asia/Tehran');
foreach (['db','helpers','mail','outreach'] as $f) require_once __DIR__.'/includes/'.$f.'.php';
$pdo = db_connect($config);
$today = date('Y-m-d');
$byStatus = $pdo->prepare("SELECT status, COUNT(*) c FROM appointments WHERE DATE(starts_at)=? GROUP BY status");
$byStatus->execute([$today]);
$tomorrow = date('Y-m-d', strtotime('+1 day'));
$yest = date('Y-m-d', strtotime('-1 day'));
$counts = [];
foreach ([$yest, $today, $tomorrow] as $d) {
    $st = $pdo->prepare("SELECT status, COUNT(*) c FROM appointments WHERE DATE(starts_at)=? GROUP BY status");
    $st->execute([$d]);
    $counts[$d] = $st->fetchAll() ?: [];
}
$autoDigest = $pdo->query("SELECT id,status,created_at,sent_at,last_error,LEFT(body,80) body,context_json FROM sms_outbox WHERE kind='doctor_daily_digest' AND (context_json IS NULL OR context_json NOT LIKE '%\"manual\":1%') ORDER BY created_at DESC LIMIT 20")->fetchAll() ?: [];
$workerExists = is_file(__DIR__.'/sms-worker.php');
$cronHints = [];
foreach (['cron.php','cron/sms.php','pages/cron_sms.php','worker.php'] as $p) {
    if (is_file(__DIR__.'/'.$p)) $cronHints[] = $p;
}
echo json_encode([
    'now' => date('Y-m-d H:i:s'),
    'today' => $today,
    'appt_by_day' => $counts,
    'worker_last' => mail_setting_get($pdo, 'sms_worker_last_run', ''),
    'auto_digests_recent' => $autoDigest,
    'sms_worker_file' => $workerExists,
    'cron_hint_files' => $cronHints,
], JSON_UNESCAPED_UNICODE);
'''
helper = helper.replace('__TOKEN__', token).replace('__EXPIRY__', str(int(time.time()) + 600))

with FTP() as ftp:
    ftp.connect(cfg['host'], int(cfg.get('port', 21)), timeout=60)
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
            data=b'{}',
            headers={'X-Setup-Token': token, 'Content-Type': 'application/json'},
            method='POST',
        )
        with urllib.request.urlopen(req, timeout=45) as resp:
            Path(r'D:/Psychology/output/access-update/probe-morning-digest2.json').write_text(
                resp.read().decode('utf-8'), encoding='utf-8'
            )
            print('ok', flush=True)
    finally:
        try:
            ftp.delete(name)
        except Exception:
            pass
        ftp.storbinary('STOR .htaccess', io.BytesIO(original_ht))
        print('cleaned', flush=True)
