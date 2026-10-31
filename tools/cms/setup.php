<?php
declare(strict_types=1);
// CLI only. No permanent owner password is ever generated here.
if(PHP_SAPI!=='cli') exit(1);
$owner=$argv[1]??'';
$site=realpath(__DIR__.'/../../cpanel');
$repo=realpath(__DIR__.'/../..');
$installationFile=$site.'/psy-admin/installation.php';
if($owner===''||is_file($installationFile)) { fwrite(STDERR,"Give a private directory outside the checkout. Existing installation settings are never replaced.\n"); exit(1); }
$ancestor=$owner; while(!file_exists($ancestor)&&dirname($ancestor)!==$ancestor) $ancestor=dirname($ancestor);
$a=strtolower(str_replace('\\','/',realpath($ancestor)?:$ancestor));
$r=strtolower(str_replace('\\','/',$repo));
if($a===$r||str_starts_with($a,$r.'/')) { fwrite(STDERR,"Owner files must be outside the checkout.\n"); exit(1); }
if(!is_dir($owner)&&!mkdir($owner,0700,true)) exit(1);
$owner=realpath($owner); if(!$owner) exit(1);
$activation=bin2hex(random_bytes(32)); $temporary=bin2hex(random_bytes(12)); $installation=bin2hex(random_bytes(12));
$config=['installation'=>$installation,'activationHash'=>hash('sha256',$activation),'bootstrapHash'=>password_hash($temporary,PASSWORD_DEFAULT)];
$guide="مانا کلینیک — راهنمای خصوصی مالک\r\nاین فایل محرمانه است؛ وارد گیت، هاست یا عکس صفحه نشود.\r\n\r\nنشانی پنل: https://manaclinic.org/psy-admin/\r\nنام کاربری: eshahabian\r\nرمز موقت: $temporary\r\nکد اختصاصی فعال‌سازی: $activation\r\nشناسه نصب: $installation\r\n\r\nدر اولین ورود رمز دائمی خود را تعیین کنید. فعال‌سازی یک‌بار برای هر محل مستقل داده است. دادهٔ محلی و آنلاین مستقل‌اند؛ این فایل شامل رمز دائمی شما نیست.\r\n\r\nداده هاست: /home3/slhgjbht/.mana-cms-$installation\r\nداده محلی: $owner/local-data\r\nراهنمای انتشار در مخزن: docs/ONLINE-ADMIN.md\r\nبرای بکاپ، کل پوشهٔ خصوصی هاست را از File Manager با نمایش فایل‌های مخفی دانلود کنید و نسخهٔ کد متناظر را نگه دارید.\r\n";
$guideFile=$owner.'/Owner-Activation.txt';
if(is_file($guideFile)) { fwrite(STDERR,"Owner guide already exists.\n"); exit(1); }
if(file_put_contents($guideFile,$guide)!==strlen($guide)) exit(1); chmod($guideFile,0600);
$code="<?php\ndeclare(strict_types=1);\n// Installation identity is stable across releases. Only bootstrap hashes belong in Git.\nreturn ".var_export($config,true).";\n";
if(file_put_contents($installationFile,$code)!==strlen($code)) exit(1);
$local=require $site.'/config.sample.php';
$local['db_host']='127.0.0.1'; $local['db_name']='mana_clinic_local'; $local['db_user']='LOCAL_DB_USER'; $local['db_pass']='';
$local['app_url']='http://127.0.0.1:8791'; $local['session_name']='mana_local_clinic';
file_put_contents($owner.'/clinic-local.php',"<?php\nreturn ".var_export($local,true).";\n");
$cmd="@echo off\r\nsetlocal\r\nset \"MANA_CMS_DATA_DIR=$owner\\local-data\"\r\nset \"MANA_CLINIC_LOCAL_CONFIG=$owner\\clinic-local.php\"\r\ncd /d \"$repo\"\r\n\"".PHP_BINARY."\" -d extension_dir=\"".dirname(PHP_BINARY)."\\ext\" -d extension=gd -d extension=pdo_mysql -S 127.0.0.1:8791 -t \"$site\" \"$repo\\tools\\cms\\router.php\"\r\npause\r\n";
file_put_contents($owner.'/Start-Mana-CMS.cmd',$cmd);
echo "Installation settings and private owner guide created. No credentials printed.\n";
