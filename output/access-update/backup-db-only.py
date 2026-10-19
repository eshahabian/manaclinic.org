"""Dump live MySQL to a server file, then FTP it down. Avoids HTTP 403 on large responses."""
from pathlib import Path
from ftplib import FTP
import io
import json
import secrets
import time
import urllib.request
import zipfile

root = Path('D:/Psychology')
cfg = json.loads((root / '.vscode/sftp.json').read_text(encoding='utf-8-sig'))
# Attach into the newest full backup folder if present.
backups = sorted((Path('D:/ManaClinic-Backups')).glob('manaclinic-full-*'), key=lambda p: p.stat().st_mtime, reverse=True)
backups = [p for p in backups if p.is_dir()]
if not backups:
    raise SystemExit('No full backup folder found')
dest = backups[0]
db_dir = dest / 'database'
db_dir.mkdir(parents=True, exist_ok=True)
local_sql = db_dir / 'manaclinic.sql'

token = secrets.token_hex(32)
helper_name = 'mana-dbdump-' + secrets.token_hex(8) + '.php'
remote_sql = 'storage/manaclinic-backup-' + secrets.token_hex(6) + '.sql'
helper = r'''<?php
@set_time_limit(0);
@ini_set('memory_limit', '512M');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || time() > __EXPIRY__ || !hash_equals('__TOKEN__', $_SERVER['HTTP_X_SETUP_TOKEN'] ?? '')) {
    http_response_code(404);
    exit;
}
$config = require __DIR__ . '/config.php';
$host = (string)($config['db_host'] ?? 'localhost');
$name = (string)($config['db_name'] ?? '');
$user = (string)($config['db_user'] ?? '');
$pass = (string)($config['db_pass'] ?? '');
$out = __DIR__ . '/__REMOTE_SQL__';
@unlink($out);
$ok = false;
$method = '';
$error = '';

$bins = ['/usr/bin/mysqldump', '/usr/local/bin/mysqldump', 'mysqldump'];
foreach ($bins as $bin) {
    $cmd = escapeshellcmd($bin)
        . ' --host=' . escapeshellarg($host)
        . ' --user=' . escapeshellarg($user)
        . ' --password=' . escapeshellarg($pass)
        . ' --single-transaction --quick --routines --triggers --default-character-set=utf8mb4 '
        . escapeshellarg($name)
        . ' > ' . escapeshellarg($out) . ' 2> ' . escapeshellarg($out . '.err');
    $code = 1;
    @exec($cmd, $unused, $code);
    if ($code === 0 && is_file($out) && filesize($out) > 100) {
        $ok = true;
        $method = 'mysqldump';
        @unlink($out . '.err');
        break;
    }
    if (is_file($out . '.err')) {
        $error = trim((string)@file_get_contents($out . '.err'));
        @unlink($out . '.err');
    }
    @unlink($out);
}

if (!$ok) {
    $mysqli = @new mysqli($host, $user, $pass, $name);
    if ($mysqli->connect_errno) {
        echo json_encode(['ok' => false, 'error' => 'DB connect failed'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $mysqli->set_charset('utf8mb4');
    $fh = fopen($out, 'wb');
    if (!$fh) {
        echo json_encode(['ok' => false, 'error' => 'Cannot write dump file'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    fwrite($fh, "-- Mana Clinic backup\n-- Generated: " . date('c') . "\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");
    $tables = [];
    $res = $mysqli->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");
    while ($row = $res->fetch_row()) {
        $tables[] = $row[0];
    }
    foreach ($tables as $table) {
        $safe = str_replace('`', '``', $table);
        $create = $mysqli->query('SHOW CREATE TABLE `' . $safe . '`')->fetch_assoc();
        $createSql = $create['Create Table'] ?? null;
        if (!$createSql) {
            continue;
        }
        fwrite($fh, "DROP TABLE IF EXISTS `$safe`;\n$createSql;\n\n");
        $q = $mysqli->query('SELECT * FROM `' . $safe . '`', MYSQLI_USE_RESULT);
        if (!$q) {
            continue;
        }
        while ($row = $q->fetch_assoc()) {
            $cols = [];
            $vals = [];
            foreach ($row as $k => $v) {
                $cols[] = '`' . str_replace('`', '``', $k) . '`';
                $vals[] = $v === null ? 'NULL' : ("'" . $mysqli->real_escape_string((string)$v) . "'");
            }
            fwrite($fh, 'INSERT INTO `' . $safe . '` (' . implode(',', $cols) . ') VALUES (' . implode(',', $vals) . ");\n");
        }
        $q->free();
        fwrite($fh, "\n");
    }
    fwrite($fh, "SET FOREIGN_KEY_CHECKS=1;\n");
    fclose($fh);
    $ok = is_file($out) && filesize($out) > 100;
    $method = 'php';
}

echo json_encode([
    'ok' => $ok,
    'method' => $method,
    'size' => $ok ? filesize($out) : 0,
    'path' => '__REMOTE_SQL__',
    'error' => $error,
], JSON_UNESCAPED_UNICODE);
'''
helper = (
    helper.replace('__TOKEN__', token)
    .replace('__EXPIRY__', str(int(time.time()) + 1200))
    .replace('__REMOTE_SQL__', remote_sql)
)

print('Target backup folder:', dest, flush=True)
with FTP() as ftp:
    ftp.connect(cfg['host'], int(cfg.get('port', 21)), timeout=90)
    ftp.login(cfg['username'], cfg['password'])
    ftp.set_pasv(True)
    ftp.cwd('/public_html')
    # ensure storage exists
    try:
        ftp.mkd('storage')
    except Exception:
        pass
    original = io.BytesIO()
    ftp.retrbinary('RETR .htaccess', original.write)
    original_ht = original.getvalue()
    rule = b'RewriteRule ^' + helper_name.replace('.', r'\.').encode() + b'$ ' + helper_name.encode() + b' [END]'
    modified = original_ht.replace(b'RewriteEngine On', b'RewriteEngine On\n' + rule, 1)
    if modified == original_ht:
        raise RuntimeError('htaccess patch failed')
    try:
        ftp.storbinary('STOR ' + helper_name, io.BytesIO(helper.encode('utf-8')))
        ftp.storbinary('STOR .htaccess', io.BytesIO(modified))
        req = urllib.request.Request(
            'https://manaclinic.org/' + helper_name,
            data=b'',
            headers={'X-Setup-Token': token, 'User-Agent': 'ManaDbBackup/1.0'},
            method='POST',
        )
        with urllib.request.urlopen(req, timeout=900) as response:
            body = response.read().decode('utf-8', 'replace')
        print('Dump helper response:', body[:500], flush=True)
        info = json.loads(body)
        if not info.get('ok'):
            raise RuntimeError('Dump failed: ' + body)
        print('Downloading SQL via FTP...', flush=True)
        with open(local_sql, 'wb') as fh:
            ftp.retrbinary('RETR ' + remote_sql, fh.write)
        print('SQL saved:', local_sql, 'MB=', round(local_sql.stat().st_size / 1_000_000, 2), flush=True)
    finally:
        try:
            ftp.storbinary('STOR .htaccess', io.BytesIO(original_ht))
        except Exception:
            pass
        for path in (helper_name, remote_sql):
            try:
                ftp.delete(path)
            except Exception:
                pass

# Refresh zip with DB included
zip_path = dest.parent / f'{dest.name}.zip'
print('Refreshing zip...', flush=True)
with zipfile.ZipFile(zip_path, 'w', compression=zipfile.ZIP_DEFLATED, compresslevel=6) as zf:
    for path in dest.rglob('*'):
        if path.is_file():
            zf.write(path, arcname=str(path.relative_to(dest.parent)))

info_txt = dest / 'BACKUP_INFO.txt'
lines = [
    f'Site: https://manaclinic.org',
    f'Created: {time.strftime("%Y-%m-%d %H:%M:%S")}',
    f'Folder: {dest}',
    f'Zip: {zip_path}',
    f'Database dump: OK ({round(local_sql.stat().st_size/1_000_000, 1)} MB)',
    f'Zip size: {round(zip_path.stat().st_size/1_000_000, 1)} MB',
]
info_txt.write_text('\n'.join(lines) + '\n', encoding='utf-8')
summary = {
    'dest': str(dest),
    'zip': str(zip_path),
    'db_ok': True,
    'db_mb': round(local_sql.stat().st_size / 1_000_000, 1),
    'zip_mb': round(zip_path.stat().st_size / 1_000_000, 1),
}
(dest / 'summary.json').write_text(json.dumps(summary, ensure_ascii=True, indent=2), encoding='utf-8')
print(json.dumps(summary, ensure_ascii=True), flush=True)
print('DB backup attached.', flush=True)
