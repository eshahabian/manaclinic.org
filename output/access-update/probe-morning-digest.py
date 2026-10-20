"""Diagnose why morning doctor digests did not send today."""
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
name = 'mdg-' + secrets.token_hex(8) + '.php'
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
ensure_outreach_schema($pdo);
$today = date('Y-m-d');
$hour = (int) date('G');
$workerLast = mail_setting_get($pdo, 'sms_worker_last_run', '');
$ready = sms_connection_ready($pdo);
$packs = sms_doctor_digests_for_date($pdo, $today, null);
$packSummary = [];
foreach ($packs as $p) {
    $packSummary[] = [
        'doctor_id' => $p['doctor_id'],
        'name' => $p['name'],
        'phone_ok' => $p['phone'] !== null,
        'phone_tail' => $p['phone'] ? substr($p['phone'], -4) : null,
        'slot_count' => $p['slot_count'],
        'body' => $p['body'],
        'body_len' => mb_strlen($p['body']),
    ];
}
$stmt = $pdo->prepare("SELECT id,phone,kind,status,last_error,body,due_at,expires_at,sent_at,created_at,attempts,context_json
  FROM sms_outbox WHERE kind='doctor_daily_digest' AND created_at>=DATE_SUB(NOW(), INTERVAL 3 DAY)
  ORDER BY created_at DESC LIMIT 40");
$stmt->execute();
$outbox = $stmt->fetchAll() ?: [];
foreach ($outbox as &$row) {
    $row['phone_tail'] = substr((string)$row['phone'], -4);
    unset($row['phone']);
    $row['body_len'] = mb_strlen((string)$row['body']);
}
unset($row);
$todayCount = $pdo->prepare("SELECT status, COUNT(*) c FROM sms_outbox WHERE kind='doctor_daily_digest' AND DATE(created_at)=? GROUP BY status");
$todayCount->execute([$today]);
$byStatus = $todayCount->fetchAll() ?: [];
$confirmedToday = (int)$pdo->query("SELECT COUNT(*) FROM appointments WHERE DATE(starts_at)='$today' AND status='CONFIRMED'")->fetchColumn();
$otherRecent = $pdo->query("SELECT kind,status,COUNT(*) c FROM sms_outbox WHERE created_at>=DATE_SUB(NOW(),INTERVAL 1 DAY) GROUP BY kind,status ORDER BY kind,status")->fetchAll() ?: [];
echo json_encode([
    'now' => date('Y-m-d H:i:s'),
    'tz' => date_default_timezone_get(),
    'hour' => $hour,
    'sms_ready' => $ready,
    'worker_last_run' => $workerLast,
    'confirmed_appt_today' => $confirmedToday,
    'digest_candidates_today' => count($packs),
    'packs' => $packSummary,
    'digest_today_by_status' => $byStatus,
    'digest_outbox_3d' => $outbox,
    'all_kinds_1d' => $otherRecent,
    'has_queue_fn' => function_exists('sms_queue_doctor_daily_digests'),
    'sample_body_fn' => function_exists('sms_doctor_daily_digest_body')
        ? sms_doctor_daily_digest_body('x', format_fa_day_long($today.' 12:00:00'), ['10:00','11:00'])
        : null,
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
            out = resp.read().decode('utf-8')
            Path(r'D:/Psychology/output/access-update/probe-morning-digest.json').write_text(out, encoding='utf-8')
            print('wrote json', len(out), flush=True)
    finally:
        try:
            ftp.delete(name)
        except Exception:
            pass
        ftp.storbinary('STOR .htaccess', io.BytesIO(original_ht))
        print('cleaned', flush=True)
