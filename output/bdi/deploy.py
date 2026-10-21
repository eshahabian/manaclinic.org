from pathlib import Path
import ftplib, io, json, secrets, time, urllib.request, urllib.error

root = Path('D:/Psychology')
changed = {'index.php': 'before-index.php', 'pages/test_detail.php': 'before-test_detail.php',
           'includes/psych_tests.php': 'before-psych_tests.php', 'pages/tests.php': 'before-tests.php'}
added = ['includes/bdi.php', 'pages/test_bdi.php', 'assets/css/bdi.css', 'assets/js/bdi.js']
files = list(changed) + added
cfg = json.loads((root / '.vscode/sftp.json').read_text('utf-8-sig'))
tag = 'bdi-check-' + secrets.token_hex(12)
token = secrets.token_hex(32)
backup = root / 'output/bdi' / ('remote-before-' + time.strftime('%Y%m%d-%H%M%S'))
backup.mkdir()
normalize = lambda b: b.decode('utf-8-sig').replace('\r\n', '\n')

with ftplib.FTP() as ftp:
    ftp.connect(cfg['host'], int(cfg.get('port', 21)), timeout=45)
    ftp.login(cfg['username'], cfg['password'])
    ftp.cwd('/public_html')

    def get(name):
        stream = io.BytesIO()
        ftp.retrbinary('RETR ' + name, stream.write)
        return stream.getvalue()

    def put(name, content):
        ftp.storbinary('STOR ' + name, io.BytesIO(content))

    originals = {}
    for name, before in changed.items():
        originals[name] = get(name)
        (backup / name.replace('/', '_')).write_bytes(originals[name])
        assert normalize(originals[name]) == normalize((root / 'output/bdi' / before).read_bytes()), 'Remote diverged: ' + name
    for name in added:
        try:
            get(name)
        except ftplib.error_perm as ex:
            if not str(ex).startswith('550'):
                raise
        else:
            raise RuntimeError('New file already exists: ' + name)
    print('PASS all remote baselines; new files absent', flush=True)
    ftp.mkd(tag)
    staged, deployed = [], []
    guard = "<?php if(time()>" + str(int(time.time()) + 1200) + " || !hash_equals('" + token + "',$_SERVER['HTTP_X_VERIFY_TOKEN']??'')){http_response_code(404);exit;} "
    harness = r'''
$mode=$_SERVER['HTTP_X_VERIFY_MODE']??'';
if($mode==='syntax'){
 foreach(glob(__DIR__.'/*.php.txt') as $f)token_get_all(file_get_contents($f),TOKEN_PARSE);
 echo 'PASS PHP syntax';exit;
}
if($mode==='test'||$mode==='render'){
 $s=file_get_contents(__DIR__.'/tests.php.txt');
 $s=preg_replace('/^<\?php\s*/','',$s);
 ob_start();eval($s);$testOutput=ob_get_clean();
 if($mode==='test'){echo $testOutput;exit;}
 function e($v):string{return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
 function to_fa_digits($v):string{return strtr((string)$v,['0'=>'۰','1'=>'۱','2'=>'۲','3'=>'۳','4'=>'۴','5'=>'۵','6'=>'۶','7'=>'۷','8'=>'۸','9'=>'۹']);}
 function url($v):string{return $v;}
 function csrf_field():string{return '<input type="hidden" name="csrf_token" value="synthetic">';}
 function current_user():?array{return ['id'=>'synthetic-user'];}
 function assistant_ai_config():array{return ['base_url'=>'https://api.metisai.ir/openai/v1'];}
 $config=[];$_SERVER['REQUEST_METHOD']='POST';$_POST=['adult'=>'1','existing_form'=>'1','bdi_action'=>'score','answers'=>scores(20)];
 $s=file_get_contents(__DIR__.'/pages_test_bdi.php.txt');
 $s=preg_replace('/^<\?php\s*declare\(strict_types=1\);/','',$s);
 $s=str_replace("require_once __DIR__ . '/../includes/bdi.php';",'',$s);
 $s=str_replace("require __DIR__ . '/../includes/layout.php';",'echo $content;',$s);
 ob_start();eval($s);$html=ob_get_clean();
 $css=file_get_contents(__DIR__.'/assets_css_bdi.css.txt');
 echo '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="https://manaclinic.org/assets/css/local-font.css"><style>body{background:#f7f4fb;font-family:Tahoma,sans-serif;margin:0;padding:20px}input,select,button{font:inherit}.container-page{margin:auto}.btn{display:inline-block;padding:10px 18px;text-decoration:none;cursor:pointer;border:1px solid #ddd}select{padding:8px;border-radius:8px;border:1px solid #ddd}'.$css.'</style></head><body>'.$html.'</body></html>';exit;
}
if($mode==='cache'){
 foreach(FILES as $name)if(function_exists('opcache_invalidate'))opcache_invalidate(dirname(__DIR__).'/'.$name,true);
 echo 'PASS cache';exit;
}
'''.replace('FILES', json.dumps(files).replace('[', 'array(', 1).rsplit(']', 1)[0] + ')')

    def request(mode):
        req = urllib.request.Request('https://manaclinic.org/' + tag + '/', headers={'X-Verify-Token': token, 'X-Verify-Mode': mode})
        return urllib.request.urlopen(req, timeout=60).read().decode('utf-8-sig')

    try:
        for name in files:
            dest = tag + '/' + name.replace('/', '_') + '.txt'
            put(dest, (root / 'cpanel' / name).read_bytes())
            staged.append(dest)
        put(tag + '/tests.php.txt', (root / 'output/bdi/tests.php').read_bytes()); staged.append(tag + '/tests.php.txt')
        put(tag + '/index.php', (guard + harness).encode()); staged.append(tag + '/index.php')
        assert request('syntax') == 'PASS PHP syntax'
        result = request('test'); assert result.startswith('PASS ') and 'BDI checks' in result, result
        print('PASS PHP syntax; ' + result, flush=True)
        preview = request('render'); assert 'bdi-report-grid' in preview and 'bdi-ai' in preview and 'Fatal error' not in preview, preview[:500]
        (root / 'output/bdi/preview.html').write_text(preview, encoding='utf-8')
        # Stage verification never contacts AI or stores health data.
        for name in files:
            put(name, (root / 'cpanel' / name).read_bytes()); deployed.append(name)
            assert get(name) == (root / 'cpanel' / name).read_bytes()
        assert request('cache') == 'PASS cache'
        public = urllib.request.urlopen('https://manaclinic.org/tests/bdi', timeout=60).read().decode('utf-8-sig')
        assert 'bdi-hero' in public and 'data-bdi-score' in public and 'Fatal error' not in public
        (backup / 'verification.txt').write_text(result + '\nPHP syntax PASS\nDeployment byte verification PASS\nLive GET PASS', encoding='utf-8')
        print('PASS deployed eight files; byte verification; live BDI page', flush=True)
    except Exception:
        for name in reversed(deployed):
            if name in originals: put(name, originals[name])
            else: ftp.delete(name)
        raise
    finally:
        for name in reversed(staged):
            try: ftp.delete(name)
            except ftplib.error_perm: pass
        try: ftp.delete(tag + '/error_log')
        except ftplib.error_perm: pass
        ftp.rmd(tag)
        print('Temporary verification files removed', flush=True)
