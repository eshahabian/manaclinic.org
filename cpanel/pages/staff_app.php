<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/staff_app.php';

/** @var PDO $pdo */
$user = staff_app_user();
$path = (string) ($GLOBALS['path'] ?? '/app');
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$userId = (string) ($user['id'] ?? '');
$role = (string) ($user['role'] ?? '');
$name = trim((string) ($user['name'] ?? ''));

if ($path === '/app/push/subscribe' && $method === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    csrf_verify();
    try {
        staff_push_save(
            $pdo,
            $userId,
            trim((string) ($_POST['endpoint'] ?? '')),
            trim((string) ($_POST['p256dh'] ?? '')),
            trim((string) ($_POST['auth'] ?? ''))
        );
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    } catch (RuntimeException $e) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        error_log('staff push subscribe: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'ثبت اعلان انجام نشد.'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if ($path === '/app/presence') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    try {
        staff_app_touch_presence($pdo, $userId);
        $online = array_values(array_filter(
            staff_app_online_ids($pdo),
            static fn(string $id): bool => $id !== $userId
        ));
        $unreadRooms = staff_app_unread_by_room($pdo, $userId);
        echo json_encode([
            'ok' => true,
            'online' => $online,
            'unread' => array_sum($unreadRooms),
            'unreadRooms' => $unreadRooms === [] ? new stdClass() : $unreadRooms,
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'online' => [], 'unread' => 0], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if (preg_match('#^/app/chat/([a-f0-9]{24})/receipts$#', $path, $m)) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    if ($method !== 'GET') {
        http_response_code(405);
        echo json_encode(['ok' => false], JSON_UNESCAPED_UNICODE);
        exit;
    }
    try {
        if (!staff_app_ready($pdo) || !staff_app_room_for_member($pdo, $m[1], $userId)) {
            http_response_code(404);
            echo json_encode(['ok' => false], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $after = trim((string) ($_GET['after'] ?? ''));
        $live = staff_app_live_messages($pdo, $m[1], $userId, $after);
        staff_app_mark_read($pdo, $userId, $m[1]);
        staff_app_touch_presence($pdo, $userId);
        $reactions = staff_app_reaction_overview($pdo, $m[1], $userId);
        echo json_encode([
            'ok' => true,
            'states' => staff_app_own_receipts($pdo, $m[1], $userId),
            'seen' => staff_app_seen_map($pdo, $m[1]),
            'messages' => $live,
            'reactions' => $reactions === [] ? new stdClass() : $reactions,
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if (preg_match('#^/app/file/([a-f0-9]{24})$#', $path, $m)) {
    if ($method !== 'GET') {
        http_response_code(405);
        exit;
    }
    try {
        staff_app_output_file($pdo, $user, $m[1]);
    } catch (Throwable $e) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'فایل پیدا نشد.';
        exit;
    }
}

if ($method === 'POST' && ($path === '/app/chat' || preg_match('#^/app/chat/([a-f0-9]{24})$#', $path))) {
    csrf_verify();
    if (!staff_app_ready($pdo)) {
        flash_set('error', 'چت الان در دسترس نیست.');
        redirect('/app/chat');
    }
    $back = '/app/chat';
    try {
        if (post('form') === 'create') {
            $members = $_POST['members'] ?? $_POST['member_ids'] ?? [];
            if (is_string($members)) {
                $members = preg_split('/\s*,\s*/', trim($members)) ?: [];
            }
            if (!is_array($members)) {
                $members = [];
            }
            $single = trim((string) ($_POST['member_id'] ?? ''));
            if ($single !== '') {
                $members[] = $single;
            }
            $roomId = staff_app_create_room($pdo, $user, post('title'), $members);
            flash_set('success', 'اتاق چت ساخته شد.');
            redirect('/app/chat/' . $roomId);
        }
        $roomId = post('room_id');
        if (!preg_match('/^[a-f0-9]{24}$/', $roomId)) {
            throw new RuntimeException('اتاق مشخص نیست.');
        }
        $back = '/app/chat/' . $roomId;
        $ajax = post('ajax') === '1';
        $form = post('form');
        if ($form === 'delete') {
            staff_app_delete_message($pdo, $user, $roomId, post('message_id'));
            if ($ajax) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
                exit;
            }
            flash_set('success', 'پیام حذف شد.');
            redirect($back);
        }
        if ($form === 'pin') {
            staff_app_pin_message($pdo, $user, $roomId, post('message_id'));
            if ($ajax) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
                exit;
            }
            redirect($back);
        }
        if ($form === 'react') {
            $reaction = staff_app_toggle_reaction($pdo, $user, $roomId, post('message_id'), post('emoji'));
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok' => true,
                'counts' => $reaction['counts'],
                'mine' => $reaction['mine'],
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($form === 'forward') {
            staff_app_forward_message($pdo, $user, $roomId, post('target_room'), post('message_id'));
            if ($ajax) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
                exit;
            }
            flash_set('success', 'پیام هدایت شد.');
            redirect($back);
        }
        $newId = staff_app_send_message($pdo, $user, $roomId, post('body'), $_FILES['file'] ?? null, post('reply_to'));
        if (post('ajax') === '1') {
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
            echo json_encode([
                'ok' => true,
                'message' => staff_app_message_card($pdo, $roomId, $userId, $newId),
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        redirect($back);
    } catch (RuntimeException $e) {
        if (post('ajax') === '1') {
            http_response_code(400);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
            exit;
        }
        flash_set('error', $e->getMessage());
        redirect($back);
    } catch (Throwable $e) {
        error_log('staff app chat: ' . $e->getMessage());
        if (post('ajax') === '1') {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => 'انجام نشد.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        flash_set('error', 'پیام فرستاده نشد.');
        redirect($back);
    }
}

if ($method === 'POST' && $path === '/app/checklist') {
    require_once __DIR__ . '/../includes/secretary_daily_tasks.php';
    csrf_verify();
    $ymd = trim((string) post('task_date'));
    $key = trim((string) post('task_key'));
    $mark = secretary_daily_task_posted_mark();
    $back = '/app/checklist?date=' . rawurlencode($ymd);
    $ajax = secretary_daily_task_request_is_ajax();
    $fail = static function (string $message, int $status = 400) use ($ajax, $back): never {
        if ($ajax) {
            secretary_daily_task_json_error($message, $status);
        }
        flash_set('error', $message);
        redirect($back);
    };
    if ($role !== 'SECRETARY') {
        $fail('تیک چک‌لیست را خود منشی می‌زند.', 403);
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ymd) || $ymd > date('Y-m-d')) {
        $fail('تاریخ این فهرست معتبر نیست.');
    }
    if (!secretary_daily_task_can_edit($pdo, $userId, $ymd, true) || ($ymd !== date('Y-m-d') && !secretary_was_present($pdo, $userId, $ymd))) {
        $fail('فقط روزهایی که در کلینیک حضور دارید قابل تیک خوردن است.', 403);
    }
    try {
        $doneAt = secretary_daily_task_set($pdo, $userId, $ymd, $key, $mark === 'done', $mark);
    } catch (Throwable $e) {
        $fail($e->getMessage());
    }
    if ($ajax) {
        secretary_daily_task_save_response($pdo, $userId, $ymd, $mark, is_string($doneAt) ? $doneAt : null);
    }
    redirect($back);
}

if ($method === 'POST' && $path === '/app/appointments') {
    if ($role !== 'SECRETARY') {
        flash_set('error', 'ثبت وقت با منشی است.');
        redirect('/app/appointments');
    }
    require __DIR__ . '/../actions/secretary_book.php';
    exit;
}

if ($method === 'POST' && $path === '/app/rooms') {
    csrf_verify();
    if ($role !== 'SECRETARY') {
        flash_set('error', 'رزرو اتاق با منشی است.');
        redirect('/app/rooms');
    }
    require_once __DIR__ . '/../includes/clinic_rooms.php';
    $day = clinic_rooms_parse_day(post('day'));
    try {
        if (post('action') === 'release') {
            $day = clinic_rooms_release($pdo, post('booking_id'));
            flash_set('success', 'ساعت اتاق آزاد شد.');
        } else {
            $repeatOn = post('repeat_weekly') === '1';
            $repeatWeeks = $repeatOn ? (int) post('repeat_weeks') : 1;
            if ($repeatOn && $repeatWeeks < 2) {
                throw new RuntimeException('برای تکرار هفتگی حداقل ۲ هفته بنویسید.');
            }
            $saved = clinic_rooms_assign(
                $pdo,
                $user,
                (int) post('room_no'),
                $day,
                post('purpose'),
                post('start_time'),
                post('end_time'),
                post('patient_id'),
                post('doctor_id'),
                post('workshop_session_id'),
                post('block_title'),
                post('note'),
                $repeatWeeks
            );
            $weeks = (int) ($saved['weeks'] ?? 1);
            $msg = clinic_room_label((int) ($saved['room'] ?? 0)) . ' رزرو شد.';
            if ($weeks > 1) {
                $msg = clinic_room_label((int) ($saved['room'] ?? 0)) . ' برای ' . to_fa_digits((string) $weeks) . ' هفته رزرو شد.';
            }
            flash_set('success', $msg);
            $day = (string) ($saved['day'] ?? $day);
        }
    } catch (Throwable $e) {
        flash_set('error', $e->getMessage());
    }
    redirect('/app/rooms?day=' . rawurlencode($day));
}

if ($method === 'POST' && $path === '/app/complaints') {
    require_once __DIR__ . '/../includes/session_complaints.php';
    csrf_verify();
    if ($role !== 'SECRETARY') {
        flash_set('error', 'ثبت شکایت با منشی است.');
        redirect('/app/complaints');
    }
    try {
        session_complaint_create(
            $pdo,
            $userId,
            trim((string) post('patient_id')),
            trim((string) post('doctor_id')),
            trim(trim((string) post('session_date')) . ' ' . trim((string) post('session_time'))),
            (string) post('reason'),
            (string) post('body')
        );
    } catch (RuntimeException $e) {
        flash_set('error', $e->getMessage());
        redirect('/app/complaints');
    } catch (Throwable $e) {
        flash_set('error', 'ثبت شکایت انجام نشد.');
        redirect('/app/complaints');
    }
    flash_set('success', 'شکایت ثبت شد.');
    redirect('/app/complaints');
}

if ($method !== 'GET' && $method !== 'HEAD') {
    http_response_code(405);
    exit;
}

$roomId = '';
if (preg_match('#^/app/chat/([a-f0-9]{24})$#', $path, $m)) {
    $roomId = $m[1];
    $path = '/app/chat';
}

$section = match ($path) {
    '/app' => 'home',
    '/app/appointments' => 'appointments',
    '/app/rooms' => 'rooms',
    '/app/chat' => 'chat',
    '/app/hours' => 'hours',
    '/app/consult' => 'consult',
    '/app/checklist' => 'checklist',
    '/app/complaints' => 'complaints',
    '/app/profile' => 'profile',
    default => '',
};

if ($section === '') {
    http_response_code(404);
    staff_app_render('home', 'یافت نشد', 'این صفحه در برنامه داخلی مانا کلینیک نیست.', '<h1>این صفحه در برنامه نیست</h1><p><a href="' . e(url('/app')) . '">برگشت به برنامه</a></p>');
    exit;
}

$descriptions = [
    'home' => 'برنامه خصوصی مانا کلینیک برای درمانگرها و منشی‌ها؛ وقت، اتاق، چت، درخواست مشاوره، کارهای روزانه و شکایت.',
    'appointments' => 'وقت‌های پیش‌رو کلینیک در برنامه داخلی، با نام مراجع، درمانگر، ساعت و اتاق.',
    'rooms' => 'وضعیت اتاق‌های کلینیک در هفت روز پیش‌رو؛ الان، رزروشده یا تمام‌شده.',
    'chat' => 'چت کلی درمانگرها و منشی‌ها و اتاق‌های جدا، با امکان فرستادن فایل.',
    'hours' => 'ساعت ورود و خروج منشی‌های مانا کلینیک و مدت حضور هر روز.',
    'consult' => 'درخواست‌های مشاوره که از فرم سایت مانا کلینیک رسیده است.',
    'checklist' => 'لیست کارهای روزانه منشی‌ها در کلینیک مانا، همان فهرست داخل سایت.',
    'complaints' => 'ثبت و دیدن شکایت مراجع از جلسه درمانگر در برنامه داخلی مانا.',
    'profile' => 'پروفایل درمانگر یا منشی در برنامه داخلی مانا کلینیک.',
];

if ($section === 'home') {
    $hello = $name !== '' ? $name : staff_app_role_label($role);
    ob_start();
    ?>
    <div class="sapp-hello">
      <h1>خوش آمدید، <?= e($hello) ?></h1>
      <p>داشبورد مدیریتی</p>
    </div>
    <div class="sapp-grid">
      <a class="sapp-tile" href="<?= e(url('/app/appointments')) ?>">
        <span class="sapp-ico sapp-ico-blue"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="5" width="16" height="15" rx="2"/><path d="M8 3.5V7M16 3.5V7M4 10h16"/></svg></span>
        <strong>وقت‌ها</strong>
        <em>Daily Schedule</em>
      </a>
      <a class="sapp-tile" href="<?= e(url('/app/rooms')) ?>">
        <span class="sapp-ico sapp-ico-green"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M6 20V6.5A2.5 2.5 0 0 1 8.5 4H18v16"/><path d="M6 20h12"/><path d="M10 12h.01"/></svg></span>
        <strong>شرایط اتاق‌ها</strong>
        <em>Availability</em>
      </a>
      <a class="sapp-tile" href="<?= e(url('/app/chat')) ?>">
        <span class="sapp-ico sapp-ico-purple"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M6 16.5 4 19V7.2A2.2 2.2 0 0 1 6.2 5h7.2A2.2 2.2 0 0 1 15.6 7.2V12a2.2 2.2 0 0 1-2.2 2.2H6z"/><path d="M10 16.2h5.6A2.2 2.2 0 0 0 17.8 14V9.2L20 11.2V17a2 2 0 0 1-2 2h-6.2"/></svg></span>
        <strong>چت کلینیک</strong>
        <em>Team Messaging</em>
      </a>
      <a class="sapp-tile" href="<?= e(url('/app/consult')) ?>">
        <span class="sapp-ico sapp-ico-teal"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M8.2 4.8h2.1a1 1 0 0 1 1 .76l.5 2.4a1 1 0 0 1-.5 1.05l-1.35.7a7.6 7.6 0 0 0 3.74 3.74l.7-1.35a1 1 0 0 1 1.05-.5l2.4.5a1 1 0 0 1 .76 1V15.4A1.6 1.6 0 0 1 17 17 11.2 11.2 0 0 1 7 7a1.6 1.6 0 0 1 1.2-2.2z"/></svg></span>
        <strong>درخواست مشاوره</strong>
        <em>Consult Requests</em>
      </a>
      <a class="sapp-tile" href="<?= e(url('/app/checklist')) ?>">
        <span class="sapp-ico sapp-ico-pink"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><rect x="5" y="4" width="14" height="16" rx="3"/><path d="m8.5 12 2.2 2.2 4.8-5"/></svg></span>
        <strong>لیست کارهای روزانه</strong>
        <em>Daily Tasks</em>
      </a>
      <a class="sapp-tile" href="<?= e(url('/app/complaints')) ?>">
        <span class="sapp-ico sapp-ico-red"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M12 4.5 20 19H4L12 4.5z"/><path d="M12 10v4.2"/><path d="M12 16.8h.01"/></svg></span>
        <strong>شکایات</strong>
        <em>Feedback</em>
      </a>
    </div>
    <?php
    staff_app_render('home', 'Mana Staff', $descriptions['home'], ob_get_clean());
    exit;
}

if ($section === 'appointments') {
    $bookForm = '';
    if ($role === 'SECRETARY') {
        $secretaryBookEmbedded = true;
        $secretaryBookNext = '/app/appointments';
        require __DIR__ . '/secretary/book.php';
        $bookForm = (string) ($secretaryBookFormHtml ?? '');
        $GLOBALS['pageHead'] = '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@majidh1/jalalidatepicker/dist/jalalidatepicker.min.css">';
        $GLOBALS['pageScripts'] = (string) ($secretaryBookScripts ?? '');
    }
    $rows = [];
    $error = '';
    try {
        $rows = staff_app_appointment_rows($pdo, $user);
    } catch (Throwable $e) {
        error_log('staff app appointments: ' . $e->getMessage());
        $error = 'بارگذاری وقت‌ها الان ممکن نیست.';
    }
    $grouped = [];
    foreach ($rows as $row) {
        $day = substr((string) ($row['starts_at'] ?? ''), 0, 10);
        $grouped[$day][] = $row;
    }
    ob_start();
    ?>
    <?php if ($bookForm !== ''): ?>
      <h1>ثبت وقت</h1>
      <?= $bookForm ?>
      <h2 class="sapp-day">وقت‌های دو هفته آینده</h2>
    <?php else: ?>
      <h1>وقت‌ها</h1>
      <p class="muted"><?= staff_app_sees_clinic_schedule($user) ? 'وقت‌های کلینیک تا دو هفته آینده.' : 'وقت‌های خودتان تا دو هفته آینده.' ?></p>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
      <p><?= e($error) ?></p>
    <?php elseif ($grouped === []): ?>
      <p class="muted">در این بازه وقتی ثبت نشده است.</p>
    <?php else: ?>
      <?php foreach ($grouped as $day => $items): ?>
        <h2 class="sapp-day"><?= e(to_jalali_label($day)) ?></h2>
        <div class="sapp-list">
          <?php foreach ($items as $row): ?>
            <?php
              $roomNo = (int) ($row['room_no'] ?? 0);
              $roomOnly = (int) ($row['room_only'] ?? 0) === 1;
              $mode = (string) ($row['session_mode'] ?? '') === 'ONLINE' ? 'آنلاین' : 'حضوری';
              $who = trim((string) ($row['patient_name'] ?? ''));
              $doctorName = trim((string) ($row['doctor_name'] ?? ''));
              $whoLine = $who !== '' && $doctorName !== '' ? $who . ' · ' . $doctorName : ($who !== '' ? $who : $doctorName);
              $roomLabel = trim((string) ($row['room_label'] ?? ''));
              if ($roomOnly && $whoLine === '') {
                  $whoLine = $roomLabel !== '' ? $roomLabel : 'رزرو اتاق';
              }
              $detail = $roomOnly ? 'رزرو اتاق' : ($mode . ' · ' . appointment_row_status_label($row));
              if ($roomOnly && $roomLabel !== '' && $roomLabel !== $whoLine) {
                  $detail .= ' · ' . $roomLabel;
              }
              if ($roomNo > 0) {
                  $detail .= ' · اتاق ' . to_fa_digits((string) $roomNo);
              }
            ?>
            <article class="sapp-row">
              <strong><?= e(format_fa_time((string) $row['starts_at'])) ?> تا <?= e(format_fa_time((string) $row['ends_at'])) ?></strong>
              <div><?= e($whoLine) ?></div>
              <small><?= e($detail) ?></small>
            </article>
          <?php endforeach; ?>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
    <?php
    staff_app_render('appointments', 'وقت‌ها', $descriptions['appointments'], ob_get_clean());
    exit;
}

if ($section === 'rooms') {
    if (!function_exists('clinic_rooms_between')) {
        require_once __DIR__ . '/../includes/clinic_rooms.php';
    }
    $roomDay = function_exists('clinic_rooms_parse_day') ? clinic_rooms_parse_day((string) ($_GET['day'] ?? '')) : date('Y-m-d');
    $roomPrev = date('Y-m-d', strtotime($roomDay . ' -1 day') ?: time());
    $roomNext = date('Y-m-d', strtotime($roomDay . ' +1 day') ?: time());
    [$roomGy, $roomGm, $roomGd] = array_map('intval', explode('-', $roomDay));
    [$roomJy, $roomJm, $roomJd] = gregorian_to_jalali($roomGy, $roomGm, $roomGd);
    $roomJalali = sprintf('%04d/%02d/%02d', $roomJy, $roomJm, $roomJd);
    $rows = [];
    $error = '';
    $roomPatients = [];
    $roomDoctors = [];
    $openWorkshops = [];
    $roomDayStart = $roomDay . ' 00:00:00';
    $roomDayEnd = date('Y-m-d H:i:s', strtotime($roomDay . ' +1 day') ?: time());
    try {
        $rows = staff_app_room_board($pdo, $roomDayStart, $roomDayEnd);
    } catch (Throwable $e) {
        error_log('staff app rooms: ' . $e->getMessage());
        $error = 'بارگذاری اتاق‌ها الان ممکن نیست.';
    }
    if ($role === 'SECRETARY') {
        try {
            $roomPatients = $pdo->query("SELECT id, name, phone FROM users WHERE role='PATIENT' AND COALESCE(is_disabled,0)=0 ORDER BY name ASC")->fetchAll() ?: [];
            $roomDoctors = $pdo->query("
              SELECT dp.id, u.name, dp.specialty
              FROM doctor_profiles dp
              JOIN users u ON u.id = dp.user_id
              WHERE dp.is_active = 1 AND dp.is_approved = 1 AND COALESCE(u.is_disabled,0)=0
              ORDER BY u.name ASC
            ")->fetchAll() ?: [];
            $openWorkshops = clinic_rooms_open_workshop_sessions($pdo);
        } catch (Throwable $e) {
            error_log('staff app room form: ' . $e->getMessage());
        }
    }
    $numbers = function_exists('clinic_rooms_numbers') ? clinic_rooms_numbers() : [1, 2, 3];
    $byRoom = [];
    foreach ($numbers as $number) {
        $byRoom[(int) $number] = [];
    }
    foreach ($rows as $row) {
        $number = (int) ($row['room_no'] ?? 0);
        if (!isset($byRoom[$number])) {
            $byRoom[$number] = [];
        }
        $byRoom[$number][] = $row;
    }
    $clockChoices = [];
    for ($hour = 8; $hour <= 21; $hour++) {
        $clockChoices[] = sprintf('%02d:00', $hour);
        if ($hour < 21) {
            $clockChoices[] = sprintf('%02d:30', $hour);
        }
    }
    if ($role === 'SECRETARY') {
        $GLOBALS['pageHead'] = '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@majidh1/jalalidatepicker/dist/jalalidatepicker.min.css">';
        $GLOBALS['pageScripts'] = '<script src="' . e(url('/assets/js/search-select.js')) . '?v=20261007cmp"></script>'
            . '<script src="https://cdn.jsdelivr.net/npm/jalaali-js@1.2.7/dist/jalaali.min.js"></script>'
            . '<script src="https://cdn.jsdelivr.net/npm/@majidh1/jalalidatepicker/dist/jalalidatepicker.min.js"></script>'
            . '<script>
(function(){
  function faToEn(v){return String(v||"").replace(/[۰-۹]/g,function(d){return "۰۱۲۳۴۵۶۷۸۹".indexOf(d);}).replace(/[٠-٩]/g,function(d){return "٠١٢٣٤٥٦٧٨٩".indexOf(d);});}
  function pad(n){return (n<10?"0":"")+n;}
  var dayView=document.getElementById("room-day-view");
  var current=' . json_encode($roomDay) . ';
  var base=' . json_encode(url('/app/rooms')) . ';
  if(dayView&&window.jalaliDatepicker){
    jalaliDatepicker.startWatch({selector:"#room-day-view",time:false,hideAfterChange:true,showTodayBtn:true,showEmptyBtn:false,autoReadOnlyInput:true,persianDigits:true,zIndex:100000,container:"body"});
    dayView.addEventListener("jdp:change",function(){
      var t=faToEn(dayView.value).replace(/-/g,"/").trim();
      var p=t.split("/");
      if(p.length!==3||!window.jalaali) return;
      var g=jalaali.toGregorian(parseInt(p[0],10),parseInt(p[1],10),parseInt(p[2],10));
      var key=g.gy+"-"+pad(g.gm)+"-"+pad(g.gd);
      if(key!==current) location.href=base+"?day="+key;
    });
  }
  document.querySelectorAll("[data-room-book]").forEach(function(form){
    var purpose=form.querySelector("[data-purpose]");
    function sync(){
      var value=purpose?purpose.value:"";
      form.querySelectorAll("[data-for]").forEach(function(box){
        var on=box.getAttribute("data-for")===value;
        box.hidden=!on;
        box.querySelectorAll("input,select,textarea").forEach(function(field){field.disabled=!on;});
      });
    }
    var repeat=form.querySelector("[data-repeat]");
    var repeatBox=form.querySelector("[data-repeat-fields]");
    function syncRepeat(){
      var on=!!(repeat&&repeat.checked);
      if(repeatBox){
        repeatBox.hidden=!on;
        repeatBox.querySelectorAll("input").forEach(function(field){field.disabled=!on;});
      }
    }
    if(purpose) purpose.addEventListener("change",sync);
    if(repeat) repeat.addEventListener("change",syncRepeat);
    if(window.enhanceSearchSelect){
      form.querySelectorAll("select[data-search]").forEach(function(sel){enhanceSearchSelect(sel);});
    }
    sync();
    syncRepeat();
  });
})();
</script>';
    }
    ob_start();
    ?>
    <h1>شرایط اتاق‌ها</h1>
    <p class="muted"><?= $role === 'SECRETARY' ? 'روز را انتخاب کنید، ساعت را بنویسید و اتاق را رزرو کنید.' : 'وضعیت اتاق‌ها در روز انتخاب‌شده.' ?></p>
    <div style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;margin:.75rem 0">
      <a class="btn btn-outline btn-sm" href="<?= e(url('/app/rooms?day=' . rawurlencode($roomPrev))) ?>">روز قبل</a>
      <strong><?= e(to_jalali_label($roomDay)) ?></strong>
      <a class="btn btn-outline btn-sm" href="<?= e(url('/app/rooms?day=' . rawurlencode($roomNext))) ?>">روز بعد</a>
    </div>
    <?php if ($role === 'SECRETARY'): ?>
      <label class="label" for="room-day-view">تاریخ رزرو</label>
      <input class="input" id="room-day-view" type="text" data-jdp data-jdp-only-date readonly autocomplete="off" value="<?= e($roomJalali) ?>" style="max-width:16rem;cursor:pointer">
    <?php endif; ?>
    <?php if ($error !== ''): ?>
      <p><?= e($error) ?></p>
    <?php else: ?>
      <datalist id="room-clock-options">
        <?php foreach ($clockChoices as $clock): ?>
          <option value="<?= e($clock) ?>"></option>
        <?php endforeach; ?>
      </datalist>
      <?php foreach ($byRoom as $number => $items): ?>
        <h2 class="sapp-day"><?= e(function_exists('clinic_room_label') ? clinic_room_label((int) $number) : ('اتاق ' . to_fa_digits((string) $number))) ?></h2>
        <?php if ($items === []): ?>
          <p class="muted">در این روز رزروی نیست.</p>
        <?php else: ?>
          <div class="sapp-list">
            <?php foreach ($items as $row): ?>
              <?php
                $now = time();
                $start = strtotime((string) ($row['starts_at'] ?? '')) ?: 0;
                $end = strtotime((string) ($row['ends_at'] ?? '')) ?: 0;
                $state = ($now >= $start && $now < $end) ? 'الان' : ($start > $now ? 'رزرو شده' : 'تمام شده');
                $purposeText = function_exists('clinic_room_purpose') ? clinic_room_purpose($row) : trim((string) ($row['title'] ?? ''));
              ?>
              <article class="sapp-row">
                <strong><?= e($state) ?> · <?= e(format_fa_time((string) $row['starts_at'])) ?> تا <?= e(format_fa_time((string) $row['ends_at'])) ?></strong>
                <div><?= e($purposeText !== '' ? $purposeText : 'بدون توضیح') ?></div>
                <?php if ($role === 'SECRETARY' && $end >= $now): ?>
                  <form method="post" action="<?= e(url('/app/rooms')) ?>" style="margin:.55rem 0 0">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="release">
                    <input type="hidden" name="day" value="<?= e($roomDay) ?>">
                    <input type="hidden" name="booking_id" value="<?= e((string) ($row['id'] ?? '')) ?>">
                    <button class="btn btn-outline btn-sm" type="submit">آزاد کردن این ساعت</button>
                  </form>
                <?php endif; ?>
              </article>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
        <?php if ($role === 'SECRETARY' && $error === ''): ?>
          <form class="sapp-compose panel" method="post" action="<?= e(url('/app/rooms')) ?>" data-room-book>
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="assign">
            <input type="hidden" name="day" value="<?= e($roomDay) ?>">
            <input type="hidden" name="room_no" value="<?= (int) $number ?>">
            <strong>رزرو <?= e(function_exists('clinic_room_label') ? clinic_room_label((int) $number) : '') ?></strong>
            <label class="label">از ساعت<input class="input" name="start_time" list="room-clock-options" inputmode="decimal" autocomplete="off" placeholder="مثلاً ۹ یا ۱۴:۳۰" required></label>
            <label class="label">تا ساعت<input class="input" name="end_time" list="room-clock-options" inputmode="decimal" autocomplete="off" placeholder="مثلاً ۱۰:۳۰" required></label>
            <label class="label">برای چیست؟
              <select class="input" name="purpose" data-purpose required>
                <option value="">انتخاب کنید</option>
                <option value="therapy">تراپی</option>
                <option value="workshop">کارگاه</option>
                <option value="block">سایر</option>
              </select>
            </label>
            <div data-for="therapy" hidden>
              <label class="label">مراجعه‌کننده
                <select class="input" name="patient_id" data-search>
                  <option value="">انتخاب مراجعه‌کننده</option>
                  <?php foreach ($roomPatients as $person): ?>
                    <option value="<?= e((string) $person['id']) ?>"><?= e((string) $person['name']) ?></option>
                  <?php endforeach; ?>
                </select>
              </label>
              <label class="label">درمانگر
                <select class="input" name="doctor_id" data-search>
                  <option value="">انتخاب درمانگر</option>
                  <?php foreach ($roomDoctors as $doctor): ?>
                    <option value="<?= e((string) $doctor['id']) ?>"><?= e((string) $doctor['name']) ?></option>
                  <?php endforeach; ?>
                </select>
              </label>
            </div>
            <div data-for="workshop" hidden>
              <label class="label">کارگاه
                <select class="input" name="workshop_session_id" data-search>
                  <option value="">انتخاب کارگاه</option>
                  <?php foreach ($openWorkshops as $sess): ?>
                    <option value="<?= e((string) $sess['id']) ?>"><?= e((string) ($sess['workshop_title'] ?? 'کارگاه')) ?> · <?= e((string) ($sess['doctor_name'] ?? '')) ?></option>
                  <?php endforeach; ?>
                </select>
              </label>
            </div>
            <div data-for="block" hidden>
              <label class="label">عنوان<input class="input" name="block_title" maxlength="255" placeholder="مثلاً جلسه تیم"></label>
            </div>
            <label class="label">یادداشت<input class="input" name="note" maxlength="255"></label>
            <label style="display:flex;gap:8px;align-items:center">
              <input type="checkbox" name="repeat_weekly" value="1" data-repeat>
              <span>تکرار هر هفته</span>
            </label>
            <div data-repeat-fields hidden>
              <label class="label">چند هفته<input class="input" name="repeat_weeks" inputmode="numeric" placeholder="مثلاً ۸" disabled></label>
            </div>
            <button class="btn btn-primary" type="submit">رزرو اتاق</button>
          </form>
        <?php endif; ?>
      <?php endforeach; ?>
    <?php endif; ?>
    <?php
    staff_app_render('rooms', 'شرایط اتاق‌ها', $descriptions['rooms'], ob_get_clean());
    exit;
}

if ($section === 'chat') {
    $html = '';
    try {
        if (!staff_app_ready($pdo)) {
            throw new RuntimeException('چت الان در دسترس نیست.');
        }
        if ($roomId !== '') {
            $room = staff_app_room_for_member($pdo, $roomId, $userId);
            if (!$room) {
                flash_set('error', 'این اتاق برای شما نیست.');
                redirect('/app/chat');
            }
            staff_app_mark_read($pdo, $userId, $roomId);
            $messages = staff_app_messages($pdo, $roomId);
            $states = staff_app_own_receipts($pdo, $roomId, $userId);
            $seenMap = staff_app_seen_map($pdo, $roomId);
            $messageIds = array_map(static fn(array $row): string => (string) $row['id'], $messages);
            [$reactCounts, $reactMine] = staff_app_reaction_maps($pdo, $messageIds, $userId);
            $memberStmt = $pdo->prepare('SELECT user_id FROM staff_app_members WHERE room_id = ? AND user_id <> ?');
            $memberStmt->execute([$roomId, $userId]);
            $peerIds = [];
            foreach ($memberStmt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $peerId) {
                $peerId = (string) $peerId;
                if ($peerId !== '') {
                    $peerIds[] = $peerId;
                }
            }
            $isGroup = !empty($room['is_general']) || count($peerIds) > 1;
            $pinned = staff_app_pinned_message($pdo, $roomId);
            $pinnedId = (string) ($pinned['id'] ?? '');
            $pinText = trim(preg_replace('/\s+/u', ' ', (string) ($pinned['body'] ?? '')) ?? '');
            if ($pinned && $pinText === '') {
                $pinText = 'فایل';
            }
            if (mb_strlen($pinText) > 80) {
                $pinText = mb_substr($pinText, 0, 80) . '…';
            }
            $forwardRooms = [];
            foreach (staff_app_rooms_for($pdo, $userId) as $forwardRoom) {
                if ((string) ($forwardRoom['id'] ?? '') === $roomId) {
                    continue;
                }
                $forwardRooms[] = [
                    'id' => (string) $forwardRoom['id'],
                    'title' => (string) ($forwardRoom['title'] ?? 'اتاق'),
                ];
            }
            $chatConfig = json_encode([
                'post' => url('/app/chat'),
                'chatBase' => url('/app/chat'),
                'roomId' => $roomId,
                'userId' => $userId,
                'receipts' => url('/app/chat/' . $roomId . '/receipts'),
                'rooms' => $forwardRooms,
            ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
            ob_start();
            ?>
            <p style="margin:0 0 8px"><a href="<?= e(url('/app/chat')) ?>">همه چت‌ها</a></p>
            <h1 class="sapp-thread-title"><i class="sapp-dot" data-online="<?= e(implode(',', $peerIds)) ?>" hidden></i><span><?= e((string) ($room['title'] ?? 'چت')) ?></span></h1>
            <?php if ($pinned): ?>
              <a class="sapp-pin" href="#m-<?= e($pinnedId) ?>">
                <strong>سنجاق‌شده · <?= e((string) ($pinned['name'] ?? '')) ?></strong>
                <span><?= e($pinText) ?></span>
              </a>
            <?php endif; ?>
            <div class="sapp-chat<?= $isGroup ? ' is-group' : '' ?>" id="sapp-thread" data-group="<?= $isGroup ? '1' : '0' ?>" data-pinned="<?= e($pinnedId) ?>">
              <?php if ($messages === []): ?>
                <p class="sapp-chat-empty">هنوز پیامی نیست.</p>
              <?php endif; ?>
              <?php foreach ($messages as $message): ?>
                <?php
                  $mine = staff_push_same_user((string) ($message['user_id'] ?? ''), $userId);
                  $msgId = (string) ($message['id'] ?? '');
                  $senderName = trim((string) ($message['name'] ?? ''));
                  if ($senderName === '') {
                      $senderName = 'کاربر';
                  }
                  $bodyText = trim((string) ($message['body'] ?? ''));
                  $fileMeta = [];
                  foreach ($message['files'] ?? [] as $file) {
                      $fileMeta[] = [
                          'url' => url('/app/file/' . (string) $file['id']),
                          'name' => (string) ($file['original_name'] ?? 'فایل'),
                          'mime' => (string) ($file['mime'] ?? ''),
                      ];
                  }
                  $attrFlags = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
                  $replyToId = (string) ($message['reply_to'] ?? '');
                  $replyName = trim((string) ($message['reply_name'] ?? ''));
                  $replyText = trim(preg_replace('/\s+/u', ' ', (string) ($message['reply_body'] ?? '')) ?? '');
                  if (mb_strlen($replyText) > 80) {
                      $replyText = mb_substr($replyText, 0, 80) . '…';
                  }
                  $forwardFrom = trim((string) ($message['forward_from'] ?? ''));
                  $mineEmoji = (string) ($reactMine[$msgId] ?? '');
                ?>
                <article class="sapp-msg<?= $mine ? ' is-mine' : ' is-theirs' ?>" id="m-<?= e($msgId) ?>" data-id="<?= e($msgId) ?>" data-created="<?= e((string) ($message['created_at'] ?? '')) ?>" data-mine="<?= $mine ? '1' : '0' ?>" data-name="<?= e($senderName) ?>" data-body="<?= e($bodyText) ?>" data-seen="<?= json_encode($seenMap[$msgId] ?? [], $attrFlags) ?>" data-files="<?= json_encode($fileMeta, $attrFlags) ?>">
                  <?php if (!$mine): ?>
                    <span class="sapp-msg-name"><?= e($senderName) ?></span>
                  <?php endif; ?>
                  <?php if ($forwardFrom !== ''): ?>
                    <span class="sapp-forward">هدایت‌شده از <?= e($forwardFrom) ?></span>
                  <?php endif; ?>
                  <?php if ($replyToId !== ''): ?>
                    <a class="sapp-quote" href="#m-<?= e($replyToId) ?>">
                      <strong><?= e($replyName !== '' ? $replyName : 'پیام') ?></strong>
                      <span><?= e($replyText !== '' ? $replyText : 'پیام حذف‌شده') ?></span>
                    </a>
                  <?php endif; ?>
                  <?php if ($bodyText !== ''): ?>
                    <p><?= e($bodyText) ?></p>
                  <?php endif; ?>
                  <?php foreach ($message['files'] ?? [] as $index => $file): ?>
                    <?php
                      $fileUrl = (string) ($fileMeta[$index]['url'] ?? '');
                      $mime = (string) ($file['mime'] ?? '');
                    ?>
                    <?php if (str_starts_with($mime, 'image/')): ?>
                      <a href="<?= e($fileUrl) ?>"><img src="<?= e($fileUrl) ?>" alt="<?= e((string) ($file['original_name'] ?? 'فایل')) ?>"></a>
                    <?php else: ?>
                      <p><a href="<?= e($fileUrl) ?>"><?= e((string) ($file['original_name'] ?? 'فایل')) ?></a></p>
                    <?php endif; ?>
                  <?php endforeach; ?>
                  <div class="sapp-reacts" data-mine="<?= e($mineEmoji) ?>">
                    <?php foreach ($reactCounts[$msgId] ?? [] as $emoji => $count): ?>
                      <span class="sapp-react-chip<?= (string) $emoji === $mineEmoji ? ' is-mine' : '' ?>"><?= e((string) $emoji) ?> <?= e(to_fa_digits((string) (int) $count)) ?></span>
                    <?php endforeach; ?>
                  </div>
                  <div class="sapp-msg-meta">
                    <time><?= e(format_fa_time((string) ($message['created_at'] ?? ''))) ?></time>
                    <?php if ($mine): ?>
                      <?= staff_app_ticks_html((string) ($states[$msgId] ?? 'sent'), $msgId) ?>
                    <?php endif; ?>
                  </div>
                </article>
              <?php endforeach; ?>
            </div>
            <form class="sapp-compose" method="post" action="<?= e(url('/app/chat')) ?>" enctype="multipart/form-data">
              <?= csrf_field() ?>
              <input type="hidden" name="form" value="send">
              <input type="hidden" name="room_id" value="<?= e($roomId) ?>">
              <div class="sapp-reply" id="sapp-reply" hidden>
                <input type="hidden" name="reply_to" id="sapp-reply-id" value="">
                <div>
                  <strong id="sapp-reply-who"></strong>
                  <span id="sapp-reply-snippet"></span>
                </div>
                <button type="button" id="sapp-reply-x" aria-label="لغو پاسخ">×</button>
              </div>
              <div class="sapp-compose-row">
                <label class="sapp-file" title="فایل">
                  <input id="chat-file" name="file" type="file" aria-label="فایل">
                  <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="m21 12-8.5 8.5a5.5 5.5 0 0 1-7.8-7.8L13.2 4.2a3.5 3.5 0 0 1 5 5L9.6 17.8a1.5 1.5 0 0 1-2.1-2.1l7.4-7.4"/></svg>
                </label>
                <textarea class="input" id="chat-body" name="body" rows="1" maxlength="4000" placeholder="پیام" autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false" enterkeyhint="send"></textarea>
                <button class="sapp-send" type="submit" aria-label="ارسال">➤</button>
              </div>
            </form>
            <div id="sapp-hold" hidden>
              <button type="button" class="sapp-hold-back" id="sapp-hold-back" aria-label="بستن"></button>
              <div class="sapp-hold-pop" id="sapp-hold-pop">
                <div class="sapp-hold-emojis">
                  <?php foreach (staff_app_allowed_reactions() as $emoji): ?>
                    <button type="button" data-emoji="<?= e($emoji) ?>"><?= e($emoji) ?></button>
                  <?php endforeach; ?>
                </div>
                <div class="sapp-hold-menu" id="sapp-hold-menu">
                  <button type="button" class="sapp-hold-item" data-act="seen" id="sapp-hold-seen">
                    <svg viewBox="0 0 20 16" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="M1.4 8.2 4.6 11.4 11 4.6"/><path d="M6.2 8.2 9.4 11.4 15.8 4.6"/></svg>
                    <span id="sapp-hold-seen-label">دیده شد</span>
                    <span class="sapp-hold-faces" id="sapp-hold-seen-faces"></span>
                  </button>
                  <div class="sapp-hold-people" id="sapp-hold-people" hidden></div>
                  <button type="button" class="sapp-hold-item" data-act="reply"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M9 8 4 12l5 4"/><path d="M4 12h10a6 6 0 0 1 6 6"/></svg><span>پاسخ</span></button>
                  <button type="button" class="sapp-hold-item" data-act="copy"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="8" y="8" width="12" height="12" rx="2"/><path d="M4 16V6a2 2 0 0 1 2-2h10"/></svg><span>کپی</span></button>
                  <button type="button" class="sapp-hold-item" data-act="save"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M12 4v10"/><path d="m8 10 4 4 4-4"/><path d="M5 19h14"/></svg><span>ذخیره فایل</span></button>
                  <button type="button" class="sapp-hold-item" data-act="pin"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="m9 4 1 6-4 4v1h12v-1l-4-4 1-6"/><path d="M12 15v5"/></svg><span>سنجاق</span></button>
                  <button type="button" class="sapp-hold-item" data-act="link"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M10 13a5 5 0 0 0 7.1.1l2-2a5 5 0 0 0-7.1-7.1l-1.1 1.1"/><path d="M14 11a5 5 0 0 0-7.1-.1l-2 2a5 5 0 0 0 7.1 7.1l1.1-1.1"/></svg><span>کپی لینک</span></button>
                  <button type="button" class="sapp-hold-item" data-act="forward"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M15 8h5v5"/><path d="M20 8 12 16a6 6 0 0 1-8.5 0"/></svg><span>هدایت</span></button>
                  <button type="button" class="sapp-hold-item is-danger is-split" data-act="delete"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M4 7h16"/><path d="M9 7V5h6v2"/><path d="M8 7l1 13h6l1-13"/></svg><span>حذف</span></button>
                  <button type="button" class="sapp-hold-item is-split" data-act="select"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="12" cy="12" r="8"/><path d="m8.5 12.2 2.2 2.2 4.8-5"/></svg><span>انتخاب</span></button>
                </div>
                <div class="sapp-hold-menu" id="sapp-hold-forward" hidden>
                  <p class="sapp-hold-forward-title">هدایت به</p>
                  <div id="sapp-hold-rooms"></div>
                </div>
              </div>
            </div>
            <div class="sapp-selectbar" id="sapp-selectbar" hidden>
              <button type="button" id="sapp-select-copy">کپی</button>
              <button type="button" class="is-danger" id="sapp-select-delete">حذف</button>
              <button type="button" id="sapp-select-cancel">لغو</button>
            </div>
            <script type="application/json" id="sapp-chat-config"><?= $chatConfig ?></script>
            <?php
            $html = ob_get_clean();
            $GLOBALS['pageScripts'] = '<script src="' . e(url('/assets/js/staff-chat.js')) . '?v=20261008lift"></script>';
        } else {
            $rooms = staff_app_rooms_for($pdo, $userId);
            $people = staff_app_people($pdo);
            $peerMap = staff_app_peer_map($pdo, $userId);
            ob_start();
            ?>
            <h1>چت</h1>
            <p class="muted">چت کلی برای همه است. برای حرف زدن با یک نفر، اسمش را بزنید.</p>
            <div class="sapp-list">
              <?php foreach ($rooms as $room): ?>
                <?php $roomUnread = (int) ($room['unread_count'] ?? 0); ?>
                <a class="sapp-row" href="<?= e(url('/app/chat/' . (string) $room['id'])) ?>" style="text-decoration:none;color:inherit">
                  <strong class="sapp-room-name"><span class="sapp-room-label"><i class="sapp-dot" data-online="<?= e(implode(',', $peerMap[(string) $room['id']] ?? [])) ?>" hidden></i><?= e((string) ($room['title'] ?? 'اتاق')) ?></span><b class="sapp-unread" data-room-unread="<?= e((string) $room['id']) ?>"<?= $roomUnread > 0 ? '' : ' hidden' ?>><?= $roomUnread > 0 ? e(to_fa_digits((string) $roomUnread)) : '' ?></b></strong>
                  <small><?= !empty($room['is_general']) ? 'همه درمانگرها و منشی‌ها' : 'گفتگوی خصوصی' ?> · <?= e(to_fa_digits((string) (int) ($room['message_count'] ?? 0))) ?> پیام</small>
                </a>
              <?php endforeach; ?>
            </div>
            <h2 id="sapp-new-chat" style="margin:18px 0 8px;font-size:1.05rem">شروع گفتگو</h2>
            <?php $shown = 0; ?>
            <?php foreach ($people as $person): ?>
              <?php if ((string) ($person['id'] ?? '') === $userId) { continue; } ?>
              <?php $shown++; ?>
              <?php $personName = trim((string) ($person['name'] ?? '')) !== '' ? (string) $person['name'] : (string) ($person['username'] ?? ''); ?>
              <form method="post" action="<?= e(url('/app/chat')) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="form" value="create">
                <input type="hidden" name="member_id" value="<?= e((string) $person['id']) ?>">
                <button class="sapp-person" type="submit">
                  <span class="sapp-person-name"><i class="sapp-dot" data-online="<?= e((string) $person['id']) ?>" hidden></i><?= e($personName) ?></span>
                  <small><?= e(staff_app_role_label((string) ($person['role'] ?? ''))) ?></small>
                </button>
              </form>
            <?php endforeach; ?>
            <?php if ($shown === 0): ?>
              <p class="muted">درمانگر یا منشی دیگری برای گفتگو پیدا نشد.</p>
            <?php endif; ?>
            <details class="sapp-group">
              <summary>گفتگوی گروهی</summary>
              <form method="post" action="<?= e(url('/app/chat')) ?>" style="margin-top:10px">
                <?= csrf_field() ?>
                <input type="hidden" name="form" value="create">
                <label class="label" for="room-title">نام گروه</label>
                <input class="input" id="room-title" name="title" maxlength="80" placeholder="مثلاً شیفت عصر">
                <?php foreach ($people as $person): ?>
                  <?php if ((string) ($person['id'] ?? '') === $userId) { continue; } ?>
                  <label class="sapp-check">
                    <input type="checkbox" name="member_ids[]" value="<?= e((string) $person['id']) ?>">
                    <span class="sapp-person-name"><i class="sapp-dot" data-online="<?= e((string) $person['id']) ?>" hidden></i><?= e(trim((string) ($person['name'] ?? '')) !== '' ? (string) $person['name'] : (string) ($person['username'] ?? '')) ?></span>
                  </label>
                <?php endforeach; ?>
                <button class="btn btn-primary" type="submit">ساخت گروه</button>
              </form>
            </details>
            <?php
            $html = ob_get_clean();
        }
    } catch (Throwable $e) {
        error_log('staff app chat page: ' . $e->getMessage());
        $html = '<h1>چت</h1><p>چت الان باز نمی‌شود. یک‌بار دیگر صفحه را باز کنید.</p>';
    }
    if ($roomId !== '') {
        $GLOBALS['staffBodyClass'] = 'is-thread';
    }
    staff_app_render('chat', 'چت کارکنان', $descriptions['chat'], $html);
    exit;
}

if ($section === 'hours') {
    $html = '<h1>ساعت کار منشی‌ها</h1><p>بارگذاری ساعت کار الان ممکن نیست.</p>';
    try {
        require_once __DIR__ . '/../includes/staff_hours_ui.php';
        $blocks = array_values(array_filter(
            staff_hours_collect($pdo),
            static fn(array $block): bool => (string) ($block['kind'] ?? '') === 'secretary'
        ));
        ob_start();
        echo '<h1>ساعت کار منشی‌ها</h1>';
        echo '<p class="muted">ورود، خروج و مدت حضور هر منشی. ساعت عادی از ۹ صبح تا ۸ شب است.</p>';
        if ($blocks === []) {
            echo '<p class="muted">منشی‌ای برای نمایش ساعت کار پیدا نشد.</p>';
        }
        foreach ($blocks as $block) {
            $label = trim((string) ($block['label'] ?? 'منشی'));
            echo staff_hours_render_self($block, [
                'title' => $label,
                'intro' => 'حضور ' . $label . ' در کلینیک.',
            ]);
        }
        $html = ob_get_clean();
        $GLOBALS['pageScripts'] = staff_hours_scripts();
    } catch (Throwable $e) {
        error_log('staff app hours: ' . $e->getMessage());
    }
    staff_app_render('hours', 'ساعت کار منشی‌ها', $descriptions['hours'], $html);
    exit;
}

if ($section === 'consult') {
    require_once __DIR__ . '/../includes/consult_requests.php';
    $rows = [];
    try {
        $rows = consult_request_list($pdo);
    } catch (Throwable $e) {
        error_log('staff app consult: ' . $e->getMessage());
    }
    $openRows = [];
    $doneRows = [];
    foreach ($rows as $row) {
        if (consult_request_is_open($row)) {
            $openRows[] = $row;
        } else {
            $doneRows[] = $row;
        }
    }
    $newCount = count($openRows);
    ob_start();
    ?>
    <h1>درخواست مشاوره</h1>
    <p class="muted">این‌ها را از فرم پایین سایت فرستاده‌اند. روی شماره بزنید تا تماس بگیرید.<?= $newCount ? ' ' . e(to_fa_digits((string) $newCount)) . ' درخواست پیگیری نشده است.' : '' ?></p>
    <?php if (!$rows): ?>
      <p class="sapp-row">هنوز درخواست مشاوره‌ای از سایت نرسیده است.</p>
    <?php endif; ?>
    <?php if ($openRows): ?>
      <h2 class="sapp-day">پیگیری نشده</h2>
    <?php endif; ?>
    <?php foreach (array_merge($openRows, $doneRows) as $row): ?>
      <?php
        $isNew = consult_request_is_open($row);
        $phone = (string) ($row['phone'] ?? '');
        $person = trim((string) ($row['name'] ?? ''));
      ?>
      <?php if (!$isNew && $openRows && $row === $doneRows[0]): ?>
        <h2 class="sapp-day">پیگیری شد</h2>
      <?php endif; ?>
      <article class="sapp-consult<?= $isNew ? ' is-new' : '' ?>">
        <header>
          <strong><?= e($person !== '' ? $person : 'بدون نام') ?></strong>
          <?php if ($isNew): ?><span>پیگیری نشده</span><?php else: ?><span class="muted">پیگیری شد</span><?php endif; ?>
          <time><?= e(format_fa_datetime((string) ($row['created_at'] ?? ''))) ?></time>
        </header>
        <a href="tel:<?= e($phone) ?>" dir="ltr"><?= e(to_fa_digits($phone)) ?></a>
        <p><?= e((string) ($row['message'] ?? '')) ?></p>
        <?php if ($isNew): ?>
          <form method="post" action="<?= e(url('/consult-requests/done')) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= e((string) $row['id']) ?>">
            <input type="hidden" name="next" value="/app/consult">
            <button type="submit" class="btn btn-outline btn-sm">تماس گرفته شد</button>
          </form>
        <?php else: ?>
          <form method="post" action="<?= e(url('/consult-requests/reopen')) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= e((string) $row['id']) ?>">
            <input type="hidden" name="next" value="/app/consult">
            <button type="submit" class="btn btn-outline btn-sm">برگرداندن به پیگیری‌نشده</button>
          </form>
        <?php endif; ?>
      </article>
    <?php endforeach; ?>
    <?php
    staff_app_render('consult', 'درخواست مشاوره', $descriptions['consult'], ob_get_clean());
    exit;
}

if ($section === 'checklist') {
    require_once __DIR__ . '/../includes/secretary_daily_tasks.php';
    $today = date('Y-m-d');
    $ymd = trim((string) ($_GET['date'] ?? $today));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ymd) || $ymd > $today) {
        $ymd = $today;
    }
    $prev = date('Y-m-d', strtotime($ymd . ' -1 day') ?: time());
    $next = date('Y-m-d', strtotime($ymd . ' +1 day') ?: time());
    $secretaries = secretary_daily_task_secretaries($pdo);
    $present = [];
    $absent = [];
    foreach ($secretaries as $sec) {
        $secId = (string) ($sec['id'] ?? '');
        $wasPresent = secretary_was_present($pdo, $secId, $ymd);
        $hasTick = false;
        if (!$wasPresent) {
            foreach (secretary_daily_task_states($pdo, $secId, $ymd) as $state) {
                if (!empty($state['done']) || !empty($state['skipped'])) {
                    $hasTick = true;
                    break;
                }
            }
        }
        if ($wasPresent || $hasTick || ($role === 'SECRETARY' && $secId === $userId && $ymd === $today)) {
            $present[] = $sec;
        } else {
            $absent[] = $sec;
        }
    }
    $tickUrl = url('/secretary/daily-tasks');
    ob_start();
    ?>
    <h1>لیست کارهای روزانه</h1>
    <p class="muted">همان فهرست داخل سایت است. پس از انجام هر کار آن را علامت بزنید. اگر کاری امروز لازم نیست، «امروز نیاز نیست» را بزنید.</p>
    <div style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;margin-top:.75rem">
      <a class="btn btn-outline btn-sm" href="<?= e(url('/app/checklist?date=' . rawurlencode($prev))) ?>">روز قبل</a>
      <strong><?= e(secretary_daily_task_date_label($ymd)) ?></strong>
      <?php if ($next <= $today): ?>
        <a class="btn btn-outline btn-sm" href="<?= e(url('/app/checklist?date=' . rawurlencode($next))) ?>">روز بعد</a>
      <?php endif; ?>
    </div>
    <?php if ($secretaries === []): ?>
      <p class="muted">منشی فعالی ثبت نشده است.</p>
    <?php elseif (!$present): ?>
      <p class="muted" style="margin-top:1rem">در این روز هیچ منشی‌ای حضور ثبت‌شده ندارد.</p>
      <?= secretary_daily_tasks_table_html($pdo, ['id' => '', 'name' => '', 'username' => ''], $ymd, false, '', true) ?>
    <?php else: ?>
      <?php foreach ($present as $secretary): ?>
        <?php
          $secId = (string) ($secretary['id'] ?? '');
          $canTick = $role === 'SECRETARY' && $secId === $userId && secretary_daily_task_can_edit($pdo, $userId, $ymd, true);
          echo secretary_daily_tasks_table_html($pdo, $secretary, $ymd, $canTick, $canTick ? $tickUrl : '', false);
        ?>
      <?php endforeach; ?>
    <?php endif; ?>
    <?php if ($absent): ?>
      <p class="muted" style="margin-top:1rem">این روز حضور نداشته‌اند: <?= e(implode('، ', array_map(static fn (array $s): string => (string) (($s['name'] ?? '') !== '' ? $s['name'] : ($s['username'] ?? '')), $absent))) ?></p>
    <?php endif; ?>
    <?php
    staff_app_render('checklist', 'لیست کارهای روزانه', $descriptions['checklist'], ob_get_clean());
    exit;
}

if ($section === 'profile') {
    $hello = $name !== '' ? $name : staff_app_role_label($role);
    ob_start();
    ?>
    <div class="sapp-hello">
      <h1><?= e($hello) ?></h1>
      <p><?= e(staff_app_role_label($role)) ?></p>
    </div>
    <div class="sapp-profile">
      <p style="margin:0;font-weight:800">همین حساب برای وقت‌ها، اتاق‌ها، چت، درخواست مشاوره، کارهای روزانه و شکایات است.</p>
      <a class="sapp-logout" href="<?= e(staff_app_logout_href()) ?>">خروج</a>
    </div>
    <?php
    staff_app_render('profile', 'پروفایل', $descriptions['profile'], ob_get_clean());
    exit;
}

require_once __DIR__ . '/../includes/session_complaints.php';
$profileId = $role === 'DOCTOR' ? staff_app_doctor_profile_id($pdo, $userId) : '';
$complaints = [];
$patients = [];
$doctors = [];
try {
    if ($role === 'DOCTOR' && $profileId === '') {
        $complaints = [];
    } else {
        $complaints = session_complaint_list($pdo, $role === 'DOCTOR' ? $profileId : null);
    }
} catch (Throwable $e) {
    $complaints = [];
}
if ($role === 'SECRETARY') {
    require_once __DIR__ . '/../includes/secretary_patient.php';
    try {
        $patients = secretary_bookable_patients($pdo);
    } catch (Throwable $e) {
        $patients = [];
    }
    try {
        $doctors = secretary_active_doctors($pdo);
    } catch (Throwable $e) {
        $doctors = [];
    }
}
ob_start();
?>
<h1>شکایت‌ها</h1>
<p class="muted"><?= $role === 'SECRETARY' ? 'اگر مراجع بعد از جلسه از درمانگر شکایت داشت، همین‌جا ثبت کنید.' : 'شکایت‌هایی که درباره جلسه‌های شما ثبت شده است.' ?></p>
<?php if ($role === 'SECRETARY'): ?>
  <form class="form-stack panel" method="post" action="<?= e(url('/app/complaints')) ?>" style="margin-top:1rem">
    <?= csrf_field() ?>
    <div>
      <label class="label" for="complaint-patient">اسم مراجعه‌کننده</label>
      <select class="input" id="complaint-patient" name="patient_id" required>
        <option value="">انتخاب مراجعه‌کننده</option>
        <?php foreach ($patients as $row): ?>
          <?php
            $pname = trim((string) ($row['name'] ?? ''));
            $uname = trim((string) ($row['username'] ?? ''));
            $label = $pname !== '' ? $pname : $uname;
          ?>
          <option value="<?= e((string) $row['id']) ?>"><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label class="label" for="complaint-doctor">درمانگر</label>
      <select class="input" id="complaint-doctor" name="doctor_id" required>
        <option value="">انتخاب درمانگر</option>
        <?php foreach ($doctors as $row): ?>
          <option value="<?= e((string) $row['id']) ?>"><?= e(trim((string) ($row['name'] ?? 'درمانگر'))) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label class="label" for="complaint-session-date">زمان جلسه</label>
      <div style="display:grid;grid-template-columns:minmax(0,1fr) 8.5rem;gap:.5rem">
        <input class="input" id="complaint-session-date" name="session_date" type="text" data-jdp data-jdp-only-date autocomplete="off" readonly required placeholder="تاریخ شمسی">
        <input class="input" id="complaint-session-time" name="session_time" type="time" required dir="ltr" aria-label="ساعت جلسه">
      </div>
    </div>
    <div>
      <label class="label" for="complaint-reason">علت</label>
      <select class="input" id="complaint-reason" name="reason" required>
        <option value="">انتخاب علت</option>
        <?php foreach (session_complaint_reasons() as $reason): ?>
          <option value="<?= e($reason) ?>"><?= e($reason) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label class="label" for="complaint-body">توضیحات</label>
      <textarea class="input" id="complaint-body" name="body" rows="5" required></textarea>
    </div>
    <button class="btn btn-primary" type="submit">ثبت شکایت</button>
  </form>
<?php endif; ?>
<div class="sapp-list">
  <h2 class="sapp-day">ثبت‌شده‌ها</h2>
  <?php if ($complaints === []): ?>
    <p class="muted">شکایتی ثبت نشده است.</p>
  <?php endif; ?>
  <?php foreach ($complaints as $row): ?>
    <article class="sapp-row">
      <strong><?= e((string) ($row['patient_name'] ?? '')) ?></strong>
      <span class="muted"> · <?= e((string) ($row['doctor_name'] ?? '')) ?></span>
      <small>
        <?php if (!empty($row['session_at'])): ?>جلسه <?= e(format_fa_datetime((string) $row['session_at'])) ?> · <?php endif; ?>
        <?php if (trim((string) ($row['reason'] ?? '')) !== ''): ?><?= e((string) $row['reason']) ?> · <?php endif; ?>
        ثبت <?= e(format_fa_datetime((string) $row['created_at'])) ?>
      </small>
      <p style="margin:.55rem 0 0;white-space:pre-wrap"><?= e((string) ($row['body'] ?? '')) ?></p>
    </article>
  <?php endforeach; ?>
</div>
<?php
if ($role === 'SECRETARY') {
    $GLOBALS['pageHead'] = '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@majidh1/jalalidatepicker/dist/jalalidatepicker.min.css">';
    $GLOBALS['pageScripts'] = '<script src="' . e(url('/assets/js/search-select.js')) . '?v=20261007cmp"></script>'
        . '<script src="https://cdn.jsdelivr.net/npm/@majidh1/jalalidatepicker/dist/jalalidatepicker.min.js"></script>'
        . '<script>["complaint-patient","complaint-doctor","complaint-reason"].forEach(function(id){var el=document.getElementById(id);if(el&&window.enhanceSearchSelect)enhanceSearchSelect(el);});if(window.jalaliDatepicker){jalaliDatepicker.startWatch({selector:"#complaint-session-date",time:false,hideAfterChange:true,showTodayBtn:true,showEmptyBtn:true,autoReadOnlyInput:true,persianDigits:true,zIndex:100000,container:"body"});}</script>';
}
staff_app_render('complaints', 'شکایت‌ها', $descriptions['complaints'], ob_get_clean());
