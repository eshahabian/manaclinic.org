<?php
declare(strict_types=1);

function ensure_therapist_payouts_schema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    $pdo->exec("
      CREATE TABLE IF NOT EXISTS therapist_payouts (
        id VARCHAR(32) PRIMARY KEY,
        doctor_id VARCHAR(32) NOT NULL,
        period_start DATE NOT NULL,
        period_end DATE NOT NULL,
        payment_count INT NOT NULL DEFAULT 0,
        total_amount INT NOT NULL DEFAULT 0,
        settled_by VARCHAR(32) NOT NULL,
        settled_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_payout_doctor (doctor_id, settled_at)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $pdo->exec("
      CREATE TABLE IF NOT EXISTS therapist_payout_lines (
        id VARCHAR(32) PRIMARY KEY,
        payout_id VARCHAR(32) NOT NULL,
        payment_id VARCHAR(32) NULL,
        patient_name VARCHAR(190) NOT NULL,
        session_at DATETIME NULL,
        amount INT NOT NULL,
        INDEX idx_payout_line (payout_id)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $ready = true;
}

/** @return list<array<string, mixed>> */
function therapist_payout_payments(PDO $pdo, string $doctorId, string $from, string $to): array
{
    $stmt = $pdo->prepare("
      SELECT p.id, p.amount, a.starts_at, u.name AS patient_name
      FROM payments p
      JOIN appointments a ON a.id = p.appointment_id
      JOIN users u ON u.id = a.patient_id
      WHERE a.doctor_id = ?
        AND p.status = 'PAID'
        AND DATE(a.starts_at) BETWEEN ? AND ?
      ORDER BY a.starts_at ASC, u.name ASC
    ");
    $stmt->execute([$doctorId, $from, $to]);

    return $stmt->fetchAll() ?: [];
}

function therapist_payout_find_exact(PDO $pdo, string $doctorId, string $from, string $to): ?array
{
    ensure_therapist_payouts_schema($pdo);
    $stmt = $pdo->prepare('
      SELECT * FROM therapist_payouts
      WHERE doctor_id=? AND period_start=? AND period_end=?
      ORDER BY settled_at DESC
      LIMIT 1
    ');
    $stmt->execute([$doctorId, $from, $to]);
    $row = $stmt->fetch();

    return $row ?: null;
}

function therapist_payout_settle(PDO $pdo, string $doctorId, string $from, string $to, string $actorId): string
{
    ensure_therapist_payouts_schema($pdo);
    if ($from > $to) {
        throw new RuntimeException('تاریخ شروع باید قبل از تاریخ پایان باشد.');
    }
    $existing = therapist_payout_find_exact($pdo, $doctorId, $from, $to);
    if ($existing) {
        throw new RuntimeException('این بازه قبلاً تسویه شده است.');
    }
    $rows = therapist_payout_payments($pdo, $doctorId, $from, $to);
    if ($rows === []) {
        throw new RuntimeException('در این بازه پرداخت تسویه‌نشده‌ای برای ثبت نیست.');
    }
    $id = cuid();
    $total = 0;
    foreach ($rows as $row) {
        $total += (int) ($row['amount'] ?? 0);
    }
    $pdo->beginTransaction();
    try {
        $pdo->prepare('
          INSERT INTO therapist_payouts (id, doctor_id, period_start, period_end, payment_count, total_amount, settled_by)
          VALUES (?,?,?,?,?,?,?)
        ')->execute([$id, $doctorId, $from, $to, count($rows), $total, $actorId]);
        $line = $pdo->prepare('
          INSERT INTO therapist_payout_lines (id, payout_id, payment_id, patient_name, session_at, amount)
          VALUES (?,?,?,?,?,?)
        ');
        foreach ($rows as $row) {
            $line->execute([
                cuid(),
                $id,
                (string) ($row['id'] ?? ''),
                (string) ($row['patient_name'] ?? ''),
                (string) ($row['starts_at'] ?? ''),
                (int) ($row['amount'] ?? 0),
            ]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return $id;
}

/** @return list<array<string, mixed>> */
function therapist_payout_archive(PDO $pdo, ?string $doctorId): array
{
    ensure_therapist_payouts_schema($pdo);
    if ($doctorId !== null && $doctorId !== '') {
        $stmt = $pdo->prepare('
          SELECT t.*, u.name AS doctor_name, s.name AS settled_by_name
          FROM therapist_payouts t
          JOIN doctor_profiles dp ON dp.id = t.doctor_id
          JOIN users u ON u.id = dp.user_id
          JOIN users s ON s.id = t.settled_by
          WHERE t.doctor_id=?
          ORDER BY t.settled_at DESC
          LIMIT 80
        ');
        $stmt->execute([$doctorId]);
    } else {
        $stmt = $pdo->query('
          SELECT t.*, u.name AS doctor_name, s.name AS settled_by_name
          FROM therapist_payouts t
          JOIN doctor_profiles dp ON dp.id = t.doctor_id
          JOIN users u ON u.id = dp.user_id
          JOIN users s ON s.id = t.settled_by
          ORDER BY t.settled_at DESC
          LIMIT 80
        ');
    }

    return $stmt->fetchAll() ?: [];
}

function therapist_payout_archive_one(PDO $pdo, string $id, ?string $doctorId): ?array
{
    ensure_therapist_payouts_schema($pdo);
    $sql = '
      SELECT t.*, u.name AS doctor_name, s.name AS settled_by_name
      FROM therapist_payouts t
      JOIN doctor_profiles dp ON dp.id = t.doctor_id
      JOIN users u ON u.id = dp.user_id
      JOIN users s ON s.id = t.settled_by
      WHERE t.id=?
    ';
    $params = [$id];
    if ($doctorId !== null && $doctorId !== '') {
        $sql .= ' AND t.doctor_id=?';
        $params[] = $doctorId;
    }
    $stmt = $pdo->prepare($sql . ' LIMIT 1');
    $stmt->execute($params);
    $head = $stmt->fetch();
    if (!$head) {
        return null;
    }
    $lines = $pdo->prepare('SELECT * FROM therapist_payout_lines WHERE payout_id=? ORDER BY session_at ASC, patient_name ASC');
    $lines->execute([$id]);
    $head['lines'] = $lines->fetchAll() ?: [];

    return $head;
}

/** @param list<array<string, mixed>> $rows */
function therapist_payout_send_csv(array $rows, string $filename): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    if ($out === false) {
        exit;
    }
    fputcsv($out, ['مراجع', 'زمان جلسه', 'مبلغ']);
    foreach ($rows as $row) {
        $when = (string) ($row['starts_at'] ?? $row['session_at'] ?? '');
        fputcsv($out, [
            (string) ($row['patient_name'] ?? ''),
            $when !== '' ? format_fa_datetime($when) : '',
            (string) (int) ($row['amount'] ?? 0),
        ]);
    }
    fclose($out);
    exit;
}

function therapist_payouts_screen(PDO $pdo, string $basePath, bool $canSettle, ?string $lockDoctorId): string
{
    ensure_therapist_payouts_schema($pdo);
    $doctors = [];
    if ($lockDoctorId === null) {
        if (!function_exists('secretary_active_doctors')) {
            require_once __DIR__ . '/secretary_patient.php';
        }
        $doctors = secretary_active_doctors($pdo);
    } else {
        $stmt = $pdo->prepare('
          SELECT dp.id, u.name
          FROM doctor_profiles dp
          JOIN users u ON u.id = dp.user_id
          WHERE dp.id=?
          LIMIT 1
        ');
        $stmt->execute([$lockDoctorId]);
        $one = $stmt->fetch();
        if ($one) {
            $doctors = [$one];
        }
    }
    $doctorId = $lockDoctorId ?? trim((string) ($_GET['doctor'] ?? ''));
    if ($doctorId === '' && $doctors) {
        $doctorId = (string) $doctors[0]['id'];
    }
    $known = false;
    $doctorName = '';
    foreach ($doctors as $doctor) {
        if ((string) $doctor['id'] === $doctorId) {
            $known = true;
            $doctorName = (string) $doctor['name'];
            break;
        }
    }
    if (!$known) {
        $doctorId = '';
    }
    $fromRaw = trim((string) ($_GET['from'] ?? ''));
    $toRaw = trim((string) ($_GET['to'] ?? ''));
    $from = $fromRaw !== '' ? parse_user_date($fromRaw) : null;
    $to = $toRaw !== '' ? parse_user_date($toRaw) : null;
    $archiveId = trim((string) ($_GET['archive'] ?? ''));
    $archiveOne = $archiveId !== '' ? therapist_payout_archive_one($pdo, $archiveId, $lockDoctorId) : null;
    $rows = ($doctorId !== '' && $from && $to && $from <= $to) ? therapist_payout_payments($pdo, $doctorId, $from, $to) : [];
    $settled = ($doctorId !== '' && $from && $to) ? therapist_payout_find_exact($pdo, $doctorId, $from, $to) : null;
    $archive = therapist_payout_archive($pdo, $lockDoctorId);
    $query = 'doctor=' . rawurlencode($doctorId) . '&from=' . rawurlencode($fromRaw) . '&to=' . rawurlencode($toRaw);

    if (($_GET['export'] ?? '') === 'csv' && $doctorId !== '' && $from && $to && $from <= $to) {
        therapist_payout_send_csv($rows, 'payout-' . $from . '-' . $to . '.csv');
    }
    if (($_GET['export'] ?? '') === 'csv' && $archiveOne) {
        therapist_payout_send_csv($archiveOne['lines'] ?? [], 'payout-archive.csv');
    }

    ob_start();
    ?>
    <h1>پرداخت درمانگر</h1>
    <p class="muted"><?= $canSettle
        ? 'بازه را انتخاب کنید تا پرداخت‌های همان درمانگر دیده شود. با «تسویه شد» همان بازه به آرشیو منشی و درمانگر می‌رود.'
        : 'پرداخت‌های خودتان را در بازه انتخابی ببینید. تسویه را منشی ثبت می‌کند و بعد اینجا در آرشیو می‌ماند.' ?></p>
    <form class="panel form-stack" method="get" action="<?= e(url($basePath)) ?>" style="margin-top:1rem">
      <?php if ($lockDoctorId === null): ?>
        <div>
          <label class="label" for="payout-doctor">درمانگر</label>
          <select class="input" id="payout-doctor" name="doctor" required>
            <?php foreach ($doctors as $doctor): ?>
              <option value="<?= e((string) $doctor['id']) ?>"<?= $doctorId === (string) $doctor['id'] ? ' selected' : '' ?>><?= e((string) $doctor['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      <?php else: ?>
        <input type="hidden" name="doctor" value="<?= e($doctorId) ?>">
        <p style="margin:0"><strong><?= e($doctorName) ?></strong></p>
      <?php endif; ?>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:.5rem">
        <div>
          <label class="label" for="payout-from">از تاریخ</label>
          <input class="input" id="payout-from" name="from" type="text" data-jdp data-jdp-only-date autocomplete="off" readonly required placeholder="تاریخ شمسی" value="<?= e($fromRaw) ?>" style="cursor:pointer">
        </div>
        <div>
          <label class="label" for="payout-to">تا تاریخ</label>
          <input class="input" id="payout-to" name="to" type="text" data-jdp data-jdp-only-date autocomplete="off" readonly required placeholder="تاریخ شمسی" value="<?= e($toRaw) ?>" style="cursor:pointer">
        </div>
      </div>
      <button class="btn btn-primary" type="submit">نمایش پرداخت‌ها</button>
    </form>
    <?php if ($fromRaw !== '' || $toRaw !== ''): ?>
      <div class="panel" style="margin-top:1rem;overflow:auto">
        <?php if (!$from || !$to || $from > $to || $doctorId === ''): ?>
          <p class="muted" style="margin:0">بازه تاریخ معتبر نیست.</p>
        <?php elseif (!$rows): ?>
          <p class="muted" style="margin:0">در این بازه پرداخت ثبت‌شده‌ای نیست.</p>
        <?php else: ?>
          <?php $sum = 0; foreach ($rows as $row) { $sum += (int) ($row['amount'] ?? 0); } ?>
          <p style="margin:0 0 .75rem"><strong><?= e($doctorName) ?></strong> · <?= e(to_fa_digits((string) count($rows))) ?> پرداخت · <?= e(format_price($sum)) ?></p>
          <table class="table">
            <thead><tr><th>مراجع</th><th>زمان جلسه</th><th>مبلغ</th></tr></thead>
            <tbody>
              <?php foreach ($rows as $row): ?>
                <tr>
                  <td><?= e((string) $row['patient_name']) ?></td>
                  <td><?= e(format_fa_datetime((string) $row['starts_at'])) ?></td>
                  <td><?= e(format_price((int) $row['amount'])) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          <p style="margin:.75rem 0 0">
            <a class="btn btn-outline btn-sm" href="<?= e(url($basePath . '?' . $query . '&export=csv')) ?>">خروجی</a>
          </p>
          <?php if ($settled): ?>
            <p class="muted" style="margin:.75rem 0 0">این بازه تسویه شده و در آرشیو است.</p>
          <?php elseif ($canSettle): ?>
            <form method="post" action="<?= e(url($basePath)) ?>" style="margin:.75rem 0 0" onsubmit="return confirm('این بازه تسویه شود و به آرشیو برود؟');">
              <?= csrf_field() ?>
              <input type="hidden" name="doctor_id" value="<?= e($doctorId) ?>">
              <input type="hidden" name="from_date" value="<?= e($fromRaw) ?>">
              <input type="hidden" name="to_date" value="<?= e($toRaw) ?>">
              <button class="btn btn-primary" type="submit">تسویه شد</button>
            </form>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    <?php endif; ?>
    <?php if ($archiveOne): ?>
      <div class="panel" style="margin-top:1rem;overflow:auto">
        <h2 style="margin:0 0 .5rem;font-size:1rem">آرشیو تسویه</h2>
        <p class="muted" style="margin:0 0 .75rem">
          <?= e((string) $archiveOne['doctor_name']) ?>
          · <?= e(to_jalali_label(substr((string) $archiveOne['period_start'], 0, 10))) ?>
          تا <?= e(to_jalali_label(substr((string) $archiveOne['period_end'], 0, 10))) ?>
          · <?= e(format_price((int) $archiveOne['total_amount'])) ?>
          · ثبت <?= e((string) $archiveOne['settled_by_name']) ?>
        </p>
        <table class="table">
          <thead><tr><th>مراجع</th><th>زمان جلسه</th><th>مبلغ</th></tr></thead>
          <tbody>
            <?php foreach ($archiveOne['lines'] as $line): ?>
              <tr>
                <td><?= e((string) $line['patient_name']) ?></td>
                <td><?= !empty($line['session_at']) ? e(format_fa_datetime((string) $line['session_at'])) : '—' ?></td>
                <td><?= e(format_price((int) $line['amount'])) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
    <div class="panel" style="margin-top:1rem;overflow:auto">
      <h2 style="margin:0 0 .75rem;font-size:1rem">آرشیو تسویه‌ها</h2>
      <?php if (!$archive): ?>
        <p class="muted" style="margin:0">هنوز بازه‌ای تسویه نشده است.</p>
      <?php else: ?>
        <table class="table">
          <thead><tr><th>درمانگر</th><th>از</th><th>تا</th><th>مبلغ</th><th>تسویه</th><th></th></tr></thead>
          <tbody>
            <?php foreach ($archive as $item): ?>
              <tr>
                <td><?= e((string) $item['doctor_name']) ?></td>
                <td><?= e(to_jalali_label(substr((string) $item['period_start'], 0, 10))) ?></td>
                <td><?= e(to_jalali_label(substr((string) $item['period_end'], 0, 10))) ?></td>
                <td><?= e(format_price((int) $item['total_amount'])) ?></td>
                <td><?= e(format_fa_datetime((string) $item['settled_at'])) ?></td>
                <td><a href="<?= e(url($basePath . '?archive=' . rawurlencode((string) $item['id']))) ?>">دیدن</a></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
    <?php
    return (string) ob_get_clean();
}

