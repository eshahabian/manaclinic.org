"""Probe WebOne GetCredit using local config without printing the API key."""
import re
import urllib.request
from pathlib import Path

cfg = Path('D:/Psychology/cpanel/config.sms.php').read_text(encoding='utf-8')
match = re.search(r"'api_key'\s*=>\s*'([^']+)'", cfg)
if not match:
    raise SystemExit('api_key missing in config.sms.php')
key = match.group(1)
req = urllib.request.Request(
    'https://api.payamakapi.ir/api/v1/SMS/GetCredit',
    headers={'Accept': 'application/json', 'X-API-KEY': key},
)
try:
    with urllib.request.urlopen(req, timeout=25) as response:
        print('HTTP', response.status)
        print(response.read()[:500].decode('utf-8', 'replace'))
except Exception as exc:
    code = getattr(exc, 'code', None)
    print('HTTP', code)
    if hasattr(exc, 'read'):
        print(exc.read()[:800].decode('utf-8', 'replace'))
    else:
        print(type(exc).__name__, exc)
