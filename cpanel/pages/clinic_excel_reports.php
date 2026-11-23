<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/clinic_excel_reports.php';

$user = require_login();
$reportPath = '/staff/excel-reports';
$embeddedReport = ($_GET['embed'] ?? null) === '1';
$reportQuery = $embeddedReport ? 'embed=1&' : '';
header('Cache-Control: private, no-store');
header('X-Robots-Tag: noindex, nofollow');
if (!clinic_excel_reports_allowed($user)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit('دسترسی به خروجی اکسل مجاز نیست.');
}
if (!in_array($method, ['GET', 'POST'], true)) {
    http_response_code(405);
    header('Allow: GET, POST');
    exit;
}

$titles = [
    'cash' => 'تنخواه',
    'doctor' => 'نوبت‌ها و پرداخت‌های درمانگر',
    'patient' => 'تاریخچه نوبت‌های مراجعه‌کننده',
];
if (($user['role'] ?? '') === 'SECRETARY') {
    unset($titles['cash']);
    if (($_GET['type'] ?? '') === 'cash') {
        http_response_code(403);
        exit('دسترسی به گزارش تنخواه مجاز نیست.');
    }
}
$type = $_GET['type'] ?? array_key_first($titles);
if (!is_string($type) || !isset($titles[$type])) {
    http_response_code(400);
    exit('نوع گزارش نامعتبر است.');
}
$formPath = $reportPath . '?' . $reportQuery . 'type=' . $type;
$search = $type === 'patient' && is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '';
$options = [];
if ($type === 'doctor') {
    $options = $pdo->query('
        SELECT dp.id, u.name FROM doctor_profiles dp JOIN users u ON u.id = dp.user_id
        ORDER BY dp.is_active DESC, u.name, dp.id
    ')->fetchAll(PDO::FETCH_ASSOC);
} elseif ($search !== '') {
    $stmt = $pdo->prepare("
        SELECT u.id, u.name, RIGHT(u.phone, 4) AS phone_tail
        FROM users u
        WHERE u.name LIKE ? AND EXISTS (SELECT 1 FROM appointments a WHERE a.patient_id = u.id)
        ORDER BY u.name, u.id LIMIT 51
    ");
    $stmt->execute(['%' . $search . '%']);
    $options = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $formPath .= '&q=' . rawurlencode($search);
}
$morePatients = $type === 'patient' && count($options) > 50;
if ($morePatients) {
    array_pop($options);
}

$month = jalali_current_month_meta();
$year = (int) $month['year'];
$monthNumber = (int) $month['month'];
$requestedYear = filter_var($_GET['jy'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1300, 'max_range' => 1600]]);
$requestedMonth = filter_var($_GET['jm'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 12]]);
if ($type === 'cash' && $requestedYear && $requestedMonth) {
    $year = $requestedYear;
    $monthNumber = $requestedMonth;
}
$from = $type === 'patient' ? '' : to_fa_digits(sprintf('%04d/%02d/01', $year, $monthNumber));
$to = $type === 'patient' ? '' : to_fa_digits(sprintf('%04d/%02d/%02d', $year, $monthNumber, jalali_month_length($year, $monthNumber)));
$selectedId = '';
$error = '';
if ($method === 'POST') {
    csrf_verify();
    $from = post('from');
    $to = post('to');
    $selectedId = post('person_id');
    $start = clinic_excel_report_date($from);
    $end = clinic_excel_report_date($to);
    $allDates = $type === 'patient' && $from === '' && $to === '';

    if (!$allDates && ($start === null || $end === null || $end < $start)) {
        $error = 'تاریخ شروع و پایان شمسی را وارد کنید. تاریخ پایان باید برابر یا بعد از تاریخ شروع باشد.';
    } elseif ($type !== 'cash' && !in_array($selectedId, array_column($options, 'id'), true)) {
        $error = 'یک نفر را از فهرست انتخاب کنید.';
    } else {
        try {
            $rows = $type === 'cash'
                ? clinic_excel_cash_rows(petty_cash_month_report($pdo, $start, $end))
                : clinic_excel_appointment_rows($pdo, $type, $selectedId, $start, $end);
            clinic_excel_send('mana-' . $type . '-' . ($start ?? 'all') . ($end === null ? '' : '-to-' . $end), $rows);
            exit;
        } catch (Throwable $exception) {
            error_log('Clinic Excel report ' . $type . ' failed: ' . $exception->getMessage());
            http_response_code(500);
            $error = 'ساخت فایل اکسل انجام نشد. دوباره تلاش کنید.';
        }
    }
    if (http_response_code() !== 500) {
        http_response_code(422);
    }
}

?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>خروجی اکسل</title>
  <link rel="stylesheet" href="<?= e(url('/assets/css/style.css')) ?>?v=20261122paid-slot">
  <link rel="stylesheet" href="<?= e(url('/assets/css/local-font.css')) ?>">
  <link rel="stylesheet" href="<?= e(url('/assets/css/clinic-excel-dialog.css')) ?>?v=1">
</head>
<body class="clinic-excel-form-page">
<main>
<?php if (!$embeddedReport): ?>
  <h1>خروجی اکسل</h1>
<?php endif; ?>
<nav class="panel" aria-label="نوع گزارش" style="display:flex;flex-wrap:wrap;gap:.5rem">
  <?php foreach ($titles as $key => $title): ?>
    <a class="btn <?= $key === $type ? 'btn-primary' : 'btn-outline' ?>" href="<?= e(url($reportPath . '?' . $reportQuery . 'type=' . $key)) ?>" <?= $key === $type ? 'aria-current="page"' : '' ?>><?= e($title) ?></a>
  <?php endforeach; ?>
</nav>
<?php if ($type === 'patient'): ?>
  <form class="panel form-stack" method="get" action="<?= e(url($reportPath)) ?>">
    <input type="hidden" name="type" value="patient">
    <?php if ($embeddedReport): ?>
      <input type="hidden" name="embed" value="1">
    <?php endif; ?>
    <label class="label" for="excel-search">نام مراجعه‌کننده</label>
    <input class="input" id="excel-search" name="q" value="<?= e($search) ?>" required>
    <div><button class="btn btn-outline" type="submit">جست‌وجو</button></div>
    <?php if ($search !== '' && !$options): ?>
      <p class="muted">مراجعه‌کننده‌ای با این نام و سابقه نوبت پیدا نشد.</p>
    <?php elseif ($morePatients): ?>
      <p class="muted">۵۰ نتیجه نخست نمایش داده می‌شود. برای نتایج دقیق‌تر نام کامل را وارد کنید.</p>
    <?php endif; ?>
  </form>
<?php endif; ?>
<?php if ($error !== ''): ?>
  <p class="panel" role="alert"><?= e($error) ?></p>
<?php endif; ?>
<?php if ($type !== 'patient' || $options): ?>
  <form class="panel form-stack" method="post" action="<?= e(url($formPath)) ?>">
    <?= csrf_field() ?>
    <?php if ($type !== 'cash'): ?>
      <label class="label" for="excel-person"><?= $type === 'doctor' ? 'درمانگر' : 'مراجعه‌کننده' ?></label>
      <select class="input" id="excel-person" name="person_id" required>
        <option value="">انتخاب کنید</option>
        <?php foreach ($options as $option): ?>
          <option value="<?= e($option['id']) ?>" <?= $selectedId === $option['id'] ? 'selected' : '' ?>>
            <?= e($option['name'] . (!empty($option['phone_tail']) ? ' · ' . to_fa_digits($option['phone_tail']) : '')) ?>
          </option>
        <?php endforeach; ?>
      </select>
    <?php endif; ?>
    <div class="grid-2">
      <?php foreach (['from' => ['از تاریخ شمسی', $from], 'to' => ['تا تاریخ شمسی', $to]] as $field => [$label, $value]): ?>
        <div>
          <label class="label" for="excel-<?= $field ?>"><?= $label ?></label>
          <input class="input" dir="ltr" id="excel-<?= $field ?>" name="<?= $field ?>" value="<?= e($value) ?>"
                 placeholder="۱۴۰۵/۰۸/۱۷" autocomplete="off" data-jdp data-jdp-only-date <?= $type !== 'patient' ? 'required' : '' ?>>
        </div>
      <?php endforeach; ?>
    </div>
    <?php if ($type === 'patient'): ?>
      <p class="muted">برای دریافت همه نوبت‌های این مراجعه‌کننده، هر دو تاریخ را خالی بگذارید.</p>
    <?php endif; ?>
    <div><button class="btn btn-primary" type="submit">دانلود اکسل</button></div>
  </form>
  <?= petty_cash_datepicker_assets() ?>
<?php endif; ?>
</main>
</body>
</html>
