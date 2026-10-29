from pathlib import Path
import ftplib, io, json, secrets, time, urllib.request

root = Path('D:/Psychology')
work = root / 'output/remove-app-guide'
cfg = json.loads((root / '.vscode/sftp.json').read_text('utf-8-sig'))
name = 'pages/staff_app.php'
new = (root / 'cpanel' / name).read_bytes()
norm = lambda b: b.replace(b'\r\n', b'\n')
baseline = (work / 'local-before.php').read_bytes()
tag = 'private-guide-check-' + secrets.token_hex(16)
token = secrets.token_hex(32)
with ftplib.FTP() as ftp:
    ftp.connect(cfg['host'], int(cfg.get('port', 21)), timeout=40)
    ftp.login(cfg['username'], cfg['password'])
    ftp.cwd('/public_html')
    def get(path):
        buf = io.BytesIO()
        ftp.retrbinary('RETR ' + path, buf.write)
        return buf.getvalue()
    def put(path, data):
        ftp.storbinary('STOR ' + path, io.BytesIO(data))
    old = get(name)
    assert norm(old) == norm(baseline), 'Remote differs from local baseline'
    (work / 'before-staff_app.php').write_bytes(old)
    ftp.mkd(tag)
    guard = "<?php if(time()>" + str(int(time.time()) + 300) + "||!hash_equals('" + token + "',$_SERVER['HTTP_X_VERIFY_TOKEN']??'')){http_response_code(404);exit;} header('Cache-Control: no-store'); "
    check = "token_get_all(file_get_contents(__DIR__.'/candidate.php'),TOKEN_PARSE); if(($_SERVER['HTTP_X_CHECK_MODE']??'')==='live'){ $p=dirname(__DIR__).'/pages/staff_app.php'; if(function_exists('opcache_invalidate'))opcache_invalidate($p,true); echo hash_file('sha256',$p); }else{echo 'PASS syntax';}"
    def call(mode):
        req = urllib.request.Request('https://manaclinic.org/' + tag + '/', headers={'X-Verify-Token': token, 'X-Check-Mode': mode})
        return urllib.request.urlopen(req, timeout=40).read().decode()
    deployed = False
    try:
        put(tag + '/candidate.php', new)
        put(tag + '/index.php', (guard + check).encode())
        assert call('syntax') == 'PASS syntax'
        assert get(name) == old, 'Remote changed during validation'
        put(name, new)
        deployed = True
        assert get(name) == new
        import hashlib
        assert call('live') == hashlib.sha256(new).hexdigest()
        assert b"home_tile_allowed($user, '/app/therapist-guide')" not in new
        print('PASS PHP syntax; exact live content; OPcache refreshed; guide tile removed')
        (work / 'verification.txt').write_text('PASS PHP syntax; exact live content; OPcache refreshed; guide tile removed\n', encoding='utf-8')
    except Exception:
        if deployed:
            put(name, old)
        raise
    finally:
        for file in ['candidate.php', 'index.php', 'error_log']:
            try:
                ftp.delete(tag + '/' + file)
            except ftplib.error_perm:
                pass
        ftp.rmd(tag)
