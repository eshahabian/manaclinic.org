<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/staff_app.php';

/** @var PDO $pdo */
$user = staff_app_user();
$path = (string) ($GLOBALS['path'] ?? '/app');
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$userId = (string) ($user['id'] ?? '');
$role = (string) ($user['role'] ?? '');

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
            $members = $_POST['members'] ?? [];
            if (!is_array($members)) {
                $members = [];
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
        staff_app_send_message($pdo, $user, $roomId, post('body'), $_FILES['file'] ?? null);
        redirect($back);
    } catch (RuntimeException $e) {
        flash_set('error', $e->getMessage());
        redirect($back);
    } catch (Throwable $e) {
        error_log('staff app chat: ' . $e->getMessage());
        flash_set('error', 'پیام فرستاده نشد.');
        redirect($back);
    }
}

if ($method === 'POST' && $path === '/app/checklist') {
    require_once __DIR__ . '/../includes/secretary_daily_tasks.php';
    csrf_verify();
    $ymd = trim((string) post('task_date'));
    $key = trim((string) post('task_key'));
    $done = post('done') === '1';
    $back = '/app/checklist?date=' . rawurlencode($ymd);
    $ajax = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
    $fail = static function (string $message) use ($ajax, $back): never {
        if ($ajax) {
            http_response_code(400);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
            exit;
        }
        flash_set('error', $message);
        redirect($back);
    };
    if ($role !== 'SECRETARY') {
        $fail('تیک چک‌لیست را خود منشی می‌زند.');
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ymd) || $ymd > date('Y-m-d')) {
        $fail('تاریخ این فهرست معتبر نیست.');
    }
    if (!secretary_daily_task_can_edit($pdo, $userId, $ymd, true) || ($ymd !== date('Y-m-d') && !secretary_was_present($pdo, $userId, $ymd))) {
        $fail('فقط روزهایی که در کلینیک حضور دارید قابل تیک خوردن است.');
    }
    try {
        $doneAt = secretary_daily_task_set($pdo, $userId, $ymd, $key, $done);
    } catch (Throwable $e) {
        $fail($e->getMessage());
    }
    if ($ajax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok' => true,
            'done' => $done,
            'time' => ($done && is_string($doneAt) && $doneAt !== '') ? format_fa_time($doneAt) : '',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    redirect($back);
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
    '/app/checklist' => 'checklist',
    '/app/complaints' => 'complaints',
    default => '',
};

if ($section === '') {
    http_response_code(404);
    staff_app_render('home', 'یافت نشد', 'این صفحه در برنامه داخلی مانا کلینیک نیست.', '<h1>این صفحه در برنامه نیست</h1><p><a href="' . e(url('/app')) . '">برگشت به برنامه</a></p>');
    exit;
}

$descriptions = [
    'home' => 'برنامه خصوصی مانا کلینیک برای درمانگرها و منشی‌ها؛ وقت، اتاق، چت، ساعت کار، چک‌لیست و شکایت.',
    'appointments' => 'وقت‌های پیش‌رو کلینیک در برنامه داخلی، با نام مراجع، درمانگر، ساعت و اتاق.',
    'rooms' => 'وضعیت اتاق‌های کلینیک در هفت روز پیش‌رو؛ الان، رزروشده یا تمام‌شده.',
    'chat' => 'چت کلی درمانگرها و منشی‌ها و اتاق‌های جدا، با امکان فرستادن فایل.',
    'hours' => 'ساعت ورود و خروج منشی‌های مانا کلینیک و مدت حضور هر روز.',
    'checklist' => 'چک‌لیست کارهای روزانه منشی‌ها در کلینیک مانا.',
    'complaints' => 'ثبت و دیدن شکایت مراجع از جلسه درمانگر در برنامه داخلی مانا.',
];

if ($section === 'home') {
    $unread = staff_app_unread_count($pdo, $userId);
    $cards = [
        ['/app/appointments', 'وقت‌ها', 'نوبت‌های امروز و روزهای پیش‌رو'],
        ['/app/rooms', 'شرایط اتاق‌ها', 'کدام اتاق الان پر است و بعدی کیست'],
        ['/app/chat', 'چت', $unread > 0 ? to_fa_digits((string) $unread) . ' پیام تازه' : 'چت کلی یا اتاق جدا، همراه فایل'],
        ['/app/hours', 'ساعت کار منشی‌ها', 'ورود، خروج و مدت حضور'],
        ['/app/checklist', 'چک‌لیست منشی‌ها', 'کارهای روزانه و تیک انجام'],
        ['/app/complaints', 'شکایت‌ها', 'شکایت مراجع از جلسه'],
    ];
    ob_start();
    ?>
    <h1>برنامه داخلی</h1>
    <p class="muted">فقط برای درمانگرها و منشی‌ها. همین شش بخش اینجاست.</p>
    <div class="sapp-grid">
      <?php foreach ($cards as $card): ?>
        <a class="sapp-card" href="<?= e(url($card[0])) ?>">
          <strong><?= e($card[1]) ?></strong>
          <span><?= e($card[2]) ?></span>
        </a>
      <?php endforeach; ?>
    </div>
    <?php
    staff_app_render('home', 'برنامه داخلی', $descriptions['home'], ob_get_clean());
    exit;
}

if ($section === 'appointments') {
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
    <h1>وقت‌ها</h1>
    <p class="muted"><?= $role === 'DOCTOR' ? 'وقت‌های خودتان تا ده روز آینده.' : 'وقت‌های کلینیک تا ده روز آینده.' ?></p>
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
              $mode = (string) ($row['session_mode'] ?? '') === 'ONLINE' ? 'آنلاین' : 'حضوری';
            ?>
            <article class="sapp-row">
              <strong><?= e(format_fa_time((string) $row['starts_at'])) ?> تا <?= e(format_fa_time((string) $row['ends_at'])) ?></strong>
              <div><?= e((string) ($row['patient_name'] ?? '')) ?> · <?= e((string) ($row['doctor_name'] ?? '')) ?></div>
              <small><?= e($mode) ?> · <?= e(appointment_row_status_label($row)) ?><?= $roomNo > 0 ? ' · اتاق ' . e(to_fa_digits((string) $roomNo)) : '' ?></small>
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
    $rows = [];
    $error = '';
    try {
        $rows = staff_app_room_board($pdo);
    } catch (Throwable $e) {
        error_log('staff app rooms: ' . $e->getMessage());
        $error = 'بارگذاری اتاق‌ها الان ممکن نیست.';
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
    ob_start();
    ?>
    <h1>شرایط اتاق‌ها</h1>
    <p class="muted">هفت روز پیش‌رو. وضعیت هر رزرو: الان، رزرو شده، یا تمام شده.</p>
    <?php if ($error !== ''): ?>
      <p><?= e($error) ?></p>
    <?php else: ?>
      <?php foreach ($byRoom as $number => $items): ?>
        <h2 class="sapp-day"><?= e(function_exists('clinic_room_label') ? clinic_room_label((int) $number) : ('اتاق ' . to_fa_digits((string) $number))) ?></h2>
        <?php if ($items === []): ?>
          <p class="muted">رزروی ثبت نشده است.</p>
        <?php else: ?>
          <div class="sapp-list">
            <?php foreach ($items as $row): ?>
              <?php
                $now = time();
                $start = strtotime((string) ($row['starts_at'] ?? '')) ?: 0;
                $end = strtotime((string) ($row['ends_at'] ?? '')) ?: 0;
                $state = ($now >= $start && $now < $end) ? 'الان' : ($start > $now ? 'رزرو شده' : 'تمام شده');
                $purpose = function_exists('clinic_room_purpose') ? clinic_room_purpose($row) : trim((string) ($row['title'] ?? ''));
              ?>
              <article class="sapp-row">
                <strong><?= e($state) ?> · <?= e(format_fa_time((string) $row['starts_at'])) ?> تا <?= e(format_fa_time((string) $row['ends_at'])) ?></strong>
                <div><?= e($purpose !== '' ? $purpose : 'بدون توضیح') ?></div>
                <small><?= e(to_jalali_label(substr((string) ($row['starts_at'] ?? ''), 0, 10))) ?></small>
              </article>
            <?php endforeach; ?>
          </div>
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
            ob_start();
            ?>
            <p style="margin:0 0 8px"><a href="<?= e(url('/app/chat')) ?>">همه چت‌ها</a></p>
            <h1><?= e((string) ($room['title'] ?? 'چت')) ?></h1>
            <p class="muted"><?= !empty($room['is_general']) ? 'چت کلی درمانگرها و منشی‌ها.' : 'فقط اعضای همین اتاق این گفتگو را می‌بینند.' ?></p>
            <div class="sapp-chat">
              <?php if ($messages === []): ?>
                <p class="muted">هنوز پیامی نیست.</p>
              <?php endif; ?>
              <?php foreach ($messages as $message): ?>
                <?php $mine = (string) ($message['user_id'] ?? '') === $userId; ?>
                <article class="sapp-msg<?= $mine ? ' is-mine' : '' ?>">
                  <strong><?= e((string) ($message['name'] ?? '')) ?></strong>
                  <small class="muted"> · <?= e(staff_app_role_label((string) ($message['role'] ?? ''))) ?> · <?= e(format_fa_datetime((string) ($message['created_at'] ?? ''))) ?></small>
                  <?php if (trim((string) ($message['body'] ?? '')) !== ''): ?>
                    <p style="margin:.45rem 0 0;white-space:pre-wrap"><?= e((string) $message['body']) ?></p>
                  <?php endif; ?>
                  <?php foreach ($message['files'] ?? [] as $file): ?>
                    <?php
                      $fileUrl = url('/app/file/' . (string) $file['id']);
                      $mime = (string) ($file['mime'] ?? '');
                    ?>
                    <?php if (str_starts_with($mime, 'image/')): ?>
                      <a href="<?= e($fileUrl) ?>"><img src="<?= e($fileUrl) ?>" alt="<?= e((string) ($file['original_name'] ?? 'فایل')) ?>"></a>
                    <?php else: ?>
                      <p style="margin:.45rem 0 0"><a href="<?= e($fileUrl) ?>"><?= e((string) ($file['original_name'] ?? 'فایل')) ?></a></p>
                    <?php endif; ?>
                  <?php endforeach; ?>
                </article>
              <?php endforeach; ?>
            </div>
            <form class="sapp-compose panel" method="post" action="<?= e(url('/app/chat')) ?>" enctype="multipart/form-data">
              <?= csrf_field() ?>
              <input type="hidden" name="form" value="send">
              <input type="hidden" name="room_id" value="<?= e($roomId) ?>">
              <label class="label" for="chat-body">پیام</label>
              <textarea class="input" id="chat-body" name="body" rows="3" maxlength="4000" placeholder="متن پیام"></textarea>
              <label class="label" for="chat-file">فایل</label>
              <input class="input" id="chat-file" name="file" type="file">
              <button class="btn btn-primary" type="submit">ارسال</button>
            </form>
            <?php
            $html = ob_get_clean();
        } else {
            $rooms = staff_app_rooms_for($pdo, $userId);
            $people = staff_app_people($pdo);
            ob_start();
            ?>
            <h1>چت</h1>
            <p class="muted">چت کلی بین همه درمانگرها و منشی‌هاست. برای جمع کوچک‌تر اتاق جدا بسازید. در هر دو می‌شود فایل فرستاد.</p>
            <div class="sapp-list">
              <?php foreach ($rooms as $room): ?>
                <a class="sapp-row" href="<?= e(url('/app/chat/' . (string) $room['id'])) ?>" style="text-decoration:none;color:inherit">
                  <strong><?= e((string) ($room['title'] ?? 'اتاق')) ?></strong>
                  <small><?= !empty($room['is_general']) ? 'همه درمانگرها و منشی‌ها' : 'اتاق خصوصی' ?> · <?= e(to_fa_digits((string) (int) ($room['message_count'] ?? 0))) ?> پیام</small>
                </a>
              <?php endforeach; ?>
            </div>
            <form class="sapp-compose panel" method="post" action="<?= e(url('/app/chat')) ?>">
              <?= csrf_field() ?>
              <input type="hidden" name="form" value="create">
              <h2 style="margin:0;font-size:1.05rem">اتاق تازه</h2>
              <label class="label" for="room-title">نام اتاق</label>
              <input class="input" id="room-title" name="title" maxlength="80" required placeholder="مثلاً شیفت عصر">
              <p class="label" style="margin-bottom:.35rem">اعضا</p>
              <?php foreach ($people as $person): ?>
                <?php if ((string) ($person['id'] ?? '') === $userId) { continue; } ?>
                <label style="display:flex;gap:8px;align-items:flex-start;margin:0 0 6px">
                  <input type="checkbox" name="members[]" value="<?= e((string) $person['id']) ?>">
                  <span><?= e(trim((string) ($person['name'] ?? '')) !== '' ? (string) $person['name'] : (string) ($person['username'] ?? '')) ?> · <?= e(staff_app_role_label((string) ($person['role'] ?? ''))) ?></span>
                </label>
              <?php endforeach; ?>
              <button class="btn btn-primary" type="submit">ساخت اتاق</button>
            </form>
            <?php
            $html = ob_get_clean();
        }
    } catch (Throwable $e) {
        error_log('staff app chat page: ' . $e->getMessage());
        $html = '<h1>چت</h1><p>چت الان باز نمی‌شود. یک‌بار دیگر صفحه را باز کنید.</p>';
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
    ob_start();
    ?>
    <h1>چک‌لیست منشی‌ها</h1>
    <p class="muted">تیک را خود منشی می‌زند. بقیه فقط پیشرفت همان روز را می‌بینند.</p>
    <div style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;margin-top:.75rem">
      <a class="btn btn-outline btn-sm" href="<?= e(url('/app/checklist?date=' . rawurlencode($prev))) ?>">روز قبل</a>
      <strong><?= e(secretary_daily_task_date_label($ymd)) ?></strong>
      <?php if ($next <= $today): ?>
        <a class="btn btn-outline btn-sm" href="<?= e(url('/app/checklist?date=' . rawurlencode($next))) ?>">روز بعد</a>
      <?php endif; ?>
    </div>
    <?php if ($secretaries === []): ?>
      <p class="muted">منشی فعالی ثبت نشده است.</p>
    <?php endif; ?>
    <?php foreach ($secretaries as $secretary): ?>
      <?php
        $secId = (string) ($secretary['id'] ?? '');
        $isSelf = $role === 'SECRETARY' && $secId === $userId;
        $present = secretary_was_present($pdo, $secId, $ymd);
        $name = trim((string) ($secretary['name'] ?? ''));
        if ($name === '') {
            $name = (string) ($secretary['username'] ?? 'منشی');
        }
        if (!$present && !($isSelf && $ymd === $today)) {
            echo '<p class="sapp-row">' . e($name) . ' این روز در کلینیک حضور نداشته است.</p>';
            continue;
        }
        $editable = $isSelf && secretary_daily_task_can_edit($pdo, $userId, $ymd, true);
        echo secretary_daily_tasks_html($pdo, $secretary, $ymd, $editable, url('/app/checklist'));
      ?>
    <?php endforeach; ?>
    <?php
    staff_app_render('checklist', 'چک‌لیست منشی‌ها', $descriptions['checklist'], ob_get_clean());
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
