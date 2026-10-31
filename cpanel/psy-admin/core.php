<?php
declare(strict_types=1);
namespace ManaCms;
require_once __DIR__.'/scanner.php';

function config(): array { static $c; return $c ??= require __DIR__.'/installation.php'; }
function root(): string { return dirname(__DIR__); }
function clinic_config_file(): string {
    // Local PHP server can only use an explicitly separate local clinic configuration.
    if(PHP_SAPI==='cli-server' && getenv('MANA_CLINIC_LOCAL_CONFIG')) return (string)getenv('MANA_CLINIC_LOCAL_CONFIG');
    return root().'/config.php';
}
function base(): string { return rtrim((string)($GLOBALS['mana_cms_base'] ?? ''), '/'); }
function esc(string $v): string { return htmlspecialchars($v, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); }
function fail(int $code, string $message): never { throw new CmsError($message, $code); }
class CmsError extends \RuntimeException {}
function inside(string $path, string $parent): bool {
    $path=strtolower(str_replace('\\','/',$path)); $parent=rtrim(strtolower(str_replace('\\','/',$parent)),'/');
    return $path===$parent || str_starts_with($path,$parent.'/');
}
function data_dir(): string {
    static $dir; if($dir) return $dir;
    $public=realpath($_SERVER['DOCUMENT_ROOT']??root())?:realpath(root());
    // cPanel document root is public_html; the checkout must never hold runtime data either.
    $candidate=getenv('MANA_CMS_DATA_DIR') ?: dirname($public).'/.mana-cms-'.config()['installation'];
    $ancestor=$candidate;
    while(!file_exists($ancestor) && dirname($ancestor)!==$ancestor) $ancestor=dirname($ancestor);
    $resolved=realpath($ancestor);
    $checkout=file_exists(dirname(root()).'/.git')?realpath(dirname(root())):false;
    foreach([$public,realpath(root()),$checkout] as $blocked)
        if($resolved && $blocked && inside($resolved,$blocked)) fail(503,'مسیر داده باید بیرون پوشهٔ عمومی و مخزن باشد.');
    if(!is_dir($candidate) && !mkdir($candidate,0700,true)) fail(503,'پوشهٔ خصوصی قابل نوشتن نیست.');
    $resolved=realpath($candidate);
    if(!$resolved) fail(503,'مسیر خصوصی معتبر نیست.');
    foreach([$public,realpath(root()),$checkout] as $blocked)
        if($blocked && inside($resolved,$blocked)) fail(503,'مسیر خصوصی مجاز نیست.');
    return $dir=$resolved;
}
function read(string $name, array $default=[]): array {
    $file=data_dir().'/'.$name.'.json';
    if(!is_file($file)) return $default;
    return json_decode(file_get_contents($file),true,128,JSON_THROW_ON_ERROR);
}
function write(string $name,array $data): void {
    $file=data_dir().'/'.$name.'.json'; $temp=$file.'.'.bin2hex(random_bytes(10)).'.tmp';
    $bytes=json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    try {
        if(file_put_contents($temp,$bytes)!==strlen($bytes)) throw new \RuntimeException('write');
        chmod($temp,0600);
        if(!rename($temp,$file)) throw new \RuntimeException('rename');
    } finally { if(is_file($temp)) unlink($temp); }
}
function locked(callable $fn): mixed {
    $fp=fopen(data_dir().'/write.lock','c');
    if(!$fp || !flock($fp,LOCK_EX)) fail(503,'قفل ذخیره در دسترس نیست.');
    try { return $fn(); } finally { flock($fp,LOCK_UN); fclose($fp); }
}
function empty_state(): array { return ['version'=>0,'edits'=>[],'menus'=>null,'images'=>[],'undo'=>null]; }
function state(): array { return read('content',empty_state()); }
function commit(array $next,array $before): void {
    // Content and its undo record form one atomic transaction, including media references.
    unset($before['undo']); $next['undo']=$before; $next['version']=$before['version']+1;
    write('content',$next);
}
function https(): bool { return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off') || (string)($_SERVER['SERVER_PORT']??'')==='443'; }
function local(): bool { return in_array($_SERVER['REMOTE_ADDR']??'', ['127.0.0.1','::1'],true) && in_array(explode(':',$_SERVER['HTTP_HOST']??'')[0],['localhost','127.0.0.1'],true); }
function boot(): void {
    if(!https() && !local()) fail(403,'پنل فقط با HTTPS قابل استفاده است.');
    if(session_status()===PHP_SESSION_ACTIVE) fail(500,'نشست پنل باید مستقل آغاز شود.');
    $sessions=data_dir().'/sessions'; if(!is_dir($sessions)) mkdir($sessions,0700);
    $name='MANA_CMS_'.config()['installation'];
    session_save_path($sessions); session_name($name);
    $cookie=$_COOKIE[$name]??'';
    session_id(is_string($cookie)&&preg_match('~^[A-Za-z0-9,-]{16,128}$~D',$cookie)?$cookie:'');
    ini_set('session.use_strict_mode','1'); ini_set('session.use_only_cookies','1'); ini_set('session.use_trans_sid','0');
    ini_set('session.gc_maxlifetime','28800');
    session_set_cookie_params(['lifetime'=>0,'path'=>base().'/', 'secure'=>https(),'httponly'=>true,'samesite'=>'Strict']);
    session_start();
    if(time()-($_SESSION['last']??time())>1800 || time()-($_SESSION['created']??time())>28800) { $_SESSION=[]; session_regenerate_id(true); }
    $_SESSION['last']=time(); $_SESSION['created']??=time(); $_SESSION['csrf']??=bin2hex(random_bytes(32));
}
function user(): ?array {
    $uid=$_SESSION['uid']??''; $u=read('users')[$uid]??null;
    return $u && $u['active'] && $u['version']===($_SESSION['uv']??0) ? $u+['id'=>$uid] : null;
}
function require_user(bool $admin=false): array {
    $u=user(); if(!$u) fail(401,'ابتدا وارد پنل شوید.');
    if($u['mustChange']) fail(403,'پیش از ادامه رمز موقت را تغییر دهید.');
    if($admin && $u['role']!=='admin') fail(403,'این کار فقط برای مدیر مجاز است.');
    return $u;
}
function empty_permissions(): array { return ['pages'=>[],'fields'=>[],'header'=>false,'footer'=>false,'menu'=>false,'images'=>[]]; }
function permissions(array $u): array { return array_replace(empty_permissions(),$u['permissions']??[]); }
function can_field(array $u,string $page,array $field): bool {
    if($u['role']==='admin') return true;
    $p=permissions($u);
    return in_array($field['id'],$p['fields'],true)
        || ($field['scope']==='page' && in_array($page,$p['pages'],true))
        || (in_array($field['scope'],['header','footer'],true) && $p[$field['scope']]);
}
function can_page(array $u,string $page,?array $catalog=null): bool {
    if($u['role']==='admin') return true;
    $p=permissions($u);
    if(in_array($page,$p['pages'],true)||$p['header']||$p['footer']) return true;
    foreach($p['fields'] as $id) if(str_starts_with($id,$page.':')||str_starts_with($id,'shared:')) return true;
    $catalog??=read('catalog');
    foreach($catalog[$page]['images']??[] as $image) if(in_array($image['id'],$p['images'],true)) return true;
    return false;
}
function require_page(string $page): array {
    $u=require_user(); if(!can_page($u,$page)) fail(403,'مدیر اجازهٔ ویرایش این صفحه را به شما نداده است.'); return $u;
}
function require_capability(string $capability,?string $image=null): array {
    $u=require_user(); if($u['role']==='admin') return $u;
    $p=permissions($u);
    if($capability==='menu' && $p['menu']) return $u;
    if($capability==='image' && $image!==null && in_array($image,$p['images'],true)) return $u;
    fail(403,'این بخش در محدودهٔ دسترسی تعیین‌شده توسط مدیر نیست.');
}
function public_user(?array $u): ?array {
    if(!$u) return null;
    return array_intersect_key($u,array_flip(['id','username','role','active','mustChange']))+['permissions'=>permissions($u)];
}
function validate_permissions(mixed $input): array {
    if(!is_array($input)||array_diff(array_keys($input),array_keys(empty_permissions()))) fail(400,'دسترسی‌ها معتبر نیستند.');
    $p=empty_permissions(); $catalog=read('catalog'); $knownPages=array_fill_keys(array_column(pages()['pages'],'id'),true); $knownFields=[]; $knownImages=[];
    foreach($catalog as $page=>$c) {
        if(!isset($knownPages[$page])||$c['stamp']!==template_stamp($page,'page')||$c['sharedStamp']!==template_stamp($page,'header')) continue;
        foreach($c['fields'] as $f) if(!isset($f['derivedFrom'])) $knownFields[$f['id']]=true;
        foreach($c['images'] as $i) $knownImages[$i['id']]=true;
    }
    foreach(['header','footer','menu'] as $key) { if(isset($input[$key])&&!is_bool($input[$key])) fail(400,'نوع دسترسی معتبر نیست.'); $p[$key]=$input[$key]??false; }
    foreach(['pages'=>$knownPages,'fields'=>$knownFields,'images'=>$knownImages] as $key=>$known) {
        $values=$input[$key]??[]; if(!is_array($values)||!array_is_list($values)||count($values)>5000) fail(400,'فهرست دسترسی معتبر نیست.');
        foreach($values as $value) if(!is_string($value)||!isset($known[$value])) fail(400,'صفحه یا بخش انتخاب‌شده معتبر نیست؛ پیش‌نمایش را تازه کنید.');
        $p[$key]=array_values(array_unique($values));
    }
    return $p;
}
function csrf(): void {
    if(!hash_equals($_SESSION['csrf'],(string)($_SERVER['HTTP_X_CMS_CSRF']??''))) fail(403,'درخواست معتبر نیست؛ پنل را تازه کنید.');
    if(($_SERVER['HTTP_SEC_FETCH_SITE']??'')==='cross-site') fail(403,'مبدأ درخواست معتبر نیست.');
    $origin=$_SERVER['HTTP_ORIGIN']??'';
    if($origin!=='' && $origin!==(https()?'https://':'http://').($_SERVER['HTTP_HOST']??'')) fail(403,'مبدأ درخواست معتبر نیست.');
}
function password_ok(mixed $p): bool { return is_string($p) && strlen($p)>=12 && strlen($p)<=72; }
function login(array $d): array {
    return locked(function() use($d) {
        $attempts=read('attempts'); $attempts=array_filter($attempts,fn($a)=>$a['since']>time()-900);
        $ip=hash('sha256',$_SERVER['REMOTE_ADDR']??'cli'); $name=strtolower((string)($d['username']??''));
        $keys=[$ip,hash('sha256','name:'.$name)];
        foreach($keys as $key) if(($attempts[$key]['count']??0)>=8) fail(429,'تلاش زیاد است؛ ۱۵ دقیقه بعد امتحان کنید.');
        $users=read('users'); $uid=null; $u=null;
        $pass=$d['password']??'';
        if(!is_string($pass)||strlen($pass)>72||strlen($name)>40) fail(400,'اطلاعات ورود معتبر نیست.');
        if(!$users) {
            if($name==='eshahabian' && hash_equals(config()['activationHash'],hash('sha256',(string)($d['activation']??''))) && password_verify($pass,config()['bootstrapHash'])) {
                $uid=bin2hex(random_bytes(12));
                $u=['username'=>'eshahabian','hash'=>config()['bootstrapHash'],'role'=>'admin','active'=>true,'mustChange'=>true,'version'=>1];
                $users[$uid]=$u; write('users',$users);
            }
        } else foreach($users as $id=>$item) if(strtolower($item['username'])===$name && $item['active'] && password_verify($pass,$item['hash'])) { $uid=$id; $u=$item; break; }
        if(!$u) {
            password_verify('timing-padding',config()['bootstrapHash']);
            foreach($keys as $key) { $a=$attempts[$key]??['count'=>0,'since'=>time()]; $a['count']++; $attempts[$key]=$a; }
            write('attempts',$attempts); fail(401,'نام کاربری، رمز یا کد فعال‌سازی درست نیست.');
        }
        foreach($keys as $key) unset($attempts[$key]); write('attempts',$attempts);
        session_regenerate_id(true); $_SESSION=['uid'=>$uid,'uv'=>$u['version'],'csrf'=>bin2hex(random_bytes(32)),'last'=>time(),'created'=>time()];
        return ['user'=>public_user($u+['id'=>$uid])];
    });
}
function change_password(array $d): void {
    locked(function() use($d) {
        $u=user(); if(!$u) fail(401,'ابتدا وارد شوید.'); $p=$d['password']??'';
        if(!password_ok($p)||$p!==($d['confirm']??null)||!password_verify((string)($d['current']??''),$u['hash'])||password_verify($p,$u['hash'])) fail(400,'رمز فعلی درست و رمز جدید متفاوت و بین ۱۲ تا ۷۲ بایت باشد.');
        $users=read('users'); unset($u['id']); $u['hash']=password_hash($p,PASSWORD_DEFAULT); $u['version']++; $u['mustChange']=false;
        $users[$_SESSION['uid']]=$u; write('users',$users); session_regenerate_id(true); $_SESSION['uv']=$u['version'];
    });
}
function manage_user(array $d): void {
    locked(function() use($d) {
        $current=require_user(true); $users=read('users'); $id=$d['id']??''; $action=$d['action']??'';
        if(!password_verify((string)($d['adminPassword']??''),$current['hash'])) fail(403,'رمز فعلی مدیر درست نیست.');
        if($action==='add') {
            $name=$d['username']??'';
            if(!is_string($name)||!preg_match('~^[A-Za-z0-9_.-]{3,40}$~D',$name)||!password_ok($d['password']??null)||count($users)>=50) fail(400,'نام یکتا و رمز موقت ۱۲ تا ۷۲ بایتی لازم است.');
            foreach($users as $u) if(strcasecmp($u['username'],$name)===0) fail(409,'نام کاربری تکراری است.');
            $id=bin2hex(random_bytes(12)); $users[$id]=['username'=>$name,'hash'=>password_hash($d['password'],PASSWORD_DEFAULT),'role'=>$d['role']??'editor','active'=>true,'mustChange'=>true,'version'=>1,'permissions'=>empty_permissions()];
        } else {
            if(!isset($users[$id])) fail(404,'کاربر پیدا نشد.');
            if($id===$current['id']) fail(400,'حساب مدیر جاری را از این بخش نمی‌توان تغییر داد.');
            if($action==='reset') {
                if(!password_ok($d['password']??null)) fail(400,'رمز موقت ۱۲ تا ۷۲ بایتی لازم است.');
                $users[$id]['hash']=password_hash($d['password'],PASSWORD_DEFAULT); $users[$id]['mustChange']=true;
            } elseif($action==='update') {
                if(!is_bool($d['active']??null)) fail(400,'وضعیت معتبر نیست.');
                if($users[$id]['role']!==($d['role']??'')) $users[$id]['permissions']=empty_permissions();
                $users[$id]['active']=$d['active']; $users[$id]['role']=$d['role']??'';
            } elseif($action==='permissions') {
                if($users[$id]['role']!=='editor') fail(400,'محدودهٔ ویرایش برای حساب ویرایشگر تعیین می‌شود.');
                $users[$id]['permissions']=validate_permissions($d['permissions']??null);
            } else fail(400,'عملیات معتبر نیست.');
            $users[$id]['version']++;
        }
        if(!in_array($users[$id]['role'],['admin','editor'],true)) fail(400,'نقش معتبر نیست.');
        if(!array_filter($users,fn($u)=>$u['role']==='admin'&&$u['active']&&!$u['mustChange'])) fail(400,'آخرین مدیر فعال باید حفظ شود.');
        write('users',$users);
    });
}
