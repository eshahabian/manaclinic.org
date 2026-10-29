<?php
declare(strict_types=1);

/** Read-only: open deferred payments by appointment day; current credits by wallet update day. */
function staff_app_appointment_parties_rows(PDO $pdo, string $side, DateTimeImmutable $day): array
{
    $bounds = [$day->format('Y-m-d') . ' 00:00:00', $day->modify('+1 day')->format('Y-m-d') . ' 00:00:00'];
    if ($side === 'creditors') {
        $sql = "SELECT u.name AS patient_name, w.balance AS amount, w.updated_at AS starts_at,
                       du.name AS doctor_name
                FROM wallets w JOIN users u ON u.id=w.user_id
                LEFT JOIN doctor_profiles dp ON dp.id=u.preferred_doctor_id
                LEFT JOIN users du ON du.id=dp.user_id
                WHERE w.balance>0 AND w.updated_at>=? AND w.updated_at<?
                  AND (u.role='PATIENT' OR LOWER(u.username)='eshahabian'
                       OR (u.name LIKE '%عماد%' AND u.name LIKE '%شهابیان%'))
                ORDER BY w.updated_at ASC, u.name ASC";
    } else {
        $sql = "SELECT p.amount, a.starts_at, a.staff_payment_note,
                       pu.name AS patient_name, du.name AS doctor_name, cu.name AS actor_name
                FROM payments p JOIN appointments a ON a.id=p.appointment_id
                JOIN users pu ON pu.id=a.patient_id
                JOIN doctor_profiles dp ON dp.id=a.doctor_id
                JOIN users du ON du.id=dp.user_id
                LEFT JOIN users cu ON cu.id=p.recorded_by_user_id
                WHERE p.status='PENDING' AND p.ref_id='LATER' AND a.status<>'CANCELLED'
                  AND a.starts_at>=? AND a.starts_at<?
                ORDER BY a.starts_at ASC, pu.name ASC";
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($bounds);
    return $stmt->fetchAll() ?: [];
}

function staff_app_appointment_parties_html(PDO $pdo, array $user, string $scheduleDay, array $query): string
{
    if (($user['role'] ?? '') !== 'SECRETARY' && !staff_app_is_eshahabian($user)) return '';
    $side = ($query['party_tab'] ?? '') === 'creditors' ? 'creditors' : 'debtors';
    $day = staff_app_schedule_selected_day((string) ($query['party_day'] ?? $scheduleDay));
    $href = static function (string $tab, string $date) use ($scheduleDay): string {
        return url('/app/appointments?' . http_build_query([
            'day' => $scheduleDay, 'party_tab' => $tab, 'party_day' => $date,
        ]) . '#appointment-parties');
    };
    $rows = [];
    $error = false;
    try {
        $rows = staff_app_appointment_parties_rows($pdo, $side, $day);
    } catch (Throwable $e) {
        error_log('staff app appointment parties: ' . $e->getMessage());
        $error = true;
    }
    $weekdays = ['یکشنبه', 'دوشنبه', 'سه‌شنبه', 'چهارشنبه', 'پنجشنبه', 'جمعه', 'شنبه'];
    ob_start();
    ?>
    <section id="appointment-parties" class="panel" aria-labelledby="appointment-parties-title" style="margin-top:1rem;scroll-margin-top:1rem">
      <h2 id="appointment-parties-title" style="margin:0 0 .8rem;font-size:1.05rem">بدهکاران و طلبکاران</h2>
      <nav class="sapp-check-tabs" aria-label="انتخاب بدهکاران یا طلبکاران" style="display:flex;gap:.5rem;margin-bottom:1rem">
        <?php foreach (['debtors' => 'بدهکاران', 'creditors' => 'طلبکاران'] as $tab => $label): ?>
          <a class="sapp-check-tab<?= $side === $tab ? ' is-active' : '' ?>" href="<?= e($href($tab, $day->format('Y-m-d'))) ?>"<?= $side === $tab ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
        <?php endforeach; ?>
      </nav>
      <nav class="sapp-daynav" aria-label="انتخاب روز <?= $side === 'creditors' ? 'طلبکاران' : 'بدهکاران' ?>">
        <a class="sapp-daynav-btn" href="<?= e($href($side, $day->modify('-1 day')->format('Y-m-d'))) ?>" aria-label="روز قبل"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m14 6-6 6 6 6"/></svg></a>
        <strong class="sapp-daynav-date sapp-schedule-date"><span><?= e($weekdays[(int) $day->format('w')] . ' · ' . to_jalali_label($day->format('Y-m-d'))) ?></span><bdi dir="ltr"><?= e($day->format('j F Y')) ?></bdi></strong>
        <a class="sapp-daynav-btn" href="<?= e($href($side, $day->modify('+1 day')->format('Y-m-d'))) ?>" aria-label="روز بعد"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m10 6 6 6-6 6"/></svg></a>
      </nav>
      <p class="muted" style="font-size:.85rem;line-height:1.7;margin:.5rem 0 1rem">
        <?= $side === 'creditors'
            ? 'مانده فعلی مثبت کیف پول؛ تفکیک روز بر اساس آخرین تغییر کیف پول است.'
            : 'پرداخت‌های بعدیِ تسویه‌نشده؛ تفکیک روز بر اساس تاریخ نوبت است.' ?>
        <a href="<?= e($href($side, (new DateTimeImmutable('today', new DateTimeZone('Asia/Tehran')))->format('Y-m-d'))) ?>">امروز</a>
      </p>
      <?php if ($error): ?>
        <p class="muted" role="status">بارگذاری این فهرست انجام نشد. دوباره تلاش کنید.</p>
      <?php elseif ($rows === []): ?>
        <p class="muted"><?= $side === 'creditors' ? 'در این روز طلبکاری با این مشخصات ثبت نشده است.' : 'برای این روز بدهیِ پرداخت بعدی باز نیست.' ?></p>
      <?php else: ?>
        <div class="sapp-list">
          <?php foreach ($rows as $row): ?>
            <article class="sapp-row" style="overflow-wrap:anywhere">
              <div style="display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:.5rem">
                <strong><?= e((string) ($row['patient_name'] ?? '')) ?></strong>
                <strong style="color:<?= $side === 'creditors' ? '#1a7a45' : '#a33b3b' ?>"><?= e(format_price((int) $row['amount'])) ?></strong>
              </div>
              <small><?= $side === 'creditors' ? 'درمانگر پرونده:' : 'نوبت با:' ?> <?= trim((string) ($row['doctor_name'] ?? '')) !== '' ? e((string) $row['doctor_name']) : 'مشخص نشده' ?></small>
              <small><?= $side === 'creditors' ? 'آخرین تغییر کیف پول:' : 'زمان نوبت:' ?> <?= e(format_fa_datetime((string) $row['starts_at'])) ?></small>
              <?php if ($side === 'debtors'): ?>
                <small>توضیح: <?= e(trim((string) ($row['staff_payment_note'] ?? '')) !== '' ? (string) $row['staff_payment_note'] : 'پرداخت بعدی') ?></small>
                <?php if (trim((string) ($row['actor_name'] ?? '')) !== ''): ?><small>ثبت توسط: <?= e((string) $row['actor_name']) ?></small><?php endif; ?>
              <?php else: ?>
                <small>طلب از کلینیک · مانده فعلی کیف پول</small>
              <?php endif; ?>
            </article>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>
    <?php
    return (string) ob_get_clean();
}
