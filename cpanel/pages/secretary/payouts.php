<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/secretary_panel.php';
require_once __DIR__ . '/../../includes/therapist_payouts.php';

$user = require_login(['SECRETARY', 'ADMIN']);
if ((string) ($user['role'] ?? '') === 'SECRETARY') {
    flash_set('error', 'پرداخت درمانگرها برای منشی در دسترس نیست.');
    redirect('/secretary/messages');
}
$html = therapist_payouts_screen($pdo, '/secretary/payouts', true, null);
$GLOBALS['pageHead'] = '<link rel="stylesheet" href="/assets/vendor/jalali/jalalidatepicker.min.css">';
$GLOBALS['pageScripts'] = '<script src="/assets/vendor/jalali/jalalidatepicker.min.js"></script><script>
if (window.jalaliDatepicker) {
  jalaliDatepicker.startWatch({ selector: "[data-jdp]", time: false, hideAfterChange: true, showTodayBtn: true, autoReadOnlyInput: true, persianDigits: true, zIndex: 100000, container: "body" });
}
</script>';
render_secretary_page('پرداخت درمانگرها', $html);
