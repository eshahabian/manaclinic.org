<?php
declare(strict_types=1);
namespace ManaCms;
require_once __DIR__.'/content.php';

function json_response(array $value): never {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($value,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE); exit;
}
function api(string $action): never {
    boot(); $method=$_SERVER['REQUEST_METHOD']??'GET'; $d=[];
    if($method==='POST') {
        csrf(); if(!str_starts_with($_SERVER['CONTENT_TYPE']??'','application/json')) fail(415,'قالب درخواست معتبر نیست.');
        $raw=file_get_contents('php://input',false,null,0,12*1024*1024+1);
        if(strlen($raw)>12*1024*1024) fail(413,'حجم درخواست زیاد است.');
        $d=json_decode($raw,true,32,JSON_THROW_ON_ERROR); if(!is_array($d)) fail(400,'درخواست معتبر نیست.');
    } elseif($method!=='GET') fail(405,'روش درخواست معتبر نیست.');
    if($method==='GET' && $action==='session') json_response(['user'=>public_user(user()),'csrf'=>$_SESSION['csrf'],'needsActivation'=>!is_file(data_dir().'/users.json')]);
    if($method==='POST' && $action==='login') json_response(login($d));
    if(!user()) fail(401,'ابتدا وارد پنل شوید.');
    if($method==='POST' && $action==='logout') { $_SESSION=[]; session_regenerate_id(true); json_response(['ok'=>true]); }
    if($method==='POST' && $action==='password') { change_password($d); json_response(['ok'=>true]); }
    $viewer=require_user();
    if($method==='GET') {
        if($action==='users') { require_user(true); $users=read('users'); $result=[]; foreach($users as $id=>$u) $result[]=public_user($u+['id'=>$id]); json_response(['users'=>$result]); }
        if($action==='permission-catalog') {
            require_user(true); $catalog=[];
            foreach(read('catalog') as $path=>$c) if($c['stamp']===template_stamp($path,'page') && $c['sharedStamp']===template_stamp($path,'header'))
                $catalog[$path]=['fields'=>public_fields($c['fields'],state()),'images'=>$c['images']];
            json_response(pages()+['catalog'=>$catalog]);
        }
        $s=state();
        if($action==='pages') {
            $result=pages(); $catalog=read('catalog');
            $result['pages']=array_values(array_filter($result['pages'],fn($p)=>can_page($viewer,$p['id'],$catalog)));
            json_response($result+['version'=>$s['version'],'canUndo'=>$viewer['role']==='admin'&&$s['undo']!==null]);
        }
        if($action==='page') {
            $id=$_GET['id']??''; if(!is_string($id)||!public_path($id)) fail(400,'صفحه معتبر نیست.');
            require_page($id);
            $c=read('catalog')[$id]??null;
            if(!$c) fail(409,'ابتدا پیش‌نمایش صفحه را بارگذاری کنید.');
            json_response(['fields'=>public_fields($c['fields'],$s,$viewer,$id),'version'=>$s['version'],'title'=>$c['title'],'anchors'=>$c['anchors']]);
        }
        if($action==='menu') { require_capability('menu'); json_response(['items'=>$s['menus']??default_menu(),'version'=>$s['version']]); }
        if($action==='images') {
            $found=[]; foreach(read('catalog') as $page=>$c) foreach($c['images'] as $i) {
                if($viewer['role']!=='admin'&&!in_array($i['id'],permissions($viewer)['images'],true)) continue;
                $id=$i['id']; $found[$id]??=$i+['pages'=>[]]; $found[$id]['pages'][]=$page;
                if(isset($s['images'][$id])) { $found[$id]['width']=$s['images'][$id]['width']; $found[$id]['height']=$s['images'][$id]['height']; }
                $found[$id]['url']=base().$id.'?cmsv='.$s['version'];
            }
            json_response(['images'=>array_values($found),'version'=>$s['version']]);
        }
        fail(404,'مسیر پیدا نشد.');
    }
    if($action==='users') manage_user($d); else save_content($action,$d);
    json_response(['ok'=>true,'version'=>state()['version']]);
}
function dispatch(): void {
    $script=str_replace('\\','/',$_SERVER['SCRIPT_NAME']??'/index.php');
    $dir=rtrim(str_replace('\\','/',dirname($script)),'/');
    if(str_ends_with($dir,'/psy-admin')) $dir=substr($dir,0,-strlen('/psy-admin'));
    $GLOBALS['mana_cms_base']=$dir==='.'?'':$dir;
    $request=parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH)?:'/';
    $path=base()!=='' && str_starts_with($request,base().'/')?substr($request,strlen(base())):$request;
    $path='/'.trim($path,'/');
    $admin=$path==='/psy-admin'||$path==='/psy-admin/index.php';
    $preview=isset($_GET['mana_preview']);
    $image=preg_match('~^/(?:assets/|uploads/(?:doctors|workshops)/).+\.(?:png|jpe?g|webp)$~iD',$path)===1;
    if(!$admin && !$preview && !$image) return;
    @ini_set('display_errors','0'); header_remove('X-Powered-By');
    try {
        if($image) { media($path); }
        header('Cache-Control: private, no-store'); header('X-Content-Type-Options: nosniff'); header('X-Robots-Tag: noindex, nofollow');
        if($admin) {
            if(str_ends_with($request,'/psy-admin')) { header('Location: '.$request.'/'); http_response_code(302); exit; }
            header('X-Frame-Options: DENY'); header("Content-Security-Policy: default-src 'self'; img-src 'self' blob: data:; style-src 'self'; script-src 'self'; frame-src 'self'; object-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'");
            if(isset($_GET['api'])) api((string)$_GET['api']);
            readfile(__DIR__.'/app.html'); exit;
        }
        if(!public_path($path)||($_SERVER['REQUEST_METHOD']??'GET')!=='GET') fail(403,'پیش‌نمایش فقط برای صفحات عمومی مجاز است.');
        // Authenticate before application config / database boot. Then close our independent session.
        $oldPath=session_save_path(); $oldName=session_name(); $oldCookie=session_get_cookie_params();
        boot(); require_page($path);
        if(!hash_equals($_SESSION['csrf'],(string)$_GET['mana_preview'])) fail(403,'پیش‌نمایش معتبر نیست.');
        $GLOBALS['mana_cms_preview']=true;
        header("Content-Security-Policy: connect-src 'none'; form-action 'none'; frame-ancestors 'self'");
        session_write_close(); $_SESSION=[]; session_id(''); session_name($oldName); session_save_path($oldPath); session_set_cookie_params($oldCookie);
        // Preview must always render as an anonymous clinic visitor, even if the browser has a clinic login.
        $clinicConfig=is_file(clinic_config_file())?require clinic_config_file():[];
        unset($_COOKIE[$clinicConfig['session_name']??'mana_clinic_sess']);
        header('Referrer-Policy: no-referrer');
    } catch(\Throwable $e) {
        $code=$e instanceof CmsError?$e->getCode():500;
        http_response_code($code>=400&&$code<=599?$code:500);
        $message=$e instanceof CmsError?$e->getMessage():'خواندن یا ذخیره انجام نشد؛ تنظیمات و دسترسی پوشهٔ خصوصی را بررسی کنید.';
        if($admin && isset($_GET['api'])) json_response(['error'=>$message]);
        header('Content-Type: text/plain; charset=utf-8'); echo $message; exit;
    }
}

// Called only while the clinic layout output buffer is closing.
function layout_output(string $html): string {
    $path=(string)($GLOBALS['path']??'/');
    $preview=!empty($GLOBALS['mana_cms_preview']);
    try {
        if($preview) {
            // Restore only our session for authorization of catalog writes, then close it again.
            if(session_status()===PHP_SESSION_ACTIVE) session_write_close();
            boot(); require_page($path);
        }
        return render($html,$path,$preview);
    } catch(\Throwable $e) {
        if($preview) { http_response_code(503); return '<p>پیش‌نمایش در دسترس نیست؛ دوباره وارد پنل شوید و تنظیمات فضای خصوصی را بررسی کنید.</p>'; }
        // If the store exists but cannot be read, fail closed rather than publish stale source content.
        http_response_code(503); return '<p>صفحه موقتاً در دسترس نیست.</p>';
    } finally { if($preview && session_status()===PHP_SESSION_ACTIVE) session_write_close(); }
}
