from pathlib import Path
import ftplib, io, json, secrets, time, urllib.request

root=Path('D:/Psychology')
changed=['includes/bdi.php','assets/css/bdi.css','assets/js/bdi.js','pages/test_bdi.php','includes/psych_tests.php','pages/tests.php']
added=['includes/bdi_form_fa.php']
files=added+changed
cfg=json.loads((root/'.vscode/sftp.json').read_text('utf-8-sig'))
tag='bdi-form-check-'+secrets.token_hex(12);token=secrets.token_hex(32)
backup=root/'output/bdi'/('form-remote-before-'+time.strftime('%Y%m%d-%H%M%S'));backup.mkdir()
norm=lambda b:b.decode('utf-8-sig').replace('\r\n','\n')
with ftplib.FTP() as ftp:
 ftp.connect(cfg['host'],int(cfg.get('port',21)),timeout=45);ftp.login(cfg['username'],cfg['password']);ftp.cwd('/public_html')
 def get(n):
  b=io.BytesIO();ftp.retrbinary('RETR '+n,b.write);return b.getvalue()
 def put(n,b):ftp.storbinary('STOR '+n,io.BytesIO(b))
 originals={}
 for n in changed:
  originals[n]=get(n);(backup/n.replace('/','_')).write_bytes(originals[n])
  assert norm(originals[n])==norm((root/'output/bdi/questionnaire-baseline'/n.replace('/','_')).read_bytes()),'Remote changed: '+n
 for n in added:
  try:get(n)
  except ftplib.error_perm as ex:
   if not str(ex).startswith('550'):raise
  else:raise RuntimeError('New file exists: '+n)
 print('PASS remote baselines match; content file absent',flush=True)
 ftp.mkd(tag);staged=[];deployed=[]
 guard="<?php if(time()>"+str(int(time.time())+1200)+" || !hash_equals('"+token+"',$_SERVER['HTTP_X_VERIFY_TOKEN']??'')){http_response_code(404);exit;} "
 harness=r'''
$mode=$_SERVER['HTTP_X_VERIFY_MODE']??'';
if($mode==='syntax'){foreach(glob(__DIR__.'/*.php.txt') as $f)token_get_all(file_get_contents($f),TOKEN_PARSE);echo 'PASS syntax';exit;}
if($mode==='test'||$mode==='render'){
 $s=file_get_contents(__DIR__.'/tests.php.txt');$s=preg_replace('/^<\?php\s*/','',$s);
 ob_start();eval($s);$test=ob_get_clean();
 if($mode==='test'){echo $test;exit;}
 function e($v):string{return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
 function to_fa_digits($v):string{return strtr((string)$v,['0'=>'۰','1'=>'۱','2'=>'۲','3'=>'۳','4'=>'۴','5'=>'۵','6'=>'۶','7'=>'۷','8'=>'۸','9'=>'۹']);}
 function url($v):string{return $v;}
 function csrf_field():string{return '<input type="hidden" name="_csrf" value="synthetic">';}
 function current_user():?array{return null;}
 function assistant_ai_config():array{return ['base_url'=>'https://api.metisai.ir/openai/v1'];}
 $_SERVER['REQUEST_METHOD']='POST';$choices=array_fill(1,21,'0');$choices[1]='3';$choices[2]='3';$choices[3]='3';$choices[4]='3';$choices[5]='3';$choices[6]='3';$choices[7]='2';
 $_POST=['adult'=>'1','bdi_action'=>'score','choices'=>$choices];
 $s=file_get_contents(__DIR__.'/pages_test_bdi.php.txt');$s=preg_replace('/^<\?php\s*declare\(strict_types=1\);/','',$s);
 $s=str_replace("require_once __DIR__ . '/../includes/bdi.php';",'',$s);
 $s=str_replace("require __DIR__ . '/../includes/layout.php';",'echo $content;',$s);
 ob_start();eval($s);$html=ob_get_clean();
 if(!str_contains($html,'bdi-band">متوسط')||!str_contains($html,'data-bdi-questionnaire')||substr_count($html,'class="bdi-option"')!==90)throw new Exception('render failed');
 echo 'PASS source form and result rendering';exit;
}
if($mode==='cache'){foreach(FILES as $f)if(function_exists('opcache_invalidate'))opcache_invalidate(dirname(__DIR__).'/'.$f,true);echo 'PASS cache';}
'''.replace('FILES','array('+','.join(json.dumps(n) for n in files)+')')
 def req(mode):
  r=urllib.request.Request('https://manaclinic.org/'+tag+'/',headers={'X-Verify-Token':token,'X-Verify-Mode':mode})
  return urllib.request.urlopen(r,timeout=60).read().decode('utf-8-sig')
 try:
  for n in files:
   dest=tag+'/'+n.replace('/','_')+'.txt';put(dest,(root/'cpanel'/n).read_bytes());staged.append(dest)
  for local,dest in [('tests.php','tests-base.php.txt'),('tests-questionnaire.php','tests.php.txt')]:
   put(tag+'/'+dest,(root/'output/bdi'/local).read_bytes());staged.append(tag+'/'+dest)
  put(tag+'/index.php',(guard+harness).encode());staged.append(tag+'/index.php')
  assert req('syntax')=='PASS syntax';result=req('test');assert result.startswith('PASS ') and 'questionnaire checks' in result,result
  print('PASS syntax; '+result,flush=True)
  resultRender=req('render');assert resultRender=='PASS source form and result rendering',resultRender
  print(resultRender,flush=True)
  for n in files:
   put(n,(root/'cpanel'/n).read_bytes());deployed.append(n);assert get(n)==(root/'cpanel'/n).read_bytes()
  assert req('cache')=='PASS cache'
  public=urllib.request.urlopen('https://manaclinic.org/tests/bdi',timeout=60).read().decode('utf-8-sig')
  assert 'data-bdi-questionnaire' in public and public.count('class="bdi-option"')==90 and 'Fatal error' not in public
  (backup/'verification.txt').write_text(result+'\n'+resultRender+'\nLive questionnaire GET and byte verification PASS',encoding='utf-8')
  print('PASS deployed seven files; live complete questionnaire has 21 items and 90 options',flush=True)
 except Exception:
  for n in reversed(deployed):
   if n in originals:put(n,originals[n])
   else:ftp.delete(n)
  raise
 finally:
  for n in reversed(staged):
   try:ftp.delete(n)
   except ftplib.error_perm:pass
  try:ftp.delete(tag+'/error_log')
  except ftplib.error_perm:pass
  ftp.rmd(tag)
  print('Temporary verification files removed',flush=True)
