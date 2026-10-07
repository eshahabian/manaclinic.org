<?php
declare(strict_types=1);
if (current_user()) {
    $next = safe_next_path((string) ($_GET['next'] ?? ''));
    $role = (string) (current_user()['role'] ?? '');
    if ($next && str_starts_with($next, '/app') && ($role === 'DOCTOR' || $role === 'SECRETARY')) {
        redirect($next);
    }
    $href = panel_href_for(current_user()) ?: '/';
    redirect($href);
}
$authMode = 'login';
$pageTitle = 'ورود به مانا کلینیک';
$pageDescription = 'ورود به حساب مانا کلینیک برای رزرو نوبت، کارگاه و ارتباط با درمانگر در سعادت‌آباد تهران.';
$pageCanonical = url('/login');
$pageKeywords = 'ورود مانا کلینیک, ورود روانشناس, ورود مراجعه‌کننده';
$pageRobots = 'noindex,nofollow';
$loginNext = safe_next_path((string) ($_GET['next'] ?? ''));
if ($loginNext && str_starts_with($loginNext, '/app')) {
    $GLOBALS['pageBodyClass'] = trim((string) ($GLOBALS['pageBodyClass'] ?? '') . ' staff-app-gate');
    $GLOBALS['pageHead'] = '<style>body.staff-app-gate .nav-links,body.staff-app-gate .nav-toggle,body.staff-app-gate .site-footer,body.staff-app-gate .mobile-nav,body.staff-app-gate a[href*="/register"]{display:none!important}body.staff-app-gate .brand{pointer-events:none}</style>';
}
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
