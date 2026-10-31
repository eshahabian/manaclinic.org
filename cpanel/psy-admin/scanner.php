<?php
declare(strict_types=1);
namespace ManaCms;
function text_scan(string $html,string $page): array {
    $nodes=[['tag'=>'root','path'=>'','counts'=>[],'textCount'=>0,'raw'=>'']];$stack=[0];$fields=[];
    preg_match_all('~<!--[\s\S]*?-->|<![^>]*>|</?[A-Za-z][A-Za-z0-9:-]*(?:"[^"]*"|\x27[^\x27]*\x27|[^\x27">])*>|[^<]+|<~',$html,$matches,PREG_OFFSET_CAPTURE);
    foreach($matches[0] as [$raw,$offset]){
        $parentId=end($stack);$parent=&$nodes[$parentId];
        if(in_array($parent['tag'],['script','style'],true)&&!str_starts_with(strtolower($raw),'</'.$parent['tag']))continue;
        if(str_starts_with($raw,'<!--')||str_starts_with($raw,'<!'))continue;
        if($raw[0]==='<'){
            if(!preg_match('~^<(/?)([\w:-]+)~',$raw,$m))continue;$tag=strtolower($m[2]);
            if($m[1]){for($j=count($stack)-1;$j>0;$j--)if($nodes[$stack[$j]]['tag']===$tag){$nodes[$stack[$j]]['closeStart']=$offset;$stack=array_slice($stack,0,$j);break;}continue;}
            $nth=$parent['counts'][$tag]??0;$parent['counts'][$tag]=$nth+1;
            $node=['tag'=>$tag,'path'=>$parent['path'].'/'.$tag.'['.$nth.']','counts'=>[],'textCount'=>0,'start'=>$offset,'end'=>$offset+strlen($raw),'raw'=>$raw];$nodes[]=$node;$nodeId=count($nodes)-1;
            if(!preg_match('~^(area|base|br|col|embed|hr|img|input|link|meta|param|source|track|wbr)$~',$tag)&&!str_ends_with($raw,'/>'))$stack[]=$nodeId;continue;
        }
        $nth=$parent['textCount']++;$skip=false;$shared=null;$menu=false;$toc=false;
        foreach($stack as $i){$n=$nodes[$i];if(in_array($n['tag'],['head','script','style','noscript','svg','button','form','textarea','select'],true)||preg_match('~aria-hidden\s*=\s*["\x27]true~',$n['raw']))$skip=true;if(in_array($n['tag'],['header','footer'],true)&&$shared===null)$shared=$n;if(preg_match('~class=["\x27][^"\x27]*cms-no-text-menu~',$n['raw']))$menu=true;if(preg_match('~class=["\x27][^"\x27]*page-toc~',$n['raw']))$toc=true;}
        $value=trim(html_entity_decode($raw,ENT_QUOTES|ENT_HTML5,'UTF-8'));if($skip||!preg_match('~[\p{L}\p{N}]~u',$value))continue;
        $scope=$shared?$shared['tag']:'page';$relative=$shared?substr($parent['path'],strlen($shared['path'])):$parent['path'];
        $key=($shared?'shared:'.$scope:$page).$relative.'/text['.$nth.']';
        $labels=['h1'=>'عنوان اصلی','h2'=>'عنوان بخش','h3'=>'عنوان فرعی','p'=>'متن','li'=>'گزینهٔ فهرست','a'=>'متن لینک','small'=>'متن کوتاه','strong'=>'متن برجسته'];
        $fields[]=['id'=>$key,'scope'=>$scope,'label'=>$labels[$parent['tag']]??'متن','value'=>$value,'derived'=>$menu||($toc&&$parent['tag']==='a'&&preg_match('~href=["\x27]#profile-~',$parent['raw'])),'menu'=>$menu,'start'=>$offset,'end'=>$offset+strlen($raw),'raw'=>$raw,'parentStart'=>$parent['start'],'parentEnd'=>$parent['end'],'parentPath'=>$relative];
    }
    unset($parent);return ['fields'=>$fields,'nodes'=>$nodes];
}
function patch(string $html,array $replacements): string {usort($replacements,fn($a,$b)=>$b['start']<=>$a['start']);foreach($replacements as $r)$html=substr($html,0,$r['start']).$r['value'].substr($html,$r['end']);return $html;}
