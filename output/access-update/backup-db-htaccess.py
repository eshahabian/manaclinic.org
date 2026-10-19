"""DB dump using the previously-working root helper + htaccess END rule pattern."""
from pathlib import Path
from ftplib import FTP
import io
import json
import secrets
import time
import urllib.error
import urllib.request
import zipfile

root = Path('D:/Psychology')
cfg = json.loads((root / '.vscode/sftp.json').read_text(encoding='utf-8-sig'))
dest = sorted((Path('D:/ManaClinic-Backups')).glob('manaclinic-full-*'), key=lambda p: p.stat().st_mtime, reverse=True)
dest = [p for p in dest if p.is_dir()][0]
db_dir = dest / 'database'
db_dir.mkdir(exist_ok=True)
local_sql = db_dir / 'manaclinic.sql'
token = secrets.token_hex(32)
name = 'sms-diag-' + secrets.token_hex(8) + '.php'  # name pattern that worked earlier today
remote_sql = 'storage/_bk_' + secrets.token_hex(6) + '.sql'
helper = r'''<?php
@set_time_limit(0);
@ini_set('memory_limit','512M');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || time() > __EXPIRY__ || !hash_equals('__TOKEN__', $_SERVER['HTTP_X_SETUP_TOKEN'] ?? '')) { http_response_code(404); exit; }
$config = require __DIR__ . '/config.php';
$mysqli = new mysqli($config['db_host'],$config['db_user'],$config['db_pass'],$config['db_name']);
if ($mysqli->connect_errno) { echo json_encode(['ok'=>false,'error'=>'connect']); exit; }
$mysqli->set_charset('utf8mb4');
$out = __DIR__ . '/__REMOTE_SQL__';
@unlink($out);
$fh = fopen($out, 'wb');
fwrite($fh, "-- Mana Clinic backup\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");
$res = $mysqli->query("SHOW FULL TABLES WHERE Table_type='BASE TABLE'");
$tables=[]; while($r=$res->fetch_row()) $tables[]=$r[0];
foreach ($tables as $table) {
  $safe=str_replace('`','``',$table);
  $create=$mysqli->query('SHOW CREATE TABLE `'.$safe.'`')->fetch_assoc();
  fwrite($fh, "DROP TABLE IF EXISTS `$safe`;\n".$create['Create Table'].";\n\n");
  $q=$mysqli->query('SELECT * FROM `'.$safe.'`', MYSQLI_USE_RESULT);
  while ($row=$q->fetch_assoc()) {
    $cols=$vals=[];
    foreach ($row as $k=>$v) { $cols[]='`'.str_replace('`','``',$k).'`'; $vals[]=($v===null?'NULL':("'".$mysqli->real_escape_string((string)$v)."'")); }
    fwrite($fh, 'INSERT INTO `'.$safe.'` ('.implode(',',$cols).') VALUES ('.implode(',',$vals).");\n");
  }
  $q->free(); fwrite($fh, "\n");
}
fwrite($fh, "SET FOREIGN_KEY_CHECKS=1;\n"); fclose($fh);
echo json_encode(['ok'=>true,'size'=>filesize($out)]);
'''
helper = helper.replace('__TOKEN__', token).replace('__EXPIRY__', str(int(time.time()) + 1200)).replace('__REMOTE_SQL__', remote_sql)

with FTP() as ftp:
    ftp.connect(cfg['host'], int(cfg.get('port', 21)), timeout=90)
    ftp.login(cfg['username'], cfg['password'])
    ftp.set_pasv(True)
    ftp.cwd('/public_html')
    # cleanup leftover invite helpers
    try:
        for item in ftp.nlst('invite'):
            base = item.split('/')[-1]
            if base.startswith('_b_') and base.endswith('.php'):
                try:
                    ftp.delete('invite/' + base)
                except Exception:
                    pass
    except Exception:
        pass
    original = io.BytesIO()
    ftp.retrbinary('RETR .htaccess', original.write)
    original_ht = original.getvalue()
    rule = b'RewriteRule ^' + name.replace('.', r'\.').encode() + b'$ ' + name.encode() + b' [END]'
    modified = original_ht.replace(b'RewriteEngine On', b'RewriteEngine On\n' + rule, 1)
    if modified == original_ht:
        raise RuntimeError('htaccess insert failed')
    try:
        ftp.storbinary('STOR ' + name, io.BytesIO(helper.encode('utf-8')))
        ftp.storbinary('STOR .htaccess', io.BytesIO(modified))
        time.sleep(1)
        req = urllib.request.Request(
            'https://manaclinic.org/' + name,
            data=b'',
            headers={'X-Setup-Token': token, 'User-Agent': 'ManaSmsProbe/1.0'},
            method='POST',
        )
        try:
            with urllib.request.urlopen(req, timeout=900) as response:
                body = response.read().decode('utf-8', 'replace')
        except urllib.error.HTTPError as e:
            err_body = e.read().decode('utf-8', 'replace')
            print('HTTP', e.code, err_body[:400], flush=True)
            raise
        print('Response:', body[:400], flush=True)
        info = json.loads(body)
        if not info.get('ok'):
            raise RuntimeError(body)
        with open(local_sql, 'wb') as fh:
            ftp.retrbinary('RETR ' + remote_sql, fh.write)
        print('SQL MB:', round(local_sql.stat().st_size / 1_000_000, 2), flush=True)
    finally:
        ftp.storbinary('STOR .htaccess', io.BytesIO(original_ht))
        for path in (name, remote_sql):
            try:
                ftp.delete(path)
            except Exception:
                pass

zip_path = dest.parent / f'{dest.name}.zip'
with zipfile.ZipFile(zip_path, 'w', compression=zipfile.ZIP_DEFLATED, compresslevel=6) as zf:
    for path in dest.rglob('*'):
        if path.is_file():
            zf.write(path, arcname=str(path.relative_to(dest.parent)))
(dest / 'BACKUP_INFO.txt').write_text(
    f'Site: https://manaclinic.org\nFolder: {dest}\nZip: {zip_path}\nDB: {round(local_sql.stat().st_size/1_000_000,1)} MB\nZip: {round(zip_path.stat().st_size/1_000_000,1)} MB\n',
    encoding='utf-8',
)
print('Done', zip_path, flush=True)
