from pathlib import Path
import json,re,urllib.request,urllib.error,http.cookiejar,secrets,hashlib,hmac,time,io,ftplib
root=Path.cwd();cookies=http.cookiejar.CookieJar();client=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(cookies))
with client.open('https://manaclinic.org/?guide-e2e='+secrets.token_hex(8),timeout=40) as r:html=r.read().decode('utf-8-sig')
data=json.loads(re.search(r'<script type="application/json" id="mana-guide-data">(.*?)</script>',html,re.S).group(1));answers={q['key']:q['options'][0][0] for q in data['questions']['premarital']};answers['domain']='premarital';nonce=secrets.token_hex(16)
key=hmac.new(data['csrf'].encode(),nonce.encode(),hashlib.sha256).hexdigest()
body=urllib.parse.urlencode({'answers':json.dumps(answers),'submission':nonce,'action':'show_result','version':data['version']}).encode()
req=urllib.request.Request('https://manaclinic.org'+data['submit'],data=body,headers={'Accept':'application/json','X-CSRF-Token':data['csrf']})
try:
 with client.open(req,timeout=45) as r:status=r.status;result=r.read().decode()
except urllib.error.HTTPError as e:status=e.code;result=e.read().decode()
print(json.dumps({'status':status,'response':result},ensure_ascii=True))
# Remove only the synthetic request created above and confirm whether it existed.
cfg=json.loads((root/'.vscode/sftp.json').read_text(encoding='utf-8-sig'));token=secrets.token_hex(32);folder='guide-e2e-'+secrets.token_hex(12)
php="<?php if(time()>"+str(int(time.time())+600)+" || !hash_equals('"+hashlib.sha256(token.encode()).hexdigest()+"',hash('sha256',$_SERVER['HTTP_X_MANA_VERIFY']??''))){http_response_code(404);exit;} header('Content-Type:application/json');header('Cache-Control:no-store');$c=require dirname(__DIR__).'/config.php';$p=new PDO('mysql:host='.$c['db_host'].';dbname='.$c['db_name'].';charset=utf8mb4',$c['db_user'],$c['db_pass'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$q=$p->prepare('DELETE FROM therapist_guide_results WHERE id=? AND user_id IS NULL');$q->execute(['"+key+"']);echo json_encode(['synthetic_rows_removed'=>$q->rowCount()]);"
ftp=ftplib.FTP(cfg['host'],timeout=40);ftp.login(cfg['username'],cfg['password']);ftp.cwd('/public_html');ftp.mkd(folder)
try:
 ftp.storbinary('STOR '+folder+'/index.php',io.BytesIO(php.encode()))
 with urllib.request.urlopen(urllib.request.Request('https://manaclinic.org/'+folder+'/',headers={'X-Mana-Verify':token}),timeout=40) as r:cleanup=r.read().decode()
 print(cleanup)
finally:ftp.delete(folder+'/index.php');ftp.rmd(folder);ftp.quit()
(root/'output/therapist-guide-live-fix/e2e.json').write_text(json.dumps({'status':status,'response':result,'cleanup':json.loads(cleanup)},ensure_ascii=False,indent=2),encoding='utf-8')
