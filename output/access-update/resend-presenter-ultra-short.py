"""Resend presenter SMS with the exact short format that previously SENT."""
from pathlib import Path
from ftplib import FTP
import io
import json
import secrets
import time
import urllib.request

root = Path('D:/Psychology')
cfg = json.loads((root / '.vscode/sftp.json').read_text(encoding='utf-8-sig'))

# deploy sms_service schedule first
sms = (root / 'cpanel/includes/sms_service.php').read_bytes()
assert b"doctor-digest:" in sms and b"'pm'" in sms
assert 'مانا کلینیک: نوبت شما در {datetime} ثبت شد.'.encode('utf-8') in sms

with FTP() as ftp:
    ftp.connect(cfg['host'], int(cfg.get('port', 21)), timeout=60)
    ftp.login(cfg['username'], cfg['password'])
    ftp.cwd('/public_html')
    try:
        ftp.delete('includes/sms_service.php')
    except Exception:
        pass
    ftp.storbinary('STOR includes/sms_service.php', io.BytesIO(sms))
    print('deployed schedule', flush=True)

token = secrets.token_hex(32)
name = 'pus-' + secrets.token_hex(8) + '.php'
# exact working format from earlier success
body = 'مانا کلینیک: اجرا «زوج درمانی» تأیید شد. چهارشنبه‌ها ۱۸:۰۰'
helper = r'''<?php
header('Content-Type: application/json; charset=utf-8');
set_time_limit(120);
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || time() > __EXPIRY__ || !hash_equals('__TOKEN__', $_SERVER['HTTP_X_SETUP_TOKEN'] ?? '')) { http_response_code(404); exit; }
$config=require __DIR__.'/config.php'; date_default_timezone_set($config['timezone']??'Asia/Tehran');
foreach(['db','helpers','mail','outreach'] as $f) require_once __DIR__.'/includes/'.$f.'.php';
$pdo=db_connect($config);
$body='__BODY__';
$targets=[];
foreach([
  ['حمید','بهرامی','hamid'],
  ['شیوا','گرانمایه','shiva'],
] as $t){
  $q=$pdo->prepare("SELECT id,name,phone FROM users WHERE name LIKE ? AND name LIKE ? AND COALESCE(is_disabled,0)=0 LIMIT 1");
  $q->execute(['%'.$t[0].'%','%'.$t[1].'%']);
  $u=$q->fetch();
  if($u && ($p=sms_iran_mobile((string)$u['phone']))) $targets[]=['label'=>$t[2],'name'=>$u['name'],'phone'=>$p];
}
$eq=$pdo->query("SELECT id,name,phone FROM users WHERE LOWER(username)='eshahabian' AND COALESCE(is_disabled,0)=0 LIMIT 1");
$emad=$eq->fetch();
if($emad && ($p=sms_iran_mobile((string)$emad['phone']))) $targets[]=['label'=>'emad','name'=>$emad['name'],'phone'=>$p];
$out=[];
foreach($targets as $i=>$t){
  if($i) usleep(1200000);
  $r=sms_send_immediate($pdo,$t['phone'],$body,'workshop_presenter','presenter-retry:'.$t['label'].':'.cuid(),date('Y-m-d H:i:s',time()+3600),['entity'=>'manual_resend']);
  $out[]=['label'=>$t['label'],'name'=>$t['name'],'tail'=>substr($t['phone'],-4),'ok'=>!empty($r['ok']),'status'=>$r['status']??'','error'=>$r['error']??'','len'=>mb_strlen($body)];
}
echo json_encode(['body'=>$body,'results'=>$out], JSON_UNESCAPED_UNICODE);
'''
helper = helper.replace('__TOKEN__', token).replace('__EXPIRY__', str(int(time.time()) + 600)).replace('__BODY__', body)

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
            headers={'X-Setup-Token': token, 'Content-Type': 'application/json', 'User-Agent': 'Mozilla/5.0'},
            method='POST',
        )
        with urllib.request.urlopen(req, timeout=120) as resp:
            out = resp.read().decode('utf-8')
            Path(r'D:/Psychology/output/access-update/resend-presenter-ultra-short.json').write_text(out, encoding='utf-8')
            print('ok', len(out), flush=True)
    finally:
        try:
            ftp.delete(name)
        except Exception:
            pass
        ftp.storbinary('STOR .htaccess', io.BytesIO(original_ht))
        print('cleaned', flush=True)
