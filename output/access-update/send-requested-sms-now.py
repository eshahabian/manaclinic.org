"""One-shot: resend 3 presenter SMS + digest samples to Atieh/Shiva/Emad. No permanent panel edits."""
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
token = secrets.token_hex(32)
name = 'snd-' + secrets.token_hex(8) + '.php'
helper = r'''<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
set_time_limit(180);
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || time() > __EXPIRY__ || !hash_equals('__TOKEN__', $_SERVER['HTTP_X_SETUP_TOKEN'] ?? '')) {
    http_response_code(404); exit;
}
$config = require __DIR__.'/config.php';
$GLOBALS['config'] = $config;
date_default_timezone_set($config['timezone'] ?? 'Asia/Tehran');
foreach (['db','helpers','mail','outreach'] as $f) require_once __DIR__.'/includes/'.$f.'.php';
$pdo = db_connect($config);
if (!sms_connection_ready($pdo)) {
    echo json_encode(['ok'=>false,'error'=>'sms not ready'], JSON_UNESCAPED_UNICODE); exit;
}

function find_user_phone(PDO $pdo, string $likeA, string $likeB = ''): ?array {
    if ($likeB !== '') {
        $q = $pdo->prepare("SELECT id,name,phone FROM users WHERE name LIKE ? AND name LIKE ? AND COALESCE(is_disabled,0)=0 ORDER BY id LIMIT 1");
        $q->execute(['%'.$likeA.'%', '%'.$likeB.'%']);
    } else {
        $q = $pdo->prepare("SELECT id,name,phone FROM users WHERE (LOWER(username)=? OR name LIKE ?) AND COALESCE(is_disabled,0)=0 ORDER BY id LIMIT 1");
        $q->execute([strtolower($likeA), '%'.$likeA.'%']);
    }
    $row = $q->fetch();
    if (!$row) return null;
    $phone = sms_iran_mobile((string)$row['phone']);
    if ($phone === null) return null;
    return ['id'=>$row['id'],'name'=>$row['name'],'phone'=>$phone];
}

$hamid = find_user_phone($pdo, 'حمید', 'بهرامی');
$shiva = find_user_phone($pdo, 'شیوا', 'گرانمایه');
$atieh = find_user_phone($pdo, 'عطیه', 'گارسچی');
$emad = find_user_phone($pdo, 'eshahabian');
if ($emad === null) $emad = find_user_phone($pdo, 'عماد', 'شهابیان');

// متن کوتاه پایدار وب‌وان (نسخه چندخطی قبلاً با کد ۸ رد شد)
$presenterHamid = 'مانا کلینیک: اجراکننده «زوج درمانی» تأیید شد. چهارشنبه‌ها، ۱۸:۰۰ تا ۲۰:۳۰';
$presenterShiva = 'مانا کلینیک: اجراکننده «زوج درمانی» تأیید شد. چهارشنبه‌ها، ۱۸:۰۰ تا ۲۰:۳۰';

$results = [];
$send = function(string $label, ?array $user, string $body, string $kind) use ($pdo, &$results) {
    if ($user === null) {
        $results[] = ['label'=>$label,'ok'=>false,'error'=>'user/phone not found'];
        return;
    }
    usleep(800000);
    $r = sms_send_immediate(
        $pdo,
        $user['phone'],
        $body,
        $kind,
        $kind.':manual:'.$user['id'].':'.cuid(),
        date('Y-m-d H:i:s', time()+86400),
        ['entity'=>'manual_resend','label'=>$label]
    );
    $results[] = [
        'label'=>$label,
        'name'=>$user['name'],
        'phone_tail'=>substr($user['phone'], -4),
        'ok'=>!empty($r['ok']),
        'status'=>$r['status']??'',
        'error'=>$r['error']??'',
        'body'=>$body,
    ];
};

// سه پیام ناموفق قبلی
$send('hamid_self', $hamid, $presenterHamid, 'workshop_presenter');
$send('hamid_copy_emad', $emad, $presenterHamid, 'workshop_presenter_copy');
$send('shiva_copy_emad', $emad, $presenterShiva, 'workshop_presenter_copy');
// خود شیوا هم باید پیام اجراکننده را بگیرد
$send('shiva_self', $shiva, $presenterShiva, 'workshop_presenter');

// نمونه پیام وقت‌ها (digest) برای عطیه، شیوا، عماد
$tomorrow = date('Y-m-d', strtotime('+1 day'));
$dayLabel = function_exists('format_fa_day_long') ? format_fa_day_long($tomorrow.' 12:00:00') : to_fa_digits($tomorrow);
$digestBody = sms_doctor_daily_digest_body('نمونه', $dayLabel, ['10:00','11:00','13:00']);
$send('digest_atieh', $atieh, $digestBody, 'doctor_daily_digest');
$send('digest_shiva', $shiva, $digestBody, 'doctor_daily_digest');
$send('digest_emad', $emad, $digestBody, 'doctor_daily_digest');

echo json_encode([
    'found'=>[
        'hamid'=>$hamid?['name'=>$hamid['name'],'tail'=>substr($hamid['phone'],-4)]:null,
        'shiva'=>$shiva?['name'=>$shiva['name'],'tail'=>substr($shiva['phone'],-4)]:null,
        'atieh'=>$atieh?['name'=>$atieh['name'],'tail'=>substr($atieh['phone'],-4)]:null,
        'emad'=>$emad?['name'=>$emad['name'],'tail'=>substr($emad['phone'],-4)]:null,
    ],
    'digest_body'=>$digestBody,
    'results'=>$results,
], JSON_UNESCAPED_UNICODE);
'''
helper = helper.replace('__TOKEN__', token).replace('__EXPIRY__', str(int(time.time()) + 900))

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
        try:
            with urllib.request.urlopen(req, timeout=180) as resp:
                out = resp.read().decode('utf-8')
                Path(r'D:/Psychology/output/access-update/send-requested-sms-now.json').write_text(out, encoding='utf-8')
                print('ok', len(out), flush=True)
        except urllib.error.HTTPError as e:
            Path(r'D:/Psychology/output/access-update/send-requested-sms-now.err').write_bytes(e.read())
            print('http', e.code, flush=True)
            raise
    finally:
        try:
            ftp.delete(name)
        except Exception:
            pass
        ftp.storbinary('STOR .htaccess', io.BytesIO(original_ht))
        print('cleaned', flush=True)
