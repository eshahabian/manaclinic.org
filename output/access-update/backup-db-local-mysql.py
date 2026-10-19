"""Try dumping DB: remote MySQL first, else server-side CLI via FTP-uploaded runner."""
from pathlib import Path
from ftplib import FTP
import io
import json
import re
import secrets
import socket
import time
import urllib.error
import urllib.request
import zipfile

root = Path('D:/Psychology')
cfg = json.loads((root / '.vscode/sftp.json').read_text(encoding='utf-8-sig'))
backups = sorted((Path('D:/ManaClinic-Backups')).glob('manaclinic-full-*'), key=lambda p: p.stat().st_mtime, reverse=True)
backups = [p for p in backups if p.is_dir()]
dest = backups[0]
db_dir = dest / 'database'
db_dir.mkdir(exist_ok=True)
local_sql = db_dir / 'manaclinic.sql'
config_text = (dest / 'public_html' / 'config.php').read_text(encoding='utf-8', errors='replace')
db_name = re.search(r"'db_name'\s*=>\s*'([^']+)'", config_text).group(1)
db_user = re.search(r"'db_user'\s*=>\s*'([^']+)'", config_text).group(1)
db_pass = re.search(r"'db_pass'\s*=>\s*'([^']+)'", config_text).group(1)

print('Backup folder:', dest, flush=True)
print('DB name:', db_name, 'user:', db_user, flush=True)

# 1) Probe whether MySQL port is open / accepts remote
for host in ('manaclinic.org', '89.42.208.211'):
    for port in (3306, 3307):
        try:
            s = socket.create_connection((host, port), timeout=5)
            s.close()
            print(f'Port open: {host}:{port}', flush=True)
        except OSError:
            print(f'Port closed: {host}:{port}', flush=True)

# 2) Server-side dump to file using a cron-like runner under includes/
#    Hit via existing front controller? Prefer direct file under invite/ which may be less filtered.
token = secrets.token_hex(32)
remote_sql = 'storage/_backup_' + secrets.token_hex(6) + '.sql'
helper_rel = 'invite/_b_' + secrets.token_hex(6) + '.php'
helper = r'''<?php
@set_time_limit(0);
@ini_set('memory_limit','512M');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if (($_SERVER['HTTP_X_SETUP_TOKEN'] ?? '') !== '__TOKEN__' || time() > __EXPIRY__) { http_response_code(404); exit; }
$config = require dirname(__DIR__) . '/config.php';
$host=(string)$config['db_host']; $name=(string)$config['db_name']; $user=(string)$config['db_user']; $pass=(string)$config['db_pass'];
$out = dirname(__DIR__) . '/__REMOTE_SQL__';
@unlink($out);
$mysqli = new mysqli($host,$user,$pass,$name);
if ($mysqli->connect_errno) { echo json_encode(['ok'=>false,'error'=>'connect']); exit; }
$mysqli->set_charset('utf8mb4');
$fh=fopen($out,'wb');
fwrite($fh, "-- Mana Clinic backup\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");
$res=$mysqli->query("SHOW FULL TABLES WHERE Table_type='BASE TABLE'");
$tables=[]; while($r=$res->fetch_row()) $tables[]=$r[0];
foreach($tables as $table){
  $safe=str_replace('`','``',$table);
  $create=$mysqli->query('SHOW CREATE TABLE `'.$safe.'`')->fetch_assoc();
  fwrite($fh, "DROP TABLE IF EXISTS `$safe`;\n".$create['Create Table'].";\n\n");
  $q=$mysqli->query('SELECT * FROM `'.$safe.'`', MYSQLI_USE_RESULT);
  while($row=$q->fetch_assoc()){
    $cols=$vals=[];
    foreach($row as $k=>$v){ $cols[]='`'.str_replace('`','``',$k).'`'; $vals[]=($v===null?'NULL':("'".$mysqli->real_escape_string((string)$v)."'")); }
    fwrite($fh,'INSERT INTO `'.$safe.'` ('.implode(',',$cols).') VALUES ('.implode(',',$vals).");\n");
  }
  $q->free(); fwrite($fh,"\n");
}
fwrite($fh,"SET FOREIGN_KEY_CHECKS=1;\n"); fclose($fh);
echo json_encode(['ok'=>true,'size'=>filesize($out),'path'=>'__REMOTE_SQL__']);
'''
helper = (
    helper.replace('__TOKEN__', token)
    .replace('__EXPIRY__', str(int(time.time()) + 1200))
    .replace('__REMOTE_SQL__', remote_sql)
)

with FTP() as ftp:
    ftp.connect(cfg['host'], int(cfg.get('port', 21)), timeout=90)
    ftp.login(cfg['username'], cfg['password'])
    ftp.set_pasv(True)
    ftp.cwd('/public_html')
    try:
        ftp.mkd('storage')
    except Exception:
        pass
    ftp.storbinary('STOR ' + helper_rel, io.BytesIO(helper.encode('utf-8')))
    print('Uploaded helper', helper_rel, flush=True)
    urls = [
        'https://manaclinic.org/' + helper_rel,
        'https://www.manaclinic.org/' + helper_rel,
    ]
    last_err = None
    body = None
    for url in urls:
        try:
            req = urllib.request.Request(
                url,
                data=b'ping=1',
                headers={
                    'X-Setup-Token': token,
                    'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) ManaBackup/1.0',
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                method='POST',
            )
            with urllib.request.urlopen(req, timeout=900) as response:
                body = response.read().decode('utf-8', 'replace')
            print('OK via', url, body[:300], flush=True)
            break
        except Exception as e:
            last_err = e
            print('Fail', url, e, flush=True)
            # read body if HTTPError
            if isinstance(e, urllib.error.HTTPError):
                try:
                    print('Body', e.read()[:300], flush=True)
                except Exception:
                    pass
    if body is None:
        try:
            ftp.delete(helper_rel)
        except Exception:
            pass
        raise SystemExit(f'DB dump helper blocked: {last_err}')

    info = json.loads(body)
    if not info.get('ok'):
        raise SystemExit('Dump failed: ' + body)
    with open(local_sql, 'wb') as fh:
        ftp.retrbinary('RETR ' + remote_sql, fh.write)
    for path in (helper_rel, remote_sql):
        try:
            ftp.delete(path)
        except Exception:
            pass

print('SQL MB:', round(local_sql.stat().st_size / 1_000_000, 2), flush=True)
zip_path = dest.parent / f'{dest.name}.zip'
with zipfile.ZipFile(zip_path, 'w', compression=zipfile.ZIP_DEFLATED, compresslevel=6) as zf:
    for path in dest.rglob('*'):
        if path.is_file():
            zf.write(path, arcname=str(path.relative_to(dest.parent)))
(dest / 'BACKUP_INFO.txt').write_text(
    '\n'.join([
        'Site: https://manaclinic.org',
        f'Folder: {dest}',
        f'Zip: {zip_path}',
        f'Files + database backup complete',
        f'Database: {round(local_sql.stat().st_size/1_000_000,1)} MB',
        f'Zip: {round(zip_path.stat().st_size/1_000_000,1)} MB',
    ]) + '\n',
    encoding='utf-8',
)
print('Updated zip:', zip_path, 'MB=', round(zip_path.stat().st_size / 1_000_000, 1), flush=True)
