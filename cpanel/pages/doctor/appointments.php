<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/doctor_panel.php';
require_once __DIR__ . '/../../includes/user_cleanup.php';
require_once __DIR__ . '/../../includes/appointment_cancel.php';
require_once __DIR__ . '/../../includes/doctor_profile_fields.php';

$ctx = require_doctor_profile($pdo);
$doctorId = (string) ($ctx['profile']['id'] ?? '');

/** دکتر شیوا گرانمایه‌پور (و پنل ادمین روی همان حساب): همه نوبت‌ها؛ بقیه فقط نوبت‌های خودشان */
$seeAllAppointments = doctor_is_shiva([
    'name' => (string) ($ctx['profile']['name'] ?? ($ctx['user']['name'] ?? '')),
    'username' => (string) ($ctx['user']['username'] ?? ''),
]) || doctor_is_shiva($ctx['user'] ?? null);

$searchQ = trim((string) ($_GET['q'] ?? ''));
$searchDay = trim((string) ($_GET['day'] ?? ''));
if ($searchDay !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $searchDay)) {
    $searchDay = '';
}
$searchJalali = '';
if ($searchDay !== '') {
    [$gy, $gm, $gd] = array_map('intval', explode('-', $searchDay));
    if ($gy > 0 && $gm > 0 && $gd > 0) {
        [$jy, $jm, $jd] = gregorian_to_jalali($gy, $gm, $gd);
        $searchJalali = $jy . '/' . $jm . '/' . $jd;
    }
}

$sql = "
  SELECT a.*, u.name AS patient_name, u.phone, u.email,
         du.name AS doctor_name,
         p.id AS payment_id, p.amount, p.status AS pay_status, p.receipt_path,
         cu.name AS actor_name, cu.username AS actor_username, cu.role AS actor_role
  FROM appointments a
  JOIN users u ON u.id = a.patient_id
  JOIN doctor_profiles dp ON dp.id = a.doctor_id
  JOIN users du ON du.id = dp.user_id
  LEFT JOIN payments p ON p.appointment_id = a.id
  LEFT JOIN users cu ON cu.id = a.created_by_user_id
";
$params = [];
if (!$seeAllAppointments) {
    $sql .= ' WHERE a.doctor_id = ?';
    $params[] = $doctorId;
}
$sql .= ' ORDER BY a.starts_at ASC';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$normName = static function (string $s): string {
    $s = mb_strtolower(trim($s), 'UTF-8');
    $s = str_replace(['ي', 'ك', 'ة', 'ۀ', '‌', 'ـ', 'أ', 'إ', 'آ', 'ؤ', 'ئ'], ['ی', 'ک', 'ه', 'ه', '', '', 'ا', 'ا', 'ا', 'و', 'ی'], $s);
    $s = preg_replace('/\s+/u', ' ', $s) ?? $s;

    return $s;
};
$normDigits = static function (string $s): string {
    $map = ['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9',
            '٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9'];
    $s = strtr($s, $map);
    return preg_replace('/\D+/', '', $s) ?? '';
};
$qNorm = $searchQ !== '' ? $normName($searchQ) : '';
$qTokens = $qNorm !== '' ? array_values(array_filter(explode(' ', $qNorm), static fn(string $t): bool => $t !== '')) : [];
$qDigits = $searchQ !== '' ? $normDigits($searchQ) : '';

$upcoming = [];
$done = [];
$cancelled = [];
$now = time();
if (function_exists('appointment_restore_auto_cancelled_unpaid')) {
    appointment_restore_auto_cancelled_unpaid($pdo);
}
foreach ($rows as $row) {
    $status = (string) ($row['status'] ?? '');
    $start = strtotime((string) ($row['starts_at'] ?? '')) ?: 0;
    if ($status === 'CANCELLED') {
        $cancelled[] = $row;
    } elseif ($status === 'COMPLETED' || $start < $now) {
        $done[] = $row;
    } else {
        $upcoming[] = $row;
    }
}

$upcomingFiltered = $upcoming;
$doneFiltered = $done;
$cancelledFiltered = $cancelled;
$applyApptFilter = static function (array $list) use ($qNorm, $qTokens, $qDigits, $searchDay, $normName, $normDigits): array {
    if ($qNorm === '' && $qDigits === '' && $searchDay === '') {
        return $list;
    }

    return array_values(array_filter($list, static function (array $row) use ($qNorm, $qTokens, $qDigits, $searchDay, $normName, $normDigits): bool {
        if ($searchDay !== '') {
            $ymd = substr(str_replace('T', ' ', (string) ($row['starts_at'] ?? '')), 0, 10);
            if ($ymd !== $searchDay) {
                return false;
            }
        }
        if ($qNorm === '' && $qDigits === '') {
            return true;
        }
        $hay = $normName((string) ($row['patient_name'] ?? ''));
        $phone = $normDigits((string) ($row['phone'] ?? ''));
        $email = $normName((string) ($row['email'] ?? ''));
        $okName = false;
        if ($qTokens !== []) {
            $okName = true;
            foreach ($qTokens as $tok) {
                if ($tok === '' || (!str_contains($hay, $tok) && !str_contains($email, $tok))) {
                    $okName = false;
                    break;
                }
            }
        } elseif ($qNorm !== '') {
            $okName = $hay !== '' && str_contains($hay, $qNorm);
        }
        $okPhone = $qDigits !== '' && $phone !== '' && str_contains($phone, $qDigits);
        if (!$okName && !$okPhone) {
            return false;
        }

        return true;
    }));
};
$upcomingFiltered = $applyApptFilter($upcoming);
$doneFiltered = $applyApptFilter($done);
$cancelledFiltered = $applyApptFilter($cancelled);

usort($upcomingFiltered, static fn(array $a, array $b): int => strcmp((string) $a['starts_at'], (string) $b['starts_at']));
usort($doneFiltered, static fn(array $a, array $b): int => strcmp((string) $b['starts_at'], (string) $a['starts_at']));
usort($cancelledFiltered, static fn(array $a, array $b): int => strcmp((string) $b['starts_at'], (string) $a['starts_at']));

$upcomingYmd = group_appointments_by_jalali_ymd($upcomingFiltered, 'doc-up', 'current');
$doneYmd = group_appointments_by_jalali_ymd($doneFiltered, 'doc-dn', 'latest');
$cancelledYmd = group_appointments_by_jalali_ymd($cancelledFiltered, 'doc-cn', 'latest');

$tabParam = trim((string) ($_GET['tab'] ?? ''));
$binderInitial = in_array($tabParam, ['upcoming', 'done', 'cancelled'], true) ? $tabParam : 'upcoming';

$showDoctorOnCards = $seeAllAppointments;
$ymdRenderDoctor = static function (array $list) use ($showDoctorOnCards): void {
    $appointmentList = $list;
    $appointmentEmpty = 'نوبتی در این بازه نیست.';
    $appointmentShowDoctor = $showDoctorOnCards;
    require __DIR__ . '/../../includes/doctor_appointment_cards.php';
};

$filterActive = $searchQ !== '' || $searchDay !== '';
$filterHintParts = [];
if ($searchQ !== '') {
    $filterHintParts[] = 'نام «' . $searchQ . '»';
}
if ($searchDay !== '') {
    $filterHintParts[] = 'تاریخ ' . to_jalali_label($searchDay);
}
$filterHint = implode(' · ', $filterHintParts);

ob_start();
?>
<h1>نوبت‌های مراجعه‌کنندگان</h1>
<p class="muted" style="margin-top:.35rem">
  <?php if ($seeAllAppointments): ?>
    شما می‌توانید نوبت همه درمانگرها را ببینید. با نام یا تاریخ جستجو کنید.
  <?php else: ?>
    فقط نوبت‌های مراجعه‌کنندگان خودتان. با نام یا تاریخ جستجو کنید.
  <?php endif; ?>
</p>

<div class="binder-tile" data-binder-tabs data-binder-hash="0" data-binder-initial="<?= e($binderInitial) ?>" data-binder-tone="<?= e($binderInitial === 'cancelled' ? 'archive' : ($binderInitial === 'done' ? 'archive' : 'appts')) ?>" style="margin-top:1.25rem">
  <div class="binder-tabs" role="tablist" aria-label="دسته‌بندی نوبت‌ها">
    <button type="button"
      class="binder-tab binder-tab-appts<?= $binderInitial === 'upcoming' ? ' is-active' : '' ?>"
      role="tab"
      data-binder-tab="upcoming"
      data-binder-tone="appts"
      aria-selected="<?= $binderInitial === 'upcoming' ? 'true' : 'false' ?>">
      نوبت‌های پیش‌رو
      <span class="binder-tab-count"><?= count($upcomingFiltered) ?></span>
    </button>
    <button type="button"
      class="binder-tab binder-tab-archive<?= $binderInitial === 'done' ? ' is-active' : '' ?>"
      role="tab"
      data-binder-tab="done"
      data-binder-tone="archive"
      aria-selected="<?= $binderInitial === 'done' ? 'true' : 'false' ?>">
      نوبت‌های انجام‌شده
      <span class="binder-tab-count"><?= count($doneFiltered) ?></span>
    </button>
    <button type="button"
      class="binder-tab binder-tab-cancelled<?= $binderInitial === 'cancelled' ? ' is-active' : '' ?>"
      role="tab"
      data-binder-tab="cancelled"
      data-binder-tone="cancelled"
      aria-selected="<?= $binderInitial === 'cancelled' ? 'true' : 'false' ?>">
      نوبت‌های لغو شده
      <span class="binder-tab-count"><?= count($cancelledFiltered) ?></span>
    </button>
  </div>
  <div class="binder-body">
    <section class="binder-panel<?= $binderInitial === 'upcoming' ? ' is-active' : '' ?>" data-binder-panel="upcoming" role="tabpanel"<?= $binderInitial === 'upcoming' ? '' : ' hidden' ?>>
      <form class="appt-search-bar" method="get" action="<?= e(url('/doctor/appointments')) ?>" id="appt-upcoming-search">
        <input type="hidden" name="tab" value="upcoming">
        <div class="appt-search-field">
          <label class="label" for="appt_search_q_up">جستجو با نام</label>
          <input
            class="input"
            type="search"
            id="appt_search_q_up"
            name="q"
            value="<?= e($searchQ) ?>"
            placeholder="<?= $seeAllAppointments ? 'نام مراجعه‌کننده…' : 'نام مراجعه‌کننده خودتان…' ?>"
            autocomplete="off"
          >
        </div>
        <div class="appt-search-field">
          <label class="label" for="appt_search_day_view_up">جستجو با تاریخ</label>
          <input
            class="input"
            type="text"
            id="appt_search_day_view_up"
            data-jdp
            data-jdp-only-date
            autocomplete="off"
            readonly
            placeholder="کلیک کنید تا تقویم باز شود"
            style="cursor:pointer"
            value="<?= e($searchJalali) ?>"
          >
          <input type="hidden" name="day" id="appt_search_day_up" value="<?= e($searchDay) ?>">
        </div>
        <div class="appt-search-actions">
          <button class="btn btn-primary" type="submit">جستجو</button>
          <?php if ($filterActive): ?>
            <a class="btn btn-outline" href="<?= e(url('/doctor/appointments?tab=upcoming')) ?>">پاک کردن</a>
          <?php endif; ?>
        </div>
      </form>
      <?php if ($filterActive): ?>
        <p class="muted" style="margin:0 0 .85rem;font-size:.85rem">
          نتیجه: <?= e($filterHint) ?>
          — <?= to_fa_digits((string) count($upcomingFiltered)) ?> نوبت
        </p>
        <?php
          $appointmentList = $upcomingFiltered;
          $appointmentEmpty = 'با این جستجو نوبت پیش‌رویی پیدا نشد.';
          $appointmentShowDoctor = $showDoctorOnCards;
          require __DIR__ . '/../../includes/doctor_appointment_cards.php';
        ?>
      <?php else: ?>
        <?php
          $ymdPack = $upcomingYmd;
          $ymdEmpty = 'نوبت پیش‌رویی نیست.';
          $ymdRenderItems = $ymdRenderDoctor;
          require __DIR__ . '/../../includes/appointment_ymd_binder.php';
        ?>
      <?php endif; ?>
    </section>
    <section class="binder-panel<?= $binderInitial === 'done' ? ' is-active' : '' ?>" data-binder-panel="done" role="tabpanel"<?= $binderInitial === 'done' ? '' : ' hidden' ?>>
      <p class="muted" style="margin:0 0 .85rem;font-size:.9rem">نوبت‌های برگزارشده یا گذشته. از تب ماه می‌توانید ببینید ماه پیش با چه کسانی وقت داشته‌اید.</p>
      <form class="appt-search-bar" method="get" action="<?= e(url('/doctor/appointments')) ?>" id="appt-done-search">
        <input type="hidden" name="tab" value="done">
        <div class="appt-search-field">
          <label class="label" for="appt_search_q_dn">جستجو با نام</label>
          <input
            class="input"
            type="search"
            id="appt_search_q_dn"
            name="q"
            value="<?= e($searchQ) ?>"
            placeholder="<?= $seeAllAppointments ? 'نام مراجعه‌کننده…' : 'نام مراجعه‌کننده خودتان…' ?>"
            autocomplete="off"
          >
        </div>
        <div class="appt-search-field">
          <label class="label" for="appt_search_day_view_dn">جستجو با تاریخ</label>
          <input
            class="input"
            type="text"
            id="appt_search_day_view_dn"
            data-jdp
            data-jdp-only-date
            autocomplete="off"
            readonly
            placeholder="کلیک کنید تا تقویم باز شود"
            style="cursor:pointer"
            value="<?= e($searchJalali) ?>"
          >
          <input type="hidden" name="day" id="appt_search_day_dn" value="<?= e($searchDay) ?>">
        </div>
        <div class="appt-search-actions">
          <button class="btn btn-primary" type="submit">جستجو</button>
          <?php if ($filterActive): ?>
            <a class="btn btn-outline" href="<?= e(url('/doctor/appointments?tab=done')) ?>">پاک کردن</a>
          <?php endif; ?>
        </div>
      </form>
      <?php if ($filterActive): ?>
        <p class="muted" style="margin:0 0 .85rem;font-size:.85rem">
          نتیجه: <?= e($filterHint) ?>
          — <?= to_fa_digits((string) count($doneFiltered)) ?> نوبت
        </p>
        <?php
          $appointmentList = $doneFiltered;
          $appointmentEmpty = 'با این جستجو نوبت انجام‌شده‌ای پیدا نشد.';
          $appointmentShowDoctor = $showDoctorOnCards;
          require __DIR__ . '/../../includes/doctor_appointment_cards.php';
        ?>
      <?php else: ?>
        <?php
          $ymdPack = $doneYmd;
          $ymdEmpty = 'نوبت انجام‌شده‌ای نیست.';
          $ymdRenderItems = $ymdRenderDoctor;
          require __DIR__ . '/../../includes/appointment_ymd_binder.php';
        ?>
      <?php endif; ?>
    </section>
    <section class="binder-panel<?= $binderInitial === 'cancelled' ? ' is-active' : '' ?>" data-binder-panel="cancelled" role="tabpanel"<?= $binderInitial === 'cancelled' ? '' : ' hidden' ?>>
      <p class="muted" style="margin:0 0 .85rem;font-size:.9rem">نوبت‌هایی که لغو شده‌اند (توسط مراجع، درمانگر یا سیستم).</p>
      <form class="appt-search-bar" method="get" action="<?= e(url('/doctor/appointments')) ?>" id="appt-cancelled-search">
        <input type="hidden" name="tab" value="cancelled">
        <div class="appt-search-field">
          <label class="label" for="appt_search_q_cn">جستجو با نام</label>
          <input
            class="input"
            type="search"
            id="appt_search_q_cn"
            name="q"
            value="<?= e($searchQ) ?>"
            placeholder="<?= $seeAllAppointments ? 'نام مراجعه‌کننده…' : 'نام مراجعه‌کننده خودتان…' ?>"
            autocomplete="off"
          >
        </div>
        <div class="appt-search-field">
          <label class="label" for="appt_search_day_view_cn">جستجو با تاریخ</label>
          <input
            class="input"
            type="text"
            id="appt_search_day_view_cn"
            data-jdp
            data-jdp-only-date
            autocomplete="off"
            readonly
            placeholder="کلیک کنید تا تقویم باز شود"
            style="cursor:pointer"
            value="<?= e($searchJalali) ?>"
          >
          <input type="hidden" name="day" id="appt_search_day_cn" value="<?= e($searchDay) ?>">
        </div>
        <div class="appt-search-actions">
          <button class="btn btn-primary" type="submit">جستجو</button>
          <?php if ($filterActive): ?>
            <a class="btn btn-outline" href="<?= e(url('/doctor/appointments?tab=cancelled')) ?>">پاک کردن</a>
          <?php endif; ?>
        </div>
      </form>
      <?php if ($filterActive): ?>
        <p class="muted" style="margin:0 0 .85rem;font-size:.85rem">
          نتیجه: <?= e($filterHint) ?>
          — <?= to_fa_digits((string) count($cancelledFiltered)) ?> نوبت
        </p>
        <?php
          $appointmentList = $cancelledFiltered;
          $appointmentEmpty = 'با این جستجو نوبت لغوشده‌ای پیدا نشد.';
          $appointmentShowDoctor = $showDoctorOnCards;
          require __DIR__ . '/../../includes/doctor_appointment_cards.php';
        ?>
      <?php else: ?>
        <?php
          $ymdPack = $cancelledYmd;
          $ymdEmpty = 'نوبت لغوشده‌ای نیست.';
          $ymdRenderItems = $ymdRenderDoctor;
          require __DIR__ . '/../../includes/appointment_ymd_binder.php';
        ?>
      <?php endif; ?>
    </section>
  </div>
</div>
<?php
$pageHead = '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@majidh1/jalalidatepicker/dist/jalalidatepicker.min.css">';
$pageScripts = '<script src="' . e(url('/assets/js/binder-tabs.js')) . '?v=20260920a"></script>'
    . '<script src="' . e(url('/assets/js/ymd-cascade.js')) . '?v=20260910r"></script>'
    . '<script src="https://cdn.jsdelivr.net/npm/jalaali-js@1.2.7/dist/jalaali.min.js"></script>'
    . '<script src="https://cdn.jsdelivr.net/npm/@majidh1/jalalidatepicker/dist/jalalidatepicker.min.js"></script>'
    . '<script>
(function(){
  function faToEn(str){ return String(str).replace(/[۰-۹]/g, function(d){ return "۰۱۲۳۴۵۶۷۸۹".indexOf(d); }); }
  function pad(n){ return (n < 10 ? "0" : "") + n; }
  function bindDay(viewId, hiddenId, formId){
    var view = document.getElementById(viewId);
    var hidden = document.getElementById(hiddenId);
    if (!view || !hidden || typeof jalaali === "undefined") return;
    function syncDay(){
      var t = faToEn(view.value).replace(/-/g, "/").trim();
      if (t === "") { hidden.value = ""; return; }
      var p = t.split("/");
      if (p.length !== 3) { hidden.value = ""; return; }
      var g = jalaali.toGregorian(parseInt(p[0],10), parseInt(p[1],10), parseInt(p[2],10));
      hidden.value = g.gy + "-" + pad(g.gm) + "-" + pad(g.gd);
    }
    if (typeof jalaliDatepicker !== "undefined") {
      jalaliDatepicker.startWatch({
        selector: "#" + viewId,
        time: false,
        hideAfterChange: true,
        showTodayBtn: true,
        showEmptyBtn: true
      });
    }
    view.addEventListener("jdp:change", syncDay);
    view.addEventListener("change", syncDay);
    syncDay();
    var form = document.getElementById(formId);
    if (form) form.addEventListener("submit", function(){ syncDay(); });
  }
  bindDay("appt_search_day_view_up", "appt_search_day_up", "appt-upcoming-search");
  bindDay("appt_search_day_view_dn", "appt_search_day_dn", "appt-done-search");
  bindDay("appt_search_day_view_cn", "appt_search_day_cn", "appt-cancelled-search");
})();
</script>';
$GLOBALS['pageHead'] = $pageHead;
$GLOBALS['pageScripts'] = $pageScripts;
render_doctor_page('نوبت‌ها', ob_get_clean());
