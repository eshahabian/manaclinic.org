<?php
declare(strict_types=1);

/**
 * کرون HTTP برای صف پیامک / خلاصه صبحگاهی درمانگرها.
 * مثال: هر دقیقه GET https://manaclinic.org/cron/sms?token=…
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$token = trim((string) ($_GET['token'] ?? $_SERVER['HTTP_X_CRON_TOKEN'] ?? ''));
$expected = sms_cron_ensure_token($pdo);
if ($token === '' || !hash_equals($expected, $token)) {
    http_response_code(404);
    echo json_encode(['ok' => false], JSON_UNESCAPED_UNICODE);
    exit;
}

$before = mail_setting_get($pdo, 'sms_worker_last_run', '');
sms_run_worker($pdo);
mail_setting_set($pdo, 'sms_worker_last_run', date('Y-m-d H:i:s'));
mail_setting_set($pdo, 'sms_cron_installed', '1');

echo json_encode([
    'ok' => true,
    'ran_at' => date('Y-m-d H:i:s'),
    'previous_run' => $before,
    'hour' => (int) date('G'),
], JSON_UNESCAPED_UNICODE);
