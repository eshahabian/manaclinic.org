"""Deploy sms_service.php aligned with Haft Rokh send body."""
from pathlib import Path
from ftplib import FTP
import io
import json

root = Path('D:/Psychology')
cfg = json.loads((root / '.vscode/sftp.json').read_text(encoding='utf-8-sig'))
local = (root / 'cpanel/includes/sms_service.php').read_bytes()
text = local.decode('utf-8')
if 'عین هفت‌رخ' not in text:
    raise RuntimeError('haft align missing')
if "'IsFlash' => false" in text.split('function sms_provider_send')[1].split('function sms_dispatch')[0]:
    raise RuntimeError('IsFlash still in send path')

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
    v = io.BytesIO()
    ftp.retrbinary('RETR ' + remote, v.write)
    if v.getvalue() != local:
        raise RuntimeError('verify failed')
    print('deployed haft-aligned sms_service.php', flush=True)
