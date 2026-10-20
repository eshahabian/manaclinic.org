"""Redeploy sms_service + hit absolute cron URL."""
from pathlib import Path
from ftplib import FTP
import io
import json
import secrets
import time
import urllib.request

root = Path('D:/Psychology')
cfg = json.loads((root / '.vscode/sftp.json').read_text(encoding='utf-8-sig'))
local = root / 'cpanel/includes/sms_service.php'
data = local.read_bytes()
assert b'seo_absolute_url' in data

with FTP() as ftp:
    ftp.connect(cfg['host'], int(cfg.get('port', 21)), timeout=60)
    ftp.login(cfg['username'], cfg['password'])
    ftp.cwd('/public_html')
    remote = 'includes/sms_service.php'
    try:
        ftp.delete(remote)
    except Exception:
        pass
    ftp.storbinary('STOR ' + remote, io.BytesIO(data))
    print('ok sms_service', flush=True)

token = secrets.token_hex(32)
name = 'cfx-' + secrets.token_hex(8) + '.php'
helper = r'''<?php
header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || time() > __EXPIRY__ || !hash_equals('__TOKEN__', $_SERVER['HTTP_X_SETUP_TOKEN'] ?? '')) {
    http_response_code(404); exit;
}
$config = require __DIR__.'/config.php';
$GLOBALS['config'] = $config;
date_default_timezone_set($config['timezone'] ?? 'Asia/Tehran');
foreach (['db','helpers','mail','seo','outreach'] as $f) require_once __DIR__.'/includes/'.$f.'.php';
$pdo = db_connect($config);
if (function_exists('opcache_invalidate')) @opcache_invalidate(__DIR__.'/includes/sms_service.php', true);
$url = sms_cron_url($pdo);
echo json_encode(['cron_url'=>$url,'worker_before'=>mail_setting_get($pdo,'sms_worker_last_run','')], JSON_UNESCAPED_UNICODE);
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
        with urllib.request.urlopen(req, timeout=40) as resp:
            meta = json.loads(resp.read().decode('utf-8'))
            print('meta', meta, flush=True)
            creq = urllib.request.Request(meta['cron_url'], method='GET')
            with urllib.request.urlopen(creq, timeout=60) as cresp:
                print('cron', cresp.read().decode('utf-8'), flush=True)
    finally:
        try:
            ftp.delete(name)
        except Exception:
            pass
        ftp.storbinary('STOR .htaccess', io.BytesIO(original_ht))
        print('cleaned', flush=True)
