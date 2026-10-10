<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/accountant.php';
require_once __DIR__ . '/../../includes/therapist_payouts.php';

require_login(['ACCOUNTANT']);
$html = therapist_payouts_screen($pdo, '/accountant/payouts', true, null);
$GLOBALS['pageHead'] = '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@majidh1/jalalidatepicker/dist/jalalidatepicker.min.css">';
$GLOBALS['pageScripts'] = '<script src="https://cdn.jsdelivr.net/npm/@majidh1/jalalidatepicker/dist/jalalidatepicker.min.js"></script><script>
if (window.jalaliDatepicker) {
  jalaliDatepicker.startWatch({ selector: "[data-jdp]", time: false, hideAfterChange: true, showTodayBtn: true, autoReadOnlyInput: true, persianDigits: true, zIndex: 100000, container: "body" });
}
</script>';
render_accountant_page('پرداخت درمانگرها', $html);
