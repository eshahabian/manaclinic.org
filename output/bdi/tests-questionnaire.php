<?php
// Numeric tests and synthetic mocks from the existing scoring suite run first.
$base=file_get_contents(__DIR__.'/tests-base.php.txt');
$base=preg_replace('/^<\?php\s*/','',$base);
$base=str_replace("echo 'PASS '.$checks.' BDI checks';",'',$base);
eval($base);
$formSource=file_get_contents(__DIR__.'/includes_bdi_form_fa.php.txt');
$formSource=preg_replace('/^<\?php\s*declare\(strict_types=1\);/','',$formSource);
if(!defined('MANA_BDI_CONTENT'))define('MANA_BDI_CONTENT',true);
$actualForm=eval($formSource);
$config=['bdi_ii_form'=>$actualForm];
check(bdi_licensed_form()!==null,'downloaded source structure accepted');
check(count($actualForm['items'])===21,'all 21 source items');
check(count($actualForm['items'][16]['options'])===7,'sleep has both directions');
check(count($actualForm['items'][18]['options'])===7,'appetite has both directions');
check($actualForm['source_sha256']==='afa2fa97d79f2266f5c2971a8f94e1c028bfb70932a819efeac027152fc1157f','source provenance fingerprint');
$choices=array_fill(1,21,0);
check(bdi_score_choices($actualForm,$choices)['total']===0,'zero choices');
$choices=array_fill(1,21,3);$choices[16]=6;$choices[18]=6;
check(bdi_score_choices($actualForm,$choices)['total']===63,'maximum choices');
foreach([16,18] as $id){
 foreach([0,1,1,2,2,3,3] as $option=>$expected){
  $choices=array_fill(1,21,0);$choices[$id]=(string)$option;
  check(bdi_score_choices($actualForm,$choices)['total']===$expected,'direction mapping '.$id.':'.$option);
 }
}
foreach([[],array_fill(1,20,0),array_replace(array_fill(1,21,0),[1=>6]),array_replace(array_fill(1,21,0),[16=>'1.5']),array_replace(array_fill(1,21,0),[18=>[]])] as $bad){
 try{bdi_score_choices($actualForm,$bad);throw new RuntimeException('invalid choices accepted');}catch(InvalidArgumentException $ex){$checks++;}
}
$choices=array_fill(1,21,'0');$choices[9]='1';
check(bdi_score_choices($actualForm,$choices)['safety_followup'],'actual question 9 safety');
echo 'PASS '.$checks.' BDI questionnaire checks';
