<?php
declare(strict_types=1);
// Local development only; never publish tools/ as the document root.
$root=realpath(__DIR__.'/../../cpanel');
$path=parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH)?:'/';
$_SERVER['SCRIPT_NAME']='/index.php';
if(preg_match('~^/psy-admin/(?:admin\.css|app\.js|permissions\.js|preview\.js)$~D',$path)) return false;
if(preg_match('~^/(?:includes|actions|pages|storage|tools|uploads/(?:receipts|journal|staff-app))(?:/|$)|\.(?:php|json|log|sql|bak|env)$~i',$path) && !in_array($path,['/index.php','/psy-admin/index.php'],true)) { http_response_code(403); return true; }
if(preg_match('~^/(?:assets/|uploads/(?:doctors|workshops)/).+\.(?:png|jpe?g|webp)$~iD',$path)) { require $root.'/psy-admin/entry.php'; \ManaCms\dispatch(); return true; }
if(str_starts_with($path,'/psy-admin')) { require $root.'/psy-admin/index.php'; return true; }
$file=realpath($root.$path);
if($file && str_starts_with($file,$root.DIRECTORY_SEPARATOR) && is_file($file) && !str_ends_with($file,'.php')) return false;
require $root.'/index.php'; return true;
