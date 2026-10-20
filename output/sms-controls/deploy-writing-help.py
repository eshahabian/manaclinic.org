from pathlib import Path
import ftplib, io, json, secrets, time, urllib.request, subprocess

root = Path('D:/Psychology')
files = ['includes/sms_service.php', 'pages/admin/sms_notify.php']
cfg = json.loads((root / '.vscode/sftp.json').read_text('utf-8-sig'))
tag = 'sms-writing-' + secrets.token_hex(12)
token = secrets.token_hex(32)
backup = root / 'output/sms-controls' / ('writing-before-' + time.strftime('%Y%m%d-%H%M%S'))
backup.mkdir()
def norm(b): return b.decode('utf-8-sig').replace('\r\n', '\n')
with ftplib.FTP() as ftp:
    ftp.connect(cfg['host'], int(cfg.get('port', 21)), timeout=45)
    ftp.login(cfg['username'], cfg['password'])
    ftp.cwd('/public_html')
    def get(n):
        b = io.BytesIO(); ftp.retrbinary('RETR ' + n, b.write); return b.getvalue()
    def put(n, b): ftp.storbinary('STOR ' + n, io.BytesIO(b))
    originals = {n: get(n) for n in files}
    for n, b in originals.items():
        (backup / n.replace('/', '_')).write_bytes(b)
        baseline = subprocess.check_output(['git', 'show', 'HEAD:cpanel/' + n], cwd=root)
        if norm(b) != norm(baseline): raise RuntimeError('Remote differs from baseline: ' + n)
    print('PASS current remote matches baseline', flush=True)
    ftp.mkd(tag)
    staged = []; deployed = []
    try:
        for name, source in [('0.txt', 'includes/sms_service.php'), ('3.txt', 'pages/admin/sms_notify.php'), ('6.txt', 'pages/staff_app.php')]:
            put(tag + '/' + name, (root / 'cpanel' / source).read_bytes()); staged.append(name)
        tests = (root / 'output/sms-controls/tests.php').read_text('utf-8-sig').removeprefix('<?php')
        tests += '''
expect(sms_template_error('appointment_booked','{patient} {doctor} {datetime}')==='', 'patient and doctor supported');
expect(sms_template_error('workshop_registered','{patient} {workshop} {place}')==='', 'workshop variables supported');
expect(sms_template_error('login_otp','{patient} {code}')!=='', 'unsupported tokens still rejected');
expect(sms_template_render($pdo,'appointment_booked',['patient'=>'مراجع نمونه','datetime'=>'DATE'],"مانا\\r\\n{patient}\\rنوبت {datetime}") === "مانا\\nمراجع نمونه\\nنوبت DATE", 'line breaks survive rendering');
expect(sms_template_render($pdo,'appointment_booked',[], 'متن\\\\nبعد') === 'متن\\\\nبعد', 'literal slash n is not a command');
expect(str_contains($GLOBALS['rendered'],'راهنمای نوشتن متن پیامک'), 'writing guide rendered');
echo '; PASS writing-help assertions; no provider calls';
'''
        put(tag + '/tests.txt', tests.encode()); staged.append('tests.txt')
        guard = "<?php if(time()>" + str(int(time.time())+900) + " || !hash_equals('" + token + "',$_SERVER['HTTP_X_VERIFY_TOKEN']??'')){http_response_code(404);exit;} "
        harness = "if(($_SERVER['HTTP_X_VERIFY_MODE']??'')==='syntax'){foreach(glob(__DIR__.'/*.txt') as $f)token_get_all((basename($f)==='tests.txt'?'<?php ':'').file_get_contents($f),TOKEN_PARSE);echo 'PASS syntax';}elseif(($_SERVER['HTTP_X_VERIFY_MODE']??'')==='test'){eval(file_get_contents(__DIR__.'/tests.txt'));}elseif(($_SERVER['HTTP_X_VERIFY_MODE']??'')==='cache'){foreach(" + json.dumps(files) + " as $f)if(function_exists('opcache_invalidate'))opcache_invalidate(dirname(__DIR__).'/'.$f,true);echo 'PASS cache';}"
        put(tag + '/index.php', (guard+harness).encode()); staged.append('index.php')
        def request(mode):
            req = urllib.request.Request('https://manaclinic.org/'+tag+'/', headers={'X-Verify-Token':token,'X-Verify-Mode':mode})
            with urllib.request.urlopen(req, timeout=45) as r: return r.read().decode('utf-8-sig')
        assert request('syntax') == 'PASS syntax'
        result = request('test')
        assert 'PASS writing-help assertions' in result, result
        print(result, flush=True)
        staged.append('preview.html')
        for n in files:
            if get(n) != originals[n]: raise RuntimeError('Remote changed during verification: '+n)
        for n in files:
            deployed.append(n); put(n, (root/'cpanel'/n).read_bytes())
            assert get(n) == (root/'cpanel'/n).read_bytes()
        assert request('cache') == 'PASS cache'
        (backup/'verification.txt').write_text(result+'\nPASS syntax, deployed byte verification and cache', encoding='utf-8')
        print('PASS deployed two files, byte verification and cache', flush=True)
    except Exception:
        for n in reversed(deployed): put(n, originals[n])
        raise
    finally:
        for n in reversed(staged):
            try: ftp.delete(tag+'/'+n)
            except ftplib.error_perm: pass
        try: ftp.delete(tag+'/error_log')
        except ftplib.error_perm: pass
        ftp.rmd(tag)
        print('Temporary verification files removed', flush=True)
