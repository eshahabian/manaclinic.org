from pathlib import Path
import urllib.request,urllib.error,urllib.parse,http.cookiejar,json,re,secrets,hashlib,hmac,time,ftplib,io
root=Path.cwd();jar=http.cookiejar.CookieJar();client=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))
with client.open('https://manaclinic.org/?callback-check='+secrets.token_hex(8),timeout=40) as r:html=r.read().decode('utf-8-sig')
d=json.loads(re.search(r'<script type="application/json" id="mana-guide-data">(.*?)</script>',html,re.S).group(1));assert 'callback' in d
nonce=secrets.token_hex(16);result_id=hmac.new(d['csrf'].encode(),nonce.encode(),hashlib.sha256).hexdigest();callback_id=hashlib.sha256(('guide-callback:'+result_id).encode()).hexdigest()[:24]
answers={q['key']:q['options'][0][0] for q in d['questions']['family']};answers['domain']='family';report={}
def post(path,body,csrf=None):
 req=urllib.request.Request('https://manaclinic.org'+path,data=urllib.parse.urlencode(body).encode(),headers={'Accept':'application/json','X-CSRF-Token':csrf if csrf is not None else d['csrf']})
 try:
  with client.open(req,timeout=40) as r:return r.status,json.loads(r.read())
 except urllib.error.HTTPError as e:return e.code,json.loads(e.read())
try:
 status,_=post(d['submit'],{'answers':json.dumps(answers),'submission':nonce,'action':'show_result','version':'20261021l'});report['stale_question_version_rejected']=status==409
 status,res=post(d['submit'],{'answers':json.dumps(answers),'submission':nonce,'action':'show_result','version':d['version']});report['result_saved']=status==200 and res.get('ok')
 base={'submission':nonce,'name':'آزمون تماس راهنما','phone':'۰۹۰۰۰۰۰۰۰۰۰'}
 status,_=post(d['callback'],dict(base,submission=secrets.token_hex(16)));report['requires_completed_result']=status==422
 status,_=post(d['callback'],dict(base,phone='123'));report['rejects_invalid_phone']=status==422
 status,_=post(d['callback'],base,csrf='invalid');report['csrf_rejected']=status==403
 status,res=post(d['callback'],base);report['contact_saved']=status==200 and res.get('ok')
 status,res=post(d['callback'],base);report['retry_success']=status==200 and res.get('ok')
finally:
 cfg=json.loads((root/'.vscode/sftp.json').read_text(encoding='utf-8-sig'));token=secrets.token_hex(32);folder='callback-check-'+secrets.token_hex(12)
 php=r'''<?php
 if(time()>EXPIRES || !hash_equals('TOKENHASH',hash('sha256',$_SERVER['HTTP_X_MANA_VERIFY']??''))){http_response_code(404);exit;}
 header('Content-Type:application/json');header('Cache-Control:no-store');$base=dirname(__DIR__);$c=require $base.'/config.php';$pdo=new PDO('mysql:host='.$c['db_host'].';dbname='.$c['db_name'].';charset=utf8mb4',$c['db_user'],$c['db_pass'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
 $report=[];
 try {
 require_once $base.'/includes/helpers.php';require_once $base.'/includes/auth.php';require_once $base.'/includes/doctor_profile_fields.php';require_once $base.'/includes/consult_requests.php';require_once $base.'/includes/therapist_guide_results.php';require_once $base.'/includes/collaboration.php';
 $q=$pdo->prepare('SELECT id,name,phone,message,status FROM consult_requests WHERE id=?');$q->execute(['CALLBACKID']);$row=$q->fetch();
 $report['one_contact_record']=(bool)$row;
 $report['normalized_phone']=$row && $row['phone']==='09000000000';
 $report['no_answers_or_analysis']=$row && $row['message']==='درخواست تماس پس از راهنمای انتخاب درمانگر';
 $named=collaboration_recipient_ids($pdo);$report['access_checks']=[];
 foreach(['SECRETARY','DOCTOR','ADMIN'] as $role){$q=$pdo->prepare('SELECT id,role,username,is_disabled FROM users WHERE role=? AND COALESCE(is_disabled,0)=0 LIMIT 30');$q->execute([$role]);foreach($q->fetchAll() as $u){if(in_array($u['id'],$named,true))continue;$_SESSION['user']=$u;$list=consult_request_list($pdo);$visible=in_array('CALLBACKID',array_column($list,'id'),true);$report['access_checks'][$role]=['contact_visible'=>$visible,'private_results_allowed'=>therapist_guide_can_read($pdo,$u)];break;}}
 foreach($named as $id){$q=$pdo->prepare('SELECT id,role,username,is_disabled FROM users WHERE id=?');$q->execute([$id]);$u=$q->fetch();$_SESSION['user']=$u;$report['named_allowed'][]=consult_guide_contact_allowed($pdo,$u)&&therapist_guide_can_read($pdo,$u);}
 }catch(Throwable $e){$report['failed']=get_class($e);}
 finally {
 $q=$pdo->prepare('DELETE FROM consult_requests WHERE id=? AND name=?');$q->execute(['CALLBACKID','آزمون تماس راهنما']);$report['synthetic_contacts_removed']=$q->rowCount();
 $q=$pdo->prepare('DELETE FROM therapist_guide_results WHERE id=? AND user_id IS NULL');$q->execute(['RESULTID']);$report['synthetic_results_removed']=$q->rowCount();
 }
 echo json_encode($report);
 '''.replace('EXPIRES',str(int(time.time())+600)).replace('TOKENHASH',hashlib.sha256(token.encode()).hexdigest()).replace('CALLBACKID',callback_id).replace('RESULTID',result_id)
 ftp=ftplib.FTP(cfg['host'],timeout=40);ftp.login(cfg['username'],cfg['password']);ftp.cwd('/public_html');ftp.mkd(folder)
 try:
  ftp.storbinary('STOR '+folder+'/index.php',io.BytesIO(php.encode()))
  with urllib.request.urlopen(urllib.request.Request('https://manaclinic.org/'+folder+'/',headers={'X-Mana-Verify':token}),timeout=40) as r:audit=json.loads(r.read())
 finally:ftp.delete(folder+'/index.php');ftp.rmd(folder);ftp.quit()
 report['server']=audit
 (root/'output/therapist-guide-live-fix/callback-e2e.json').write_text(json.dumps(report,ensure_ascii=False,indent=2),encoding='utf-8');print(json.dumps(report,ensure_ascii=True,indent=2))
assert all(report.get(k) for k in ['stale_question_version_rejected','result_saved','requires_completed_result','rejects_invalid_phone','csrf_rejected','contact_saved','retry_success'])
assert audit['one_contact_record'] and audit['normalized_phone'] and audit['no_answers_or_analysis'] and all(audit['named_allowed'])
assert audit['access_checks']['SECRETARY']=={'contact_visible':True,'private_results_allowed':False}
for role in ['DOCTOR','ADMIN']:
 if role in audit['access_checks']:assert audit['access_checks'][role]=={'contact_visible':False,'private_results_allowed':False}
assert audit['synthetic_contacts_removed']==audit['synthetic_results_removed']==1
print('PASS callback end-to-end, access separation, validation, retry, cleanup')
