<?php
declare(strict_types=1);
namespace ManaCms;

// Page documents, revisions, reusable patterns and media metadata share the atomic content store.
function studio(array $s): array { return array_replace(['documents'=>[],'media'=>[],'patterns'=>[],'theme'=>null,'taxonomies'=>[],'menu'=>null,'placements'=>['footerCalligraphy'=>[]]],$s['studio']??[]); }
function design_allowed(array $u,string $path,?array $doc=null): bool {
    if($u['role']==='admin') return true;
    $p=permissions($u);
    return in_array($path,$p['layouts'],true) || (!empty($p['blocks'][$path])) || ($p['createPages'] && !($doc['native']??true) && ($doc['creator']??null)===$u['id']);
}
function full_design(array $u,string $path,?array $doc=null): bool {
    return $u['role']==='admin'||in_array($path,permissions($u)['layouts'],true)||(permissions($u)['createPages']&&!($doc['native']??true)&&($doc['creator']??null)===$u['id']);
}
function design_require(string $permission,?array $u=null): array {
    $u??=require_user(); if($u['role']!=='admin' && !(permissions($u)[$permission]??false)) fail(403,'مدیر اجازهٔ این عملیات طراحی را نداده است.'); return $u;
}
function design_native_path(string $path): bool {
    if(isset(static_pages()[$path])) return true;
    if(!preg_match('~^/(doctors|articles|issues|tests)/[A-Za-z0-9_-]+$~D',$path)) return false;
    if(isset(read('catalog')[$path])) return true;
    return in_array($path,array_column(pages()['pages'],'id'),true);
}
function design_text(mixed $v,int $max=60000): string {
    if(!is_string($v)||strlen($v)>$max||preg_match('~[\x00-\x08\x0b\x0c\x0e-\x1f]~',$v)) fail(400,'متن یا طول آن معتبر نیست.');
    check_policy($v); return $v;
}
function design_path(mixed $path): string {
    if(!is_string($path)||strlen($path)>180||!preg_match('~^/[a-z0-9]+(?:[a-z0-9/-]*[a-z0-9])?$~D',$path)||str_contains($path,'//')) fail(400,'آدرس صفحه با حروف کوچک انگلیسی، عدد و خط تیره وارد شود.');
    preg_match_all("~'(?:GET|POST) (/[^']*)'~",file_get_contents(root().'/index.php'),$m);
    $reserved=['psy-admin','psy-media','assets','uploads','includes','actions','pages','storage','api','admin','doctor','secretary','dashboard','app','staff','payments','cron'];
    foreach($m[1] as $route) { $top=explode('/',trim($route,'/'))[0]; if($top!=='') $reserved[]=$top; }
    if(in_array(explode('/',trim($path,'/'))[0],$reserved,true)||file_exists(root().$path)) fail(400,'این آدرس برای بخش موجود یا عملیاتی سایت رزرو شده است.');
    return $path;
}
function design_link(mixed $href,array $s): string {
    $href=design_text($href,2048);
    if($href==='') return '';
    if(preg_match('~[\x00-\x20\\\\]~',$href)||str_contains($href,'%')||str_contains($href,'..')||str_starts_with($href,'//')) fail(400,'لینک معتبر نیست.');
    if(preg_match('~^https?://~i',$href)) {
        if(!filter_var($href,FILTER_VALIDATE_URL)||parse_url($href,PHP_URL_USER)!==null) fail(400,'لینک خارجی معتبر نیست.');
        if(strcasecmp((string)parse_url($href,PHP_URL_HOST),explode(':',$_SERVER['HTTP_HOST']??'')[0])!==0) return $href;
        $href=(parse_url($href,PHP_URL_PATH)?:'/').(($f=parse_url($href,PHP_URL_FRAGMENT))!==null?'#'.$f:'');
        if(base()!==''&&str_starts_with($href,base().'/')) $href=substr($href,strlen(base()));
    }
    if(preg_match('~^tel:\+?[0-9()-]{3,30}$~D',$href)||preg_match('~^mailto:[A-Za-z0-9_.+\-]+@[A-Za-z0-9.\-]+$~D',$href)) return $href;
    if(preg_match('~^#[A-Za-z][A-Za-z0-9_-]*$~D',$href)) return $href;
    $path=explode('#',$href)[0];
    if(!str_starts_with($href,'/')||(!public_path($path)&&!isset(studio($s)['documents'][$path]))) fail(400,'مقصد داخلی وجود ندارد.');
    return $href;
}
function design_style(mixed $input): array {
    if(!is_array($input)) fail(400,'تنظیمات ظاهر معتبر نیست.'); $out=[];
    $numbers=['padding'=>[0,160],'gap'=>[0,100],'fontSize'=>[10,100],'radius'=>[0,100],'maxWidth'=>[200,1800],'columns'=>[1,4],'height'=>[0,600]];
    foreach($input as $k=>$v) {
        if(isset($numbers[$k])) { if(!is_int($v)||$v<$numbers[$k][0]||$v>$numbers[$k][1]) fail(400,'اندازهٔ ظاهر خارج از محدوده است.'); $out[$k]=$v; }
        elseif(in_array($k,['background','color'],true)) { if(!is_string($v)||!preg_match('~^#[a-fA-F0-9]{6}$~D',$v)) fail(400,'رنگ معتبر نیست.'); $out[$k]=$v; }
        elseif($k==='align'&&in_array($v,['start','center','end'],true)) $out[$k]=$v;
        elseif($k==='font'&&in_array($v,['Vazirmatn','Tahoma','sans-serif'],true)) $out[$k]=$v;
        elseif($k==='hidden'&&is_bool($v)) $out[$k]=$v;
        else fail(400,'تنظیم ظاهر ناشناخته است.');
    } return $out;
}
function design_asset(mixed $id,array $s): string {
    if(!is_string($id)) fail(400,'عکس معتبر نیست.');
    if($id===''||canonical_image($id)===$id) return $id;
    if(isset(studio($s)['media'][$id])) {
        $u=require_user(); if($u['role']==='admin'||permissions($u)['mediaLibrary']||published_media_used($id,$s)) return $id;
        foreach(studio($s)['documents'] as $path=>$doc) if(design_allowed($u,$path,$doc)&&in_array($id,document_assets($doc['draft']),true)) return $id;
        fail(403,'این تصویر خصوصی در محدودهٔ دسترسی شما نیست.');
    }
    fail(400,'عکس در کتابخانه یا دارایی‌های سایت پیدا نشد.');
}
function clean_blocks(mixed $input,array $s,int $depth=0,?array &$ids=null): array {
    $ids??=[]; if(!is_array($input)||!array_is_list($input)||count($input)>150||$depth>5) fail(400,'ساختار یا تعداد بلوک‌ها مجاز نیست.'); $out=[];
    foreach($input as $b) {
        if(!is_array($b)||!is_string($b['id']??null)||!preg_match('~^[a-zA-Z][a-zA-Z0-9_-]{0,63}$~D',$b['id'])||isset($ids[$b['id']])||count($ids)>=300) fail(400,'شناسهٔ بلوک تکراری یا معتبر نیست.');
        $ids[$b['id']]=true; $type=$b['type']??'';
        if(!in_array($type,['section','columns','heading','text','image','button','hero','gallery','faq','spacer','list','navigation'],true)) fail(400,'نوع بلوک پشتیبانی نمی‌شود.');
        $n=['id'=>$b['id'],'type'=>$type,'style'=>design_style($b['style']??[]),'mobile'=>design_style($b['mobile']??[])];
        if(isset($b['anchor'])&&$b['anchor']!=='') { if(!is_string($b['anchor'])||!preg_match('~^[A-Za-z][A-Za-z0-9_-]{0,63}$~D',$b['anchor'])) fail(400,'شناسهٔ بخش معتبر نیست.'); $n['anchor']=$b['anchor']; }
        if(in_array($type,['heading','text','button','hero','list'],true)) $n['text']=design_text($b['text']??'');
        if($type==='heading') { $n['level']=$b['level']??2; if(!in_array($n['level'],[1,2,3,4],true)) fail(400,'سطح عنوان معتبر نیست.'); }
        if(in_array($type,['button','hero'],true)) { $n['href']=design_link($b['href']??'',$s); $n['label']=design_text($b['label']??'',300); }
        if(in_array($type,['image','hero'],true)) { $n['image']=design_asset($b['image']??'',$s); $n['alt']=design_text($b['alt']??'',1000); }
        if($type==='gallery') { $n['images']=[]; if(!is_array($b['images']??[])||count($b['images']??[])>30) fail(400,'گالری حداکثر ۳۰ تصویر دارد.'); foreach($b['images']??[] as $image) $n['images'][]=['image'=>design_asset($image['image']??'',$s),'alt'=>design_text($image['alt']??'',1000)]; }
        if($type==='faq') { $n['items']=[]; if(!is_array($b['items']??[])||count($b['items']??[])>40) fail(400,'تعداد پرسش‌ها مجاز نیست.'); foreach($b['items']??[] as $item) $n['items'][]=['question'=>design_text($item['question']??'',2000),'answer'=>design_text($item['answer']??'',10000)]; }
        if(in_array($type,['section','columns'],true)) $n['children']=clean_blocks($b['children']??[],$s,$depth+1,$ids);
        $out[]=$n;
    } return $out;
}
function block_ids(array $blocks): array { $out=[]; foreach($blocks as $b) { $out[]=$b['id']; $out=array_merge($out,block_ids($b['children']??[])); } return $out; }
function limited_blocks(array $blocks,array $allowed): array {
    foreach($blocks as &$b) { if(in_array($b['id'],$allowed,true)) $b=['id'=>$b['id'],'allowed'=>true]; elseif(isset($b['children'])) $b['children']=limited_blocks($b['children'],$allowed); } unset($b); return $blocks;
}
function new_document(string $path,array $u,bool $native): array {
    return ['path'=>$path,'native'=>$native,'creator'=>$u['id'],'revision'=>0,'trashed'=>false,'live'=>null,'history'=>[],
        'draft'=>['title'=>'','mode'=>'append','blocks'=>[],'edits'=>[],'seo'=>['title'=>'','description'=>'','image'=>'','indexing'=>'inherit'],'style'=>[],'mobile'=>[]]];
}
function clean_document(array $input,array $s,bool $native,string $path): array {
    $mode=$input['mode']??($native?'append':'replace');
    if(!in_array($mode,['append','replace'],true)) fail(400,'چیدمان معتبر نیست.');
    if($native&&$mode==='replace'&&!in_array(static_pages()[$path]??'', ['about','faq','rules','guide_first_session','services','service_individual','service_couples','service_child','service_premarital','service_family','service_assessments','service_workshops'],true)) fail(400,'بدنهٔ کاربردی این صفحه حفظ می‌شود؛ بخش جدید را به آن اضافه کنید.');
    $seo=$input['seo']??[]; if(!is_array($seo)||!in_array($seo['indexing']??'inherit',['inherit','index','noindex'],true)) fail(400,'تنظیم نمایه‌سازی معتبر نیست.');
    if(strlen(json_encode($input,JSON_THROW_ON_ERROR))>512*1024) fail(400,'حجم محتوای یک صفحه حداکثر ۵۱۲ کیلوبایت است؛ تصاویر را در کتابخانه بارگذاری کنید.');
    $blocks=clean_blocks($input['blocks']??[],$s); $anchors=[];
    $walk=function(array $items) use(&$walk,&$anchors) { foreach($items as $b) { if(isset($b['anchor'])) { if(isset($anchors[$b['anchor']])) fail(400,'شناسهٔ بخش تکراری است.'); $anchors[$b['anchor']]=true; } $walk($b['children']??[]); } }; $walk($blocks);
    return ['title'=>design_text($input['title']??'',500),'mode'=>$mode,'blocks'=>$blocks,'edits'=>$input['edits']??[],
        'seo'=>['title'=>design_text($seo['title']??'',500),'description'=>design_text($seo['description']??'',3000),'image'=>design_asset($seo['image']??'',$s),'indexing'=>$seo['indexing']??'inherit'],
        'style'=>design_style($input['style']??[]),'mobile'=>design_style($input['mobile']??[])];
}
function snapshot(array &$doc,string $action,array $u): void {
    $doc['history'][]=['id'=>bin2hex(random_bytes(8)),'time'=>gmdate('c'),'author'=>$u['username'],'action'=>$action,'draft'=>$doc['draft'],'live'=>$doc['live'],'trashed'=>$doc['trashed']];
    $doc['history']=array_slice($doc['history'],-30); $doc['revision']++;
}
function document_payload(array $doc,array $u): array {
    $p=permissions($u); $full=full_design($u,$doc['path'],$doc);
    $doc['draft']=design_payload($doc['draft']);
    if($doc['live']!==null) $doc['live']=design_payload($doc['live']);
    foreach($doc['history'] as &$r) { $r['draft']=design_payload($r['draft']); if($r['live']!==null) $r['live']=design_payload($r['live']); } unset($r);
    return $doc+['access'=>['full'=>$full,'blocks'=>$p['blocks'][$doc['path']]??[],'publish'=>$u['role']==='admin'||$p['publishPages'],'create'=>$u['role']==='admin'||$p['createPages']]];
}
function blocks_payload(array $blocks): array {
    foreach($blocks as &$b) { $b['style']=(object)$b['style']; $b['mobile']=(object)$b['mobile']; if(isset($b['children'])) $b['children']=blocks_payload($b['children']); } unset($b); return $blocks;
}
function design_payload(array $design): array {
    $design['style']=(object)$design['style']; $design['mobile']=(object)$design['mobile']; $design['edits']=(object)$design['edits']; $design['blocks']=blocks_payload($design['blocks']); return $design;
}
function theme_payload(array $theme): array {
    $theme['draft']=(object)$theme['draft']; $theme['live']=(object)$theme['live']; foreach($theme['history'] as &$r) $r['draft']=(object)$r['draft']; unset($r); return $theme;
}
function studio_api(string $action,string $method,array $d): array {
    $u=require_user();
    if($method==='GET') {
        $s=state(); $st=studio($s);
        if($action==='studio-part') {
            design_require('theme',$u); $kind=(string)($_GET['kind']??'');
            return ['document'=>part_payload(part_document($st,$kind,$u)),'version'=>$s['version']];
        }
        if($action==='studio-list') {
            $docs=[]; foreach($st['documents'] as $doc) if(design_allowed($u,$doc['path'],$doc)) $docs[]=array_intersect_key($doc,array_flip(['path','native','creator','revision','trashed']))+['title'=>$doc['draft']['title'],'published'=>$doc['live']!==null];
            $native=[]; foreach(pages()['pages'] as $page) if(design_allowed($u,$page['id'],$st['documents'][$page['id']]??null)) $native[]=$page;
            return ['documents'=>$docs,'native'=>$native,'version'=>$s['version'],'capabilities'=>$u['role']==='admin'?array_fill_keys(['createPages','publishPages','mediaLibrary','theme','patterns','taxonomy'],true):permissions($u)];
        }
        if($action==='studio-document') {
            $path=(string)($_GET['path']??''); $doc=$st['documents'][$path]??null;
            if(!$doc && design_native_path($path)) $doc=new_document($path,$u,true);
            if(!$doc||!design_allowed($u,$path,$doc)) fail(403,'چیدمان این صفحه در دسترسی شما نیست.');
            return ['document'=>document_payload($doc,$u),'version'=>$s['version']];
        }
        if($action==='studio-media') { design_require('mediaLibrary',$u); $media=[]; foreach($st['media'] as $id=>$m) $media[]=$m+['id'=>$id,'url'=>base().'/psy-admin/?media='.rawurlencode($id)]; return ['media'=>$media,'version'=>$s['version']]; }
        if($action==='studio-patterns') { design_require('patterns',$u); $patterns=$st['patterns']; foreach($patterns as &$pattern) $pattern['blocks']=blocks_payload($pattern['blocks']); unset($pattern); return ['patterns'=>$patterns,'version'=>$s['version']]; }
        if($action==='studio-theme') { design_require('theme',$u); return ['theme'=>theme_payload($st['theme']??['revision'=>0,'draft'=>[],'live'=>[],'history'=>[]]),'version'=>$s['version']]; }
        if($action==='studio-taxonomy') { design_require('taxonomy',$u); return ['taxonomies'=>taxonomy_records($s),'version'=>$s['version']]; }
        if($action==='studio-menu') { require_capability('menu'); return ['menu'=>$st['menu']??['draft'=>$s['menus']??default_menu(),'history'=>[]],'version'=>$s['version']]; }
        fail(404,'بخش طراحی پیدا نشد.');
    }
    return locked(function() use($action,$d) {
        $u=require_user(); $s=state(); $before=$s; $st=studio($s);
        if(in_array($action,['studio-media-upload','studio-media-update','studio-media-place','studio-pattern-save','studio-pattern-delete','studio-taxonomy-save','studio-menu-save','studio-menu-publish','studio-menu-revision'],true) && ($d['version']??null)!==$s['version']) fail(409,'داده تغییر کرده؛ دوباره بارگذاری کنید.');
        $result=[];
        if(in_array($action,['studio-part-save','studio-part-publish','studio-part-revision'],true)) {
            design_require('theme',$u); $kind=(string)($d['kind']??''); $part=part_document($st,$kind,$u);
            if(($d['revision']??null)!==$part['revision']) fail(409,'بخش مشترک تغییر کرده؛ دوباره بارگذاری کنید.');
            if($action==='studio-part-save') {
                $input=$d['document']??null; if(!is_array($input)) fail(400,'طرح معتبر نیست.');
                $clean=clean_document($input,$s,false,'/'); $clean['edits']=[];
                snapshot($part,$action,$u); $part['draft']=$clean;
            } elseif($action==='studio-part-publish') {
                design_require('publishPages',$u);
                // Global blocks cannot resolve page-local anchors across every public route.
                $check=function(array $blocks) use(&$check) { foreach($blocks as $b) { if(str_starts_with($b['href']??'','#')) fail(400,'در بخش مشترک، آدرس کامل صفحه و بخش مقصد را وارد کنید.'); $check($b['children']??[]); } }; $check($part['draft']['blocks']);
                validate_design_links($part['draft'],'/__shared_part__',$s);
                snapshot($part,$action,$u); $part['live']=$part['draft'];
            } else {
                $found=null; foreach($part['history'] as $r) if($r['id']===($d['id']??'')) $found=$r;
                if(!$found) fail(404,'نسخه پیدا نشد.'); snapshot($part,$action,$u); $part['draft']=$found['draft'];
            }
            $st['parts'][$kind]=$part; $result=['document'=>part_payload($part)];
        } elseif($action==='studio-create'||$action==='studio-copy') {
            design_require('createPages',$u); $path=design_path($d['path']??''); if(isset($st['documents'][$path])) fail(409,'این آدرس قبلاً ساخته شده است؛ سطل زباله را هم بررسی کنید.');
            $doc=new_document($path,$u,false); $doc['draft']['title']=design_text($d['title']??'صفحهٔ جدید',500); $doc['draft']['mode']='replace';
            if($action==='studio-copy') { $source=$st['documents'][$d['source']??'']??null; if(!$source||!full_design($u,$source['path'],$source)) fail(403,'کپی صفحه نیازمند دسترسی کامل چیدمان آن است.'); $doc['draft']=$source['draft']; $doc['draft']['title']=design_text($d['title']??($source['draft']['title'].' — کپی'),500); $doc['draft']['edits']=[]; }
            snapshot($doc,'create',$u); $st['documents'][$path]=$doc; $result=['document'=>document_payload($doc,$u)];
        } elseif(in_array($action,['studio-save','studio-publish','studio-trash','studio-restore','studio-unpublish','studio-revision'],true)) {
            $path=(string)($d['path']??''); $doc=$st['documents'][$path]??null;
            if(!$doc&&design_native_path($path)) $doc=new_document($path,$u,true);
            if(!$doc||!design_allowed($u,$path,$doc)) fail(403,'اجازهٔ طراحی این صفحه را ندارید.');
            if(($d['revision']??null)!==$doc['revision']) fail(409,'نسخهٔ صفحه تغییر کرده؛ دوباره بارگذاری کنید.');
            $full=full_design($u,$path,$doc);
            if($action==='studio-save') {
                if($doc['trashed']) fail(400,'ابتدا صفحه را از سطل زباله برگردانید.');
                if(!is_array($d['document']??null)) fail(400,'دادهٔ صفحه معتبر نیست.');
                $next=clean_document($d['document'],$s,$doc['native'],$path);
                // Legacy text drafts are only populated by their dedicated field-authorized endpoint.
                $next['edits']=$doc['draft']['edits'];
                if(!$full) {
                    $a=$next; $b=$doc['draft']; $allowed=permissions($u)['blocks'][$path]??[];
                    $a['blocks']=limited_blocks($a['blocks'],$allowed); $b['blocks']=limited_blocks($b['blocks'],$allowed);
                    if($a!==$b) fail(403,'فقط بلوک‌های مشخص‌شده توسط مدیر قابل تغییرند.');
                }
                snapshot($doc,'save-draft',$u); $doc['draft']=$next;
            } elseif($action==='studio-publish') {
                design_require('publishPages',$u); if(!$full) fail(403,'انتشار کامل صفحه نیازمند دسترسی کامل چیدمان آن است.');
                if($doc['trashed']) fail(400,'صفحه در سطل زباله است.');
                $candidate=clean_document($doc['draft'],$s,$doc['native'],$path); $candidate['edits']=$doc['draft']['edits'];
                validate_design_links($candidate,$path,$s);
                if(trim($candidate['title'])==='') fail(400,'عنوان صفحه را وارد کنید.');
                $fields=array_column(read('catalog')[$path]['fields']??[],null,'id');
                foreach($candidate['edits'] as $id=>$edit) {
                    if($u['role']!=='admin'&&(!isset($fields[$id])||!can_field($u,$path,$fields[$id]))) fail(403,'دسترسی انتشار یکی از متن‌های پیش‌نویس را ندارید.');
                    $s['edits'][$id]=$edit;
                }
                snapshot($doc,'publish',$u); $doc['live']=$candidate;
            } elseif($action==='studio-trash'||$action==='studio-unpublish') {
                design_require('publishPages',$u); if(!$full||$doc['native']) fail(403,'حذف صفحه‌های اصلی و کاربردی سایت مجاز نیست.');
                snapshot($doc,$action,$u); $doc['live']=null; if($action==='studio-trash') $doc['trashed']=true;
            } elseif($action==='studio-restore') {
                if(!$full) fail(403,'اجازهٔ بازگردانی صفحه را ندارید.'); snapshot($doc,'restore-trash',$u); $doc['trashed']=false;
            } else {
                if(!$full) fail(403,'بازگردانی تاریخچه نیازمند دسترسی کامل صفحه است.'); $rev=null;
                foreach($doc['history'] as $r) if($r['id']===($d['id']??null)) $rev=$r;
                if(!$rev) fail(404,'نسخه پیدا نشد.'); snapshot($doc,'restore-revision',$u); $doc['draft']=$rev['draft']; $doc['trashed']=false;
            }
            $st['documents'][$path]=$doc; $result=['document'=>document_payload($doc,$u)];
        } elseif($action==='studio-text-draft') {
            $path=(string)($d['path']??''); require_page($path); $c=read('catalog')[$path]??null;
            if(!$c||($d['version']??null)!==$s['version']||$c['stamp']!==template_stamp($path,'page')||$c['sharedStamp']!==template_stamp($path,'header')) fail(409,'ابتدا پیش‌نمایش را تازه کنید.');
            $fields=array_column($c['fields'],null,'id'); $doc=$st['documents'][$path]??new_document($path,$u,true);
            if(!is_array($d['changes']??null)||count($d['changes'])>1000) fail(400,'ویرایش متن معتبر نیست.');
            foreach($d['changes'] as $change) { $f=$fields[$change['id']??'']??null; if(!$f||isset($f['derivedFrom'])||!can_field($u,$path,$f)) fail(403,'متن در محدودهٔ دسترسی نیست.'); $doc['draft']['edits'][$f['id']]=['value'=>design_text($change['value']??null)]; }
            if(strlen(json_encode($doc['draft']))>512*1024) fail(413,'حجم پیش‌نویس صفحه بیش از حد مجاز است.');
            snapshot($doc,'text-draft',$u); $st['documents'][$path]=$doc; $result=['revision'=>$doc['revision']];
        } elseif($action==='studio-media-upload') {
            design_require('mediaLibrary',$u); $image=store_image((string)($d['base64']??''),$d['crop']??null); $id=bin2hex(random_bytes(12));
            $placement=clean_placement($d['placement']??['kind'=>'library'],$s);
            $st['media'][$id]=$image+['label'=>design_text($d['label']??'تصویر',500),'alt'=>design_text($d['alt']??'',1000),'placement'=>$placement,'created'=>gmdate('c')]; $result=['id'=>$id];
        } elseif($action==='studio-media-place') {
            design_require('mediaLibrary',$u); $id=(string)($d['id']??''); if(!isset($st['media'][$id])) fail(404,'تصویر پیدا نشد.');
            $placement=clean_placement($d['placement']??($st['media'][$id]['placement']??['kind'=>'library']),$s); $st['media'][$id]['placement']=$placement;
            if($placement['kind']==='footer-calligraphy') {
                design_require('footerMedia',$u); design_require('publishPages',$u);
                $st['placements']['footerCalligraphy']=array_values(array_unique(array_merge($st['placements']['footerCalligraphy'],[$id])));
            } elseif($placement['kind']==='replace') {
                design_require('publishPages',$u); require_capability('image',$placement['image']); $s['images'][$placement['image']]=array_intersect_key($st['media'][$id],array_flip(['file','mime','width','height']));
            } elseif($placement['kind']==='page') {
                $path=$placement['path']; $target=$st['documents'][$path]??new_document($path,$u,true);
                if(!full_design($u,$path,$target)) fail(403,'افزودن تصویر نیازمند دسترسی چیدمان کامل صفحه است.');
                snapshot($target,'add-image-draft',$u); $target['draft']['blocks'][]=['id'=>'img'.bin2hex(random_bytes(8)),'type'=>'image','style'=>[],'mobile'=>[],'image'=>$id,'alt'=>$st['media'][$id]['alt']];
                $st['documents'][$path]=$target;
            }
        } elseif($action==='studio-media-update') {
            design_require('mediaLibrary',$u); $id=$d['id']??''; if(!isset($st['media'][$id])) fail(404,'تصویر پیدا نشد.');
            $st['media'][$id]['label']=design_text($d['label']??'',500); $st['media'][$id]['alt']=design_text($d['alt']??'',1000);
            if(isset($d['crop'])) {
                $original=$st['media'][$id]; $encoded=base64_encode(file_get_contents(data_dir().'/media/'.$original['file'])); $new=store_image($encoded,$d['crop']);
                $newId=bin2hex(random_bytes(12)); $st['media'][$newId]=$new+['label'=>$st['media'][$id]['label'].' — برش','alt'=>$st['media'][$id]['alt'],'created'=>gmdate('c')]; $result=['id'=>$newId];
            }
        } elseif($action==='studio-pattern-save'||$action==='studio-pattern-delete') {
            design_require('patterns',$u);
            if($action==='studio-pattern-delete') { $id=(string)($d['id']??''); if(!isset($st['patterns'][$id])) fail(404,'الگو پیدا نشد.'); unset($st['patterns'][$id]); }
            else { $id=bin2hex(random_bytes(8)); $st['patterns'][$id]=['title'=>design_text($d['title']??'',500),'blocks'=>clean_blocks($d['blocks']??[],$s)]; $result=['id'=>$id]; }
        } elseif($action==='studio-theme-save'||$action==='studio-theme-publish'||$action==='studio-theme-revision') {
            design_require('theme',$u); $theme=$st['theme']??['revision'=>0,'draft'=>[],'live'=>[],'history'=>[]];
            if(($d['revision']??null)!==$theme['revision']) fail(409,'تنظیم ظاهر تغییر کرده است.');
            $theme['history'][]=['id'=>bin2hex(random_bytes(8)),'draft'=>$theme['draft'],'time'=>gmdate('c')]; $theme['history']=array_slice($theme['history'],-30);
            if($action==='studio-theme-save') $theme['draft']=design_style($d['style']??[]);
            elseif($action==='studio-theme-publish') { design_require('publishPages',$u); $theme['live']=$theme['draft']; }
            else { $found=null; foreach($theme['history'] as $r) if($r['id']===($d['id']??'')) $found=$r; if(!$found) fail(404,'نسخه پیدا نشد.'); $theme['draft']=$found['draft']; }
            $theme['revision']++; $st['theme']=$theme; $result=['theme'=>theme_payload($theme)];
        } elseif(in_array($action,['studio-menu-save','studio-menu-publish','studio-menu-revision'],true)) {
            require_capability('menu'); $menu=$st['menu']??['draft'=>$s['menus']??default_menu(),'history'=>[]];
            $menu['history'][]=['id'=>bin2hex(random_bytes(8)),'time'=>gmdate('c'),'items'=>$menu['draft']]; $menu['history']=array_slice($menu['history'],-30);
            if($action==='studio-menu-save') $menu['draft']=validate_menu($d['items']??[],read('catalog'),true);
            elseif($action==='studio-menu-publish') { design_require('publishPages',$u); $s['menus']=validate_menu($menu['draft'],read('catalog')); }
            else { $found=null; foreach($menu['history'] as $rev) if($rev['id']===($d['id']??'')) $found=$rev; if(!$found) fail(404,'نسخهٔ منو پیدا نشد.'); $menu['draft']=$found['items']; }
            $st['menu']=$menu;
        } elseif($action==='studio-taxonomy-save') {
            design_require('taxonomy',$u); $kind=$d['kind']??''; if(!in_array($kind,['domain','focus','approach'],true)) fail(400,'دسته معتبر نیست.');
            $prior=$st['taxonomies'][$kind]??['revision'=>0,'draft'=>null,'live'=>null,'history'=>[]];
            $protected=taxonomy_base($kind); foreach($prior['live']??[] as $node) $protected[$node['id']]=$node['label'];
            $nodes=clean_taxonomy($d['nodes']??[],$protected);
            $prior['history'][]=['id'=>bin2hex(random_bytes(8)),'time'=>gmdate('c'),'nodes'=>$prior['draft']??taxonomy_seed($kind)]; $prior['history']=array_slice($prior['history'],-30);
            $prior['draft']=$nodes; $prior['revision']++;
            if(!empty($d['publish'])) { design_require('publishPages',$u); $prior['live']=$nodes; }
            $st['taxonomies'][$kind]=$prior;
        } else fail(404,'عملیات طراحی پیدا نشد.');
        $s['studio']=$st; commit($s,$before); return $result+['ok'=>true,'version'=>$before['version']+1];
    });
}

function design_css(array $style): string {
    $out=''; foreach($style as $k=>$v) {
        $prop=['padding'=>'padding','gap'=>'gap','fontSize'=>'font-size','radius'=>'border-radius','maxWidth'=>'max-width','height'=>'min-height','background'=>'background-color','color'=>'color','align'=>'text-align','font'=>'font-family'][$k]??null;
        if($prop) $out.=$prop.':'.$v.(is_int($v)?'px':'').';';
        elseif($k==='columns') $out.='grid-template-columns:repeat('.$v.',minmax(0,1fr));';
        elseif($k==='hidden') $out.='display:'.($v?'none':'revert').';';
    } return $out;
}
function asset_url(string $id,array $s): string {
    if(!isset(studio($s)['media'][$id])) return base().$id;
    return base().'/psy-media/'.$id.(!empty($GLOBALS['mana_cms_preview'])?'?mana_preview='.rawurlencode($_SESSION['csrf']??''):'');
}
function block_html(array $blocks,array $s,bool $preview=false,string $prefix=''): string {
    $html=''; foreach($blocks as $b) {
        $id='psy-'.$prefix.$b['id']; $style=design_css($b['style']); $mobile=design_css($b['mobile']);
        $html.='<style>#'.$id.'{'.$style.'}@media(max-width:768px){#'.$id.'{'.$mobile.'}}</style>';
        $html.='<div id="'.$id.'" class="psy-block psy-'.$b['type'].'"'.($preview?' data-psy-block="'.esc($b['id']).'"':'').'>';
        if(isset($b['anchor'])) $html.='<span id="'.esc($b['anchor']).'"></span>';
        $text=esc($b['text']??''); $type=$b['type'];
        if($type==='heading') $html.='<h'.$b['level'].'>'.$text.'</h'.$b['level'].'>';
        elseif($type==='navigation') { $html.='<nav aria-label="منوی سایت" class="psy-navigation-links">'; foreach($s['menus']??default_menu() as $item) { $href=$item['href']; $html.='<a href="'.esc(str_starts_with($href,'/')?base().$href:$href).'">'.esc($item['label']).'</a>'; } $html.='</nav>'; }
        elseif($type==='text') $html.='<p>'.$text.'</p>';
        elseif($type==='section'||$type==='columns') $html.=block_html($b['children'],$s,$preview,$prefix);
        elseif($type==='image'&&$b['image']!=='') $html.='<img src="'.esc(asset_url($b['image'],$s)).'" alt="'.esc($b['alt']).'" loading="lazy">';
        elseif($type==='button') { $href=$b['href']; $html.='<a class="btn btn-primary" href="'.esc(str_starts_with($href,'/')?base().$href:$href).'">'.($text?:esc($b['label'])).'</a>'; }
        elseif($type==='hero') {
            if($b['image']!=='') $html.='<img class="psy-hero-image" src="'.esc(asset_url($b['image'],$s)).'" alt="'.esc($b['alt']).'">';
            $html.='<div class="psy-hero-content"><h2>'.$text.'</h2>';
            if($b['href']!=='') $html.='<a class="btn btn-primary" href="'.esc(str_starts_with($b['href'],'/')?base().$b['href']:$b['href']).'">'.esc($b['label']).'</a>'; $html.='</div>';
        } elseif($type==='gallery') { foreach($b['images'] as $i) if($i['image']!=='') $html.='<img loading="lazy" src="'.esc(asset_url($i['image'],$s)).'" alt="'.esc($i['alt']).'">'; }
        elseif($type==='faq') { foreach($b['items'] as $item) $html.='<details><summary>'.esc($item['question']).'</summary><p>'.esc($item['answer']).'</p></details>'; }
        elseif($type==='list') { $html.='<ul>'; foreach(explode("\n",$b['text']) as $line) if(trim($line)!=='') $html.='<li>'.esc($line).'</li>'; $html.='</ul>'; }
        $html.='</div>';
    } return $html;
}
function selected_design(string $path,array $s): ?array {
    $doc=studio($s)['documents'][$path]??null; if(!$doc||$doc['trashed']) return null;
    return !empty($GLOBALS['mana_cms_draft'])?$doc['draft']:$doc['live'];
}
function studio_body(string $path,string $native): string {
    $s=state(); $design=selected_design($path,$s); if(!$design) return $native;
    $css='<link rel="stylesheet" href="'.esc(base()).'/psy-admin/blocks.css">';
    $style=design_css($design['style']); $mobile=design_css($design['mobile']);
    $blocks=$css.'<style>.psy-document{'.$style.'}@media(max-width:768px){.psy-document{'.$mobile.'}}</style><div class="psy-document container-page">'.block_html($design['blocks'],$s,!empty($GLOBALS['mana_cms_preview'])).'</div>';
    return $design['mode']==='replace'?$blocks:$native.$blocks;
}
function studio_meta(string $path): void {
    if(!public_path($path)) return;
    $s=state(); $design=selected_design($path,$s); if(!$design) return;
    $seo=$design['seo'];
    if($seo['title']!=='') $GLOBALS['pageTitle']=$seo['title'];
    elseif(!isset(static_pages()[$path])) $GLOBALS['pageTitle']=$design['title'];
    if($seo['description']!=='') $GLOBALS['pageDescription']=$seo['description'];
    if($seo['image']!=='') $GLOBALS['pageImage']=\seo_absolute_url(isset(studio($s)['media'][$seo['image']])?'/psy-media/'.$seo['image']:$seo['image']);
    if($seo['indexing']!=='inherit') $GLOBALS['pageRobots']=$seo['indexing']==='noindex'?'noindex,follow':'index,follow';
}
function studio_theme_css(): string {
    $s=state(); $theme=studio($s)['theme']; if(!$theme) return '';
    $style=!empty($GLOBALS['mana_cms_draft'])?$theme['draft']:$theme['live']; if(!$style) return '';
    $css=design_css($style); $vars=''; foreach(['background'=>'--bg','color'=>'--fg','font'=>'--font'] as $k=>$v) if(isset($style[$k])) $vars.=$v.':'.$style[$k].';';
    return '<style>:root{'.$vars.'}body{'.$css.'}</style>';
}
function studio_custom_serve(string $path): never {
    $doc=studio(state())['documents'][$path]??null;
    if(!$doc||$doc['native']||$doc['trashed']||(!isset($GLOBALS['mana_cms_draft'])&&$doc['live']===null)) { http_response_code(404); exit('صفحه پیدا نشد.'); }
    $content=''; $pageTitle=$doc['draft']['title']; $pageCanonical=$path; require root().'/includes/layout.php'; exit;
}
function design_anchors(array $design): array {
    $out=[]; $walk=function(array $blocks) use(&$walk,&$out) { foreach($blocks as $b) { if(isset($b['anchor'])) $out[]=$b['anchor']; $walk($b['children']??[]); } }; $walk($design['blocks']); return $out;
}
function validate_design_links(array $design,string $path,array $s): void {
    $st=studio($s); $catalog=read('catalog');
    $walk=function(array $blocks) use(&$walk,$st,$catalog,$design,$path) {
        foreach($blocks as $b) {
            $href=$b['href']??'';
            if(str_starts_with($href,'/')||str_starts_with($href,'#')) {
                $parts=explode('#',$href); $target=$parts[0]===''?$path:$parts[0];
                $targetDoc=$st['documents'][$target]??null;
                if($target!==$path&&$targetDoc&&!$targetDoc['native']&&($targetDoc['trashed']||$targetDoc['live']===null)) fail(400,'مقصد یکی از لینک‌ها هنوز منتشر نشده است.');
                if(isset($parts[1])) {
                    $anchors=$target===$path?design_anchors($design):($targetDoc&&$targetDoc['live']?design_anchors($targetDoc['live']):[]);
                    if(!in_array($parts[1],array_merge($anchors,$catalog[$target]['anchors']??[]),true)) fail(400,'بخش مقصد یکی از لینک‌ها وجود ندارد.');
                }
            }
            $walk($b['children']??[]);
        }
    }; $walk($design['blocks']);
}
function published_media_used(string $id,array $s): bool {
    $st=studio($s); if(in_array($id,$st['placements']['footerCalligraphy'],true)) return true;
    foreach($st['parts']??[] as $part) if($part['live']!==null&&in_array($id,document_assets($part['live']),true)) return true;
    foreach($s['images'] as $image) if(($image['file']??'')===($st['media'][$id]['file']??null)) return true;
    foreach(studio($s)['documents'] as $doc) if(!$doc['trashed']&&$doc['live']!==null && in_array($id,document_assets($doc['live']),true)) return true; return false;
}
function part_document(array $st,string $kind,array $u): array {
    if(!in_array($kind,['header','footer'],true)) fail(400,'بخش مشترک معتبر نیست.');
    return $st['parts'][$kind]??new_document('/__part-'.$kind,$u,false);
}
function part_payload(array $part): array {
    $part['draft']=design_payload($part['draft']);
    if($part['live']!==null) $part['live']=design_payload($part['live']);
    return $part+['part'=>str_replace('/__part-','',$part['path']),'access'=>['full'=>true,'blocks'=>[],'publish'=>require_user()['role']==='admin'||permissions(require_user())['publishPages'],'create'=>false]];
}
function studio_part_html(string $kind): string {
    if(!public_path((string)($GLOBALS['path']??'/'))) return '';
    $s=state(); $part=studio($s)['parts'][$kind]??null; if(!$part) return '';
    $design=!empty($GLOBALS['mana_cms_draft'])?$part['draft']:$part['live']; if(!$design) return '';
    $selector='.psy-shared-'.$kind;
    return '<link rel="stylesheet" href="'.esc(base()).'/psy-admin/blocks.css"><style>'.$selector.'{'.design_css($design['style']).'}@media(max-width:768px){'.$selector.'{'.design_css($design['mobile']).'}}</style><div class="psy-shared-part psy-shared-'.esc($kind).'">'.block_html($design['blocks'],$s,!empty($GLOBALS['mana_cms_preview']),$kind.'-').'</div>';
}
function studio_part_replaces(string $kind): bool {
    if(!public_path((string)($GLOBALS['path']??'/'))) return false;
    $part=studio(state())['parts'][$kind]??null; if(!$part) return false;
    $design=!empty($GLOBALS['mana_cms_draft'])?$part['draft']:$part['live'];
    return $design!==null&&$design['mode']==='replace';
}
function document_assets(array $design): array {
    $ids=[$design['seo']['image']??''];
    $walk=function(array $blocks) use(&$walk,&$ids) { foreach($blocks as $b) { if(isset($b['image'])) $ids[]=$b['image']; foreach($b['images']??[] as $i) $ids[]=$i['image']; $walk($b['children']??[]); } };
    $walk($design['blocks']??[]); return array_values(array_unique(array_filter($ids)));
}
function clean_placement(mixed $value,array $s): array {
    if(!is_array($value)) fail(400,'محل استفادهٔ تصویر معتبر نیست.'); $kind=$value['kind']??'';
    if(in_array($kind,['library','footer-calligraphy'],true)) return ['kind'=>$kind];
    if($kind==='replace') { $id=$value['image']??''; if(!is_string($id)||canonical_image($id)!==$id) fail(400,'تصویر مقصد پیدا نشد.'); return ['kind'=>$kind,'image'=>$id]; }
    if($kind==='page') { $path=$value['path']??''; if(!is_string($path)||(!design_native_path($path)&&!isset(studio($s)['documents'][$path]))) fail(400,'صفحهٔ مقصد پیدا نشد.'); return ['kind'=>$kind,'path'=>$path]; }
    fail(400,'محل استفاده پشتیبانی نمی‌شود.');
}
function footer_media(): array {
    try { $s=state(); } catch(\Throwable $e) { return []; } $st=studio($s); $out=[];
    foreach($st['placements']['footerCalligraphy'] as $id) if(isset($st['media'][$id])) { $m=$st['media'][$id]; $file=data_dir().'/media/'.$m['file']; if(is_file($file)) $out[$file]=asset_url($id,$s); }
    return $out;
}
function studio_media(string $id,bool $private=false): never {
    $s=state(); $m=studio($s)['media'][$id]??null;
    if(!$m||!preg_match('~^[a-f0-9]{24}$~D',$id)) fail(404,'تصویر پیدا نشد.');
    if($private) {
        $u=require_user(); $allowed=$u['role']==='admin'||permissions($u)['mediaLibrary'];
        if(!$allowed&&!empty($GLOBALS['mana_cms_preview_media'])) foreach(studio($s)['documents'] as $path=>$doc) if(design_allowed($u,$path,$doc)&&in_array($id,document_assets($doc['draft']),true)) $allowed=true;
        if(!$allowed) fail(403,'تصویر در محدودهٔ دسترسی نیست.');
    }
    elseif(!published_media_used($id,$s)) fail(404,'این تصویر منتشر نشده است.');
    $file=data_dir().'/media/'.$m['file']; if(!is_file($file)) fail(404,'تصویر پیدا نشد.');
    header('Content-Type: '.$m['mime']); header('X-Content-Type-Options: nosniff'); header('Cache-Control: '.($private?'private, no-store':'no-cache')); header("Content-Security-Policy: default-src 'none'"); readfile($file); exit;
}

function taxonomy_base(string $kind): array {
    return match($kind) {
        'domain'=>['individual'=>'مشاوره فردی','couples'=>'زوج درمانی','premarital'=>'پیش از ازدواج','family'=>'خانواده درمانی','child'=>'کودک و نوجوان','organizational'=>'مشاوره سازمانی'],
        'focus'=>['anxiety'=>'اضطراب','depression'=>'افسردگی','ocd'=>'وسواس','personality'=>'اختلالات شخصیت','eating'=>'اختلال خوردن','trauma'=>'تروما','grief'=>'سوگ و فقدان','panic'=>'حملات پانیک','sexual'=>'مشکلات جنسی','relationships'=>'مشکلات روابط عاطفی','growth'=>'رشد فردی'],
        'approach'=>['cbt'=>'درمان شناختی-رفتاری (CBT)','act'=>'درمان مبتنی بر پذیرش و تعهد (ACT)','schema'=>'طرحواره‌درمانی','dbt'=>'رفتاردرمانی دیالکتیکی (DBT)','psychodynamic'=>'روان‌درمانی روان‌پویشی','psychoanalysis'=>'روان‌کاوی','eft'=>'درمان هیجان‌مدار (EFT)','systemic'=>'خانواده‌درمانی / درمان سیستمی','sfbt'=>'درمان راه‌حل‌محور (SFBT)'],
        default=>[]
    };
}
function taxonomy_seed(string $kind): array { $out=[]; foreach(taxonomy_base($kind) as $id=>$label) $out[]=['id'=>$id,'label'=>$label,'parent'=>'','active'=>true]; return $out; }
function taxonomy_records(array $s): array { $out=[]; foreach(['domain','focus','approach'] as $kind) $out[$kind]=studio($s)['taxonomies'][$kind]??['revision'=>0,'draft'=>taxonomy_seed($kind),'live'=>null,'history'=>[]]; return $out; }
function clean_taxonomy(mixed $input,array $builtin): array {
    if(!is_array($input)||!array_is_list($input)||count($input)>250) fail(400,'فهرست دسته‌بندی معتبر نیست.'); $nodes=[];
    foreach($input as $node) {
        $id=$node['id']??''; $parent=$node['parent']??'';
        if(!is_string($id)||!preg_match('~^[a-z][a-z0-9_-]{0,63}$~D',$id)||isset($nodes[$id])||!is_string($parent)||!is_bool($node['active']??null)) fail(400,'شناسه، والد یا وضعیت دسته معتبر نیست.');
        $label=design_text($node['label']??'',500); if(trim($label)==='') fail(400,'عنوان دسته نمی‌تواند خالی باشد.');
        $nodes[$id]=['id'=>$id,'label'=>$label,'parent'=>$parent,'active'=>$node['active']];
    }
    foreach($builtin as $id=>$label) if(!isset($nodes[$id])) fail(400,'گزینه‌های موجود را حذف نکنید؛ می‌توانید نمایش آن‌ها را غیرفعال کنید.');
    foreach($nodes as $id=>$node) { $seen=[$id=>true]; $parent=$node['parent']; $depth=0; while($parent!=='') { if(!isset($nodes[$parent])||isset($seen[$parent])||++$depth>5) fail(400,'والد ناموجود، چرخه یا عمق بیش از پنج سطح مجاز نیست.'); $seen[$parent]=true; $parent=$nodes[$parent]['parent']; } }
    return array_values($nodes);
}
function taxonomy_options(string $kind,array $base): array {
    $nodes=studio(state())['taxonomies'][$kind]['live']??null; if($nodes===null) return $base;
    $options=[]; foreach($nodes as $node) $options[$node['id']]=$node['label']; return $options;
}
function taxonomy_matches(string $kind,string $selected,array $assigned): bool {
    if(in_array($selected,$assigned,true)) return true;
    $nodes=studio(state())['taxonomies'][$kind]['live']??[]; $by=array_column($nodes,null,'id');
    foreach($assigned as $id) { $seen=[]; while(isset($by[$id])&&!isset($seen[$id])) { $seen[$id]=true; $id=$by[$id]['parent']; if($id===$selected) return true; } } return false;
}
function taxonomy_display_options(string $kind,array $fallback): array {
    $nodes=studio(state())['taxonomies'][$kind]['live']??null; if($nodes===null) return $fallback;
    $out=[]; $walk=function(string $parent,int $depth) use(&$walk,&$out,$nodes) {
        foreach($nodes as $node) if($node['parent']===$parent&&$node['active']) { $out[$node['id']]=str_repeat('— ',$depth).$node['label']; $walk($node['id'],$depth+1); }
    }; $walk('',0); return $out;
}
