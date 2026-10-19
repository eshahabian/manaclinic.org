import ftplib
import io
import json
import secrets
import time
import urllib.request
from pathlib import Path

root = Path.cwd()
config = json.loads((root / '.vscode/sftp.json').read_text(encoding='utf-8-sig'))
files = ['pages/guide_first_session.php', 'pages/services.php', 'pages/articles.php', 'pages/faq.php', 'includes/service_page_helpers.php', 'includes/clinic_directory.php', 'index.php', 'pages/sitemap.php']
assets = []
backup = root / 'output' / ('content-seo-before-' + time.strftime('%Y%m%d-%H%M%S'))
backup.mkdir()
ftp = ftplib.FTP(config['host'], timeout=60)
ftp.login(config['username'], config['password'])
ftp.cwd('/public_html')

def read(name):
    buf = io.BytesIO()
    ftp.retrbinary('RETR ' + name, buf.write)
    return buf.getvalue()

def put(name, data):
    ftp.storbinary('STOR ' + name, io.BytesIO(data))

def get(url):
    with urllib.request.urlopen(url, timeout=60) as response:
        return response.read().decode('utf-8-sig')

def normalize(data):
    return data.decode('utf-8-sig').replace('\r\n', '\n')

originals = {}
for name in files:
    try:
        data = read(name)
    except ftplib.error_perm as e:
        if name != 'pages/guide_first_session.php' or not str(e).startswith('550'):
            raise
        originals[name] = None
        continue
    originals[name] = data
    (backup / name.replace('/', '_')).write_bytes(data)
    baseline = root / 'output/content-seo-baseline' / name.replace('/', '_')
    if normalize(data) != normalize(baseline.read_bytes()):
        raise RuntimeError('Remote differs from baseline; saved for merge: ' + name)
    print('Baseline checked: ' + name, flush=True)

tag = 'seo-verify-' + secrets.token_hex(12)
ftp.mkd(tag)
staged = []
deployed = []
try:
    php_names = [name for name in files if name.endswith('.php')]
    for i, name in enumerate(php_names):
        target = tag + '/' + str(i) + '.txt'
        put(target, (root / 'cpanel' / name).read_bytes())
        staged.append(target)
    harness = b'<?php header("Content-Type: text/plain; charset=utf-8"); try { foreach (glob(__DIR__ . "/*.txt") as $file) { token_get_all(file_get_contents($file), TOKEN_PARSE); } echo "PASS PHP syntax"; } catch (Throwable $e) { http_response_code(500); echo "FAIL PHP syntax: " . $e->getMessage(); }'
    put(tag + '/index.php', harness)
    staged.append(tag + '/index.php')
    result = get('https://manaclinic.org/' + tag + '/')
    if result.strip() != 'PASS PHP syntax':
        raise RuntimeError(result)
    print(result + ': ' + str(len(php_names)) + ' files', flush=True)

    for name in assets + files:
        data = (root / 'cpanel' / name).read_bytes()
        put(name, data)
        deployed.append(name)
        if read(name) != data:
            raise RuntimeError('Upload mismatch: ' + name)
        print('Deployed and verified: ' + name, flush=True)
except Exception:
    for name in reversed(deployed):
        if name in originals:
            if originals[name] is None:
                ftp.delete(name)
            else:
                put(name, originals[name])
    raise
finally:
    for name in staged:
        ftp.delete(name)
    ftp.rmd(tag)
    ftp.quit()

print('Deployment complete.', flush=True)
for path in ['/guides/first-therapy-session', '/services', '/articles', '/faq', '/services/couples', '/sitemap.xml']:
    html = get('https://manaclinic.org' + path)
    assert '/guides/first-therapy-session' in html, path
    print('PASS guide and links: ' + path, flush=True)
