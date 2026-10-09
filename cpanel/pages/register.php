<?php
declare(strict_types=1);
if (current_user()) {
    redirect('/dashboard');
}
$authMode = 'register';
$pageTitle = 'ثبت‌نام در مانا کلینیک';
$pageDescription = 'ثبت‌نام مراجعه‌کننده در مانا کلینیک؛ نوبت آنلاین، کارگاه و ارتباط امن با متخصصان روانشناسی در سعادت‌آباد.';
$pageCanonical = url('/register');
$pageKeywords = 'ثبت‌نام مانا کلینیک, ثبت‌نام مراجعه‌کننده, نوبت روانشناسی';
$pageRobots = 'noindex,nofollow';
$GLOBALS['pageTitle'] = $pageTitle;
$GLOBALS['pageDescription'] = $pageDescription;
$GLOBALS['pageCanonical'] = $pageCanonical;
$GLOBALS['pageKeywords'] = $pageKeywords;
$GLOBALS['pageRobots'] = $pageRobots;
ob_start();
require __DIR__ . '/../includes/auth_gate.php';
$content = ob_get_clean();
$pageScripts = $GLOBALS['pageScripts'] ?? '';
$pageHead = $GLOBALS['pageHead'] ?? '';
require __DIR__ . '/../includes/layout.php';
