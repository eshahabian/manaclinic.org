"""Deploy sms_service.php with first-name-only greeting."""
from pathlib import Path
from ftplib import FTP
import io

root = Path('D:/Psychology')
cfg = __import__('json').loads((root / '.vscode/sftp.json').read_text(encoding='utf-8-sig'))
local = (root / 'cpanel/includes/sms_service.php').read_bytes()
needle = 'Always greet with first name only'.encode('utf-8')
if needle not in local:
    raise RuntimeError('first-name change missing in local file')

with FTP() as ftp:
    ftp.connect(cfg['host'], int(cfg.get('port', 21)), timeout=45)
    ftp.login(cfg['username'], cfg['password'])
    ftp.cwd('/public_html')
    remote = 'includes/sms_service.php'
    try:
        ftp.delete(remote)
    except Exception:
        pass
    ftp.storbinary('STOR ' + remote, io.BytesIO(local))
    verify = io.BytesIO()
    ftp.retrbinary('RETR ' + remote, verify.write)
    if verify.getvalue() != local:
        raise RuntimeError('verify failed')
    print('deployed first-name greeting', flush=True)
