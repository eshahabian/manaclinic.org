<?php
declare(strict_types=1);
if (current_user()) {
    $href = panel_href_for(current_user()) ?: '/';
    redirect($href);
}
$authMode = 'login';
$pageTitle = 'ورود به مانا کلینیک';
$pageDescription = 'ورود به حساب مانا کلینیک برای رزرو نوبت، کارگاه و ارتباط با درمانگر در سعادت‌آباد تهران.';
$pageCanonical = url('/login');
$pageKeywords = 'ورود مانا کلینیک, ورود روانشناس, ورود مراجعه‌کننده';
$GLOBALS['pageTitle'] = $pageTitle;
$GLOBALS['pageDescription'] = $pageDescription;
$GLOBALS['pageCanonical'] = $pageCanonical;
$GLOBALS['pageKeywords'] = $pageKeywords;
ob_start();
require __DIR__ . '/../includes/auth_gate.php';
$content = ob_get_clean();
$pageScripts = $GLOBALS['pageScripts'] ?? '';
$pageHead = $GLOBALS['pageHead'] ?? '';
require __DIR__ . '/../includes/layout.php';
