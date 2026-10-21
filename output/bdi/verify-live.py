from pathlib import Path
import ftplib, io, json, secrets, time, urllib.request, urllib.parse, urllib.error, http.cookiejar, re

root=Path('D:/Psychology')
base='https://manaclinic.org'
jar=http.cookiejar.CookieJar()
client=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))
page=client.open(base+'/tests/bdi',timeout=60).read().decode('utf-8-sig')
csrf=re.search(r'name="_csrf" value="([^"]+)"',page).group(1)
def post(answers, action='score', include_csrf=True):
    data={'adult':'1','existing_form':'1','bdi_action':action,'ai_consent':'1'}
    if include_csrf:data['_csrf']=csrf
    data.update({'answers['+str(i)+']':str(v) for i,v in answers.items()})
    req=urllib.request.Request(base+'/tests/bdi',data=urllib.parse.urlencode(data).encode(),headers={'Accept':'application/json' if action=='ai' else 'text/html','Referer':base+'/tests/bdi'})
    try:r=client.open(req,timeout=60)
    except urllib.error.HTTPError as ex:r=ex
    return r.status,r.headers,r.read().decode('utf-8-sig')

answers={i:0 for i in range(1,22)}
answers.update({1:3,2:3,3:3,4:3,5:3,6:3,7:2})
code,headers,html=post(answers)
assert code==200 and 'bdi-report-grid' in html and '>۲۰</text>' in html and 'bdi-band">متوسط' in html
assert 'no-store' in headers.get('Cache-Control','')
assert 'name="ai_consent"' not in html and 'ورود برای تحلیل' in html
preview=html.replace('href="/assets/','href="'+base+'/assets/').replace('src="/assets/','src="'+base+'/assets/')
(root/'output/bdi/live-result-synthetic.html').write_text(preview,encoding='utf-8')
print('PASS real POST: score 20, moderate band, charts, no-store, login gate',flush=True)
del answers[21]
assert post(answers)[0]==422
answers[21]=0;answers[9]=1
code,_,html=post(answers)
assert code==200 and 'همراهی انسانی در اولویت است' in html and 'data-bdi-ai>' not in html
assert post(answers,'ai')[0]==401
assert post(answers,'ai',False)[0]==403
print('PASS incomplete response rejected; item 9 safety; unauthorized AI and CSRF rejected',flush=True)

cfg=json.loads((root/'.vscode/sftp.json').read_text('utf-8-sig'))
tag='bdi-smoke-'+secrets.token_hex(12);token=secrets.token_hex(32)
with ftplib.FTP() as ftp:
    ftp.connect(cfg['host'],int(cfg.get('port',21)),timeout=45);ftp.login(cfg['username'],cfg['password']);ftp.cwd('/public_html')
    def put(n,b):ftp.storbinary('STOR '+n,io.BytesIO(b))
    remote=io.BytesIO();ftp.retrbinary('RETR includes/bdi.php',remote.write)
    assert remote.getvalue()==(root/'cpanel/includes/bdi.php').read_bytes()
    ftp.mkd(tag)
    php="<?php if(time()>"+str(int(time.time())+600)+" || !hash_equals('"+token+"',$_SERVER['HTTP_X_VERIFY_TOKEN']??'')){http_response_code(404);exit;} "
    php+=r'''
header('Content-Type: application/json; charset=utf-8');
$config=require dirname(__DIR__).'/config.php';
require dirname(__DIR__).'/includes/assistant.php';
require dirname(__DIR__).'/includes/bdi.php';
if(!assistant_enabled()||!assistant_ai_available()){echo json_encode(['ok'=>false,'reason'=>'AI not configured']);exit;}
// Synthetic fixture only: not a real patient's answer sheet.
$input=array_fill(1,21,0);$input[1]=2;$input[2]=2;$input[4]=2;$input[16]=2;$input[20]=2;
try{
 $analysis=bdi_parse_ai(assistant_ai_chat(bdi_ai_messages(bdi_score($input)),1100));
 echo json_encode(['ok'=>true,'synthetic'=>true,'analysis'=>$analysis],JSON_UNESCAPED_UNICODE);
}catch(Throwable $ex){http_response_code(503);echo json_encode(['ok'=>false,'reason'=>'AI smoke failed']);}
'''
    try:
        put(tag+'/index.php',php.encode())
        req=urllib.request.Request(base+'/'+tag+'/',headers={'X-Verify-Token':token})
        with urllib.request.urlopen(req,timeout=80) as r: result=json.loads(r.read().decode('utf-8-sig'))
        assert result.get('ok'),result
        (root/'output/bdi/ai-smoke-synthetic.json').write_text(json.dumps(result,ensure_ascii=False,indent=2),encoding='utf-8')
        print('PASS live configured AI: synthetic input, structured Persian analysis',flush=True)
    finally:
        for name in ['index.php','error_log']:
            try:ftp.delete(tag+'/'+name)
            except ftplib.error_perm:pass
        ftp.rmd(tag)
        print('Temporary AI verification endpoint removed',flush=True)
