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
token = secrets.token_hex(16)
inject = (root / 'output/access-update/emad-sms-fix-inject.php').read_text(encoding='utf-8').replace('__TOKEN__', token)

with FTP() as ftp:
    ftp.connect(cfg['host'], int(cfg.get('port', 21)), timeout=90)
    ftp.login(cfg['username'], cfg['password'])
    ftp.set_pasv(True)
    ftp.cwd('/public_html')
    original = io.BytesIO()
    ftp.retrbinary('RETR index.php', original.write)
    original_index = original.getvalue()
    text = original_index.decode('utf-8')
    patched = text.replace('declare(strict_types=1);', 'declare(strict_types=1);' + inject, 1)
    try:
        try:
            ftp.delete('index.php')
        except Exception:
            pass
        ftp.storbinary('STOR index.php', io.BytesIO(patched.encode('utf-8')))
        time.sleep(1)
        req = urllib.request.Request(
            'https://manaclinic.org/internal-sms-fix',
            data=b'1',
            headers={
                'User-Agent': 'Mozilla/5.0 ManaSmsFix/1.0',
                'X-Setup-Token': token,
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            method='POST',
        )
        try:
            with urllib.request.urlopen(req, timeout=90) as resp:
                data = resp.read().decode('utf-8', errors='replace')
        except urllib.error.HTTPError as e:
            data = e.read().decode('utf-8', errors='replace')
            print('HTTP', e.code)
        out = root / 'output/access-update/probe-emad-sms-fix.json'
        out.write_text(data, encoding='utf-8')
        print(data.encode('ascii', 'backslashreplace').decode('ascii')[:2000])
    finally:
        try:
            ftp.delete('index.php')
        except Exception:
            pass
        ftp.storbinary('STOR index.php', io.BytesIO(original_index))
        print('restored')
