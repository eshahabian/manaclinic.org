from pathlib import Path
from ftplib import FTP
import io, json, secrets, time, urllib.request

root = Path('D:/Psychology')
cfg = json.loads((root / '.vscode/sftp.json').read_text('utf-8-sig'))
token = secrets.token_hex(32)
name = 'sms-audit-' + secrets.token_hex(12) + '.php'
php = r'''<?php
header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
if (($_SERVER['REQUEST_METHOD']??'')!=='POST' || time()>__EXPIRY__ || !hash_equals('__TOKEN__',$_SERVER['HTTP_X_SETUP_TOKEN']??'')) {http_response_code(404);exit;}
try {
$c=require __DIR__.'/config.php'; date_default_timezone_set($c['timezone']??'Asia/Tehran');
$pdo=new PDO(sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4',$c['db_host'],$c['db_name']),$c['db_user'],$c['db_pass'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$pdo->exec("SET time_zone='+03:30'"); $pdo->exec('SET TRANSACTION READ ONLY'); $pdo->beginTransaction();
$out=['now'=>date('Y-m-d H:i:s')];
$out['settings']=$pdo->query("SELECT setting_key,setting_value FROM app_settings WHERE setting_key IN ('sms_enabled','sms_worker_last_run','sms_cron_installed','sms_kind_enabled_doctor_daily_digest','sms_kind_enabled_doctor_appointment_cancelled','sms_kind_enabled_therapist_copy')")->fetchAll();
$out['daily']=$pdo->query("SELECT DATE(created_at) day,kind,status,COUNT(*) n FROM sms_outbox WHERE created_at>=DATE_SUB(NOW(),INTERVAL 7 DAY) GROUP BY DATE(created_at),kind,status ORDER BY day DESC,kind,status")->fetchAll();
$out['doctor_recent']=$pdo->query("SELECT kind,status,body,created_at,due_at,sent_at,last_error,provider_id IS NOT NULL has_provider_id,context_json FROM sms_outbox WHERE (kind LIKE 'doctor_%' OR kind='therapist_copy') AND created_at>=DATE_SUB(NOW(),INTERVAL 4 DAY) ORDER BY created_at DESC LIMIT 100")->fetchAll();
$out['errors']=$pdo->query("SELECT kind,status,last_error,COUNT(*) n,MAX(created_at) latest FROM sms_outbox WHERE created_at>=DATE_SUB(NOW(),INTERVAL 4 DAY) AND status IN ('FAILED','UNKNOWN','PENDING','PROCESSING') GROUP BY kind,status,last_error")->fetchAll();
$out['eligible']=$pdo->query("SELECT DATE(a.starts_at) day,COUNT(*) appointments,COUNT(DISTINCT a.doctor_id) doctors FROM appointments a JOIN doctor_profiles dp ON dp.id=a.doctor_id JOIN users u ON u.id=dp.user_id WHERE a.starts_at>=CURDATE()-INTERVAL 3 DAY AND a.starts_at<CURDATE()+INTERVAL 2 DAY AND a.status='CONFIRMED' AND dp.is_active=1 AND dp.is_approved=1 AND u.is_disabled=0 GROUP BY DATE(a.starts_at)")->fetchAll();
$pdo->rollBack();
$out['source_hash']=hash_file('sha256',__DIR__.'/includes/sms_service.php');
$lines=[];$rc=1;if(is_callable('exec'))exec('crontab -l 2>/dev/null',$lines,$rc);$out['cron_rc']=$rc;$out['cron_sms_count']=count(array_filter($lines,fn($x)=>str_contains($x,'sms-worker.php')||str_contains($x,'cron/sms')));
echo json_encode($out,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
}catch(Throwable $e){http_response_code(500);echo json_encode(['error'=>$e->getMessage()]);}
'''.replace('__EXPIRY__',str(int(time.time())+600)).replace('__TOKEN__',token)
with FTP() as ftp:
    ftp.connect(cfg['host'],int(cfg.get('port',21)),timeout=40)
    ftp.login(cfg['username'],cfg['password']); ftp.cwd('/public_html')
    buf=io.BytesIO();ftp.retrbinary('RETR .htaccess',buf.write);original=buf.getvalue()
    modified=original.replace(b'RewriteEngine On',b'RewriteEngine On\nRewriteRule ^'+name.replace('.',r'\.').encode()+b'$ '+name.encode()+b' [END]',1)
    if original==modified: raise RuntimeError('No route insertion point')
    try:
        ftp.storbinary('STOR '+name,io.BytesIO(php.encode()))
        ftp.storbinary('STOR .htaccess',io.BytesIO(modified))
        req=urllib.request.Request('https://manaclinic.org/'+name,data=b'',headers={'X-Setup-Token':token},method='POST')
        with urllib.request.urlopen(req,timeout=60) as res: result=json.load(res)
        (root/'output/access-update/therapist-sms-audit-20261102.json').write_text(json.dumps(result,ensure_ascii=False,indent=2),encoding='utf-8')
        print(json.dumps(result.get("doctor_recent", []),ensure_ascii=True))
        buf=io.BytesIO();ftp.retrbinary('RETR includes/sms_service.php',buf.write)
        (root/'output/access-update/therapist-sms-live-source-20261102.php').write_bytes(buf.getvalue())
    finally:
        buf=io.BytesIO();ftp.retrbinary('RETR .htaccess',buf.write)
        if buf.getvalue()==modified: ftp.storbinary('STOR .htaccess',io.BytesIO(original))
        else: raise RuntimeError('Routing changed concurrently; manual cleanup required')
        ftp.delete(name)
        buf=io.BytesIO();ftp.retrbinary('RETR .htaccess',buf.write)
        assert buf.getvalue()==original
        print('Temporary probe removed; routing restored and verified.')

