"""Diagnose why workshop SMS did not send."""
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
name = 'wsp-' + secrets.token_hex(8) + '.php'
helper = r'''<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || time() > __EXPIRY__ || !hash_equals('__TOKEN__', $_SERVER['HTTP_X_SETUP_TOKEN'] ?? '')) {
    http_response_code(404); exit;
}
$config = require __DIR__.'/config.php';
$GLOBALS['config'] = $config;
date_default_timezone_set($config['timezone'] ?? 'Asia/Tehran');
foreach (['db','helpers','mail','outreach','workshop_sms','workshop_presenters'] as $f) {
    require_once __DIR__.'/includes/'.$f.'.php';
}
$pdo = db_connect($config);
ensure_outreach_schema($pdo);
$kinds = [
  'workshop_presenter','workshop_presenter_copy','workshop_registered','workshop_registered_copy',
  'workshop_session_reflect','workshop_session_reflect_copy','workshop_media_added','workshop_media_added_copy',
  'workshop_reminder','workshop_announcement'
];
$marks = implode(',', array_fill(0, count($kinds), '?'));
$q = $pdo->prepare("SELECT id,kind,status,phone,LEFT(body,120) body,last_error,created_at,sent_at,due_at,attempts,context_json
  FROM sms_outbox WHERE kind IN ($marks) AND created_at>=DATE_SUB(NOW(), INTERVAL 2 DAY)
  ORDER BY created_at DESC LIMIT 50");
$q->execute($kinds);
$rows = $q->fetchAll() ?: [];
foreach ($rows as &$r) {
  $r['phone_tail'] = substr((string)$r['phone'], -4);
  unset($r['phone']);
}
unset($r);
$byKind = $pdo->prepare("SELECT kind,status,COUNT(*) c FROM sms_outbox WHERE kind IN ($marks) AND created_at>=DATE_SUB(NOW(), INTERVAL 2 DAY) GROUP BY kind,status");
$byKind->execute($kinds);
$presenters = $pdo->query("SELECT wp.workshop_id,wp.doctor_id,wp.assigned_at,w.title,u.name,u.phone
  FROM workshop_presenters wp JOIN workshops w ON w.id=wp.workshop_id
  JOIN doctor_profiles dp ON dp.id=wp.doctor_id JOIN users u ON u.id=dp.user_id
  ORDER BY wp.assigned_at DESC LIMIT 15")->fetchAll() ?: [];
foreach ($presenters as &$p) {
  $p['phone_tail'] = substr((string)$p['phone'], -4);
  unset($p['phone']);
}
unset($p);
$worker = mail_setting_get($pdo, 'sms_worker_last_run', '');
$ready = sms_connection_ready($pdo);
$emad = workshop_sms_emad_phone($pdo);
$fileHas = [
  'workshop_sms' => is_file(__DIR__.'/includes/workshop_sms.php'),
  'reflect_in_worker' => str_contains(file_get_contents(__DIR__.'/includes/sms_service.php'), 'workshop_sms_queue_session_reflections'),
  'media_notify' => str_contains(file_get_contents(__DIR__.'/includes/workshop_media.php'), 'workshop_sms_notify_media_added'),
];
echo json_encode([
  'now' => date('Y-m-d H:i:s'),
  'sms_ready' => $ready,
  'worker_last' => $worker,
  'emad_ok' => $emad !== null,
  'files' => $fileHas,
  'by_kind' => $byKind->fetchAll() ?: [],
  'recent' => $rows,
  'recent_presenters' => $presenters,
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
            headers={
                'X-Setup-Token': token,
                'Content-Type': 'application/json',
                'User-Agent': 'Mozilla/5.0 ManaProbe',
            },
            method='POST',
        )
        try:
            with urllib.request.urlopen(req, timeout=45) as resp:
                Path(r'D:/Psychology/output/access-update/probe-workshop-sms-now.json').write_text(
                    resp.read().decode('utf-8'), encoding='utf-8'
                )
                print('ok', flush=True)
        except urllib.error.HTTPError as e:
            Path(r'D:/Psychology/output/access-update/probe-workshop-sms-now.err').write_bytes(e.read())
            print('http', e.code, flush=True)
            raise
    finally:
        try:
            ftp.delete(name)
        except Exception:
            pass
        ftp.storbinary('STOR .htaccess', io.BytesIO(original_ht))
        print('cleaned', flush=True)
