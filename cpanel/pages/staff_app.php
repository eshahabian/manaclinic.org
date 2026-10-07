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
    'home' => 'برنامه خصوصی مانا کلینیک برای درمانگرها و منشی‌ها؛ وقت، اتاق، چت، ساعت کار، چک‌لیست و شکایت.',
    'appointments' => 'وقت‌های پیش‌رو کلینیک در برنامه داخلی، با نام مراجع، درمانگر، ساعت و اتاق.',
    'rooms' => 'وضعیت اتاق‌های کلینیک در هفت روز پیش‌رو؛ الان، رزروشده یا تمام‌شده.',
    'chat' => 'چت کلی درمانگرها و منشی‌ها و اتاق‌های جدا، با امکان فرستادن فایل.',
    'hours' => 'ساعت ورود و خروج منشی‌های مانا کلینیک و مدت حضور هر روز.',
    'checklist' => 'چک‌لیست کارهای روزانه منشی‌ها در کلینیک مانا.',
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
      <a class="sapp-tile" href="<?= e(url('/app/hours')) ?>">
        <span class="sapp-ico sapp-ico-amber"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="8"/><path d="M12 8v4.2l2.6 1.6"/><path d="M16.5 16.8l1.2 1.2"/></svg></span>
        <strong>ساعت کاری</strong>
        <em>Shifts &amp; Records</em>
      </a>
      <a class="sapp-tile" href="<?= e(url('/app/checklist')) ?>">
        <span class="sapp-ico sapp-ico-pink"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><rect x="5" y="4" width="14" height="16" rx="3"/><path d="m8.5 12 2.2 2.2 4.8-5"/></svg></span>
        <strong>چک‌لیست</strong>
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
      <h2 class="sapp-day">وقت‌های ده روز آینده</h2>
    <?php else: ?>
      <h1>وقت‌ها</h1>
      <p class="muted">وقت‌های خودتان تا ده روز آینده.</p>
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
    try {
        $rows = clinic_rooms_between($pdo, $roomDay . ' 00:00:00', date('Y-m-d H:i:s', strtotime($roomDay . ' +1 day') ?: time()));
        if ($role === 'SECRETARY') {
            $roomPatients = $pdo->query("SELECT id, name, phone FROM users WHERE role='PATIENT' AND COALESCE(is_disabled,0)=0 ORDER BY name ASC")->fetchAll() ?: [];
            $roomDoctors = $pdo->query("
              SELECT dp.id, u.name, dp.specialty
              FROM doctor_profiles dp
              JOIN users u ON u.id = dp.user_id
              WHERE dp.is_active = 1 AND dp.is_approved = 1 AND COALESCE(u.is_disabled,0)=0
              ORDER BY u.name ASC
            ")->fetchAll() ?: [];
            $openWorkshops = clinic_rooms_open_workshop_sessions($pdo);
        }
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
            <form class="sapp-compose panel" id="sapp-new-chat" method="post" action="<?= e(url('/app/chat')) ?>">
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

if ($section === 'profile') {
    $hello = $name !== '' ? $name : staff_app_role_label($role);
    ob_start();
    ?>
    <div class="sapp-hello">
      <h1><?= e($hello) ?></h1>
      <p><?= e(staff_app_role_label($role)) ?></p>
    </div>
    <div class="sapp-profile">
      <p style="margin:0;font-weight:800">همین حساب برای وقت‌ها، اتاق‌ها، چت، ساعت کار، چک‌لیست و شکایات است.</p>
      <a class="sapp-logout" href="<?= e(url('/logout')) ?>">خروج</a>
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
