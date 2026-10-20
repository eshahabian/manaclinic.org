"""Deploy HTTP SMS cron route + kick worker once."""
from pathlib import Path
from ftplib import FTP
import io
import json
import secrets
import time
import urllib.request
import urllib.error

root = Path('D:/Psychology')
cfg = json.loads((root / '.vscode/sftp.json').read_text(encoding='utf-8-sig'))
files = {
    'includes/sms_service.php': root / 'cpanel/includes/sms_service.php',
    'pages/cron_sms.php': root / 'cpanel/pages/cron_sms.php',
    'pages/admin/sms_settings.php': root / 'cpanel/pages/admin/sms_settings.php',
    'index.php': root / 'cpanel/index.php',
}
sms = files['includes/sms_service.php'].read_text(encoding='utf-8')
assert 'مانا کلینیک: نوبت شما در {datetime} ثبت شد.' in sms
assert 'sms_cron_ensure_token' in sms
assert 'GET /cron/sms' in files['index.php'].read_text(encoding='utf-8')

with FTP() as ftp:
    ftp.connect(cfg['host'], int(cfg.get('port', 21)), timeout=60)
    ftp.login(cfg['username'], cfg['password'])
    ftp.cwd('/public_html')
    for remote, local in files.items():
        data = local.read_bytes()
        try:
            ftp.delete(remote)
        except Exception:
            pass
        ftp.storbinary('STOR ' + remote, io.BytesIO(data))
        v = io.BytesIO()
        ftp.retrbinary('RETR ' + remote, v.write)
        if v.getvalue() != data:
            raise RuntimeError('verify failed ' + remote)
        print('ok', remote, flush=True)

token = secrets.token_hex(32)
name = 'kick-' + secrets.token_hex(8) + '.php'
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
foreach (['sms_service.php'] as $f) {
    if (function_exists('opcache_invalidate')) @opcache_invalidate(__DIR__.'/includes/'.$f, true);
}
if (function_exists('opcache_invalidate')) {
    @opcache_invalidate(__DIR__.'/index.php', true);
    @opcache_invalidate(__DIR__.'/pages/cron_sms.php', true);
}
$cronToken = sms_cron_ensure_token($pdo);
$cronUrl = sms_cron_url($pdo);
sms_run_worker($pdo);
mail_setting_set($pdo, 'sms_worker_last_run', date('Y-m-d H:i:s'));
$today = date('Y-m-d');
$digests = $pdo->prepare("SELECT status, COUNT(*) c FROM sms_outbox WHERE kind='doctor_daily_digest' AND DATE(created_at)=? GROUP BY status");
$digests->execute([$today]);
echo json_encode([
    'now' => date('Y-m-d H:i:s'),
    'worker_last' => mail_setting_get($pdo, 'sms_worker_last_run', ''),
    'cron_url' => $cronUrl,
    'cron_token_len' => strlen($cronToken),
    'confirmed_today' => (int)$pdo->query("SELECT COUNT(*) FROM appointments WHERE DATE(starts_at)='$today' AND status='CONFIRMED'")->fetchColumn(),
    'digest_today' => $digests->fetchAll() ?: [],
    'has_cron_route' => str_contains(file_get_contents(__DIR__.'/index.php'), "GET /cron/sms"),
], JSON_UNESCAPED_UNICODE);
'''
helper = helper.replace('__TOKEN__', token).replace('__EXPIRY__', str(int(time.time()) + 600))

with FTP() as ftp:
    ftp.connect(cfg['host'], int(cfg.get('port', 21)), timeout=45)
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
        with urllib.request.urlopen(req, timeout=60) as resp:
            out = resp.read().decode('utf-8')
            Path(r'D:/Psychology/output/access-update/deploy-sms-cron-http.json').write_text(out, encoding='utf-8')
            data = json.loads(out)
            print('kicked', data.get('worker_last'), 'confirmed_today', data.get('confirmed_today'), flush=True)
            # verify HTTP cron endpoint
            cron_url = data.get('cron_url') or ''
            if cron_url:
                creq = urllib.request.Request(cron_url, method='GET')
                with urllib.request.urlopen(creq, timeout=60) as cresp:
                    cout = cresp.read().decode('utf-8')
                    Path(r'D:/Psychology/output/access-update/cron-sms-hit.json').write_text(cout, encoding='utf-8')
                    print('cron_hit', cout[:200], flush=True)
    except urllib.error.HTTPError as e:
        print('http', e.code, e.read()[:400], flush=True)
        raise
    finally:
        try:
            ftp.delete(name)
        except Exception:
            pass
        ftp.storbinary('STOR .htaccess', io.BytesIO(original_ht))
        print('cleaned', flush=True)
