"""
Full live-site backup to the laptop:
1) Recursive FTP download of /public_html
2) MySQL dump via ephemeral authenticated PHP helper (deleted after)
Does not print secrets.
"""
from __future__ import annotations

from pathlib import Path
from ftplib import FTP, error_perm
import io
import json
import secrets
import time
import urllib.request
import zipfile

root = Path('D:/Psychology')
# Keep full backups outside the git repo so they never hit GitHub size limits.
backup_root = Path('D:/ManaClinic-Backups')
cfg = json.loads((root / '.vscode/sftp.json').read_text(encoding='utf-8-sig'))
stamp = time.strftime('%Y%m%d-%H%M%S')
dest = backup_root / f'manaclinic-full-{stamp}'
files_dir = dest / 'public_html'
db_dir = dest / 'database'
meta_path = dest / 'BACKUP_INFO.txt'
backup_root.mkdir(parents=True, exist_ok=True)
files_dir.mkdir(parents=True, exist_ok=True)
db_dir.mkdir(parents=True, exist_ok=True)

SKIP_DIR_NAMES = {'.', '..'}
# Soft skip only ephemeral junk; keep media/uploads/config.
SKIP_NAME_PREFIXES = ()
SKIP_EXACT = {'.ftpquota', 'error_log', 'cgi-bin'}


def should_skip(name: str) -> bool:
    if name in SKIP_EXACT or name in SKIP_DIR_NAMES:
        return True
    lower = name.lower()
    if lower.endswith('.log') and name.startswith('php_error'):
        return True
    return False


def list_entries(ftp: FTP, remote: str) -> tuple[list[str], list[str], list[str]]:
    """Return (dirs, files, errors)."""
    dirs: list[str] = []
    files: list[str] = []
    errors: list[str] = []
    try:
        for name, facts in ftp.mlsd(remote):
            if should_skip(name):
                continue
            ftype = (facts.get('type') or '').lower()
            if ftype == 'dir':
                dirs.append(name)
            elif ftype == 'file':
                files.append(name)
        return dirs, files, errors
    except Exception:
        pass
    try:
        lines: list[str] = []
        ftp.retrlines(f'LIST {remote}', lines.append)
        for line in lines:
            parts = line.split(maxsplit=8)
            if len(parts) < 9:
                continue
            name = parts[8]
            if should_skip(name):
                continue
            if line.startswith('d'):
                dirs.append(name)
            elif line.startswith('-') or line.startswith('l'):
                files.append(name)
        return dirs, files, errors
    except Exception as e:
        errors.append(f'LIST failed {remote}: {e}')
        return [], [], errors


def ftp_walk(ftp: FTP, remote: str, local: Path) -> tuple[int, int, list[str]]:
    count = 0
    bytes_total = 0
    local.mkdir(parents=True, exist_ok=True)
    dirs, files, errors = list_entries(ftp, remote)

    for name in files:
        remote_path = f'{remote.rstrip("/")}/{name}'
        local_path = local / name
        try:
            with open(local_path, 'wb') as fh:
                ftp.retrbinary(f'RETR {remote_path}', fh.write)
            size = local_path.stat().st_size
            count += 1
            bytes_total += size
            if count % 50 == 0:
                print(f'  ... {count} files, {bytes_total/1_000_000:.1f} MB', flush=True)
        except Exception as e:
            errors.append(f'FILE {remote_path}: {e}')

    for name in dirs:
        remote_path = f'{remote.rstrip("/")}/{name}'
        sub_count, sub_bytes, sub_errors = ftp_walk(ftp, remote_path, local / name)
        count += sub_count
        bytes_total += sub_bytes
        errors.extend(sub_errors)

    return count, bytes_total, errors


print(f'Backup folder: {dest}', flush=True)
print('Downloading public_html via FTP...', flush=True)
t0 = time.time()
with FTP() as ftp:
    ftp.connect(cfg['host'], int(cfg.get('port', 21)), timeout=90)
    ftp.login(cfg['username'], cfg['password'])
    ftp.set_pasv(True)
    # Site document root used by previous deploy scripts
    try:
        ftp.cwd('/public_html')
        remote_root = '/public_html'
    except error_perm:
        ftp.cwd('/')
        remote_root = '/'
    file_count, file_bytes, file_errors = ftp_walk(ftp, remote_root, files_dir)

print(f'Files done: {file_count} files, {file_bytes/1_000_000:.1f} MB in {time.time()-t0:.0f}s', flush=True)
if file_errors:
    (dest / 'download-errors.txt').write_text('\n'.join(file_errors), encoding='utf-8')
    print(f'File download warnings: {len(file_errors)} (see download-errors.txt)', flush=True)

print('Creating database dump on server...', flush=True)
token = secrets.token_hex(32)
helper_name = 'mana-backup-' + secrets.token_hex(10) + '.php'
helper = r'''<?php
@set_time_limit(0);
@ini_set('memory_limit', '512M');
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
if ($name === '' || $user === '') {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'DB config missing';
    exit;
}
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="manaclinic.sql"');

// Prefer mysqldump when available on the host.
$candidates = [
    '/usr/bin/mysqldump',
    '/usr/local/bin/mysqldump',
    'mysqldump',
];
foreach ($candidates as $bin) {
    $cmd = escapeshellcmd($bin)
        . ' --host=' . escapeshellarg($host)
        . ' --user=' . escapeshellarg($user)
        . ' --password=' . escapeshellarg($pass)
        . ' --single-transaction --quick --routines --triggers --default-character-set=utf8mb4 '
        . escapeshellarg($name) . ' 2>/dev/null';
    $out = [];
    $code = 1;
    @exec($cmd, $out, $code);
    if ($code === 0 && $out) {
        echo implode("\n", $out);
        exit;
    }
}

$mysqli = @new mysqli($host, $user, $pass, $name);
if ($mysqli->connect_errno) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'DB connect failed';
    exit;
}
$mysqli->set_charset('utf8mb4');
echo "-- Mana Clinic backup\n-- Generated: " . date('c') . "\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n";
$tables = [];
$res = $mysqli->query('SHOW FULL TABLES WHERE Table_type = \'BASE TABLE\'');
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
    echo "DROP TABLE IF EXISTS `$safe`;\n$createSql;\n\n";
    $q = $mysqli->query('SELECT * FROM `' . $safe . '`', MYSQLI_USE_RESULT);
    if (!$q) {
        continue;
    }
    while ($row = $q->fetch_assoc()) {
        $cols = [];
        $vals = [];
        foreach ($row as $k => $v) {
            $cols[] = '`' . str_replace('`', '``', $k) . '`';
            if ($v === null) {
                $vals[] = 'NULL';
            } else {
                $vals[] = "'" . $mysqli->real_escape_string((string)$v) . "'";
            }
        }
        echo 'INSERT INTO `' . $safe . '` (' . implode(',', $cols) . ') VALUES (' . implode(',', $vals) . ");\n";
    }
    $q->free();
    echo "\n";
}
echo "SET FOREIGN_KEY_CHECKS=1;\n";
'''
helper = helper.replace('__TOKEN__', token).replace('__EXPIRY__', str(int(time.time()) + 900))

sql_path = db_dir / 'manaclinic.sql'
db_ok = False
db_error = ''
with FTP() as ftp:
    ftp.connect(cfg['host'], int(cfg.get('port', 21)), timeout=90)
    ftp.login(cfg['username'], cfg['password'])
    ftp.set_pasv(True)
    ftp.cwd('/public_html')
    original = io.BytesIO()
    ftp.retrbinary('RETR .htaccess', original.write)
    original_ht = original.getvalue()
    rule = b'RewriteRule ^' + helper_name.replace('.', r'\.').encode() + b'$ ' + helper_name.encode() + b' [END]'
    modified = original_ht.replace(b'RewriteEngine On', b'RewriteEngine On\n' + rule, 1)
    if modified == original_ht:
        raise RuntimeError('Could not patch .htaccess for DB dump helper')
    try:
        ftp.storbinary('STOR ' + helper_name, io.BytesIO(helper.encode('utf-8')))
        ftp.storbinary('STOR .htaccess', io.BytesIO(modified))
        req = urllib.request.Request(
            'https://manaclinic.org/' + helper_name,
            data=b'',
            headers={'X-Setup-Token': token, 'User-Agent': 'ManaFullBackup/1.0'},
            method='POST',
        )
        with urllib.request.urlopen(req, timeout=600) as response:
            payload = response.read()
        if payload.startswith(b'DB connect failed') or payload.startswith(b'<'):
            db_error = payload[:300].decode('utf-8', 'replace')
            (db_dir / 'dump-error.txt').write_text(db_error, encoding='utf-8')
        else:
            sql_path.write_bytes(payload)
            db_ok = True
            print(f'Database dump: {sql_path.stat().st_size/1_000_000:.1f} MB', flush=True)
    except Exception as e:
        db_error = str(e)
        (db_dir / 'dump-error.txt').write_text(db_error, encoding='utf-8')
        print('Database dump failed:', db_error, flush=True)
    finally:
        try:
            ftp.storbinary('STOR .htaccess', io.BytesIO(original_ht))
        except Exception:
            pass
        try:
            ftp.delete(helper_name)
        except Exception:
            pass

print('Creating zip archive...', flush=True)
zip_path = dest.parent / f'{dest.name}.zip'
with zipfile.ZipFile(zip_path, 'w', compression=zipfile.ZIP_DEFLATED, compresslevel=6) as zf:
    for path in dest.rglob('*'):
        if path.is_file():
            zf.write(path, arcname=str(path.relative_to(dest.parent)))

info = [
    f'Site: https://manaclinic.org',
    f'Created: {time.strftime("%Y-%m-%d %H:%M:%S")}',
    f'Folder: {dest}',
    f'Zip: {zip_path}',
    f'Files: {file_count} ({file_bytes/1_000_000:.1f} MB downloaded)',
    f'Database dump: {"OK (" + str(round(sql_path.stat().st_size/1_000_000, 1)) + " MB)" if db_ok else "FAILED - see database/dump-error.txt"}',
    f'File warnings: {len(file_errors)}',
    f'Zip size: {zip_path.stat().st_size/1_000_000:.1f} MB',
]
meta_path.write_text('\n'.join(info) + '\n', encoding='utf-8')
summary = {
    'dest': str(dest),
    'zip': str(zip_path),
    'files': file_count,
    'files_mb': round(file_bytes / 1_000_000, 1),
    'db_ok': db_ok,
    'db_mb': round(sql_path.stat().st_size / 1_000_000, 1) if db_ok else 0,
    'zip_mb': round(zip_path.stat().st_size / 1_000_000, 1),
    'errors': len(file_errors),
}
(dest / 'summary.json').write_text(json.dumps(summary, ensure_ascii=True, indent=2), encoding='utf-8')
print(json.dumps(summary, ensure_ascii=True), flush=True)
print('Backup complete.', flush=True)
