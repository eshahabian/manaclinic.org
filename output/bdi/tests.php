<?php
function check(bool $ok, string $message): void {global $checks; if (!$ok) throw new RuntimeException($message); $checks++;}
$checks = 0;
$source = file_get_contents(__DIR__ . '/includes_bdi.php.txt');
eval(substr($source, strpos($source, 'function bdi_score')));
function scores(int $total): array {
    $values = array_fill(1, 21, 0);
    foreach ($values as $i => $_) {$values[$i] = min(3, $total); $total -= $values[$i];}
    return $values;
}
foreach ([0=>'minimal',13=>'minimal',14=>'mild',19=>'mild',20=>'moderate',28=>'moderate',29=>'severe',63=>'severe'] as $total=>$band) {
    $r = bdi_score(scores($total)); check($r['total'] === $total && $r['band'] === $band, 'boundary '.$total);
}
foreach ([-1,4,1.5,true,null,[], '1e0','01','1.0',' 1','۱'] as $bad) {
    $input=scores(0);$input[1]=$bad;
    try {bdi_score($input);throw new RuntimeException('accepted invalid value');} catch (InvalidArgumentException $ex) {$checks++;}
}
foreach ([[],array_fill(0,21,0),array_fill(1,20,0),array_fill(1,22,0)] as $bad) {
    try {bdi_score($bad);throw new RuntimeException('accepted missing/extra items');} catch (InvalidArgumentException $ex) {$checks++;}
}
$r=bdi_score(array_map('strval',scores(14)));check($r['total']===14,'POST strings');
for($n=1;$n<=3;$n++){
    $input=scores(0);$input[9]=$n;$r=bdi_score($input);
    check($r['safety_followup'] && $r['total']===$n,'low total cannot hide safety');
    try {bdi_ai_messages($r);throw new RuntimeException('safety sent to model');}catch(DomainException $ex){$checks++;}
}
$input=scores(0);$input[1]=2;$r=bdi_score($input);$r['total']=63;$r['label']='tampered';
$messages=bdi_ai_messages($r);$payload=json_decode($messages[1]['content'],true);
check($payload['total']===2 && $payload['severity']==='minimal','AI input recalculated');
check(array_keys($payload)===['instrument','timeframe','total','severity','item_scores'],'no identity fields');
check(str_contains($messages[0]['content'],'تشخیص') && str_contains($messages[0]['content'],'دارو'),'prompt guardrails');
$config=[];check(bdi_licensed_form()===null,'no fabricated form');
$form=['online_use_authorized'=>true,'version'=>'BDI-II','source'=>'Synthetic fixture, not clinical content','instructions'=>'Synthetic instructions','items'=>[]];
for($n=1;$n<=21;$n++)$form['items'][$n]=['text'=>'Synthetic item '.$n,'options'=>array_map(fn($v)=>['score'=>$v,'text'=>'Synthetic option '.$v],range(0,3))];
$config['bdi_ii_form']=$form;check(bdi_licensed_form()!==null,'licensed form accepted');
$config['bdi_ii_form']['items'][16]['options'][]=['score'=>3,'text'=>'Synthetic alternate direction'];
check(bdi_licensed_form()!==null,'alternate sleep/appetite options');
$config['bdi_ii_form']['online_use_authorized']=false;check(bdi_licensed_form()===null,'authorization required');
$config['bdi_ii_form']=$form;$config['bdi_ii_form']['items'][1]['options'][0]['score']=7;check(bdi_licensed_form()===null,'invalid content rejected');
$valid=['summary'=>'Synthetic AI summary','observations'=>['One','Two'],'next_steps'=>['One','Two']];
check(bdi_parse_ai(json_encode($valid))===$valid,'AI structured response');
check(bdi_parse_ai("```json\n".json_encode($valid)."\n```")===$valid,'fenced response');
foreach(['not json','{}','{"summary":"x","observations":[],"next_steps":[]}'] as $bad){try{bdi_parse_ai($bad);throw new RuntimeException('invalid AI accepted');}catch(RuntimeException $ex){check($ex->getMessage()!=='invalid AI accepted','invalid AI rejected');}}
echo 'PASS '.$checks.' BDI checks';
