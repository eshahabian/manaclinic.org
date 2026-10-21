from pathlib import Path
import ftplib, io, json, secrets, time, urllib.request, hashlib

root=Path('D:/Psychology')
code=['includes/bdi_form_fa.php','includes/bdi.php','includes/psych_assessments.php','assets/css/bdi.css','assets/css/psych-tests.css','assets/js/bdi.js','pages/test_bdi.php','pages/test_assessment.php','includes/psych_tests.php','includes/home_tests_col.php','pages/tests.php','pages/test_detail.php','index.php']
images=['assets/img/tests/'+slug+'.webp' for slug in ['bdi','bai','dass-21','ghq','scl-90','pss','neo','swls','mmpi','ybocs']]
cfg=json.loads((root/'.vscode/sftp.json').read_text('utf-8-sig'))
tag='assessment-verify-'+secrets.token_hex(12);token=secrets.token_hex(32)
backup=root/'output/psych-tests'/('remote-audit-'+time.strftime('%Y%m%d-%H%M%S'));backup.mkdir()
normalize=lambda b:b.decode('utf-8-sig').replace('\r\n','\n')
digest=lambda b:hashlib.sha256(b).hexdigest()

with ftplib.FTP() as ftp:
 ftp.connect(cfg['host'],int(cfg.get('port',21)),timeout=45);ftp.login(cfg['username'],cfg['password']);ftp.cwd('/public_html')
 def get(n):
  b=io.BytesIO();ftp.retrbinary('RETR '+n,b.write);return b.getvalue()
 def optional(n):
  try:return get(n)
  except ftplib.error_perm as ex:
   if str(ex).startswith('550'):return None
   raise
 def put(n,b):ftp.storbinary('STOR '+n,io.BytesIO(b))
 audit={};different=[]
 for n in code+images:
  remote=optional(n);local=(root/'cpanel'/n).read_bytes()
  if remote is not None:(backup/n.replace('/','_')).write_bytes(remote)
  same=remote is not None and (normalize(remote)==normalize(local) if n.endswith(('.php','.css','.js')) else remote==local)
  audit[n]={'remote_hash':digest(remote) if remote is not None else None,'local_hash':digest(local),'same':same}
  if not same:different.append(n)
 (backup/'audit.json').write_text(json.dumps(audit,indent=2),encoding='utf-8')
 print('Remote files needing publication: '+(', '.join(different) or 'none'),flush=True)
 ftp.mkd(tag);staged=[]
 guard="<?php if(time()>"+str(int(time.time())+1200)+" || !hash_equals('"+token+"',$_SERVER['HTTP_X_VERIFY_TOKEN']??'')){http_response_code(404);exit;} "
 harness=r'''
$mode=$_SERVER['HTTP_X_VERIFY_MODE']??'';
if($mode==='syntax'){foreach(glob(__DIR__.'/*.php.txt') as $f)token_get_all(file_get_contents($f),TOKEN_PARSE);echo 'PASS PHP syntax';exit;}
if($mode==='test'||$mode==='render'){
 $s=file_get_contents(__DIR__.'/tests.php.txt');$s=preg_replace('/^<\?php\s*/','',$s);
 ob_start();eval($s);$unitOutput=ob_get_clean();
 if($mode==='test'){echo $unitOutput;exit;}
 function e($v):string{return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
 function to_fa_digits($v):string{return strtr((string)$v,['0'=>'۰','1'=>'۱','2'=>'۲','3'=>'۳','4'=>'۴','5'=>'۵','6'=>'۶','7'=>'۷','8'=>'۸','9'=>'۹']);}
 function url($v):string{return $v;}
 function csrf_field():string{return '<input type="hidden" name="_csrf" value="synthetic">';}
 function current_user():?array{return ['id'=>'synthetic','role'=>'DOCTOR'];}
 function assistant_ai_config():array{return ['base_url'=>'https://api.metisai.ir/openai/v1'];}
 function render_test(string $slug,array $spec,bool $ai=false,string $validity='valid'):string{
  global $config,$downloadedForm;
  $config=['bdi_ii_form'=>$downloadedForm,'psych_test_enabled'=>[$slug=>true],'psych_test_ai_enabled'=>[$slug=>$ai]];
  $test=['title'=>'Synthetic fixture','abbr'=>$spec['version']];$pageHead='';$pageScripts='';
  $_SERVER['REQUEST_METHOD']='POST';
  $_POST=['adult'=>'1','existing_form'=>'1','official_report'=>'1','version'=>$spec['version'],'validity'=>$validity];
  if($slug==='bdi'){$_POST['bdi_action']='score';$_POST['choices']=array_fill(1,21,0);$file='pages_test_bdi.php.txt';}
  else{$_POST['assessment_action']='score';$_POST['values']=$spec['mode']==='items'?array_fill(1,$spec['count'],$spec['min']):array_fill_keys(array_keys(psych_profile_labels($slug)),$slug==='neo'?24:50);$file='pages_test_assessment.php.txt';}
  $s=file_get_contents(__DIR__.'/'.$file);$s=preg_replace('/^<\?php\s*declare\(strict_types=1\);/','',$s);
  $s=str_replace("require_once __DIR__ . '/../includes/psych_assessments.php';",'',$s);
  $s=str_replace("require __DIR__ . '/../includes/layout.php';",'echo $content;',$s);
  ob_start();eval($s);return ob_get_clean();
 }
 foreach(psych_assessment_specs() as $slug=>$spec){$html=render_test($slug,$spec);if(!str_contains($html,'bdi-report-grid')||str_contains($html,'data-bdi-ai>'))throw new Exception('render '.$slug);}
 $html=render_test('bdi',['version'=>'BDI-II'],true);if(substr_count($html,'class="bdi-option"')!==90||!str_contains($html,'data-bdi-ai>'))throw new Exception('BDI source rendering');
 $html=render_test('bai',psych_assessment_specs()['bai'],true);if(!str_contains($html,'data-bdi-ai>'))throw new Exception('isolated AI enable rendering');
 $html=render_test('mmpi',psych_assessment_specs()['mmpi'],true,'invalid');if(str_contains($html,'data-bdi-ai>'))throw new Exception('invalid MMPI exposed AI');
 echo 'PASS ten report renderers, independent AI flags and MMPI invalidity guard';exit;
}
if($mode==='cache'){foreach(FILES as $f)if(function_exists('opcache_invalidate'))opcache_invalidate(dirname(__DIR__).'/'.$f,true);echo 'PASS cache';}
'''.replace('FILES','array('+','.join(json.dumps(n) for n in code)+')')
 def req(mode):
  r=urllib.request.Request('https://manaclinic.org/'+tag+'/',headers={'X-Verify-Token':token,'X-Verify-Mode':mode})
  return urllib.request.urlopen(r,timeout=60).read().decode('utf-8-sig')
 try:
  for n in code:
   dest=tag+'/'+n.replace('/','_')+'.txt';put(dest,(root/'cpanel'/n).read_bytes());staged.append(dest)
  put(tag+'/tests.php.txt',(root/'output/psych-tests/tests.php').read_bytes());staged.append(tag+'/tests.php.txt')
  put(tag+'/index.php',(guard+harness).encode());staged.append(tag+'/index.php')
  syntax=req('syntax');assert syntax=='PASS PHP syntax',syntax
  result=req('test');assert result.startswith('PASS ') and 'assessment checks' in result,result
  render=req('render');assert render.startswith('PASS ten report'),render
  print(syntax+'; '+result+'; '+render,flush=True)
  (backup/'tests.txt').write_text(syntax+'\n'+result+'\n'+render,encoding='utf-8')
  # Publish only absent image assets. Existing images are never overwritten here.
  for n in images:
   old=optional(n);local=(root/'cpanel'/n).read_bytes()
   if old is None:
    try:ftp.mkd('assets/img/tests')
    except ftplib.error_perm:pass
    put(n,local)
   else:assert old==local,'Image changed: '+n
   assert get(n)==local
  # The editor automatically syncs saved code. Do not overwrite divergent production code.
  mismatch=[]
  for n in code:
   remote=optional(n)
   if remote is None or normalize(remote)!=normalize((root/'cpanel'/n).read_bytes()):mismatch.append(n)
  if mismatch:
   print('Code still differs, review needed: '+', '.join(mismatch),flush=True)
   (backup/'mismatch.json').write_text(json.dumps(mismatch),encoding='utf-8')
  else:
   assert req('cache')=='PASS cache';print('PASS all production code matches; cache refreshed; ten images verified',flush=True)
 finally:
  for n in reversed(staged):
   try:ftp.delete(n)
   except ftplib.error_perm:pass
  try:ftp.delete(tag+'/error_log')
  except ftplib.error_perm:pass
  ftp.rmd(tag)
  print('Temporary test endpoint removed',flush=True)
