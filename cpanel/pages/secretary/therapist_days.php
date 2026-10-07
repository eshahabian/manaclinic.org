<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/secretary_panel.php';
require_once __DIR__ . '/../../includes/secretary_patient.php';
require_once __DIR__ . '/../../includes/availability.php';
require_once __DIR__ . '/../../includes/therapist_presence.php';

require_login(['SECRETARY']);
ensure_availability_schema($pdo);
ensure_therapist_presence_schema($pdo);

$doctors = secretary_active_doctors($pdo);
$doctorId = trim((string) ($_GET['doctor'] ?? ''));
if ($doctorId === '' && $doctors) {
    $doctorId = (string) $doctors[0]['id'];
}
$known = false;
foreach ($doctors as $doctor) {
    if ((string) $doctor['id'] === $doctorId) {
        $known = true;
        break;
    }
}
if (!$known) {
    $doctorId = '';
}

$today = date('Y-m-d');
$end = appointment_booking_horizon_end($today);
$days = [];
$cursor = $today;
while ($cursor <= $end) {
    $days[] = $cursor;
    $cursor = date('Y-m-d', strtotime($cursor . ' +1 day') ?: time());
}
$presence = $doctorId !== '' ? therapist_presence_map($pdo, $doctorId, $today, $end) : [];
$hoursByDate = [];
if ($doctorId !== '') {
    $stmt = $pdo->prepare('SELECT date, available_hours FROM availabilities WHERE doctor_id=? AND date BETWEEN ? AND ?');
    $stmt->execute([$doctorId, $today, $end]);
    foreach ($stmt->fetchAll() as $row) {
        $hoursByDate[substr((string) $row['date'], 0, 10)] = appointment_hours_decode((string) ($row['available_hours'] ?? ''));
    }
}
$bookingHours = appointment_booking_hours();

ob_start();
?>
<h1>روزهای درمانگر</h1>
<p class="muted">روزهایی که درمانگر در کلینیک هست را انتخاب کنید تا بشود نوبت گرفت. از امروز فقط تا دو هفته بعد باز است.</p>

<form method="get" action="<?= e(url('/secretary/therapist-days')) ?>" class="panel" style="margin-top:1rem">
  <label class="label" for="presence-doctor">درمانگر</label>
  <select class="input" id="presence-doctor" name="doctor" onchange="this.form.submit()">
    <?php if (!$doctors): ?>
      <option value="">درمانگر فعالی نیست</option>
    <?php endif; ?>
    <?php foreach ($doctors as $doctor): ?>
      <option value="<?= e((string) $doctor['id']) ?>"<?= $doctorId === (string) $doctor['id'] ? ' selected' : '' ?>><?= e((string) $doctor['name']) ?></option>
    <?php endforeach; ?>
  </select>
</form>

<?php if ($doctorId !== ''): ?>
  <form method="post" action="<?= e(url('/secretary/therapist-days')) ?>" class="stack" style="margin-top:1rem">
    <?= csrf_field() ?>
    <input type="hidden" name="doctor_id" value="<?= e($doctorId) ?>">
    <?php foreach ($days as $date): ?>
      <?php
        $saved = $presence[$date] ?? null;
        $existingHours = $hoursByDate[$date] ?? [];
        $checked = $saved ? ((int) $saved['present'] === 1) : ($existingHours !== []);
        $from = $saved['from'] ?? ($existingHours[0] ?? 9);
        $to = $saved['to'] ?? ($existingHours !== [] ? $existingHours[count($existingHours) - 1] : 17);
      ?>
      <div class="panel" style="margin:0">
        <input type="hidden" name="days[]" value="<?= e($date) ?>">
        <label style="display:flex;gap:.6rem;align-items:center;font-weight:600">
          <input type="checkbox" name="present[<?= e($date) ?>]" value="1"<?= $checked ? ' checked' : '' ?>>
          <?= e(to_jalali_label($date)) ?>
        </label>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:.5rem;margin-top:.75rem">
          <label>
            <span class="label">از ساعت</span>
            <select class="input" name="hour_from[<?= e($date) ?>]">
              <?php foreach ($bookingHours as $hour): ?>
                <option value="<?= (int) $hour ?>"<?= (int) $hour === (int) $from ? ' selected' : '' ?>><?= e(appointment_hour_chip_label((int) $hour)) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label>
            <span class="label">تا ساعت</span>
            <select class="input" name="hour_to[<?= e($date) ?>]">
              <?php foreach ($bookingHours as $hour): ?>
                <option value="<?= (int) $hour ?>"<?= (int) $hour === (int) $to ? ' selected' : '' ?>><?= e(appointment_hour_chip_label((int) $hour)) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
        </div>
      </div>
    <?php endforeach; ?>
    <button class="btn btn-primary" type="submit">ذخیره روزها</button>
  </form>
<?php endif; ?>
<?php
render_secretary_page('روزهای درمانگر', ob_get_clean());
