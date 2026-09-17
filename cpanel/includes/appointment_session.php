<?php
declare(strict_types=1);

/** نوع جلسه نوبت فردی + یادداشت مشترک خصوصی مراجع↔درمانگر */

function ensure_appointment_session_schema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    try {
        $has = $pdo->query("SHOW COLUMNS FROM appointments LIKE 'session_mode'")->fetch();
        if (!$has) {
            $pdo->exec("ALTER TABLE appointments ADD COLUMN session_mode ENUM('IN_PERSON','ONLINE') NOT NULL DEFAULT 'IN_PERSON' AFTER notes");
        }
    } catch (Throwable $ignored) {
    }

    try {
        $pdo->exec("
          CREATE TABLE IF NOT EXISTS appointment_shared_notes (
            id VARCHAR(32) PRIMARY KEY,
            appointment_id VARCHAR(32) NOT NULL,
            author_user_id VARCHAR(32) NOT NULL,
            author_role ENUM('DOCTOR','PATIENT','ADMIN') NOT NULL,
            body MEDIUMTEXT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_shared_note_app (appointment_id, created_at),
            INDEX idx_shared_note_author (author_user_id)
          ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (Throwable $ignored) {
    }

    $ready = true;
}

function appointment_normalize_session_mode(?string $mode): string
{
    $mode = strtoupper(trim((string) $mode));

    return $mode === 'ONLINE' ? 'ONLINE' : 'IN_PERSON';
}

function appointment_session_mode_label(?string $mode): string
{
    return appointment_normalize_session_mode($mode) === 'ONLINE' ? 'آنلاین' : 'حضوری';
}

function appointment_session_mode_badge_html(?string $mode): string
{
    $mode = appointment_normalize_session_mode($mode);
    $cls = $mode === 'ONLINE' ? 'appt-mode-badge appt-mode-badge--online' : 'appt-mode-badge appt-mode-badge--in-person';
    $label = appointment_session_mode_label($mode);

    return '<span class="' . e($cls) . '">' . e($label) . '</span>';
}

function appointment_is_upcoming(array $row, ?int $now = null): bool
{
    $now = $now ?? time();
    $status = (string) ($row['status'] ?? '');
    if (in_array($status, ['CANCELLED', 'COMPLETED'], true)) {
        return false;
    }
    $start = strtotime((string) ($row['starts_at'] ?? '')) ?: 0;

    return $start >= $now;
}

function appointment_is_online_mode(array $row): bool
{
    return appointment_normalize_session_mode((string) ($row['session_mode'] ?? '')) === 'ONLINE';
}

/** انتخاب نوع جلسه در فرم رزرو */
function appointment_session_mode_pick_html(string $name = 'session_mode', string $selected = 'IN_PERSON', string $idPrefix = 'sm'): string
{
    $selected = appointment_normalize_session_mode($selected);
    ob_start();
    ?>
    <div class="session-mode-pick" data-session-mode-pick>
      <span class="label" style="display:block;margin-bottom:.4rem">نوع جلسه</span>
      <div class="session-mode-pick-opts" role="radiogroup" aria-label="نوع جلسه">
        <label class="session-mode-opt">
          <input type="radio" name="<?= e($name) ?>" id="<?= e($idPrefix) ?>-in-person" value="IN_PERSON"<?= $selected === 'IN_PERSON' ? ' checked' : '' ?>>
          <span>حضوری</span>
        </label>
        <label class="session-mode-opt">
          <input type="radio" name="<?= e($name) ?>" id="<?= e($idPrefix) ?>-online" value="ONLINE"<?= $selected === 'ONLINE' ? ' checked' : '' ?>>
          <span>آنلاین</span>
        </label>
      </div>
    </div>
    <?php
    return (string) ob_get_clean();
}

/**
 * @return array{ok:bool,row?:array,error?:string}
 */
function appointment_shared_note_load_context(PDO $pdo, array $user, string $appointmentId): array
{
    ensure_appointment_session_schema($pdo);
    if ($appointmentId === '') {
        return ['ok' => false, 'error' => 'نوبت مشخص نیست.'];
    }

    $stmt = $pdo->prepare("
      SELECT a.*,
             u.name AS patient_name,
             du.name AS doctor_name,
             dp.user_id AS doctor_user_id,
             dp.id AS doctor_profile_id
      FROM appointments a
      JOIN users u ON u.id = a.patient_id
      JOIN doctor_profiles dp ON dp.id = a.doctor_id
      JOIN users du ON du.id = dp.user_id
      WHERE a.id = ?
      LIMIT 1
    ");
    $stmt->execute([$appointmentId]);
    $row = $stmt->fetch();
    if (!$row) {
        return ['ok' => false, 'error' => 'نوبت یافت نشد.'];
    }

    $role = (string) ($user['role'] ?? '');
    $uid = (string) ($user['id'] ?? '');
    $allowed = false;
    if ($role === 'PATIENT' && $uid === (string) ($row['patient_id'] ?? '')) {
        $allowed = true;
    } elseif ($role === 'DOCTOR' && $uid === (string) ($row['doctor_user_id'] ?? '')) {
        $allowed = true;
    } elseif ($role === 'ADMIN') {
        $allowed = true;
    } elseif ($role === 'DOCTOR' && function_exists('doctor_is_shiva') && doctor_is_shiva($user)) {
        $allowed = true;
    }

    if (!$allowed) {
        return ['ok' => false, 'error' => 'دسترسی به این یادداشت ندارید.'];
    }

    return ['ok' => true, 'row' => $row];
}

function appointment_shared_notes_list(PDO $pdo, string $appointmentId): array
{
    ensure_appointment_session_schema($pdo);
    $stmt = $pdo->prepare("
      SELECT n.*, u.name AS author_name
      FROM appointment_shared_notes n
      LEFT JOIN users u ON u.id = n.author_user_id
      WHERE n.appointment_id = ?
      ORDER BY n.created_at ASC, n.id ASC
    ");
    $stmt->execute([$appointmentId]);

    return $stmt->fetchAll() ?: [];
}

function appointment_shared_note_add(PDO $pdo, array $user, string $appointmentId, string $body): bool
{
    $body = trim($body);
    if ($body === '') {
        return false;
    }
    $ctx = appointment_shared_note_load_context($pdo, $user, $appointmentId);
    if (empty($ctx['ok'])) {
        return false;
    }

    $role = (string) ($user['role'] ?? '');
    $authorRole = $role === 'ADMIN' ? 'ADMIN' : ($role === 'DOCTOR' ? 'DOCTOR' : 'PATIENT');
    $id = function_exists('cuid') ? cuid() : bin2hex(random_bytes(12));
    $pdo->prepare('INSERT INTO appointment_shared_notes (id, appointment_id, author_user_id, author_role, body) VALUES (?,?,?,?,?)')
        ->execute([$id, $appointmentId, (string) $user['id'], $authorRole, $body]);

    return true;
}

function appointment_shared_notes_count_map(PDO $pdo, array $appointmentIds): array
{
    ensure_appointment_session_schema($pdo);
    $ids = array_values(array_filter(array_map('strval', $appointmentIds)));
    if ($ids === []) {
        return [];
    }
    $in = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT appointment_id, COUNT(*) AS c FROM appointment_shared_notes WHERE appointment_id IN ($in) GROUP BY appointment_id");
    $stmt->execute($ids);
    $map = [];
    foreach ($stmt->fetchAll() ?: [] as $row) {
        $map[(string) $row['appointment_id']] = (int) $row['c'];
    }

    return $map;
}

function appointment_shared_note_role_label(string $role): string
{
    return match ($role) {
        'DOCTOR', 'ADMIN' => 'درمانگر',
        'PATIENT' => 'مراجعه‌کننده',
        default => 'کاربر',
    };
}

/**
 * رندر صفحه/پنل یادداشت مشترک
 *
 * @param list<array<string,mixed>> $notes
 */
function appointment_shared_notes_panel_html(
    array $appointment,
    array $notes,
    string $postUrl,
    string $backUrl,
    string $viewerRole
): string {
    $when = function_exists('format_fa_datetime')
        ? format_fa_datetime((string) ($appointment['starts_at'] ?? ''))
        : (string) ($appointment['starts_at'] ?? '');
    $peer = $viewerRole === 'PATIENT'
        ? (string) ($appointment['doctor_name'] ?? 'درمانگر')
        : (string) ($appointment['patient_name'] ?? 'مراجعه‌کننده');
    ob_start();
    ?>
    <div class="stack shared-note-page">
      <div class="row-between" style="gap:.75rem;flex-wrap:wrap;align-items:flex-start">
        <div>
          <h1 style="margin:0">یادداشت مشترک جلسه</h1>
          <p class="muted" style="margin:.35rem 0 0;font-size:.9rem;line-height:1.7">
            خصوصی بین شما و <?= e($peer) ?> · <?= e($when) ?>
            · <?= appointment_session_mode_badge_html((string) ($appointment['session_mode'] ?? '')) ?>
          </p>
        </div>
        <a class="btn btn-outline btn-sm" href="<?= e(url($backUrl)) ?>">بازگشت</a>
      </div>

      <div class="panel stack shared-note-thread">
        <?php if (!$notes): ?>
          <p class="muted" style="margin:0">هنوز یادداشتی برای این جلسه نوشته نشده. اولین پیام را بنویسید.</p>
        <?php else: ?>
          <ul class="shared-note-feed">
            <?php foreach ($notes as $n): ?>
              <?php
                $ar = (string) ($n['author_role'] ?? '');
                $mine = ($viewerRole === 'PATIENT' && $ar === 'PATIENT')
                    || ($viewerRole !== 'PATIENT' && in_array($ar, ['DOCTOR', 'ADMIN'], true));
              ?>
              <li class="shared-note-item<?= $mine ? ' is-mine' : ' is-peer' ?>">
                <div class="shared-note-meta muted">
                  <?= e(appointment_shared_note_role_label($ar)) ?>
                  <?php if (!empty($n['author_name'])): ?>
                    · <?= e((string) $n['author_name']) ?>
                  <?php endif; ?>
                  · <?= e(format_fa_datetime((string) ($n['created_at'] ?? ''))) ?>
                </div>
                <div class="shared-note-body"><?= nl2br(e((string) ($n['body'] ?? ''))) ?></div>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>

      <form class="panel form-stack" method="post" action="<?= e(url($postUrl)) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="appointment_id" value="<?= e((string) ($appointment['id'] ?? '')) ?>">
        <input type="hidden" name="next" value="<?= e($backUrl) ?>">
        <label class="label" for="shared-note-body">یادداشت جدید برای این جلسه</label>
        <textarea class="input" id="shared-note-body" name="body" rows="4" required placeholder="فقط شما و طرف مقابل این متن را می‌بینید…"></textarea>
        <button class="btn btn-primary" type="submit">ثبت یادداشت</button>
      </form>
    </div>
    <?php
    return (string) ob_get_clean();
}
