<?php
declare(strict_types=1);
namespace ManaCms;
require_once __DIR__.'/core.php';

function static_pages(): array {
    $pages=[];
    preg_match_all("~'GET (/[^']*)' => 'pages/([^']+)\.php'~",file_get_contents(root().'/index.php'),$matches,PREG_SET_ORDER);
    foreach($matches as $m) if(in_array($m[2],['home','doctors','workshops','articles','guide_first_session','issues','tests','about','services','service_individual','service_couples','service_child','service_premarital','service_family','service_assessments','service_workshops','faq','rules','contact','collaborate','assistant'],true)) $pages[$m[1]]=$m[2];
    return $pages;
}
function public_path(string $path): bool {
    return isset(static_pages()[$path]) || preg_match('~^/(doctors|articles|issues|tests)/[A-Za-z0-9_-]+$~D',$path)===1;
}
function pages(): array {
    $labels=['/'=>'خانه','/doctors'=>'متخصصان','/articles'=>'مقالات','/services'=>'خدمات','/about'=>'درباره ما','/contact'=>'تماس','/faq'=>'پرسش‌ها','/rules'=>'قوانین','/tests'=>'آزمون‌ها','/issues'=>'موضوعات','/workshops'=>'کارگاه‌ها','/collaborate'=>'همکاری','/assistant'=>'دستیار'];
    $out=[];
    foreach(static_pages() as $path=>$file) $out[$path]=['id'=>$path,'title'=>$labels[$path]??$path,'kind'=>'public'];
    $warning=null;
    // Only read public record identifiers. Do not invoke schema boot, seed or application actions.
    if(is_file(clinic_config_file())) try {
        $c=require clinic_config_file();
        $pdo=new \PDO('mysql:host='.$c['db_host'].';dbname='.$c['db_name'].';charset=utf8mb4',$c['db_user'],$c['db_pass'],[\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION]);
        foreach($pdo->query('SELECT slug,title FROM articles WHERE published=1')->fetchAll(\PDO::FETCH_ASSOC) as $a) $out['/articles/'.$a['slug']]=['id'=>'/articles/'.$a['slug'],'title'=>$a['title'],'kind'=>'article'];
        foreach($pdo->query('SELECT dp.id,u.name FROM doctor_profiles dp JOIN users u ON u.id=dp.user_id WHERE dp.is_active=1 AND dp.is_approved=1 AND u.is_disabled=0')->fetchAll(\PDO::FETCH_ASSOC) as $a) $out['/doctors/'.$a['id']]=['id'=>'/doctors/'.$a['id'],'title'=>$a['name'],'kind'=>'doctor'];
    } catch(\Throwable $e) { $warning='فهرست رکوردهای عمومی دیتابیس در این محیط در دسترس نیست.'; }
    else $warning='تنظیمات دیتابیس محلی موجود نیست؛ صفحات پویا پس از اتصال نمایش داده می‌شوند.';
    require_once root().'/includes/clinic_directory.php';
    foreach(\clinic_issue_topics() as $slug=>$topic) $out['/issues/'.$slug]=['id'=>'/issues/'.$slug,'title'=>$topic['title']??$slug,'kind'=>'issue'];
    require_once root().'/includes/psych_tests.php';
    foreach(\psych_tests_catalog() as $test) if(!empty($test['ready'])) $out['/tests/'.$test['slug']]=['id'=>'/tests/'.$test['slug'],'title'=>$test['title'],'kind'=>'test'];
    return ['pages'=>array_values($out),'warning'=>$warning];
}
function template_stamp(string $page,string $scope): string {
    $files=[root().'/includes/layout.php'];
    if($scope==='page') {
        $name=static_pages()[$page]??(str_starts_with($page,'/articles/')?'article_detail':(str_starts_with($page,'/doctors/')?'doctor_detail':(str_starts_with($page,'/issues/')?'issue':'test_detail')));
        $files[]=root().'/pages/'.$name.'.php';
        $files[]=root().'/includes/service_page_helpers.php';
        if(str_starts_with($page,'/issues')) $files[]=root().'/includes/clinic_directory.php';
        if(str_starts_with($page,'/tests')) foreach(['psych_tests','psych_assessments','bdi'] as $helper) $files[]=root().'/includes/'.$helper.'.php';
    }
    $hash=''; foreach($files as $file) if(is_file($file)) $hash.=hash_file('sha256',$file);
    return substr(hash('sha256',$hash),0,16);
}
function scan(string $html,string $page): array {
    $scan=text_scan($html,$page); $regions=[]; $menus=[]; $anchors=[];
    foreach($scan['nodes'] as $node) {
        if(!isset($node['start'],$node['closeStart'])) continue;
        if(($node['tag']==='header' && str_contains($node['raw'],'site-header')) || ($node['tag']==='footer' && str_contains($node['raw'],'site-footer')) || $node['tag']==='main') $regions[]=$node;
        if($node['tag']==='nav' && (preg_match('~\bid=["\x27]site-nav["\x27]~',$node['raw']) || str_contains($node['raw'],'mobile-nav-list'))) $menus[]=$node;
        if(preg_match('~\bid=["\x27]([^"\x27]+)["\x27]~',$node['raw'],$m)) $anchors[]=$m[1];
    }
    $fields=[]; $toc=[]; $headings=[];
    foreach($scan['fields'] as $f) {
        $region=null; foreach($regions as $r) if($f['start']>$r['start'] && $f['end']<$r['closeStart']) { $region=$r; break; }
        if(!$region) continue;
        foreach($menus as $m) if($f['start']>$m['start'] && $f['end']<$m['closeStart']) continue 2;
        $f['scope']=$region['tag']==='main'?'page':$region['tag'];
        $f['id']=($f['scope']==='page'?$page:'shared:'.$f['scope']).':'.hash('sha256',$f['id'].'|'.template_stamp($page,$f['scope']).'|'.$f['value']);
        foreach($scan['nodes'] as $n) if(($n['start']??-1)===$f['parentStart']) {
            if(preg_match('~^h[1-6]$~',$n['tag']) && preg_match('~\bid=["\x27]([^"\x27]+)["\x27]~',$n['raw'],$m)) $headings[$m[1]]=$f['id'];
            if($n['tag']==='a' && preg_match('~href=["\x27]#([^"\x27]+)["\x27]~',$n['raw'],$m)) $toc[$f['id']]=$m[1];
        }
        $fields[$f['id']]=$f;
    }
    foreach($toc as $id=>$anchor) if(isset($headings[$anchor]) && $fields[$id]['value']===$fields[$headings[$anchor]]['value']) $fields[$id]['derivedFrom']=$headings[$anchor];
    return ['fields'=>$fields,'menus'=>$menus,'anchors'=>array_values(array_unique($anchors)),'nodes'=>$scan['nodes']];
}
function canonical_image(string $url,string $context='/'): ?string {
    $url=html_entity_decode(trim($url),ENT_QUOTES|ENT_HTML5,'UTF-8');
    if(preg_match('~^(?:[a-z]+:|//)~i',$url)) return null;
    $url=explode('?',explode('#',$url)[0])[0];
    if(str_contains($url,'\\')||str_contains(rawurldecode($url),'..')||str_contains($url,'%')) return null;
    if(!str_starts_with($url,'/')) $url=rtrim(dirname($context),'/').'/'.$url;
    if(base()!=='' && str_starts_with($url,base().'/')) $url=substr($url,strlen(base()));
    if(!preg_match('~^/(?:assets/|uploads/(?:doctors|workshops)/)[A-Za-z0-9_./-]+\.(?:png|jpe?g|webp)$~iD',$url)) return null;
    $file=realpath(root().$url);
    return $file && inside($file,realpath(root())) && is_file($file) ? $url : null;
}
function image_catalog(string $html,string $page): array {
    $images=[];
    $collect=function(string $text,string $context) use(&$images) {
        preg_match_all('~(?:\b(?:src|href)=["\x27]([^"\x27]+)["\x27]|\bsrcset=["\x27]([^"\x27]+)["\x27]|url\(\s*["\x27]?([^\s)"\x27]+))~i',$text,$matches,PREG_SET_ORDER);
        foreach($matches as $m) {
            $urls=isset($m[2]) && $m[2]!=='' ? array_map(fn($v)=>preg_split('~\s+~',trim($v))[0],explode(',',$m[2])) : [($m[1]??'')?:($m[3]??'')];
            foreach($urls as $url) {
                // CSS relative URLs may contain ..; resolve them locally before applying the allowlist.
                if(!str_starts_with($url,'/') && !preg_match('~^(?:[a-z]+:|//)~i',$url)) {
                    $resolved=realpath(root().dirname($context).'/'.$url);
                    if($resolved && inside($resolved,realpath(root()))) $url='/'.str_replace('\\','/',substr($resolved,strlen(realpath(root()))+1));
                }
                $id=canonical_image($url,$context); if(!$id) continue;
                $info=@getimagesize(root().$id); if(!$info) continue;
                $images[$id]=['id'=>$id,'label'=>basename($id),'width'=>$info[0],'height'=>$info[1],'sourceHash'=>hash_file('sha256',root().$id)];
                if(str_contains($id,'logo')) $images[$id]['label']='نشان مانا کلینیک';
                elseif(str_contains($id,'hero')) $images[$id]['label']='بنر اصلی — '.basename($id);
            }
        }
    };
    $collect($html,$page);
    preg_match_all('~<link\b[^>]*href=["\x27]([^"\x27]+\.css)(?:\?[^"\x27]*)?["\x27]~i',$html,$styles);
    foreach($styles[1] as $url) {
        if(base()!=='' && str_starts_with($url,base().'/')) $url=substr($url,strlen(base()));
        if(!preg_match('~^/assets/[A-Za-z0-9_./-]+\.css$~D',$url)) continue;
        $file=realpath(root().$url); if($file && inside($file,realpath(root().'/assets'))) $collect(file_get_contents($file),$url);
    }
    return array_values($images);
}
function public_fields(array $fields,array $s,?array $u=null,string $page=''): array {
    $result=[];
    foreach($fields as $f) {
        if(isset($f['derivedFrom'])) continue;
        if($u && !can_field($u,$page,$f)) continue;
        $f['original']=$f['value']; $f['value']=$s['edits'][$f['id']]['value']??$f['value'];
        $result[]=array_intersect_key($f,array_flip(['id','scope','label','value','original']));
    }
    return $result;
}
function default_menu(): array {
    foreach(read('catalog') as $c) if(!empty($c['originalMenu'])) return $c['originalMenu'];
    $items=[]; foreach(['/' =>'صفحه اصلی','/services'=>'خدمات','/doctors'=>'متخصصان','/articles'=>'مقالات','/faq'=>'سوالات متداول','/rules'=>'قوانین','/about'=>'درباره ما','/contact'=>'تماس با ما'] as $href=>$label) $items[]=['label'=>$label,'href'=>$href];
    return $items;
}
function record_catalog(string $page,array $scan,array $images,string $html): void {
    locked(function() use($page,$scan,$images,$html) {
        require_page($page); $catalog=read('catalog');
        $title=$page; if(preg_match('~<title[^>]*>(.*?)</title>~s',$html,$m)) $title=html_entity_decode(strip_tags($m[1]),ENT_QUOTES|ENT_HTML5,'UTF-8');
        $originalMenu=[];
        foreach($scan['menus'] as $m) if(str_contains($m['raw'],'site-nav')) {
            $inner=substr($html,$m['end'],$m['closeStart']-$m['end']);
            preg_match_all('~<a\b[^>]*href=["\x27]([^"\x27]+)["\x27][^>]*>(.*?)</a>~s',$inner,$links,PREG_SET_ORDER);
            foreach($links as $link) {
                $href=html_entity_decode($link[1],ENT_QUOTES|ENT_HTML5,'UTF-8');
                if(base()!=='' && str_starts_with($href,base().'/')) $href=substr($href,strlen(base()));
                $originalMenu[]=['label'=>trim(html_entity_decode(strip_tags($link[2]),ENT_QUOTES|ENT_HTML5,'UTF-8')),'href'=>$href];
            }
            break;
        }
        $catalog[$page]=['title'=>$title,'originalMenu'=>$originalMenu,'fields'=>array_values(array_map(fn($f)=>array_intersect_key($f,array_flip(['id','scope','label','value','derivedFrom'])),$scan['fields'])),'anchors'=>$scan['anchors'],'images'=>$images,'stamp'=>template_stamp($page,'page'),'sharedStamp'=>template_stamp($page,'header')];
        write('catalog',$catalog);
    });
}
function render(string $html,string $page,bool $preview=false): string {
    if(!public_path($page)) return $html;
    $s=state();
    if(!$preview && !$s['edits'] && $s['menus']===null) return $html;
    $scan=scan($html,$page); $replacements=[];
    $viewer=$preview?require_page($page):null;
    if($preview) record_catalog($page,$scan,image_catalog($html,$page),$html);
    foreach($scan['fields'] as $f) {
        $target=$f['derivedFrom']??$f['id']; $value=$s['edits'][$target]['value']??$f['value'];
        if(!isset($s['edits'][$target]) && !$preview) continue;
        preg_match('~^\s*~u',$f['raw'],$pre); preg_match('~\s*$~u',$f['raw'],$post);
        $text=esc($value);
        if($viewer && !isset($f['derivedFrom']) && can_field($viewer,$page,$f)) $text='<span data-mana-field="'.esc($f['id']).'">'.$text.'</span>';
        $replacements[]=['start'=>$f['start'],'end'=>$f['end'],'value'=>$pre[0].$text.$post[0]];
    }
    if($s['menus']!==null) foreach($scan['menus'] as $m) {
        $links=''; foreach($s['menus'] as $item) {
            $href=$item['href']; if(str_starts_with($href,'/')) $href=base().$href;
            $links.='<a href="'.esc($href).'">'.esc($item['label']).'</a>';
        }
        // Keep the clinic's booking CTA and signed-in panel shortcut functional.
        $inner=substr($html,$m['end'],$m['closeStart']-$m['end']);
        preg_match_all('~<a\b[^>]*href=["\x27]([^"\x27]+)["\x27][^>]*>.*?</a>~s',$inner,$existing,PREG_SET_ORDER);
        foreach($existing as $link) {
            $target=html_entity_decode($link[1],ENT_QUOTES|ENT_HTML5,'UTF-8');
            if(base()!=='' && str_starts_with($target,base().'/')) $target=substr($target,strlen(base()));
            if(str_contains($link[0],'mobile-nav-cta')) $links=$link[0].$links;
            elseif(preg_match('~^/(?:admin|doctor|secretary|dashboard|accountant)(?:/|$)~',$target)) $links.=$link[0];
        }
        $replacements[]=['start'=>$m['end'],'end'=>$m['closeStart'],'value'=>$links];
    }
    $html=patch($html,$replacements);
    if($preview) {
        $html=str_replace('<head>','<head><script src="'.esc(base()).'/psy-admin/preview.js"></script>',$html);
        $html=str_replace('</head>','<style>[data-mana-field]{cursor:text;white-space:pre-line}[data-mana-field]:empty{display:inline-block;min-width:1em;min-height:1em;border:1px dashed #12a899}[data-mana-field]:hover{outline:2px solid #12a899}body [data-content-protected]{user-select:text}</style></head>',$html);
        header('Cache-Control: private, no-store'); header('X-Robots-Tag: noindex, nofollow');
    }
    return $html;
}
function validate_menu(array $items,array $catalog): array {
    if(count($items)<1||count($items)>30) fail(400,'منو باید بین ۱ و ۳۰ گزینه داشته باشد.');
    $known=array_fill_keys(array_column(pages()['pages'],'id'),true); $clean=[];
    foreach($items as $item) {
        $label=$item['label']??null; $href=$item['href']??null;
        if(!is_string($label)||trim($label)===''||strlen($label)>300||!is_string($href)||strlen($href)>2048||preg_match('~[\x00-\x20\\\\]~',$href)) fail(400,'عنوان یا لینک معتبر نیست.');
        if(preg_match('~^https?://~i',$href) && strcasecmp((string)parse_url($href,PHP_URL_HOST),explode(':',$_SERVER['HTTP_HOST']??'')[0])===0) {
            if(parse_url($href,PHP_URL_USER)!==null||parse_url($href,PHP_URL_QUERY)!==null) fail(400,'لینک داخلی معتبر نیست.');
            $href=(parse_url($href,PHP_URL_PATH)?:'/').(($fragment=parse_url($href,PHP_URL_FRAGMENT))!==null?'#'.$fragment:'');
            if(base()!=='' && str_starts_with($href,base().'/')) $href=substr($href,strlen(base()));
        }
        if(preg_match('~^https?://~i',$href)) {
            if(!filter_var($href,FILTER_VALIDATE_URL)||parse_url($href,PHP_URL_USER)!==null||parse_url($href,PHP_URL_PASS)!==null) fail(400,'لینک خارجی معتبر نیست.');
        } elseif(preg_match('~^mailto:[A-Za-z0-9.!#$%&\x27*+/=?^_`{|}\~-]+@[A-Za-z0-9.-]+$~D',$href) || preg_match('~^tel:\+?[0-9()-]{3,30}$~D',$href)) {}
        else {
            $parts=explode('#',$href);
            if(count($parts)>2||!isset($known[$parts[0]])||str_contains($href,'..')||str_contains($href,'%')||str_starts_with($href,'//')) fail(400,'مقصد داخلی وجود ندارد.');
            if(isset($parts[1]) && (!isset($catalog[$parts[0]]) || !in_array($parts[1],$catalog[$parts[0]]['anchors'],true))) fail(400,'بخش مقصد وجود ندارد؛ ابتدا پیش‌نمایش آن صفحه را باز کنید.');
        }
        check_policy($label); $clean[]=['label'=>trim($label),'href'=>$href];
    }
    return $clean;
}
function check_policy(string $value): void {
    if(preg_match('~[0-9۰-۹٠-٩][0-9۰-۹٠-٩\s٬,٫.]*\s*(?:تومان|ریال|تومن|هزار تومان|میلیون تومان)|(?:قیمت|تعرفه|هزینه|تخفیف|fee|price|discount)[^\n]{0,35}[0-9۰-۹٠-٩]|[0-9۰-۹٠-٩]\s*[%٪]~ui',$value)) fail(400,'انتشار قیمت، تعرفه و تخفیف عددی در سایت عمومی طبق سیاست کلینیک مجاز نیست.');
}
function save_content(string $action,array $d): void {
    locked(function() use($action,$d) {
        $u=require_user(); $s=state();
        if($action==='undo') require_user(true);
        elseif($action==='menu') require_capability('menu');
        elseif($action==='image') require_capability('image',is_string($d['id']??null)?$d['id']:null);
        elseif($action==='save') require_page((string)($d['page']??''));
        if(($d['version']??null)!==$s['version']) fail(409,'نسخه تغییر کرده است؛ دوباره بارگذاری کنید.');
        $next=$s; $catalog=read('catalog');
        if($action==='undo') { if(!$s['undo']) fail(404,'ذخیره‌ای برای بازگردانی وجود ندارد.'); $next=$s['undo']; }
        elseif($action==='menu') $next['menus']=validate_menu($d['items']??[],$catalog);
        elseif($action==='save') {
            $page=$d['page']??''; $c=$catalog[$page]??null;
            if(!$c || $c['stamp']!==template_stamp($page,'page') || $c['sharedStamp']!==template_stamp($page,'header')) fail(409,'ابتدا پیش‌نمایش تازهٔ صفحه را باز کنید.');
            $fields=array_column($c['fields'],null,'id'); $changes=$d['changes']??null;
            if(!is_array($changes)||count($changes)>1000) fail(400,'ویرایش معتبر نیست.');
            foreach($changes as $change) {
                $id=$change['id']??''; $f=$fields[$id]??null; $value=$change['value']??null;
                if(!$f||isset($f['derivedFrom'])||!is_string($value)||strlen($value)>60000||preg_match('~[\x00-\x08\x0b\x0c\x0e-\x1f]~',$value)) fail(400,'متن معتبر نیست.');
                if(!can_field($u,$page,$f)) fail(403,'ویرایش این متن توسط مدیر مجاز نشده است.');
                check_policy($value); $next['edits'][$id]=['value'=>$value];
            }
        } elseif($action==='image') {
            $id=$d['id']??''; $found=null;
            foreach($catalog as $c) foreach($c['images'] as $i) if($i['id']===$id) $found=$i;
            if(!$found || canonical_image($id)!==$id) fail(400,'عکس معتبر نیست؛ پیش‌نمایش را باز کنید.');
            if(!hash_equals($found['sourceHash'],hash_file('sha256',root().$id))) fail(409,'عکس مبنا تغییر کرده؛ پیش‌نمایش را تازه کنید.');
            $next['images'][$id]=store_image($d['base64']??'');
        } else fail(404,'عملیات پیدا نشد.');
        commit($next,$s);
    });
}
function store_image(string $encoded): array {
    if(!function_exists('imagecreatefromstring')) fail(503,'افزونهٔ GD برای پردازش امن تصویر لازم است.');
    $bytes=base64_decode($encoded,true);
    if($bytes===false||strlen($bytes)>8*1024*1024) fail(400,'حداکثر حجم عکس ۸ مگابایت است.');
    $info=@getimagesizefromstring($bytes);
    if(!$info||!in_array($info['mime'],['image/jpeg','image/png','image/webp'],true)||$info[0]<1||$info[1]<1||$info[0]>8192||$info[1]>8192||$info[0]*$info[1]>16000000) fail(400,'نوع یا ابعاد عکس مجاز نیست (حداکثر ۱۶ میلیون پیکسل).');
    $image=@imagecreatefromstring($bytes); if(!$image) fail(400,'تصویر قابل پردازش نیست.');
    ob_start();
    try {
        if($info['mime']==='image/jpeg') { $ok=imagejpeg($image,null,90); $ext='jpg'; }
        elseif($info['mime']==='image/png') { imagesavealpha($image,true); $ok=imagepng($image); $ext='png'; }
        else { if(!function_exists('imagewebp')) fail(503,'GD هاست از WebP پشتیبانی نمی‌کند.'); $ok=imagewebp($image,null,90); $ext='webp'; }
        $output=ob_get_contents();
    } finally { ob_end_clean(); imagedestroy($image); }
    if(!$ok||strlen($output)>8*1024*1024) fail(400,'خروجی تصویر بیش از حد مجاز است.');
    $folder=data_dir().'/media'; if(!is_dir($folder)) mkdir($folder,0700);
    $name=hash('sha256',$output).'.'.$ext; $temp=$folder.'/'.bin2hex(random_bytes(12)).'.tmp';
    try {
        if(file_put_contents($temp,$output)!==strlen($output)) fail(503,'ذخیرهٔ عکس انجام نشد.'); chmod($temp,0600);
        if(!rename($temp,$folder.'/'.$name)) fail(503,'ذخیرهٔ عکس انجام نشد.');
    } finally { if(is_file($temp)) unlink($temp); }
    return ['file'=>$name,'mime'=>$info['mime'],'width'=>$info[0],'height'=>$info[1]];
}
function media(string $id): never {
    if(canonical_image($id)!==$id) fail(404,'عکس پیدا نشد.');
    $image=state()['images'][$id]??null;
    $file=$image ? data_dir().'/media/'.$image['file'] : root().$id;
    if(!is_file($file)) fail(404,'عکس پیدا نشد.');
    $info=@getimagesize($file); if(!$info) fail(404,'عکس پیدا نشد.');
    header('Content-Type: '.$info['mime']); header('Content-Length: '.filesize($file)); header('Cache-Control: no-cache');
    header('X-Content-Type-Options: nosniff'); header('Content-Security-Policy: default-src \'none\'');
    if(($_SERVER['REQUEST_METHOD']??'GET')!=='HEAD') readfile($file); exit;
}
