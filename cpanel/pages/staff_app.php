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
        $watchRoom = staff_app_ready($pdo) ? staff_app_room_for_viewer($pdo, $m[1], $userId) : null;
        if (!$watchRoom && staff_app_is_eshahabian($user)) {
            $watchRoom = staff_app_room_archived($pdo, $m[1]);
        }
        if (!$watchRoom) {
            http_response_code(404);
            echo json_encode(['ok' => false], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $after = trim((string) ($_GET['after'] ?? ''));
        $live = staff_app_live_messages($pdo, $m[1], $userId, $after);
        if (empty($watchRoom['observe_only'])) {
            staff_app_mark_read($pdo, $userId, $m[1]);
        }
        staff_app_touch_presence($pdo, $userId);
        $reactions = staff_app_reaction_overview($pdo, $m[1], $userId);
        echo json_encode([
            'ok' => true,
            'states' => staff_app_own_receipts($pdo, $m[1], $userId),
            'seen' => staff_app_seen_map($pdo, $m[1]),
            'messages' => $live,
            'edits' => staff_app_live_edits($pdo, $m[1]),
            'removed' => staff_app_recent_removals($pdo, $m[1]),
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
            $roomId = staff_app_create_room($pdo, $user, post('title'), $members, post('is_private') === '1');
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
        if ($form === 'delete_room') {
            staff_app_delete_room($pdo, $user, $roomId);
            flash_set('success', staff_app_is_eshahabian($user) ? 'چت حذف شد و در آرشیو ماند.' : 'چت حذف شد.');
            redirect('/app/chat');
        }
        if ($form === 'keep_room') {
            staff_app_keep_room($pdo, $user, $roomId);
            flash_set('success', 'چت بایگانی شد و برای همیشه می‌ماند.');
            redirect('/app/chat/archive');
        }
        if ($form === 'edit') {
            $edited = staff_app_edit_message($pdo, $user, $roomId, post('message_id'), post('body'));
            if ($ajax) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => true, 'message' => $edited], JSON_UNESCAPED_UNICODE);
                exit;
            }
            redirect($back);
        }
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
        $newId = staff_app_send_message($pdo, $user, $roomId, post('body'), $_FILES['file'] ?? null, post('reply_to'), post('voice') === '1');
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
    $back = '/app/checklist?date=' . rawurlencode($ymd) . '&secretary=' . rawurlencode($userId);
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

if ($method === 'POST' && $path === '/app/checklist/emad') {
    require_once __DIR__ . '/../includes/secretary_daily_tasks.php';
    staff_app_emad_tasks_handle_post($pdo, $user);
}

if ($method === 'POST' && $path === '/app/checklist/assign') {
    require_once __DIR__ . '/../includes/secretary_daily_tasks.php';
    secretary_assigned_tasks_handle_create($pdo, $user, '/app/checklist');
}

if ($method === 'POST' && $path === '/app/petty-cash') {
    require_once __DIR__ . '/../includes/petty_cash.php';
    require_once __DIR__ . '/../includes/staff_desk.php';
    csrf_verify();
    $jy = (int) post('jy');
    $jm = (int) post('jm');
    $back = '/app/petty-cash?jy=' . $jy . '&jm=' . $jm;
    if (!petty_cash_staff_app_allowed($user)) {
        flash_set('error', 'دسترسی به تنخواه مجاز نیست.');
        redirect('/app');
    }
    try {
        flash_set('success', petty_cash_apply_post($pdo, $user));
    } catch (RuntimeException $e) {
        flash_set('error', $e->getMessage());
    }
    redirect($back);
}

if ($method === 'POST' && $path === '/app/therapists') {
    if ($role !== 'SECRETARY') {
        flash_set('error', 'فهرست درمانگرها با منشی است.');
        redirect('/app');
    }
    $_POST['next'] = '/app/therapists';
    require __DIR__ . '/../actions/secretary_therapists.php';
    exit;
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
        if (post('hour_only') === '1') {
            $hourEnd = clinic_rooms_hour_end((string) post('start_time'));
            if ($hourEnd === null) {
                throw new RuntimeException('ساعت را از فهرست انتخاب کنید.');
            }
            $_POST['end_time'] = $hourEnd;
        }
        if (post('action') === 'release_hour') {
            $day = clinic_rooms_release_hour($pdo, (string) post('booking_id'), (string) post('slice_start'));
            flash_set('success', 'همان یک ساعت آزاد شد.');
        } elseif (post('action') === 'release') {
            $day = clinic_rooms_release($pdo, post('booking_id'));
            flash_set('success', 'ساعت اتاق آزاد شد.');
        } elseif (post('action') === 'update') {
            $saved = clinic_rooms_update(
                $pdo,
                post('booking_id'),
                (int) post('room_no'),
                post('purpose'),
                post('start_time'),
                post('end_time'),
                post('patient_id'),
                post('doctor_id'),
                post('workshop_session_id'),
                post('block_title'),
                post('note'),
                post('apply_series') === '1',
                post('booking_day')
            );
            $count = (int) ($saved['count'] ?? 1);
            flash_set('success', $count > 1
                ? 'ویرایش روی ' . to_fa_digits((string) $count) . ' هفتهٔ این تکرار ذخیره شد.'
                : 'رزرو ویرایش شد.');
            $day = (string) ($saved['day'] ?? $day);
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
        flash_set('error', 'ثبت انتقاد و پیشنهاد در برنامه، با منشی است.');
        redirect('/app/complaints');
    }
    try {
        session_complaint_create(
            $pdo,
            $userId,
            trim((string) post('patient_id')),
            trim((string) post('kind')),
            trim(trim((string) post('session_date')) . ' ' . trim((string) post('session_time'))),
            (string) post('reason'),
            (string) post('body')
        );
    } catch (RuntimeException $e) {
        flash_set('error', $e->getMessage());
        redirect('/app/complaints');
    } catch (Throwable $e) {
        flash_set('error', 'ثبت انجام نشد.');
        redirect('/app/complaints');
    }
    $kind = session_feedback_kind(trim((string) post('kind')));
    flash_set('success', ($kind === 'suggestion' ? 'پیشنهاد' : 'انتقاد') . ' ثبت شد.');
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
    '/app/chat', '/app/chat/archive' => 'chat',
    '/app/hours' => 'hours',
    '/app/consult' => 'consult',
    '/app/checklist' => 'checklist',
    '/app/petty-cash' => 'petty',
    '/app/complaints' => 'complaints',
    '/app/therapists' => 'therapists',
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
    'consult' => 'درخواست مشاوره و درخواست همکاری که از فرم سایت مانا کلینیک رسیده است.',
    'checklist' => 'لیست کارهای روزانه منشی‌ها در کلینیک مانا، همان فهرست داخل سایت.',
    'petty' => 'تنخواه کلینیک مانا؛ ثبت هزینه، حساب پرداخت و تاییدکننده، همان فهرست داخل سایت.',
    'complaints' => 'ثبت انتقاد یا پیشنهاد مراجع؛ برای دکتر شیوا گرانمایه‌پور و دکتر عطیه گارسچی.',
    'therapists' => 'درمانگرهای کلینیک مانا؛ فعال و غیرفعال، و درخواست‌های همکاری.',
    'profile' => 'پروفایل درمانگر یا منشی در برنامه داخلی مانا کلینیک.',
];

if ($section === 'home') {
    $hello = $name !== '' ? $name : staff_app_role_label($role);
    if (!function_exists('collaboration_request_new_count')) {
        require_once __DIR__ . '/../includes/collaboration.php';
    }
    $consultOpen = (function_exists('consult_request_new_count') ? consult_request_new_count() : 0)
        + (function_exists('collaboration_request_new_count') ? collaboration_request_new_count() : 0);
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
        <span class="sapp-ico sapp-ico-teal sapp-consult-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M8.2 4.8h2.1a1 1 0 0 1 1 .76l.5 2.4a1 1 0 0 1-.5 1.05l-1.35.7a7.6 7.6 0 0 0 3.74 3.74l.7-1.35a1 1 0 0 1 1.05-.5l2.4.5a1 1 0 0 1 .76 1V15.4A1.6 1.6 0 0 1 17 17 11.2 11.2 0 0 1 7 7a1.6 1.6 0 0 1 1.2-2.2z"/></svg><?php if ($consultOpen > 0): ?><span class="sapp-consult-badge"><?= e(to_fa_digits((string) $consultOpen)) ?></span><?php endif; ?></span>
        <strong>درخواست مشاوره/درخواست همکاری</strong>
        <em>Consult &amp; Collaboration</em>
      </a>
      <?php if ($role === 'SECRETARY'): ?>
      <a class="sapp-tile" href="<?= e(url('/app/therapists')) ?>">
        <span class="sapp-ico sapp-ico-blue"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="8" r="2.4"/><circle cx="16" cy="9" r="2"/><path d="M4.8 18.2a4.4 4.4 0 0 1 8.4 0M13.2 18.2a3.6 3.6 0 0 1 6-2.2"/></svg></span>
        <strong>درمانگرها</strong>
        <em>Therapists</em>
      </a>
      <?php endif; ?>
      <a class="sapp-tile" href="<?= e(url('/app/checklist')) ?>">
        <span class="sapp-ico sapp-ico-pink"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><rect x="5" y="4" width="14" height="16" rx="3"/><path d="m8.5 12 2.2 2.2 4.8-5"/></svg></span>
        <strong>لیست کارهای روزانه</strong>
        <em>Daily Tasks</em>
      </a>
      <?php if (!function_exists('petty_cash_staff_app_allowed')) { require_once __DIR__ . '/../includes/petty_cash.php'; } ?>
      <?php if (petty_cash_staff_app_allowed($user)): ?>
      <a class="sapp-tile" href="<?= e(url('/app/petty-cash')) ?>">
        <span class="sapp-ico sapp-ico-amber"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><rect x="3.5" y="6" width="17" height="13" rx="2"/><path d="M3.5 10h17"/><path d="M16 15h.01"/></svg></span>
        <strong>تنخواه</strong>
        <em>Petty Cash</em>
      </a>
      <?php endif; ?>
      <a class="sapp-tile" href="<?= e(url('/app/complaints')) ?>">
        <span class="sapp-ico sapp-ico-red"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M12 4.5 20 19H4L12 4.5z"/><path d="M12 10v4.2"/><path d="M12 16.8h.01"/></svg></span>
        <strong>انتقادات و پیشنهادات</strong>
        <em>Feedback</em>
      </a>
    </div>
    <?php
    staff_app_render('home', 'Mana Staff', $descriptions['home'], ob_get_clean());
    exit;
}

if ($section === 'therapists') {
    if ($role !== 'SECRETARY') {
        flash_set('error', 'فهرست درمانگرها با منشی است.');
        redirect('/app');
    }
    require_once __DIR__ . '/../includes/collaboration.php';
    $rows = $pdo->query("
      SELECT u.id, u.name, u.phone, u.is_disabled, dp.id AS profile_id, dp.specialty, dp.is_active, dp.is_approved
      FROM users u
      LEFT JOIN doctor_profiles dp ON dp.user_id = u.id
      WHERE u.role = 'DOCTOR'
      ORDER BY u.name ASC
    ")->fetchAll() ?: [];
    $requests = collaboration_requests($pdo);
    ob_start();
    ?>
    <h1>درمانگرها</h1>
    <p class="muted">فعال یعنی در نوبت‌دهی دیده می‌شود. با فعال شدن، پروفایل درمانگر ساخته می‌شود.</p>
    <div class="sapp-list">
      <?php foreach ($rows as $row): ?>
        <?php
          $on = (int) ($row['is_disabled'] ?? 0) === 0
              && (string) ($row['profile_id'] ?? '') !== ''
              && (int) ($row['is_active'] ?? 0) === 1
              && (int) ($row['is_approved'] ?? 0) === 1;
        ?>
        <article class="sapp-row">
          <div style="display:flex;justify-content:space-between;gap:.6rem;align-items:center">
            <div>
              <strong><?= e((string) $row['name']) ?></strong>
              <div><?= e(trim((string) ($row['specialty'] ?? '')) !== '' ? (string) $row['specialty'] : 'بدون تخصص') ?></div>
              <small><?= $on ? 'فعال' : 'غیرفعال' ?></small>
            </div>
            <form method="post" action="<?= e(url('/app/therapists')) ?>" style="margin:0">
              <?= csrf_field() ?>
              <input type="hidden" name="user_id" value="<?= e((string) $row['id']) ?>">
              <input type="hidden" name="active" value="<?= $on ? '0' : '1' ?>">
              <button class="btn btn-outline btn-sm" type="submit"><?= $on ? 'غیرفعال' : 'فعال' ?></button>
            </form>
          </div>
        </article>
      <?php endforeach; ?>
      <?php if (!$rows): ?>
        <p class="muted">درمانگری ثبت نشده است.</p>
      <?php endif; ?>
    </div>
    <h2 class="sapp-day">درخواست‌های همکاری</h2>
    <?php if (!$requests): ?>
      <p class="muted">درخواستی نرسیده است.</p>
    <?php else: ?>
      <div class="sapp-list">
        <?php foreach ($requests as $request): ?>
          <article class="sapp-row">
            <strong><?= e((string) $request['name']) ?></strong>
            <div><?= e((string) $request['specialty']) ?> · <?= e((string) $request['phone']) ?></div>
            <small><?= e((string) $request['message']) ?></small>
          </article>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
    <?php
    staff_app_render('therapists', 'درمانگرها', $descriptions['therapists'], ob_get_clean());
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
    if ($role === 'SECRETARY' && !function_exists('appointment_time_edit_form_html')) {
        require_once __DIR__ . '/../includes/appointment_session.php';
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
      <?php
        if (!function_exists('appointment_debts_html')) {
            require_once __DIR__ . '/../includes/appointment_cancel.php';
        }
        echo appointment_debts_html($pdo);
      ?>
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
              <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:.5rem">
                <span><?= e($whoLine) ?></span>
                <?php if (!$roomOnly): ?>
                  <details class="name-more">
                    <summary aria-label="توضیحات بیشتر">⋯</summary>
                    <div class="panel" style="margin-top:.45rem;font-size:.82rem;line-height:1.7">
                      <?php $phone = trim((string) ($row['patient_phone'] ?? '')); ?>
                      <div>تلفن: <?= $phone !== '' ? e($phone) : 'ثبت نشده' ?></div>
                      <div><?= e($detail) ?></div>
                      <?php if ((int) ($row['amount'] ?? 0) > 0): ?>
                        <div>مبلغ: <?= e(format_price((int) $row['amount'])) ?>
                          <?php if ((string) ($row['pay_ref'] ?? '') === 'LATER'): ?> · پرداخت بعدی<?php elseif ((string) ($row['pay_status'] ?? '') === 'PAID'): ?> · پرداخت شده<?php endif; ?>
                        </div>
                      <?php endif; ?>
                      <?php $note = trim((string) ($row['notes'] ?? '')); ?>
                      <?php if ($note !== ''): ?><div>یادداشت: <?= e($note) ?></div><?php endif; ?>
                    </div>
                  </details>
                <?php endif; ?>
              </div>
              <small><?= e($detail) ?><?php if (!$roomOnly && $roomNo < 1 && (string) ($row['session_mode'] ?? '') !== 'ONLINE'): ?> · اتاق هنوز انتخاب نشده<?php endif; ?></small>
              <?php if ($role === 'SECRETARY' && !$roomOnly && trim((string) ($row['id'] ?? '')) !== '' && function_exists('appointment_time_edit_form_html')): ?>
                <details class="appt-one-edit">
                  <summary class="btn btn-outline btn-sm">ویرایش</summary>
                  <div style="margin-top:.55rem">
                    <?= appointment_time_edit_form_html($row, '/app/appointments') ?>
                  </div>
                </details>
              <?php endif; ?>
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
    if (function_exists('clinic_rooms_hour_slices')) {
        foreach ($byRoom as $number => $items) {
            $slices = [];
            foreach ($items as $row) {
                foreach (clinic_rooms_hour_slices($row) as $slice) {
                    $slices[] = $slice;
                }
            }
            usort($slices, static fn(array $a, array $b): int => strcmp((string) ($a['slice_start'] ?? ''), (string) ($b['slice_start'] ?? '')));
            $byRoom[$number] = $slices;
        }
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
    jalaliDatepicker.startWatch({selector:"[data-jdp]",time:false,hideAfterChange:true,showTodayBtn:true,showEmptyBtn:false,autoReadOnlyInput:true,persianDigits:true,zIndex:100000,container:"body"});
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
    function fillSelects(){
      form.querySelectorAll("select[data-search]").forEach(function(sel){
        if(sel.getAttribute("data-filled")==="1") return;
        var key=sel.getAttribute("data-options");
        var tpl=key?document.getElementById("room-opts-"+key):null;
        if(tpl&&tpl.content){
          var have={};
          Array.prototype.forEach.call(sel.options,function(opt){ if(opt.value) have[opt.value]=1; });
          var frag=tpl.content.cloneNode(true);
          Array.prototype.forEach.call(frag.querySelectorAll("option"),function(opt){
            if(opt.value&&have[opt.value]) opt.parentNode.removeChild(opt);
          });
          var current=sel.value;
          sel.appendChild(frag);
          if(current) sel.value=current;
        }
        sel.setAttribute("data-filled","1");
        if(window.enhanceSearchSelect) enhanceSearchSelect(sel);
      });
    }
    var details=form.closest("details");
    if(details&&!details.open){
      details.addEventListener("toggle",function(){ if(details.open) fillSelects(); });
    }else{
      form.addEventListener("focusin",function(){ fillSelects(); },{once:true});
      form.addEventListener("pointerdown",function(){ fillSelects(); },{once:true});
    }
    sync();
    syncRepeat();
  });
})();
</script>';
    }
    $roomOpt = ['patient_id' => '', 'doctor_id' => '', 'workshop_session_id' => ''];
    if ($role === 'SECRETARY') {
        foreach ($roomPatients as $person) {
            $roomOpt['patient_id'] .= '<option value="' . e((string) $person['id']) . '">' . e((string) $person['name']) . '</option>';
        }
        foreach ($roomDoctors as $doctor) {
            $roomOpt['doctor_id'] .= '<option value="' . e((string) $doctor['id']) . '">' . e((string) $doctor['name']) . '</option>';
        }
        foreach ($openWorkshops as $sess) {
            $workshopLabel = trim((string) ($sess['workshop_title'] ?? 'کارگاه')) . ' · ' . trim((string) ($sess['doctor_name'] ?? ''));
            $roomOpt['workshop_session_id'] .= '<option value="' . e((string) $sess['id']) . '">' . e($workshopLabel) . '</option>';
        }
    }
    ob_start();
    ?>
    <h1>شرایط اتاق‌ها</h1>
    <p class="muted"><?= $role === 'SECRETARY' ? 'روز را انتخاب کنید و اتاق را رزرو کنید. اگر رزرو اشتباه بود، از همان کارت ویرایش کنید.' : 'وضعیت اتاق‌ها در روز انتخاب‌شده.' ?></p>
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
      <template id="room-opts-patient_id"><?= $roomOpt['patient_id'] ?></template>
      <template id="room-opts-doctor_id"><?= $roomOpt['doctor_id'] ?></template>
      <template id="room-opts-workshop_session_id"><?= $roomOpt['workshop_session_id'] ?></template>
      <?php foreach ($byRoom as $number => $items): ?>
        <h2 class="sapp-day"><?= e(function_exists('clinic_room_label') ? clinic_room_label((int) $number) : ('اتاق ' . to_fa_digits((string) $number))) ?></h2>
        <?php if ($items === []): ?>
          <p class="muted">در این روز رزروی نیست.</p>
        <?php else: ?>
          <div class="sapp-list">
            <?php foreach ($items as $row): ?>
              <?php
                $now = time();
                $start = strtotime((string) ($row['slice_start'] ?? $row['starts_at'] ?? '')) ?: 0;
                $end = strtotime((string) ($row['slice_end'] ?? $row['ends_at'] ?? '')) ?: 0;
                $wholeStart = strtotime((string) ($row['starts_at'] ?? '')) ?: $start;
                $wholeEnd = strtotime((string) ($row['ends_at'] ?? '')) ?: $end;
                $oneHour = ($wholeEnd - $wholeStart) <= 3700;
                $state = ($now >= $start && $now < $end) ? 'الان' : ($start > $now ? 'رزرو شده' : 'تمام شده');
                $purposeText = function_exists('clinic_room_purpose') ? clinic_room_purpose($row) : trim((string) ($row['title'] ?? ''));
              ?>
              <article class="sapp-row">
                <strong><?= e($state) ?> · <?= e(format_fa_time(date('Y-m-d H:i:s', $start))) ?> تا <?= e(format_fa_time(date('Y-m-d H:i:s', $end))) ?></strong>
                <div><?= e($purposeText !== '' ? $purposeText : 'بدون توضیح') ?></div>
                <?php if ($role === 'SECRETARY' && $end >= $now): ?>
                  <?php
                    $editKind = (string) ($row['kind'] ?? '');
                    $editPurpose = $editKind === 'WORKSHOP' ? 'workshop' : ($editKind === 'APPOINTMENT' ? 'therapy' : 'block');
                    $editStart = date('H:i', $start ?: time());
                    $editEnd = date('H:i', $end ?: time());
                    $editId = 'room-edit-' . preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($row['id'] ?? ''));
                    $editPatientId = trim((string) ($row['patient_id'] ?? ''));
                    if ($editPatientId === '') {
                        $editPatientId = trim((string) ($row['appt_patient_id'] ?? ''));
                    }
                    $editDoctorId = trim((string) ($row['doctor_id'] ?? ''));
                    if ($editDoctorId === '') {
                        $editDoctorId = trim((string) ($row['appt_doctor_id'] ?? ''));
                    }
                    $editSessionId = trim((string) ($row['workshop_session_id'] ?? ''));
                  ?>
                  <div style="display:flex;gap:.45rem;flex-wrap:wrap;align-items:flex-start;margin-top:.55rem">
                    <?php if ($oneHour): ?>
                    <details class="room-edit">
                      <summary class="btn btn-outline btn-sm">ویرایش</summary>
                      <form class="panel" method="post" action="<?= e(url('/app/rooms')) ?>" data-room-book style="margin-top:.7rem;display:flex;flex-direction:column;gap:.55rem">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="update">
                        <input type="hidden" name="hour_only" value="1">
                        <input type="hidden" name="booking_id" value="<?= e((string) ($row['id'] ?? '')) ?>">
                        <input type="hidden" name="day" value="<?= e($roomDay) ?>">
                        <label class="label">اتاق
                          <select class="input" name="room_no">
                            <?php foreach ($numbers as $editRoom): ?>
                              <option value="<?= (int) $editRoom ?>" <?= (int) ($row['room_no'] ?? 0) === (int) $editRoom ? 'selected' : '' ?>><?= e(function_exists('clinic_room_label') ? clinic_room_label((int) $editRoom) : ('اتاق ' . to_fa_digits((string) $editRoom))) ?></option>
                            <?php endforeach; ?>
                          </select>
                        </label>
                        <label class="label">تاریخ
                          <input class="input" type="text" name="booking_day" data-jdp data-jdp-only-date readonly autocomplete="off" required style="cursor:pointer" value="<?= e(function_exists('clinic_rooms_jalali_input') ? clinic_rooms_jalali_input(substr((string) ($row['starts_at'] ?? ''), 0, 10)) : '') ?>">
                        </label>
                        <label class="label">ساعت
                          <select class="input" name="start_time" required>
                            <?php foreach ($clockChoices as $clock): ?>
                              <?php if (!str_ends_with($clock, ':00')) { continue; } ?>
                              <option value="<?= e($clock) ?>"<?= $editStart === $clock ? ' selected' : '' ?>><?= e(to_fa_digits($clock)) ?></option>
                            <?php endforeach; ?>
                          </select>
                        </label>
                        <label class="label">برای چیست؟
                          <select class="input" name="purpose" data-purpose>
                            <option value="therapy" <?= $editPurpose === 'therapy' ? 'selected' : '' ?>>تراپی</option>
                            <option value="workshop" <?= $editPurpose === 'workshop' ? 'selected' : '' ?>>کارگاه</option>
                            <option value="block" <?= $editPurpose === 'block' ? 'selected' : '' ?>>سایر</option>
                          </select>
                        </label>
                        <div data-for="therapy" <?= $editPurpose === 'therapy' ? '' : 'hidden' ?>>
                          <label class="label">مراجعه‌کننده
                            <select class="input" name="patient_id" data-search data-options="patient_id" id="<?= e($editId) ?>-patient">
                              <option value="">انتخاب مراجعه‌کننده</option>
                              <?php if ($editPatientId !== ''): ?>
                                <option value="<?= e($editPatientId) ?>" selected><?= e(trim((string) ($row['patient_name'] ?? '')) !== '' ? (string) $row['patient_name'] : 'مراجعه‌کننده فعلی') ?></option>
                              <?php endif; ?>
                            </select>
                          </label>
                          <label class="label">درمانگر
                            <select class="input" name="doctor_id" data-search data-options="doctor_id" id="<?= e($editId) ?>-doctor">
                              <option value="">انتخاب درمانگر</option>
                              <?php if ($editDoctorId !== ''): ?>
                                <option value="<?= e($editDoctorId) ?>" selected><?= e(trim((string) ($row['doctor_name'] ?? '')) !== '' ? (string) $row['doctor_name'] : 'درمانگر فعلی') ?></option>
                              <?php endif; ?>
                            </select>
                          </label>
                        </div>
                        <div data-for="workshop" <?= $editPurpose === 'workshop' ? '' : 'hidden' ?>>
                          <label class="label">کارگاه
                            <select class="input" name="workshop_session_id" data-search data-options="workshop_session_id" id="<?= e($editId) ?>-workshop">
                              <option value="">انتخاب کارگاه</option>
                              <?php if ($editSessionId !== ''): ?>
                                <option value="<?= e($editSessionId) ?>" selected><?= e(trim((string) ($row['workshop_title'] ?? $row['title'] ?? '')) !== '' ? (string) ($row['workshop_title'] ?? $row['title']) : 'کارگاه فعلی') ?></option>
                              <?php endif; ?>
                            </select>
                          </label>
                        </div>
                        <div data-for="block" <?= $editPurpose === 'block' ? '' : 'hidden' ?>>
                          <label class="label">عنوان<input class="input" name="block_title" maxlength="255" value="<?= e($editPurpose === 'block' ? (string) ($row['title'] ?? '') : '') ?>" placeholder="مثلاً جلسه تیم"></label>
                        </div>
                        <label class="label">یادداشت<input class="input" name="note" maxlength="255" value="<?= e((string) ($row['note'] ?? '')) ?>"></label>
                        <?php if (trim((string) ($row['series_id'] ?? '')) !== ''): ?>
                          <label style="display:flex;gap:8px;align-items:center">
                            <input type="checkbox" name="apply_series" value="1">
                            <span>همین تغییر، از جمله تاریخ، برای هفته‌های بعدی این تکرار</span>
                          </label>
                        <?php endif; ?>
                        <button class="btn btn-primary btn-sm" type="submit">ذخیره ویرایش</button>
                      </form>
                    </details>
                    <?php endif; ?>
                    <form method="post" action="<?= e(url('/app/rooms')) ?>" style="margin:0">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="release_hour">
                      <input type="hidden" name="day" value="<?= e($roomDay) ?>">
                      <input type="hidden" name="booking_id" value="<?= e((string) ($row['id'] ?? '')) ?>">
                      <input type="hidden" name="slice_start" value="<?= e(date('Y-m-d H:i:s', $start)) ?>">
                      <button class="btn btn-outline btn-sm" type="submit">آزاد کردن این ساعت</button>
                    </form>
                  </div>
                <?php endif; ?>
              </article>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
        <?php if ($role === 'SECRETARY' && $error === ''): ?>
          <form class="sapp-compose panel" method="post" action="<?= e(url('/app/rooms')) ?>" data-room-book>
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="assign">
            <input type="hidden" name="hour_only" value="1">
            <input type="hidden" name="day" value="<?= e($roomDay) ?>">
            <input type="hidden" name="room_no" value="<?= (int) $number ?>">
            <strong>رزرو <?= e(function_exists('clinic_room_label') ? clinic_room_label((int) $number) : '') ?></strong>
            <label class="label">ساعت
              <select class="input" name="start_time" required>
                <option value="">یک ساعت را انتخاب کنید</option>
                <?php foreach ($clockChoices as $clock): ?>
                  <?php if (!str_ends_with($clock, ':00')) { continue; } ?>
                  <option value="<?= e($clock) ?>"><?= e(to_fa_digits($clock)) ?> تا <?= e(to_fa_digits(sprintf('%02d:00', ((int) substr($clock, 0, 2)) + 1))) ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <p class="muted" style="margin:0;font-size:.8rem">هر رزرو فقط یک ساعت است و از ساعت کناری جدا می‌ماند.</p>
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
                <select class="input" name="patient_id" data-search data-options="patient_id">
                  <option value="">انتخاب مراجعه‌کننده</option>
                </select>
              </label>
              <label class="label">درمانگر
                <select class="input" name="doctor_id" data-search data-options="doctor_id">
                  <option value="">انتخاب درمانگر</option>
                </select>
              </label>
            </div>
            <div data-for="workshop" hidden>
              <label class="label">کارگاه
                <select class="input" name="workshop_session_id" data-search data-options="workshop_session_id">
                  <option value="">انتخاب کارگاه</option>
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
        if ($path === '/app/chat/archive') {
            if (!staff_app_is_eshahabian($user)) {
                redirect('/app/chat');
            }
            $archived = staff_app_archive_rooms($pdo);
            ob_start();
            ?>
            <p style="margin:0 0 8px"><a href="<?= e(url('/app/chat')) ?>">همه چت‌ها</a></p>
            <h1>آرشیو</h1>
            <p class="muted">چت‌هایی که کسی حذف کرده یک ماه اینجا می‌مانند. شمارش معکوس فقط برای شماست. بایگانی یعنی همان چت برای همیشه بماند.</p>
            <?php if ($archived === []): ?>
              <p class="sapp-row">هنوز چیزی در آرشیو نیست.</p>
            <?php endif; ?>
            <div class="sapp-list">
              <?php foreach ($archived as $room): ?>
                <?php $kept = !empty($room['kept_forever']); ?>
                <div class="sapp-row">
                  <a href="<?= e(url('/app/chat/' . (string) $room['id'])) ?>" style="text-decoration:none;color:inherit;display:block">
                    <strong><?= e((string) ($room['title'] ?? 'چت')) ?></strong>
                    <small><?= e(staff_app_archive_countdown($room)) ?> · <?= e(to_fa_digits((string) (int) ($room['message_count'] ?? 0))) ?> پیام</small>
                  </a>
                  <?php if (!$kept): ?>
                    <form method="post" action="<?= e(url('/app/chat')) ?>" style="margin-top:8px" onsubmit="return confirm('این چت برای همیشه بایگانی شود؟');">
                      <?= csrf_field() ?>
                      <input type="hidden" name="form" value="keep_room">
                      <input type="hidden" name="room_id" value="<?= e((string) $room['id']) ?>">
                      <button class="btn btn-outline btn-sm" type="submit">بایگانی</button>
                    </form>
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>
            </div>
            <?php
            staff_app_render('chat', 'آرشیو چت', 'آرشیو چت‌های حذف‌شده و بایگانی‌شده.', ob_get_clean());
            exit;
        }
        if ($roomId !== '') {
            $room = staff_app_room_for_viewer($pdo, $roomId, $userId);
            $fromArchive = false;
            if (!$room && staff_app_is_eshahabian($user)) {
                $room = staff_app_room_archived($pdo, $roomId);
                $fromArchive = $room !== null;
            }
            if (!$room) {
                flash_set('error', 'این اتاق برای شما نیست.');
                redirect('/app/chat');
            }
            $observeOnly = !empty($room['observe_only']) || $fromArchive;
            if (!$observeOnly) {
                staff_app_mark_read($pdo, $userId, $roomId);
            }
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
            $isGroup = !empty($room['is_general']) || count($peerIds) > ($observeOnly ? 2 : 1);
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
                if (!empty($forwardRoom['observe_only']) || (string) ($forwardRoom['id'] ?? '') === $roomId) {
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
                'observeOnly' => $observeOnly,
            ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
            ob_start();
            ?>
            <?php
              $watchedCount = count($peerIds) + ($observeOnly ? 0 : 1);
              $watchedDirect = empty($room['is_general']) && empty($room['is_private']) && $watchedCount === 2;
              $canDeleteChat = empty($room['is_general']) && empty($room['deleted_at']) && (!$observeOnly || (staff_app_is_eshahabian($user) && !$watchedDirect));
              $canKeepChat = $fromArchive && staff_app_is_eshahabian($user) && empty($room['kept_forever']);
            ?>
            <div class="sapp-thread-head">
              <h1 class="sapp-thread-title"><i class="sapp-dot" data-online="<?= e(implode(',', $peerIds)) ?>" hidden></i><span><?= e((string) ($room['title'] ?? 'چت')) ?></span></h1>
              <?php if ($canDeleteChat): ?>
                <form class="sapp-thread-delete" method="post" action="<?= e(url('/app/chat')) ?>" onsubmit="return confirm('این چت برای همه حذف شود؟');">
                  <?= csrf_field() ?>
                  <input type="hidden" name="form" value="delete_room">
                  <input type="hidden" name="room_id" value="<?= e($roomId) ?>">
                  <button class="btn btn-outline btn-sm" type="submit">حذف چت</button>
                </form>
              <?php endif; ?>
            </div>
            <?php if ($fromArchive): ?>
              <p class="muted" style="margin:0 0 8px"><?= e(staff_app_archive_countdown($room)) ?></p>
            <?php elseif ($observeOnly): ?>
              <p class="muted" style="margin:0 0 8px"><?= !empty($room['is_private']) ? 'خصوصی · فقط مشاهده' : 'فقط مشاهده' ?></p>
            <?php elseif (!empty($room['is_private'])): ?>
              <p class="muted" style="margin:0 0 8px">خصوصی — فقط اعضای گروه</p>
            <?php endif; ?>
            <?php if ($canKeepChat): ?>
              <form method="post" action="<?= e(url('/app/chat')) ?>" onsubmit="return confirm('این چت برای همیشه بایگانی شود؟');" style="margin:0 0 10px">
                <?= csrf_field() ?>
                <input type="hidden" name="form" value="keep_room">
                <input type="hidden" name="room_id" value="<?= e($roomId) ?>">
                <button class="btn btn-outline btn-sm" type="submit">بایگانی</button>
              </form>
            <?php endif; ?>
            <?php if ($pinned): ?>
              <a class="sapp-pin" href="#m-<?= e($pinnedId) ?>">
                <strong>سنجاق‌شده · <?= e((string) ($pinned['name'] ?? '')) ?></strong>
                <span><?= e($pinText) ?></span>
              </a>
            <?php endif; ?>
            <div class="sapp-chat<?= $isGroup ? ' is-group' : '' ?>" id="sapp-thread" data-group="<?= $isGroup ? '1' : '0' ?>" data-pinned="<?= e($pinnedId) ?>" data-observe="<?= $observeOnly ? '1' : '0' ?>">
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
                    <p class="sapp-msg-body"><?= e($bodyText) ?></p>
                  <?php endif; ?>
                  <?php foreach ($message['files'] ?? [] as $index => $file): ?>
                    <?php
                      $fileUrl = (string) ($fileMeta[$index]['url'] ?? '');
                      $mime = (string) ($file['mime'] ?? '');
                    ?>
                    <?php if (str_starts_with($mime, 'image/')): ?>
                      <a href="<?= e($fileUrl) ?>"><img src="<?= e($fileUrl) ?>" alt="<?= e((string) ($file['original_name'] ?? 'فایل')) ?>"></a>
                    <?php elseif (staff_app_is_audio_mime($mime)): ?>
                      <audio class="sapp-audio" controls preload="none" src="<?= e($fileUrl) ?>" aria-label="پیام صوتی"></audio>
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
                    <?php if (!empty($message['edited_at'])): ?>
                      <span class="sapp-edited">ویرایش شد</span>
                    <?php endif; ?>
                    <time><?= e(format_fa_time((string) ($message['created_at'] ?? ''))) ?></time>
                    <?php if ($mine): ?>
                      <?= staff_app_ticks_html((string) ($states[$msgId] ?? 'sent'), $msgId) ?>
                    <?php endif; ?>
                  </div>
                </article>
              <?php endforeach; ?>
            </div>
            <?php if (!$observeOnly): ?>
            <form class="sapp-compose" method="post" action="<?= e(url('/app/chat')) ?>" enctype="multipart/form-data">
              <?= csrf_field() ?>
              <input type="hidden" name="form" value="send">
              <input type="hidden" name="room_id" value="<?= e($roomId) ?>">
              <div class="sapp-reply" id="sapp-edit" hidden>
                <input type="hidden" id="sapp-edit-id" value="">
                <div>
                  <strong>ویرایش پیام</strong>
                  <span id="sapp-edit-snippet"></span>
                </div>
                <button type="button" id="sapp-edit-x" aria-label="لغو ویرایش">×</button>
              </div>
              <div class="sapp-reply" id="sapp-reply" hidden>
                <input type="hidden" name="reply_to" id="sapp-reply-id" value="">
                <div>
                  <strong id="sapp-reply-who"></strong>
                  <span id="sapp-reply-snippet"></span>
                </div>
                <button type="button" id="sapp-reply-x" aria-label="لغو پاسخ">×</button>
              </div>
              <p class="sapp-voice-hint" id="sapp-voice-hint" hidden></p>
              <div class="sapp-compose-row" id="sapp-compose-row">
                <label class="sapp-file" title="فایل">
                  <input id="chat-file" name="file" type="file" aria-label="فایل">
                  <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="m21 12-8.5 8.5a5.5 5.5 0 0 1-7.8-7.8L13.2 4.2a3.5 3.5 0 0 1 5 5L9.6 17.8a1.5 1.5 0 0 1-2.1-2.1l7.4-7.4"/></svg>
                </label>
                <button type="button" class="sapp-voice" id="sapp-voice" aria-pressed="false" aria-label="ضبط وویس">
                  <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="9" y="3" width="6" height="11" rx="3"/><path d="M6 11a6 6 0 0 0 12 0"/><path d="M12 17v3"/><path d="M8 20h8"/></svg>
                </button>
                <textarea class="input" id="chat-body" name="body" rows="1" maxlength="4000" placeholder="پیام" autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false" enterkeyhint="send"></textarea>
                <button class="sapp-send" type="submit" aria-label="ارسال">➤</button>
                <div class="sapp-voice-rec" id="sapp-voice-rec" hidden>
                  <div class="sapp-voice-live" id="sapp-voice-live">
                    <div class="sapp-voice-pill">
                      <span class="sapp-voice-dot" aria-hidden="true"></span>
                      <span class="sapp-voice-time" id="sapp-voice-time">۰:۰۰,۰۰</span>
                      <button type="button" class="sapp-voice-cancel" id="sapp-voice-cancel">لغو</button>
                    </div>
                    <div class="sapp-voice-stack">
                      <button type="button" class="sapp-voice-mini" id="sapp-voice-pause" aria-label="مکث">
                        <svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><rect x="7" y="6" width="3.2" height="12" rx="1" fill="currentColor"/><rect x="13.8" y="6" width="3.2" height="12" rx="1" fill="currentColor"/></svg>
                      </button>
                      <button type="button" class="sapp-voice-go" id="sapp-voice-up" aria-label="ارسال وویس">
                        <svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 19V6"/><path d="m6.5 11.5 5.5-5.5 5.5 5.5"/></svg>
                      </button>
                    </div>
                  </div>
                  <div class="sapp-voice-paused" id="sapp-voice-paused" hidden>
                    <button type="button" class="sapp-voice-trash" id="sapp-voice-trash" aria-label="حذف">
                      <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M4 7h16"/><path d="M9 7V5h6v2"/><path d="M8 7l1 13h6l1-13"/></svg>
                    </button>
                    <div class="sapp-voice-preview">
                      <button type="button" class="sapp-voice-play" id="sapp-voice-play" aria-label="پخش" aria-pressed="false">
                        <svg class="is-play" viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><path d="M9 7.2v9.6l8.2-4.8L9 7.2z" fill="currentColor"/></svg>
                        <svg class="is-pause" viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><rect x="7" y="6" width="3.2" height="12" rx="1" fill="currentColor"/><rect x="13.8" y="6" width="3.2" height="12" rx="1" fill="currentColor"/></svg>
                      </button>
                      <div class="sapp-voice-wave" id="sapp-voice-wave" aria-hidden="true"></div>
                      <span class="sapp-voice-dur" id="sapp-voice-dur">۰:۰۰</span>
                    </div>
                    <div class="sapp-voice-stack">
                      <button type="button" class="sapp-voice-mini" id="sapp-voice-resume" aria-label="ادامه ضبط">
                        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="9" y="3" width="6" height="11" rx="3"/><path d="M6 11a6 6 0 0 0 12 0"/><path d="M12 17v3"/><path d="M8 20h8"/></svg>
                      </button>
                      <button type="button" class="sapp-voice-go" id="sapp-voice-plane" aria-label="ارسال">
                        <svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true"><path d="m21.5 3.5-8.8 18-2.4-7.8-7.8-2.4 19-7.8z" fill="currentColor"/></svg>
                      </button>
                    </div>
                  </div>
                </div>
              </div>
            </form>
            <?php endif; ?>
            <div id="sapp-hold" hidden>
              <button type="button" class="sapp-hold-back" id="sapp-hold-back" aria-label="بستن"></button>
              <div class="sapp-hold-pop" id="sapp-hold-pop">
                <?php if (!$observeOnly): ?>
                <div class="sapp-hold-emojis">
                  <?php foreach (staff_app_allowed_reactions() as $emoji): ?>
                    <button type="button" data-emoji="<?= e($emoji) ?>"><?= e($emoji) ?></button>
                  <?php endforeach; ?>
                </div>
                <?php endif; ?>
                <div class="sapp-hold-menu" id="sapp-hold-menu">
                  <button type="button" class="sapp-hold-item" data-act="seen" id="sapp-hold-seen">
                    <svg viewBox="0 0 20 16" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="M1.4 8.2 4.6 11.4 11 4.6"/><path d="M6.2 8.2 9.4 11.4 15.8 4.6"/></svg>
                    <span id="sapp-hold-seen-label">دیده شد</span>
                    <span class="sapp-hold-faces" id="sapp-hold-seen-faces"></span>
                  </button>
                  <div class="sapp-hold-people" id="sapp-hold-people" hidden></div>
                  <?php if (!$observeOnly): ?>
                  <button type="button" class="sapp-hold-item" data-act="reply"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M9 8 4 12l5 4"/><path d="M4 12h10a6 6 0 0 1 6 6"/></svg><span>پاسخ</span></button>
                  <?php endif; ?>
                  <button type="button" class="sapp-hold-item" data-act="copy"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="8" y="8" width="12" height="12" rx="2"/><path d="M4 16V6a2 2 0 0 1 2-2h10"/></svg><span>کپی</span></button>
                  <button type="button" class="sapp-hold-item" data-act="save"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M12 4v10"/><path d="m8 10 4 4 4-4"/><path d="M5 19h14"/></svg><span>ذخیره فایل</span></button>
                  <?php if (!$observeOnly): ?>
                  <button type="button" class="sapp-hold-item" data-act="pin"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="m9 4 1 6-4 4v1h12v-1l-4-4 1-6"/><path d="M12 15v5"/></svg><span>سنجاق</span></button>
                  <?php endif; ?>
                  <button type="button" class="sapp-hold-item" data-act="link"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M10 13a5 5 0 0 0 7.1.1l2-2a5 5 0 0 0-7.1-7.1l-1.1 1.1"/><path d="M14 11a5 5 0 0 0-7.1-.1l-2 2a5 5 0 0 0 7.1 7.1l1.1-1.1"/></svg><span>کپی لینک</span></button>
                  <?php if (!$observeOnly): ?>
                  <button type="button" class="sapp-hold-item" data-act="forward"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M15 8h5v5"/><path d="M20 8 12 16a6 6 0 0 1-8.5 0"/></svg><span>هدایت</span></button>
                  <button type="button" class="sapp-hold-item" data-act="edit" hidden><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M4 20h4l10.5-10.5a2 2 0 0 0 0-2.8l-.7-.7a2 2 0 0 0-2.8 0L4.5 16.5 4 20z"/><path d="M13 7.5l3.5 3.5"/></svg><span>ویرایش</span></button>
                  <button type="button" class="sapp-hold-item is-danger is-split" data-act="delete"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M4 7h16"/><path d="M9 7V5h6v2"/><path d="M8 7l1 13h6l1-13"/></svg><span>حذف</span></button>
                  <button type="button" class="sapp-hold-item is-split" data-act="select"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="12" cy="12" r="8"/><path d="m8.5 12.2 2.2 2.2 4.8-5"/></svg><span>انتخاب</span></button>
                  <?php endif; ?>
                </div>
                <div class="sapp-hold-menu" id="sapp-hold-forward" hidden>
                  <p class="sapp-hold-forward-title">هدایت به</p>
                  <div id="sapp-hold-rooms"></div>
                </div>
              </div>
            </div>
            <?php if (!$observeOnly): ?>
            <div class="sapp-selectbar" id="sapp-selectbar" hidden>
              <button type="button" id="sapp-select-copy">کپی</button>
              <button type="button" class="is-danger" id="sapp-select-delete">حذف</button>
              <button type="button" id="sapp-select-cancel">لغو</button>
            </div>
            <?php endif; ?>
            <script type="application/json" id="sapp-chat-config"><?= $chatConfig ?></script>
            <?php
            $html = ob_get_clean();
            $GLOBALS['pageScripts'] = '<script src="' . e(url('/assets/js/staff-chat.js')) . '?v=20261009edit"></script>';
        } else {
            $rooms = staff_app_rooms_for($pdo, $userId);
            $people = staff_app_people($pdo);
            $peerMap = staff_app_peer_map($pdo, $userId);
            ob_start();
            ?>
            <h1>چت</h1>
            <?php if (staff_app_is_eshahabian($user)): ?>
              <p style="margin:0 0 8px"><a href="<?= e(url('/app/chat/archive')) ?>">آرشیو</a></p>
            <?php endif; ?>
            <div class="sapp-list">
              <?php foreach ($rooms as $room): ?>
                <?php $roomUnread = (int) ($room['unread_count'] ?? 0); ?>
                <?php
                  $isGroupRoom = empty($room['is_general']) && (!empty($room['is_private']) || (int) ($room['member_count'] ?? 0) !== 2);
                  $isDirectRoom = empty($room['is_general']) && empty($room['is_private']) && (int) ($room['member_count'] ?? 0) === 2;
                  $canDeleteChat = empty($room['is_general']) && (empty($room['observe_only']) || (staff_app_is_eshahabian($user) && !$isDirectRoom));
                  $roomKind = 'گفتگوی دو نفره';
                  if (!empty($room['is_general'])) {
                      $roomKind = 'همه درمانگرها و منشی‌ها';
                  } elseif (!empty($room['observe_only']) && !empty($room['is_private'])) {
                      $roomKind = 'خصوصی · فقط مشاهده';
                  } elseif (!empty($room['observe_only']) && $isDirectRoom) {
                      $roomKind = 'گفتگوی دو نفره · فقط مشاهده';
                  } elseif (!empty($room['observe_only'])) {
                      $roomKind = 'گروه · فقط مشاهده';
                  } elseif (!empty($room['is_private'])) {
                      $roomKind = 'خصوصی';
                  } elseif ($isGroupRoom) {
                      $roomKind = 'گروه';
                  }
                ?>
                <div class="sapp-row">
                <a href="<?= e(url('/app/chat/' . (string) $room['id'])) ?>" style="text-decoration:none;color:inherit;display:block">
                  <strong class="sapp-room-name"><span class="sapp-room-label"><i class="sapp-dot" data-online="<?= e(implode(',', $peerMap[(string) $room['id']] ?? [])) ?>" hidden></i><?= e((string) ($room['title'] ?? 'اتاق')) ?></span><b class="sapp-unread" data-room-unread="<?= e((string) $room['id']) ?>"<?= $roomUnread > 0 ? '' : ' hidden' ?>><?= $roomUnread > 0 ? e(to_fa_digits((string) $roomUnread)) : '' ?></b></strong>
                  <small data-room-unread-note="<?= e((string) $room['id']) ?>" data-room-kind="<?= e($roomKind) ?>" data-room-total="<?= e(to_fa_digits((string) (int) ($room['message_count'] ?? 0))) ?>"><?= e($roomKind) ?> · <?= e(to_fa_digits((string) (int) ($room['message_count'] ?? 0))) ?> پیام<?= $roomUnread > 0 ? ' · ' . e(to_fa_digits((string) $roomUnread)) . ' پیام نخوانده' : '' ?></small>
                </a>
                <?php if ($canDeleteChat): ?>
                  <form method="post" action="<?= e(url('/app/chat')) ?>" onsubmit="return confirm('این چت برای همه حذف شود؟');" style="margin-top:8px">
                    <?= csrf_field() ?>
                    <input type="hidden" name="form" value="delete_room">
                    <input type="hidden" name="room_id" value="<?= e((string) $room['id']) ?>">
                    <button class="btn btn-outline btn-sm" type="submit">حذف چت</button>
                  </form>
                <?php endif; ?>
                </div>
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
                  <small><?= e(staff_app_role_label((string) ($person['role'] ?? ''), $person)) ?></small>
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
                <label class="sapp-check" for="room-private">
                  <input type="checkbox" id="room-private" name="is_private" value="1">
                  <span>خصوصی — بقیه این گروه را نمی‌بینند</span>
                </label>
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
    if ($role === 'SECRETARY') {
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Pragma: no-cache');
        redirect('/app');
    }
    $html = '<h1>ساعت کار منشی‌ها</h1><p>بارگذاری ساعت کار الان ممکن نیست.</p>';
    try {
        require_once __DIR__ . '/../includes/staff_hours_ui.php';
        $blocks = array_values(array_filter(
            staff_hours_collect($pdo),
            static fn(array $block): bool => (string) ($block['kind'] ?? '') === 'secretary'
        ));
        $today = date('Y-m-d');
        $ymd = trim((string) ($_GET['date'] ?? $today));
        $dateOk = preg_match('/^\d{4}-\d{2}-\d{2}$/', $ymd) === 1;
        if ($dateOk) {
            [$gy, $gm, $gd] = array_map('intval', explode('-', $ymd));
            $dateOk = checkdate($gm, $gd, $gy);
        }
        if (!$dateOk || $ymd > $today) {
            $ymd = $today;
        }
        $prev = date('Y-m-d', strtotime($ymd . ' -1 day') ?: time());
        $next = date('Y-m-d', strtotime($ymd . ' +1 day') ?: time());
        $secretaryIdOf = static function (array $block): string {
            $row = is_array($block['user'] ?? null) ? $block['user'] : [];
            $id = trim((string) ($row['id'] ?? ''));
            if ($id !== '') {
                return $id;
            }

            return trim((string) ($block['tab_id'] ?? ''));
        };
        $secretaryNameOf = static function (array $block): string {
            $row = is_array($block['user'] ?? null) ? $block['user'] : [];
            $person = trim((string) ($row['name'] ?? ''));
            if ($person !== '') {
                return $person;
            }
            $username = trim((string) ($row['username'] ?? ''));
            if ($username !== '') {
                return $username;
            }
            $label = trim((string) ($block['label'] ?? ''));

            return $label !== '' ? $label : 'منشی';
        };
        $secretaryIds = [];
        foreach ($blocks as $block) {
            $secretaryIds[] = $secretaryIdOf($block);
        }
        $requestedSecretary = trim((string) ($_GET['secretary'] ?? ''));
        if ($requestedSecretary !== '' && in_array($requestedSecretary, $secretaryIds, true)) {
            $selectedId = $requestedSecretary;
        } else {
            $selectedId = $secretaryIds[0] ?? '';
        }
        $selected = null;
        foreach ($blocks as $block) {
            if ($secretaryIdOf($block) === $selectedId) {
                $selected = $block;
                break;
            }
        }
        $hoursHref = static function (string $day, string $secId): string {
            $query = 'date=' . rawurlencode($day);
            if ($secId !== '') {
                $query .= '&secretary=' . rawurlencode($secId);
            }

            return url('/app/hours?' . $query);
        };
        $weekTs = strtotime($ymd . ' 12:00:00') ?: time();
        $weekNames = ['یکشنبه', 'دوشنبه', 'سه‌شنبه', 'چهارشنبه', 'پنجشنبه', 'جمعه', 'شنبه'];
        $weekName = $weekNames[(int) date('w', $weekTs)] ?? '';
        $jalali = function_exists('to_jalali_label') ? to_jalali_label($ymd) : $ymd;
        $dateLabel = trim($weekName . ' · ' . $jalali);
        $arrowLeft = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14.5 6.5 9 12l5.5 5.5"/></svg>';
        $arrowRight = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9.5 6.5 15 12l-5.5 5.5"/></svg>';
        ob_start();
        ?>
        <div class="sapp-checklist">
        <h1>ساعت کار منشی‌ها</h1>
        <p class="muted">ورود، خروج و مدت حضور هر روز. ساعت عادی از ۹ صبح تا ۸ شب است.</p>
        <?php if ($blocks === [] || $selected === null): ?>
          <p class="muted">منشی‌ای برای نمایش ساعت کار پیدا نشد.</p>
        <?php else: ?>
          <?php $selected['label'] = $secretaryNameOf($selected); ?>
          <div class="sapp-check-tabs" role="tablist" aria-label="منشی‌ها">
            <?php foreach ($blocks as $block): ?>
              <?php
                $tabId = $secretaryIdOf($block);
                $tabName = $secretaryNameOf($block);
                $isActive = $tabId === $selectedId;
              ?>
              <a class="sapp-check-tab<?= $isActive ? ' is-active' : '' ?>" role="tab" id="sapp-hours-tab-<?= e($tabId) ?>" href="<?= e($hoursHref($ymd, $tabId)) ?>" aria-selected="<?= $isActive ? 'true' : 'false' ?>"<?= $isActive ? ' aria-current="page"' : '' ?>><?= e($tabName) ?></a>
            <?php endforeach; ?>
          </div>
          <div role="tabpanel" aria-labelledby="sapp-hours-tab-<?= e($selectedId) ?>">
            <div class="sapp-daynav">
              <a class="sapp-daynav-btn" href="<?= e($hoursHref($prev, $selectedId)) ?>" aria-label="روز قبل"><?= $arrowLeft ?></a>
              <strong class="sapp-daynav-date"><?= e($dateLabel) ?></strong>
              <?php if ($next <= $today): ?>
                <a class="sapp-daynav-btn" href="<?= e($hoursHref($next, $selectedId)) ?>" aria-label="روز بعد"><?= $arrowRight ?></a>
              <?php else: ?>
                <span class="sapp-daynav-btn is-off" aria-hidden="true"><?= $arrowRight ?></span>
              <?php endif; ?>
            </div>
            <?= staff_hours_render_tile($selected, $ymd, $today) ?>
          </div>
        <?php endif; ?>
        </div>
        <?php
        $html = ob_get_clean();
    } catch (Throwable $e) {
        error_log('staff app hours: ' . $e->getMessage());
    }
    staff_app_render('hours', 'ساعت کار منشی‌ها', $descriptions['hours'], $html);
    exit;
}

if ($section === 'consult') {
    require_once __DIR__ . '/../includes/consult_requests.php';
    require_once __DIR__ . '/../includes/collaboration.php';
    $rows = [];
    $collabRows = [];
    try {
        $rows = consult_request_list($pdo);
    } catch (Throwable $e) {
        error_log('staff app consult: ' . $e->getMessage());
    }
    try {
        $collabRows = collaboration_requests($pdo, 80);
    } catch (Throwable $e) {
        error_log('staff app collaboration: ' . $e->getMessage());
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
    $newCount = consult_request_new_count();
    $collabNew = collaboration_request_new_count();
    $badgeCount = $newCount + $collabNew;
    ob_start();
    ?>
    <h1>درخواست مشاوره/درخواست همکاری<?php if ($badgeCount > 0): ?><span class="sapp-consult-badge sapp-consult-badge-inline"><?= e(to_fa_digits((string) $badgeCount)) ?></span><?php endif; ?></h1>
    <p class="muted">هر دو از فرم سایت آمده‌اند. روی شماره بزنید تا تماس بگیرید.<?= $newCount ? ' ' . e(to_fa_digits((string) $newCount)) . ' درخواست مشاوره پیگیری نشده است.' : '' ?></p>
    <h2 class="sapp-day">درخواست مشاوره</h2>
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
        </header>
        <a href="tel:<?= e($phone) ?>" dir="ltr"><?= e(to_fa_digits($phone)) ?></a>
        <p><?= e((string) ($row['message'] ?? '')) ?></p>
        <?= consult_request_stamps_html($row) ?>
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
    <h2 class="sapp-day">درخواست همکاری</h2>
    <p class="muted">این‌ها را از فرم همکاری سایت فرستاده‌اند.</p>
    <?php if (!$collabRows): ?>
      <p class="sapp-row">هنوز درخواست همکاری از سایت نرسیده است.</p>
    <?php endif; ?>
    <?php foreach ($collabRows as $request): ?>
      <?php
        $collabPhone = (string) ($request['phone'] ?? '');
        $collabName = trim((string) ($request['name'] ?? ''));
        $collabOpen = strtolower(trim((string) ($request['status'] ?? 'new'))) === '' || strtolower(trim((string) ($request['status'] ?? 'new'))) === 'new';
      ?>
      <article class="sapp-consult<?= $collabOpen ? ' is-new' : '' ?>">
        <header>
          <strong><?= e($collabName !== '' ? $collabName : 'بدون نام') ?></strong>
          <span><?= e(trim((string) ($request['specialty'] ?? '')) !== '' ? (string) $request['specialty'] : 'همکاری') ?></span>
        </header>
        <a href="tel:<?= e($collabPhone) ?>" dir="ltr"><?= e(to_fa_digits($collabPhone)) ?></a>
        <p><?= e((string) ($request['message'] ?? '')) ?></p>
        <small class="muted"><?= e(format_fa_datetime((string) ($request['created_at'] ?? ''))) ?></small>
      </article>
    <?php endforeach; ?>
    <?php
    staff_app_render('consult', 'درخواست مشاوره/درخواست همکاری', $descriptions['consult'], ob_get_clean());
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
    $ownChecklist = $role === 'SECRETARY'
        && strtolower(trim((string) ($user['username'] ?? ''))) !== 'eshahabian';
    if ($ownChecklist) {
        $selfRow = null;
        foreach ($secretaries as $sec) {
            if ($userId !== '' && (string) ($sec['id'] ?? '') === $userId) {
                $selfRow = $sec;
                break;
            }
        }
        if ($selfRow === null && $userId !== '') {
            $selfRow = [
                'id' => $userId,
                'name' => $name,
                'username' => (string) ($user['username'] ?? ''),
            ];
        }
        $secretaries = $selfRow ? [$selfRow] : [];
    }
    $secretaryIds = [];
    foreach ($secretaries as $sec) {
        $secretaryIds[] = (string) ($sec['id'] ?? '');
    }
    $requestedSecretary = $ownChecklist ? '' : trim((string) ($_GET['secretary'] ?? ''));
    if ($requestedSecretary !== '' && in_array($requestedSecretary, $secretaryIds, true)) {
        $selectedId = $requestedSecretary;
    } elseif (in_array($userId, $secretaryIds, true)) {
        $selectedId = $userId;
    } else {
        $selectedId = $secretaryIds[0] ?? '';
    }
    $selected = null;
    foreach ($secretaries as $sec) {
        if ((string) ($sec['id'] ?? '') === $selectedId) {
            $selected = $sec;
            break;
        }
    }
    $tickUrl = url('/secretary/daily-tasks');
    $checkHref = static function (string $day, string $secId): string {
        $query = 'date=' . rawurlencode($day);
        if ($secId !== '') {
            $query .= '&secretary=' . rawurlencode($secId);
        }

        return url('/app/checklist?' . $query);
    };
    $arrowLeft = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14.5 6.5 9 12l5.5 5.5"/></svg>';
    $arrowRight = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9.5 6.5 15 12l-5.5 5.5"/></svg>';
    $showPeerTabs = !$ownChecklist && staff_app_emad_tasks_can_open($user);
    $peers = $showPeerTabs ? staff_peer_task_people($pdo) : [];
    $activePeer = null;
    if ($showPeerTabs && $requestedSecretary !== '' && !in_array($requestedSecretary, $secretaryIds, true)) {
        $wantedPeer = staff_peer_task_id_from_tab($requestedSecretary);
        if ($wantedPeer === '' && $requestedSecretary === staff_app_emad_task_tab()) {
            foreach ($peers as $peer) {
                if (staff_app_emad_tasks_is_owner($peer)) {
                    $wantedPeer = (string) ($peer['id'] ?? '');
                    break;
                }
            }
        }
        foreach ($peers as $peer) {
            if ((string) ($peer['id'] ?? '') === $wantedPeer) {
                $activePeer = $peer;
                break;
            }
        }
    }
    $onPeer = is_array($activePeer);
    $peerId = $onPeer ? (string) ($activePeer['id'] ?? '') : '';
    $peerName = '';
    if ($onPeer) {
        $peerName = trim((string) ($activePeer['name'] ?? ''));
        if ($peerName === '') {
            $peerName = trim((string) ($activePeer['username'] ?? ''));
        }
    }
    $peerTab = $peerId !== '' ? staff_peer_task_tab($peerId) : '';
    $peerCanTick = $onPeer && $userId !== '' && $userId === $peerId;
    $peerCanAssign = $onPeer && staff_app_emad_tasks_can_assign($user);
    $emadPost = url('/app/checklist/emad');
    ob_start();
    ?>
    <div class="sapp-checklist">
    <h1>لیست کارهای روزانه</h1>
    <?php if ($onPeer && $peerCanTick && $peerCanAssign): ?>
      <p class="muted">برای خودتان هم می‌توانید کار بنویسید. تیک را خودتان می‌زنید. اگر کس دیگری کار را فرستاده باشد، با انجام آن برای او پیام می‌آید.</p>
    <?php elseif ($onPeer && $peerCanTick): ?>
      <p class="muted">کارهایی که برای شما فرستاده شده. هر کدام را که انجام دادید تیک بزنید.</p>
    <?php elseif ($onPeer): ?>
      <p class="muted">برای <?= e($peerName) ?> در همین روز کار بنویسید. تیک را خودش می‌زند و پس از انجام، برای شما پیام می‌آید.</p>
    <?php else: ?>
      <p class="muted">همان فهرست داخل سایت است. پس از انجام هر کار آن را علامت بزنید. اگر کاری امروز لازم نیست، «امروز نیاز نیست» را بزنید.</p>
    <?php endif; ?>
    <?php if (($secretaries === [] || $selected === null) && !$showPeerTabs): ?>
      <p class="muted">منشی فعالی ثبت نشده است.</p>
    <?php else: ?>
      <?php if (!$ownChecklist): ?>
      <div class="sapp-check-tabs" role="tablist" aria-label="<?= e($showPeerTabs ? 'فهرست‌ها' : 'منشی‌ها') ?>">
        <?php foreach ($secretaries as $sec): ?>
          <?php
            $tabId = (string) ($sec['id'] ?? '');
            $tabName = trim((string) ($sec['name'] ?? ''));
            if ($tabName === '') {
                $tabName = trim((string) ($sec['username'] ?? ''));
            }
            if ($tabName === '') {
                $tabName = 'منشی';
            }
            $isActive = !$onPeer && $tabId === $selectedId;
          ?>
          <a class="sapp-check-tab<?= $isActive ? ' is-active' : '' ?>" role="tab" id="sapp-check-tab-<?= e($tabId) ?>" href="<?= e($checkHref($ymd, $tabId)) ?>" aria-selected="<?= $isActive ? 'true' : 'false' ?>"<?= $isActive ? ' aria-current="page"' : '' ?>><?= e($tabName) ?></a>
        <?php endforeach; ?>
        <?php foreach ($peers as $peer): ?>
          <?php
            $tabId = staff_peer_task_tab((string) ($peer['id'] ?? ''));
            $tabName = trim((string) ($peer['name'] ?? ''));
            if ($tabName === '') {
                $tabName = trim((string) ($peer['username'] ?? ''));
            }
            $isActive = $onPeer && $tabId === $peerTab;
          ?>
          <a class="sapp-check-tab<?= $isActive ? ' is-active' : '' ?>" role="tab" id="sapp-check-tab-<?= e($tabId) ?>" href="<?= e($checkHref($ymd, $tabId)) ?>" aria-selected="<?= $isActive ? 'true' : 'false' ?>"<?= $isActive ? ' aria-current="page"' : '' ?>><?= e($tabName) ?></a>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
      <?php if ($onPeer): ?>
        <div class="sapp-check-panel" role="tabpanel" aria-labelledby="sapp-check-tab-<?= e($peerTab) ?>">
          <div class="sapp-daynav">
            <a class="sapp-daynav-btn" href="<?= e($checkHref($prev, $peerTab)) ?>" aria-label="روز قبل"><?= $arrowLeft ?></a>
            <strong class="sapp-daynav-date"><?= e(secretary_daily_task_date_label($ymd)) ?></strong>
            <?php if ($next <= $today): ?>
              <a class="sapp-daynav-btn" href="<?= e($checkHref($next, $peerTab)) ?>" aria-label="روز بعد"><?= $arrowRight ?></a>
            <?php else: ?>
              <span class="sapp-daynav-btn is-off" aria-hidden="true"><?= $arrowRight ?></span>
            <?php endif; ?>
          </div>
          <?= staff_app_emad_tasks_panel_html($pdo, $ymd, $peerName, $peerCanTick, $peerCanAssign, $emadPost, $peerId, '/app/checklist?date=' . rawurlencode($ymd) . '&secretary=' . rawurlencode($peerTab)) ?>
        </div>
      <?php elseif ($selected === null): ?>
        <p class="muted">منشی فعالی ثبت نشده است.</p>
      <?php else: ?>
      <div class="sapp-check-panel"<?php if (!$ownChecklist): ?> role="tabpanel" aria-labelledby="sapp-check-tab-<?= e($selectedId) ?>"<?php endif; ?>>
        <div class="sapp-daynav">
          <a class="sapp-daynav-btn" href="<?= e($checkHref($prev, $selectedId)) ?>" aria-label="روز قبل"><?= $arrowLeft ?></a>
          <strong class="sapp-daynav-date"><?= e(secretary_daily_task_date_label($ymd)) ?></strong>
          <?php if ($next <= $today): ?>
            <a class="sapp-daynav-btn" href="<?= e($checkHref($next, $selectedId)) ?>" aria-label="روز بعد"><?= $arrowRight ?></a>
          <?php else: ?>
            <span class="sapp-daynav-btn is-off" aria-hidden="true"><?= $arrowRight ?></span>
          <?php endif; ?>
        </div>
        <?php
          $secId = (string) ($selected['id'] ?? '');
          $wasPresent = secretary_was_present($pdo, $secId, $ymd);
          $hasTick = false;
          if (!$wasPresent) {
              foreach (secretary_daily_task_states($pdo, $secId, $ymd) as $state) {
                  if (!empty($state['done']) || !empty($state['skipped'])) {
                      $hasTick = true;
                      break;
                  }
              }
              if (!$hasTick && secretary_assigned_tasks_for($pdo, $secId, $ymd) !== []) {
                  $hasTick = true;
              }
          }
          $showList = $wasPresent || $hasTick || ($role === 'SECRETARY' && $secId === $userId && $ymd === $today);
          if (secretary_assigned_tasks_can_give($user)) {
              echo secretary_assigned_task_composer_html(
                  url('/app/checklist/assign'),
                  $ymd,
                  $secretaries,
                  $selectedId,
                  '/app/checklist?date=' . rawurlencode($ymd) . '&secretary=' . rawurlencode($selectedId)
              );
          }
          if (!$showList) {
              echo '<p class="sapp-check-absent">این روز حضور نداشته است.</p>';
          } else {
              $canTick = $role === 'SECRETARY' && $secId === $userId && secretary_daily_task_can_edit($pdo, $userId, $ymd, true);
              echo secretary_daily_tasks_table_html($pdo, $selected, $ymd, $canTick, $canTick ? $tickUrl : '', false);
          }
        ?>
      </div>
      <?php endif; ?>
    <?php endif; ?>
    </div>
    <?php
    staff_app_render('checklist', 'لیست کارهای روزانه', $descriptions['checklist'], ob_get_clean());
    exit;
}

if ($section === 'petty') {
    if (!function_exists('petty_cash_staff_app_allowed')) {
        require_once __DIR__ . '/../includes/petty_cash.php';
    }
    if (!petty_cash_staff_app_allowed($user)) {
        flash_set('error', 'تنخواه فقط برای منشی، دکتر شیوا گرانمایه‌پور و دکتر عطیه گارسچی است.');
        redirect('/app');
    }
    $pettyCashEmbedded = true;
    $pettyCashUser = $user;
    $pettyCashHtml = '';
    require __DIR__ . '/petty_cash.php';
    staff_app_render('petty', 'تنخواه', $descriptions['petty'], $pettyCashHtml);
    exit;
}

if ($section === 'profile') {
    $hello = $name !== '' ? $name : staff_app_role_label($role);
    ob_start();
    ?>
    <div class="sapp-hello">
      <h1><?= e($hello) ?></h1>
      <p><?= e(staff_app_role_label($role, $user)) ?></p>
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
$complaints = [];
$patients = [];
try {
    $complaints = session_complaint_list($pdo);
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
}
ob_start();
?>
<h1>انتقادات و پیشنهادات</h1>
<p class="muted"><?= $role === 'SECRETARY' ? 'اسم شخص را از فهرست انتخاب کنید تا نام، شماره تماس و درمانگرش مشخص شود. مورد برای دکتر شیوا گرانمایه‌پور و دکتر عطیه گارسچی می‌رود.' : 'انتقادها و پیشنهادهایی که مراجع یا منشی ثبت کرده اینجاست.' ?></p>
<?php if ($role === 'SECRETARY'): ?>
  <?= session_feedback_form_html('/app/complaints', true, $patients, '', '', '') ?>
<?php endif; ?>
<?= session_feedback_list_html($complaints, 'هنوز انتقاد یا پیشنهادی ثبت نشده است.') ?>
<?php
if ($role === 'SECRETARY') {
    $GLOBALS['pageScripts'] = session_feedback_assets(true);
}
staff_app_render('complaints', 'انتقادات و پیشنهادات', $descriptions['complaints'], ob_get_clean());
