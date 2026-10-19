"""Deploy sms_service.php + helpers.php (first name + short date without year)."""
from pathlib import Path
from ftplib import FTP
import io

root = Path('D:/Psychology')
cfg = __import__('json').loads((root / '.vscode/sftp.json').read_text(encoding='utf-8-sig'))

files = {
    'includes/sms_service.php': root / 'cpanel/includes/sms_service.php',
    'includes/helpers.php': root / 'cpanel/includes/helpers.php',
}

for local_path in files.values():
    data = local_path.read_bytes()
    if local_path.name == 'helpers.php' and b'format_fa_sms_datetime' not in data:
        raise RuntimeError('format_fa_sms_datetime missing')
    if local_path.name == 'sms_service.php' and b'format_fa_sms_datetime' not in data:
        raise RuntimeError('sms_service not updated')
    if local_path.name == 'sms_service.php' and b'Always greet with first name only' not in data:
        raise RuntimeError('first-name change missing')

with FTP() as ftp:
    ftp.connect(cfg['host'], int(cfg.get('port', 21)), timeout=60)
    ftp.login(cfg['username'], cfg['password'])
    ftp.cwd('/public_html')
    for remote, local_path in files.items():
        data = local_path.read_bytes()
        try:
            ftp.delete(remote)
        except Exception:
            pass
        ftp.storbinary('STOR ' + remote, io.BytesIO(data))
        verify = io.BytesIO()
        ftp.retrbinary('RETR ' + remote, verify.write)
        if verify.getvalue() != data:
            raise RuntimeError('verify failed: ' + remote)
        print('ok', remote, flush=True)
print('deploy done', flush=True)
