
if (
    (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST')
    && hash_equals('__TOKEN__', (string) ($_SERVER['HTTP_X_SETUP_TOKEN'] ?? ''))
) {
    $uri = (string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
    if ($uri === '/internal-sms-fix' || str_ends_with($uri, '/internal-sms-fix')) {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        $config = require __DIR__ . '/config.php';
        date_default_timezone_set($config['timezone'] ?? 'Asia/Tehran');
        foreach (['db', 'helpers', 'mail', 'outreach'] as $f) {
            require_once __DIR__ . '/includes/' . $f . '.php';
        }
        $pdo = db_connect($config);
        ensure_mail_schema($pdo);
        ensure_outreach_schema($pdo);

        $before = [
            'appointment_booked' => sms_kind_enabled($pdo, 'appointment_booked'),
            'doctor_appointment_booked' => sms_kind_enabled($pdo, 'doctor_appointment_booked'),
        ];

        mail_setting_set($pdo, 'sms_kind_enabled_appointment_booked', '1');
        mail_setting_set($pdo, 'sms_kind_enabled_doctor_appointment_booked', '1');

        $after = [
            'appointment_booked' => sms_kind_enabled($pdo, 'appointment_booked'),
            'doctor_appointment_booked' => sms_kind_enabled($pdo, 'doctor_appointment_booked'),
        ];

        $appointmentId = 'c21f4d8be30f258ef944463d';
        $send = sms_notify_appointment_now($pdo, $appointmentId, 'booked');

        echo json_encode([
            'before' => $before,
            'after' => $after,
            'send' => $send,
            'ready' => sms_connection_ready($pdo),
        ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }
}
