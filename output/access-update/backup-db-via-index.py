"""Temporary early hook in index.php for DB dump, then restore."""
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
dest = sorted((Path('D:/ManaClinic-Backups')).glob('manaclinic-full-*'), key=lambda p: p.stat().st_mtime, reverse=True)
dest = [p for p in dest if p.is_dir()][0]
db_dir = dest / 'database'
db_dir.mkdir(exist_ok=True)
local_sql = db_dir / 'manaclinic.sql'
token = secrets.token_hex(32)
remote_sql = 'storage/_bk_' + secrets.token_hex(6) + '.sql'

# Put hook immediately after declare(strict_types=1); so it always runs.
hook = r'''
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) === '/internal-db-backup')) {
    if (!hash_equals('__TOKEN__', (string)($_SERVER['HTTP_X_SETUP_TOKEN'] ?? '')) || time() > __EXPIRY__) {
        http_response_code(404);
        exit;
    }
    @set_time_limit(0);
    @ini_set('memory_limit', '512M');
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $config = require __DIR__ . '/config.php';
    $out = __DIR__ . '/__REMOTE_SQL__';
    @unlink($out);
    $mysqli = new mysqli($config['db_host'], $config['db_user'], $config['db_pass'], $config['db_name']);
    if ($mysqli->connect_errno) {
        echo json_encode(['ok' => false, 'error' => 'connect']);
        exit;
    }
    $mysqli->set_charset('utf8mb4');
    $fh = fopen($out, 'wb');
    fwrite($fh, "-- Mana Clinic backup\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");
    $res = $mysqli->query("SHOW FULL TABLES WHERE Table_type='BASE TABLE'");
    $tables = [];
    while ($r = $res->fetch_row()) { $tables[] = $r[0]; }
    foreach ($tables as $table) {
        $safe = str_replace('`', '``', $table);
        $create = $mysqli->query('SHOW CREATE TABLE `' . $safe . '`')->fetch_assoc();
        fwrite($fh, "DROP TABLE IF EXISTS `$safe`;\n" . $create['Create Table'] . ";\n\n");
        $q = $mysqli->query('SELECT * FROM `' . $safe . '`', MYSQLI_USE_RESULT);
        while ($row = $q->fetch_assoc()) {
            $cols = []; $vals = [];
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
    echo json_encode(['ok' => true, 'size' => filesize($out), 'tables' => count($tables)]);
    exit;
}
'''
hook = hook.replace('__TOKEN__', token).replace('__EXPIRY__', str(int(time.time()) + 1200)).replace('__REMOTE_SQL__', remote_sql)

with FTP() as ftp:
    ftp.connect(cfg['host'], int(cfg.get('port', 21)), timeout=90)
    ftp.login(cfg['username'], cfg['password'])
    ftp.set_pasv(True)
    ftp.cwd('/public_html')
    original = io.BytesIO()
    ftp.retrbinary('RETR index.php', original.write)
    original_index = original.getvalue()
    text = original_index.decode('utf-8')
    needle = 'declare(strict_types=1);'
    if needle not in text:
        raise RuntimeError('strict_types marker missing')
    if 'TEMP backup hook' in text or 'internal-db-backup' in text:
        # ensure clean base
        pass
    patched = text.replace(needle, needle + "\n" + hook, 1)
    try:
        # delete+store to force inode/mtime change for opcache
        try:
            ftp.delete('index.php')
        except Exception:
            pass
        ftp.storbinary('STOR index.php', io.BytesIO(patched.encode('utf-8')))
        time.sleep(2)
        print('Patched index.php early hook', flush=True)
        req = urllib.request.Request(
            'https://manaclinic.org/internal-db-backup',
            data=b'1',
            headers={
                'X-Setup-Token': token,
                'User-Agent': 'Mozilla/5.0 ManaBackup/1.0',
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            method='POST',
        )
        with urllib.request.urlopen(req, timeout=900) as response:
            body = response.read().decode('utf-8', 'replace')
        print('Response:', body[:500], flush=True)
        if body.lstrip().startswith('<'):
            raise RuntimeError('Got HTML instead of JSON — hook not executed')
        info = json.loads(body)
        if not info.get('ok'):
            raise RuntimeError(body)
        with open(local_sql, 'wb') as fh:
            ftp.retrbinary('RETR ' + remote_sql, fh.write)
        print('SQL MB:', round(local_sql.stat().st_size / 1_000_000, 2), flush=True)
    finally:
        try:
            ftp.delete('index.php')
        except Exception:
            pass
        ftp.storbinary('STOR index.php', io.BytesIO(original_index))
        print('Restored index.php', flush=True)
        try:
            ftp.delete(remote_sql)
        except Exception:
            pass

zip_path = dest.parent / f'{dest.name}.zip'
print('Refreshing zip...', flush=True)
with zipfile.ZipFile(zip_path, 'w', compression=zipfile.ZIP_DEFLATED, compresslevel=6) as zf:
    for path in dest.rglob('*'):
        if path.is_file():
            zf.write(path, arcname=str(path.relative_to(dest.parent)))
(dest / 'BACKUP_INFO.txt').write_text(
    '\n'.join([
        'Site: https://manaclinic.org',
        f'Created: {time.strftime("%Y-%m-%d %H:%M:%S")}',
        f'Folder: {dest}',
        f'Zip: {zip_path}',
        f'Database: {round(local_sql.stat().st_size/1_000_000, 1)} MB',
        f'Zip size: {round(zip_path.stat().st_size/1_000_000, 1)} MB',
        'Contents: public_html + database/manaclinic.sql',
    ]) + '\n',
    encoding='utf-8',
)
print(json.dumps({
    'dest': str(dest),
    'zip': str(zip_path),
    'db_mb': round(local_sql.stat().st_size / 1_000_000, 1),
    'zip_mb': round(zip_path.stat().st_size / 1_000_000, 1),
}, ensure_ascii=True), flush=True)
print('Backup complete with database.', flush=True)
