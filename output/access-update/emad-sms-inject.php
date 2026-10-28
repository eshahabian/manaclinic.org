
if (
    (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST')
    && hash_equals('__TOKEN__', (string) ($_SERVER['HTTP_X_SETUP_TOKEN'] ?? ''))
) {
    $uri = (string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
    if ($uri === '/internal-sms-diag' || str_ends_with($uri, '/internal-sms-diag')) {
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
        $users = $pdo->query("SELECT id,username,name,phone,role FROM users WHERE (name LIKE '%عماد%' AND name LIKE '%شهابیان%') OR LOWER(username)='eshahabian'")->fetchAll();
        $userIds = array_values(array_filter(array_map(static fn ($u) => (string) ($u['id'] ?? ''), $users)));
        $appts = [];
        if ($userIds) {
            $in = implode(',', array_fill(0, count($userIds), '?'));
            $st = $pdo->prepare("SELECT a.id,a.status,a.starts_at,a.created_at,a.updated_at,a.created_by_user_id,
                pu.id patient_id,pu.name patient,pu.phone patient_phone,pu.role patient_role,
                dp.id doctor_profile_id,du.id doctor_user_id,du.name doctor,du.phone doctor_phone
                FROM appointments a
                JOIN users pu ON pu.id=a.patient_id
                JOIN doctor_profiles dp ON dp.id=a.doctor_id
                JOIN users du ON du.id=dp.user_id
                WHERE (a.patient_id IN ($in) OR du.id IN ($in)) AND DATE(a.starts_at)=CURDATE()
                ORDER BY a.starts_at ASC");
            $st->execute(array_merge($userIds, $userIds));
            $appts = $st->fetchAll();
        }
        $phones = [];
        foreach ($users as $u) {
            $p = sms_iran_mobile((string) ($u['phone'] ?? ''));
            if ($p) {
                $phones[$p] = true;
            }
        }
        foreach ($appts as $a) {
            foreach (['patient_phone', 'doctor_phone'] as $k) {
                $p = sms_iran_mobile((string) ($a[$k] ?? ''));
                if ($p) {
                    $phones[$p] = true;
                }
            }
        }
        $outbox = [];
        $apptIds = array_map(static fn ($a) => (string) $a['id'], $appts);
        if ($phones || $apptIds) {
            $sql = "SELECT id,phone,kind,status,due_at,sent_at,last_error,LEFT(body,160) body,created_at,context_json FROM sms_outbox WHERE created_at>=DATE_SUB(NOW(), INTERVAL 2 DAY) AND (";
            $parts = [];
            $args = [];
            if ($phones) {
                $parts[] = 'phone IN (' . implode(',', array_fill(0, count($phones), '?')) . ')';
                $args = array_merge($args, array_keys($phones));
            }
            foreach ($apptIds as $aid) {
                $parts[] = 'context_json LIKE ?';
                $args[] = '%' . $aid . '%';
            }
            $parts[] = "body LIKE '%شهابیان%'";
            $sql .= implode(' OR ', $parts) . ') ORDER BY created_at DESC LIMIT 40';
            $st = $pdo->prepare($sql);
            $st->execute($args);
            $outbox = $st->fetchAll();
        }
        $kinds = ['appointment_booked','appointment_cancelled','appointment_reminder','doctor_appointment_booked','doctor_appointment_cancelled','therapist_copy'];
        $enabled = [];
        foreach ($kinds as $k) {
            $enabled[$k] = sms_kind_enabled($pdo, $k);
        }
        $why = [];
        foreach ($appts as $a) {
            $row = ['id' => $a['id'], 'starts_at' => $a['starts_at'], 'status' => $a['status'], 'as' => '?'];
            foreach ($userIds as $uid) {
                if ((string) $a['patient_id'] === $uid) {
                    $row['as'] = 'patient';
                }
                if ((string) $a['doctor_user_id'] === $uid) {
                    $row['as'] = ($row['as'] === 'patient') ? 'both' : 'doctor';
                }
            }
            $pp = sms_iran_mobile((string) $a['patient_phone']);
            $dp = sms_iran_mobile((string) $a['doctor_phone']);
            $row['patient_phone_ok'] = $pp !== null;
            $row['doctor_phone_ok'] = $dp !== null;
            $row['same_phone'] = (bool) ($pp && $dp && $pp === $dp);
            $related = array_values(array_filter($outbox, static fn ($o) => str_contains((string) ($o['context_json'] ?? ''), (string) $a['id'])));
            $row['outbox_count'] = count($related);
            $row['outbox'] = $related;
            if (!in_array((string) $a['status'], ['CONFIRMED', 'PENDING_APPROVAL'], true)) {
                $row['likely'] = 'status_not_sms_ready:' . $a['status'];
            } elseif (!$enabled['appointment_booked'] && $row['as'] !== 'doctor') {
                $row['likely'] = 'appointment_booked_disabled';
            } elseif ($pp === null && in_array($row['as'], ['patient', 'both'], true)) {
                $row['likely'] = 'invalid_patient_phone';
            } elseif (!$related) {
                $row['likely'] = 'no_outbox_row_sms_never_queued';
            } else {
                $row['likely'] = 'outbox:' . implode(',', array_unique(array_map(static fn ($o) => (string) $o['status'], $related)));
            }
            $why[] = $row;
        }
        $mask = static function (array $rows, array $phoneKeys) {
            foreach ($rows as &$r) {
                foreach ($phoneKeys as $k) {
                    if (isset($r[$k])) {
                        $r[$k . '_tail'] = substr((string) $r[$k], -4);
                        unset($r[$k]);
                    }
                }
            }
            unset($r);
            return $rows;
        };
        echo json_encode([
            'now' => date('Y-m-d H:i:s'),
            'ready' => sms_connection_ready($pdo),
            'worker_last' => mail_setting_get($pdo, 'sms_worker_last_run', ''),
            'events_started' => mail_setting_get($pdo, 'sms_events_started_at', ''),
            'enabled' => $enabled,
            'users' => $mask($users, ['phone']),
            'appts' => $mask($appts, ['patient_phone', 'doctor_phone']),
            'why' => $why,
            'outbox' => $mask($outbox, ['phone']),
            'pending_total' => (int) $pdo->query("SELECT COUNT(*) FROM sms_outbox WHERE status='PENDING'")->fetchColumn(),
        ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }
}
