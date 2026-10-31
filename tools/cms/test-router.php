<?php
declare(strict_types=1);
// Used only in temporary test copies; never included in cPanel deployment.
$root=$_SERVER['DOCUMENT_ROOT'];
header('X-Cms-Fixture: '.(getenv('CMS_TEST_RUN')?:'browser'));
$prefix=getenv('CMS_TEST_PREFIX')?:'';
$uri=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH)?:'/';
$path=$prefix!==''?substr($uri,strlen($prefix)):$uri;
$_SERVER['SCRIPT_NAME']=$prefix.'/index.php';
if(($_SERVER['HTTP_X_FIXTURE_HTTPS']??'')==='1') $_SERVER['HTTPS']='on';
require_once $root.'/psy-admin/entry.php';
\ManaCms\dispatch();
if(preg_match('~^/psy-admin/(?:app\.js|permissions\.js|admin\.css|preview\.js)$~D',$path)||str_starts_with($path,'/assets/')) {
    $file=realpath($root.$path);
    if($file && \ManaCms\inside($file,$root) && is_file($file)) {
        $mime=['js'=>'text/javascript','css'=>'text/css','woff2'=>'font/woff2','ttf'=>'font/ttf'][pathinfo($file,PATHINFO_EXTENSION)]??'application/octet-stream';
        header('Content-Type: '.$mime); readfile($file); return true;
    }
    http_response_code(404); return true;
}
if(str_starts_with($path,'/psy-admin/') || str_contains($path,'..') || str_ends_with($path,'.json') || str_ends_with($path,'.php')) {http_response_code(403);return true;}
if(!in_array($path,['/about','/faq','/','/services/individual'],true)) {http_response_code(404);return true;}
// Real clinic templates/layout; all clinic account, database and action code is disconnected.
$base=$prefix; $config=['app_url'=>'http://127.0.0.1:8792'.$prefix,'app_name'=>'مانا کلینیک'];
session_name('mana_fixture'); session_start();
$GLOBALS['path']=$path;
require_once $root.'/includes/helpers.php';
require_once $root.'/includes/auth.php';
require_once $root.'/includes/seo.php';
if($path==='/about'||$path==='/faq') require $root.'/pages/'.substr($path,1).'.php';
else {
    // Additional fixture to exercise empty text, nested markup, TOC, CSS/srcset and page scope.
    $content='<section><h1>صفحهٔ آزمایشی</h1><h2 id="section-one">عنوان آزمایشی</h2><nav><a href="#section-one">عنوان آزمایشی</a></nav><p>متن <strong>درون تگ</strong> پایانی</p><p>۱۰۰٪ آرامش</p><img src="'.url('/assets/img/logo.png').'" alt="نشان"><picture><source srcset="'.url('/assets/img/logo.png').' 1x"><img src="'.url('/assets/img/hero.webp').'"></picture><div style="background-image:url('.url('/assets/img/logo.png').')"></div></section>';
    require $root.'/includes/layout.php';
}
return true;
