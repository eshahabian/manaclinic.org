<?php
// Isolated tests: no real patient data, configuration writes, database or provider calls.
$checks=0;
function expect(bool $condition,string $label):void{global $checks;if(!$condition)throw new RuntimeException($label);$checks++;}
function invalid(callable $call,string $label):void{try{$call();throw new RuntimeException($label);}catch(InvalidArgumentException $ex){expect(true,$label);}}
foreach(['includes_bdi.php.txt','includes_psych_assessments.php.txt'] as $file){
 $s=file_get_contents(__DIR__.'/'.$file);$s=preg_replace('/^<\?php\s*declare\(strict_types=1\);/','',$s);
 $s=str_replace("require_once __DIR__ . '/bdi.php';",'',$s);eval($s);
}
$config=[];
expect(psych_assessment_enabled('bdi')&&psych_assessment_ai_enabled('bdi'),'only BDI launch is authorized');
foreach(array_keys(psych_assessment_specs()) as $slug){expect(!psych_assessment_enabled($slug),'disabled default '.$slug);expect(!psych_assessment_ai_enabled($slug),'AI disabled default '.$slug);}
$config=['psych_test_enabled'=>['bai'=>true]];expect(psych_assessment_enabled('bai')&&!psych_assessment_ai_enabled('bai'),'test enable does not enable AI');
$config['psych_test_ai_enabled']['bai']=true;expect(psych_assessment_ai_enabled('bai'),'independent AI switch');
$config['psych_test_enabled']['bai']=false;expect(!psych_assessment_ai_enabled('bai'),'parent switch wins');
$config=[];
function values_for(int $count,int $total,int $max):array{$v=array_fill(1,$count,0);foreach($v as $id=>$_){$v[$id]=min($max,$total);$total-=$v[$id];}return $v;}
function score(string $slug,array $input,array $extra=[]):array{return psych_assessment_score($slug,$input,array_merge(['version'=>psych_assessment_specs()[$slug]['version']],$extra));}
foreach([0,7,8,15,16,25,26,63] as $n){$r=score('bai',values_for(21,$n,3));expect($r['total']===$n,'BAI boundary '.$n);}
expect(score('bai',values_for(21,7,3))['label']!==score('bai',values_for(21,8,3))['label'],'BAI first transition');
expect(score('bai',values_for(21,25,3))['label']!==score('bai',values_for(21,26,3))['label'],'BAI severe transition');
foreach(['bai','dass-21','ghq','scl-90','pss','swls','ybocs'] as $slug){
 $spec=psych_assessment_specs()[$slug];$input=array_fill(1,$spec['count'],$spec['min']);
 invalid(fn()=>psych_assessment_score($slug,$input,['version'=>'wrong']),'version mismatch '.$slug);
 $bad=$input;unset($bad[$spec['count']]);invalid(fn()=>score($slug,$bad),'missing '.$slug);
 $bad=$input;$bad[1]=$spec['max']+1;invalid(fn()=>score($slug,$bad),'out of range '.$slug);
 $bad=$input;$bad[1]=true;invalid(fn()=>score($slug,$bad),'bool rejected '.$slug);
 $bad=$input;$bad[1]='1.0';invalid(fn()=>score($slug,$bad),'float string rejected '.$slug);
}
$r=score('dass-21',array_fill(1,21,3));expect(array_column($r['axes'],'value')===[42,42,42],'DASS each multiplied once');expect(array_column($r['axes'],'raw')===[21,21,21],'DASS raw retained');expect($r['total']===null,'no invented DASS total severity');
foreach([3=>0,2=>1,1=>2] as $id=>$axis){$v=array_fill(1,21,0);$v[$id]=3;$r=score('dass-21',$v);expect($r['axes'][$axis]['value']===6 && array_sum(array_column($r['axes'],'value'))===6,'DASS exact mapping '.$id);}
$r=score('ghq',array_fill(1,28,1));expect($r['total']===28&&$r['metrics'][0]['value']===0,'GHQ likelihood and binary differ');
$r=score('ghq',array_fill(1,28,2));expect($r['total']===56&&$r['metrics'][0]['value']===28,'GHQ binary mapping');expect(array_column($r['axes'],'value')===[14,14,14,14],'GHQ four blocks');
$r=score('scl-90',array_fill(1,90,0));expect($r['metrics'][0]['value']==0&&$r['metrics'][1]['value']===0&&$r['metrics'][2]['value']===null,'SCL zero PST has undefined PSDI');
$r=score('scl-90',array_fill(1,90,4));expect(array_column($r['axes'],'value')==array_fill(0,9,4),'SCL nine scale maxima');expect(array_column($r['metrics'],'value')==[4,90,4],'SCL global maxima');
$covered=[];foreach(psych_scl_keys() as [$label,$ids]){$covered=array_merge($covered,$ids);}expect(count($covered)===83&&count(array_unique($covered))===83,'SCL 83 dimension items');expect(array_values(array_diff(range(1,90),$covered))===[19,44,59,60,64,66,89],'SCL seven additional items');
$v=array_fill(1,90,0);$v[89]=4;$r=score('scl-90',$v);expect($r['metrics'][1]['value']===1&&$r['metrics'][2]['value']==4&&array_sum(array_column($r['axes'],'value'))==0,'additional item contributes only to global indices');
$v=array_fill(1,10,0);foreach([4,5,7,8] as $id)$v[$id]=4;expect(score('pss',$v)['total']===0,'PSS true minimum after reversal');foreach($v as &$n)$n=4-$n;unset($n);expect(score('pss',$v)['total']===40,'PSS true maximum after reversal');
expect(score('pss',array_fill(1,10,0))['total']===16,'PSS raw zeros are not scored zeros');
expect(score('swls',array_fill(1,5,1))['total']===5,'SWLS minimum');expect(score('swls',array_fill(1,5,7))['total']===35,'SWLS maximum');
foreach([0,7,8,15,16,23,24,31,32,40] as $n){$r=score('ybocs',values_for(10,$n,4));expect($r['total']===$n && array_sum(array_column($r['axes'],'value'))===$n,'YBOCS boundary '.$n);}
foreach(['ghq'=>25,'scl-90'=>15] as $slug=>$id){$s=psych_assessment_specs()[$slug];$v=array_fill(1,$s['count'],0);$v[$id]=1;$r=score($slug,$v);expect($r['safety_followup'],'sensitive item at low total '.$slug);try{psych_assessment_ai_messages($r);throw new RuntimeException('safety model call');}catch(DomainException $ex){$checks++;}}
$profile=array_fill_keys(array_keys(psych_profile_labels('neo')),24);
invalid(fn()=>score('neo',$profile),'NEO report attestation required');$r=score('neo',$profile,['official_report'=>true]);expect($r['total']===null&&count($r['axes'])===5,'NEO five raw traits, no total');
$key=['version'=>'NEO-FFI-60-original','verified'=>true,'source'=>'Synthetic test fixture, not a clinical key','items'=>[]];$domains=['N','E','O','A','C'];
for($id=1;$id<=60;$id++)$key['items'][$id]=['domain'=>$domains[($id-1)%5],'reverse'=>$id%2===0];
$r=psych_neo_score_items(array_fill(1,60,2),$key);expect(array_column($r['axes'],'value')===[24,24,24,24,24],'NEO verified key pipeline');$badKey=$key;$badKey['verified']=false;invalid(fn()=>psych_neo_score_items(array_fill(1,60,0),$badKey),'NEO unverified key rejected');
$mmpi=array_fill_keys(array_keys(psych_profile_labels('mmpi')),50);
invalid(fn()=>score('mmpi',$mmpi,['official_report'=>true,'validity'=>'valid']),'MMPI clinical role required');
foreach(['valid','invalid','uncertain'] as $state){$r=score('mmpi',$mmpi,['official_report'=>true,'clinical_operator'=>true,'validity'=>$state]);expect($r['total']===null,'MMPI no raw sum');expect($r['ai_allowed']===($state==='valid'),'MMPI validity gating '.$state);}
invalid(fn()=>score('mmpi',array_fill(1,567,1),['official_report'=>true,'clinical_operator'=>true,'validity'=>'valid']),'MMPI never sums raw 567 answers');
$r=score('pss',array_fill(1,10,2));$messages=psych_assessment_ai_messages($r);$data=json_decode($messages[1]['content'],true);expect(!isset($data['name'])&&!isset($data['user_id'])&&!isset($data['patient']),'AI input contains no identity');expect(str_contains($messages[0]['content'],'بدون')||str_contains($messages[0]['content'],'نساز'),'AI norm guardrails');
if(!defined('MANA_BDI_CONTENT'))define('MANA_BDI_CONTENT',true);$s=file_get_contents(__DIR__.'/includes_bdi_form_fa.php.txt');$s=preg_replace('/^<\?php\s*declare\(strict_types=1\);/','',$s);$downloadedForm=eval($s);$config=['bdi_ii_form'=>$downloadedForm];
expect(bdi_licensed_form()!==null,'authorized BDI source accepted');expect(count($downloadedForm['items'])===21,'BDI complete source');
foreach([16,18] as $id){expect(count($downloadedForm['items'][$id]['options'])===7,'BDI directional options '.$id);foreach([0,1,1,2,2,3,3] as $choice=>$expected){$v=array_fill(1,21,0);$v[$id]=$choice;expect(bdi_score_choices($downloadedForm,$v)['total']===$expected,'BDI direction weight '.$id.':'.$choice);}}
expect(bdi_score_choices($downloadedForm,array_fill(1,21,0))['total']===0,'BDI no symptom fixture');
foreach(array_keys(psych_assessment_specs()) as $slug)expect(psych_assessment_form($slug)===null,'no unlicensed question content '.$slug);
echo 'PASS '.$checks.' assessment checks';
