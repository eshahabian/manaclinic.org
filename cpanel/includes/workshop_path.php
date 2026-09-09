<?php
declare(strict_types=1);

function ensure_workshop_path_notes_schema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    require_once __DIR__ . '/workshop_sessions.php';
    ensure_workshop_sessions_schema($pdo);
    try {
        $pdo->exec("
          CREATE TABLE IF NOT EXISTS workshop_path_notes (
            id VARCHAR(32) PRIMARY KEY,
            enrollment_id VARCHAR(32) NOT NULL,
            session_id VARCHAR(32) NOT NULL,
            kind ENUM('patient','instructor') NOT NULL,
            body TEXT NOT NULL,
            author_user_id VARCHAR(32) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_path_note (enrollment_id, session_id, kind),
            INDEX idx_path_note_enrollment (enrollment_id),
            CONSTRAINT fk_wpn_enrollment FOREIGN KEY (enrollment_id) REFERENCES workshop_enrollments(id) ON DELETE CASCADE,
            CONSTRAINT fk_wpn_session FOREIGN KEY (session_id) REFERENCES workshop_sessions(id) ON DELETE CASCADE
          ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (Throwable $ignored) {
    }
    $ready = true;
}

function workshop_path_member_statuses(): array
{
    return ['CONFIRMED', 'COMPLETED'];
}

function workshop_path_url(string $enrollmentId): string
{
    return url('/dashboard/workshops/path?enrollment=' . rawurlencode($enrollmentId));
}

function workshop_path_doctor_url(string $enrollmentId): string
{
    return url('/doctor/workshops/path?enrollment=' . rawurlencode($enrollmentId));
}

function workshop_doctor_board_url(array $workshop, string $sessionId = ''): string
{
    $workshopId = (string) ($workshop['id'] ?? '');
    $archived = function_exists('workshop_is_archived') && workshop_is_archived($workshop);
    $tab = $archived
        ? 'archive'
        : (function_exists('workshop_courses_tab_for_type')
            ? workshop_courses_tab_for_type((string) ($workshop['type'] ?? ''))
            : 'in-person');
    $url = url('/doctor/workshops?tab=' . rawurlencode($tab) . '&doctor_path=' . rawurlencode($workshopId));
    if ($sessionId !== '') {
        return $url . '#doctor-step-' . rawurlencode($sessionId);
    }

    return $url . '#workshop-' . rawurlencode($workshopId) . '-doctor-path';
}

function workshop_doctor_board_close_url(array $workshop): string
{
    $archived = function_exists('workshop_is_archived') && workshop_is_archived($workshop);
    $tab = $archived
        ? 'archive'
        : (function_exists('workshop_courses_tab_for_type')
            ? workshop_courses_tab_for_type((string) ($workshop['type'] ?? ''))
            : 'in-person');

    return url('/doctor/workshops?tab=' . rawurlencode($tab) . '#workshop-' . rawurlencode((string) ($workshop['id'] ?? '')));
}

function workshop_doctor_path_board(PDO $pdo, array $workshop, array $enrollments): array
{
    require_once __DIR__ . '/workshop_sessions.php';
    require_once __DIR__ . '/workshop_media.php';
    $workshopId = (string) ($workshop['id'] ?? '');
    workshop_sessions_sync(
        $pdo,
        $workshopId,
        (string) ($workshop['type'] ?? 'OFFLINE'),
        (string) ($workshop['starts_at'] ?? date('Y-m-d H:i:s')),
        (string) ($workshop['ends_at'] ?? date('Y-m-d H:i:s')),
        [],
        function_exists('workshop_session_interval_normalize')
            ? workshop_session_interval_normalize((string) ($workshop['session_interval'] ?? 'WEEKLY'))
            : 'WEEKLY'
    );
    $sessions = workshop_sessions_with_media($pdo, $workshopId);
    $notesMap = workshop_session_notes_map($pdo, $workshopId);
    $today = date('Y-m-d');
    $offline = function_exists('workshop_is_offline') && workshop_is_offline((string) ($workshop['type'] ?? ''));
    $people = [];
    foreach ($enrollments as $enr) {
        if (!is_array($enr)) {
            continue;
        }
        if (!in_array((string) ($enr['status'] ?? ''), workshop_path_member_statuses(), true)) {
            continue;
        }
        $people[] = $enr;
    }
    $steps = [];
    foreach ($sessions as $i => $session) {
        if (!is_array($session)) {
            continue;
        }
        $sid = (string) ($session['id'] ?? '');
        if ($sid === '') {
            continue;
        }
        $date = (string) ($session['session_date'] ?? '');
        $note = $notesMap['by_session'][$sid] ?? ($date !== '' ? ($notesMap['by_date'][$date] ?? null) : null);
        $visual = workshop_path_date_state($date, $today);
        $displayState = $offline && $visual === 'future' ? 'open' : $visual;
        $steps[] = [
            'id' => $sid,
            'index' => $i + 1,
            'title' => (string) ($session['title'] ?? ('جلسه ' . ($i + 1))),
            'date' => $date,
            'date_fa' => $date !== '' ? to_jalali_label($date) : '',
            'state' => $displayState,
            'visual' => $displayState,
            'doctor_note' => (string) ($note['note_text'] ?? ''),
            'note_id' => (string) ($note['id'] ?? ''),
            'files' => is_array($session['files'] ?? null) ? $session['files'] : [],
        ];
    }
    $progress = workshop_path_progress(array_map(static function (array $step): array {
        $step['patient_note'] = (string) ($step['doctor_note'] ?? '');
        return $step;
    }, $steps), $offline);

    return [
        'workshop' => $workshop,
        'steps' => $steps,
        'people' => $people,
        'progress' => $progress,
        'offline' => $offline,
        'post_url' => url('/doctor/workshops/doctor-path'),
        'media_post' => url('/doctor/workshop-media'),
    ];
}

function workshop_path_rich_toolbar_html(string $toolbarId): string
{
    ob_start();
    ?>
          <div class="clinical-toolbar workshop-path-toolbar" id="<?= e($toolbarId) ?>" data-rich-toolbar>
            <button type="button" class="tool-btn bold" data-cmd="bold" title="ضخیم">B</button>
            <span class="tool-sep"></span>
            <span class="muted" style="font-size:.8rem;margin-inline-end:.25rem">هایلایت</span>
            <button type="button" class="swatch yellow" data-hl="#ffe566" title="زرد"></button>
            <button type="button" class="swatch green" data-hl="#8fd6a8" title="سبز"></button>
            <button type="button" class="swatch pink" data-hl="#f5a3c0" title="صورتی"></button>
            <button type="button" class="swatch blue" data-hl="#8eb7e8" title="آبی"></button>
            <button type="button" class="tool-btn" data-cmd="removeFormat" title="پاک کردن فرمت">پاک‌کردن رنگ</button>
          </div>
    <?php
    return (string) ob_get_clean();
}

function workshop_doctor_path_render(array $board): string
{
    $workshop = is_array($board['workshop'] ?? null) ? $board['workshop'] : [];
    $steps = is_array($board['steps'] ?? null) ? $board['steps'] : [];
    $people = is_array($board['people'] ?? null) ? $board['people'] : [];
    $progress = is_array($board['progress'] ?? null) ? $board['progress'] : [];
    $postUrl = (string) ($board['post_url'] ?? '');
    $mediaPost = (string) ($board['media_post'] ?? '');
    $workshopId = (string) ($workshop['id'] ?? '');
    $current = (int) ($progress['current'] ?? 0);
    global $config;
    $mediaMaxMb = (int) ($config['workshop_media_max_mb'] ?? 300);

    ob_start();
    ?>
<div class="workshop-path-wrap workshop-doctor-board" id="workshop-<?= e($workshopId) ?>-doctor-path">
  <div class="workshop-path-motivate" role="status">
    <strong>مسیر و یادداشت‌های درمانگر</strong>
    <?php if ((int) ($progress['total'] ?? 0) > 0): ?>
      <span class="workshop-path-motivate-count">
        جلسه <?= e(to_fa_digits((string) max(1, $current))) ?> از <?= e(to_fa_digits((string) $progress['total'])) ?>
      </span>
    <?php endif; ?>
    <p>یادداشت خصوصی فقط برای شماست. اگر بخواهید، همان جلسه می‌توانید فقط به یک مراجع پیام بدهید و فایل جلسه را همین‌جا بگذارید.</p>
  </div>

  <?php if ($steps === []): ?>
    <p class="muted">هنوز جلسه‌ای برای این دوره تعریف نشده است. کارگاه را ذخیره کنید تا روزهای برگزاری ساخته شود.</p>
  <?php else: ?>
    <ol class="workshop-path">
      <?php foreach ($steps as $step): ?>
        <?php
          $state = (string) ($step['visual'] ?? $step['state'] ?? 'open');
          $isCurrent = $current > 0 && (int) ($step['index'] ?? 0) === $current;
          $classes = 'workshop-path-step is-' . preg_replace('/[^a-z]/', '', $state);
          if ($isCurrent) {
              $classes .= ' is-current';
          }
          $sid = (string) ($step['id'] ?? '');
          $noteHtml = function_exists('rich_html_for_display')
              ? rich_html_for_display((string) ($step['doctor_note'] ?? ''))
              : e((string) ($step['doctor_note'] ?? ''));
          $files = is_array($step['files'] ?? null) ? $step['files'] : [];
        ?>
        <li class="<?= e($classes) ?>" id="doctor-step-<?= e($sid) ?>">
          <span class="workshop-path-dot" aria-hidden="true">
            <?php if ($state === 'past'): ?>✓<?php elseif ($state === 'today' || $isCurrent): ?>●<?php else: ?>○<?php endif; ?>
          </span>
          <div class="workshop-path-card">
            <div class="workshop-path-card-head">
              <strong><?= e((string) ($step['title'] ?? 'جلسه')) ?></strong>
              <span class="badge"><?= e(workshop_path_state_label($state)) ?></span>
            </div>
            <?php if (!empty($step['date_fa'])): ?>
              <div class="muted" style="font-size:.85rem;margin-top:.25rem"><?= e((string) $step['date_fa']) ?></div>
            <?php endif; ?>

            <form class="workshop-path-form" method="post" action="<?= e($postUrl) ?>" enctype="multipart/form-data" data-rich-note>
              <?= csrf_field() ?>
              <input type="hidden" name="workshop_id" value="<?= e($workshopId) ?>">
              <input type="hidden" name="session_id" value="<?= e($sid) ?>">
              <input type="hidden" name="note_id" value="<?= e((string) ($step['note_id'] ?? '')) ?>">

              <span class="workshop-path-note-label">یادداشت خصوصی من</span>
              <?= workshop_path_rich_toolbar_html('doctor-note-toolbar-' . $sid) ?>
              <div
                class="clinical-editor workshop-path-editor"
                contenteditable="true"
                role="textbox"
                data-rich-editor
                data-placeholder="نکات جلسه را بنویسید؛ کلمه را انتخاب کنید و Bold یا هایلایت بزنید…"
              ><?= $noteHtml ?></div>
              <textarea name="note_html" hidden data-rich-hidden><?= e((string) ($step['doctor_note'] ?? '')) ?></textarea>

              <label class="workshop-path-note-label" for="patient-msg-<?= e($sid) ?>">پیام خصوصی برای مراجع (اختیاری)</label>
              <textarea class="input" id="patient-msg-<?= e($sid) ?>" name="patient_message" rows="3" maxlength="4000" placeholder="اگر خالی بماند فقط یادداشت خودتان ذخیره می‌شود. این متن را فقط مراجع انتخاب‌شده می‌بیند…"></textarea>
              <label class="label" for="share-enr-<?= e($sid) ?>">ارسال پیام به</label>
              <select class="input" id="share-enr-<?= e($sid) ?>" name="share_enrollment_id">
                <option value="">ارسال نشود</option>
                <?php if (count($people) > 1): ?>
                  <option value="ALL">همه شرکت‌کننده‌های تأییدشده</option>
                <?php endif; ?>
                <?php foreach ($people as $enr): ?>
                  <option value="<?= e((string) ($enr['id'] ?? '')) ?>"><?= e((string) ($enr['patient_name'] ?? 'مراجع')) ?></option>
                <?php endforeach; ?>
              </select>
              <?php if (!$people): ?>
                <p class="muted" style="margin:.35rem 0 0;font-size:.8rem">هنوز شرکت‌کننده تأییدشده‌ای نیست؛ پیام بعد از تأیید ثبت‌نام قابل ارسال است.</p>
              <?php endif; ?>

              <span class="workshop-path-note-label">فایل‌های این جلسه</span>
              <p class="muted" style="margin:0 0 .45rem;font-size:.8rem">حداکثر <?= e(to_fa_digits((string) $mediaMaxMb)) ?> مگابایت برای هر فایل. فقط اعضای تأییدشده این فایل‌ها را در مسیر خود می‌بینند.</p>
              <div class="workshop-session-slots workshop-path-files">
                <?php foreach (['PDF' => ['پی‌دی‌اف', '.pdf,application/pdf'], 'AUDIO' => ['صوت', 'audio/*,.mp3,.m4a,.wav,.ogg'], 'VIDEO' => ['ویدیو', 'video/*,.mp4,.webm,.mov']] as $kind => $meta): ?>
                  <?php $existing = is_array($files[$kind] ?? null) ? $files[$kind] : null; ?>
                  <div class="workshop-session-kind">
                    <label class="label"><?= e($meta[0]) ?></label>
                    <?php if ($existing): ?>
                      <div class="muted" style="font-size:.78rem;margin-bottom:.35rem">
                        <?= e((string) ($existing['original_name'] ?? 'فایل')) ?>
                        <?php if (!empty($existing['file_size'])): ?>
                          · <?= e(workshop_media_format_size((int) $existing['file_size'])) ?>
                        <?php endif; ?>
                      </div>
                      <?php if ($mediaPost !== '' && !empty($existing['id'])): ?>
                        <button type="submit" class="btn btn-outline btn-sm" name="delete_media_id" value="<?= e((string) $existing['id']) ?>" formnovalidate onclick="return confirm('این فایل حذف شود؟')">حذف</button>
                      <?php endif; ?>
                    <?php endif; ?>
                    <input class="input" type="file" name="path_file[<?= e($kind) ?>]" accept="<?= e($meta[1]) ?>">
                  </div>
                <?php endforeach; ?>
              </div>

              <button class="btn btn-primary btn-sm" type="submit">ذخیره این جلسه</button>
            </form>
          </div>
        </li>
      <?php endforeach; ?>
    </ol>
  <?php endif; ?>
</div>
    <?php
    return (string) ob_get_clean();
}

function workshop_path_date_state(string $sessionDate, string $today): string
{
    if ($sessionDate === '') {
        return 'open';
    }
    if ($sessionDate < $today) {
        return 'past';
    }
    if ($sessionDate === $today) {
        return 'today';
    }

    return 'future';
}

function workshop_path_state_label(string $state): string
{
    return match ($state) {
        'past' => 'برگزار شده',
        'today' => 'جلسه امروز',
        'future' => 'هنوز نرسیده',
        default => 'جلسه دوره',
    };
}

function workshop_path_notes_map(PDO $pdo, string $enrollmentId): array
{
    ensure_workshop_path_notes_schema($pdo);
    $stmt = $pdo->prepare('
      SELECT session_id, kind, body, updated_at
      FROM workshop_path_notes
      WHERE enrollment_id = ?
    ');
    $stmt->execute([$enrollmentId]);
    $map = [];
    foreach ($stmt->fetchAll() as $row) {
        $sid = (string) ($row['session_id'] ?? '');
        $kind = (string) ($row['kind'] ?? '');
        if ($sid === '' || $kind === '') {
            continue;
        }
        $map[$sid][$kind] = $row;
    }

    return $map;
}

function workshop_path_motivation(int $currentOneBased, int $total, bool $finished, bool $started): string
{
    if ($total < 1) {
        return 'مسیر این دوره به‌زودی با روزهای برگزاری روشن می‌شود.';
    }
    $cur = to_fa_digits((string) $currentOneBased);
    $tot = to_fa_digits((string) $total);
    if ($finished) {
        return 'آفرین؛ مسیر این دوره تمام شد. یادداشت‌هایت اینجاست — به خودت افتخار کن.';
    }
    if (!$started) {
        return 'شروع مسیرت همین‌جاست. با حضور در اولین جلسه، قدم اول را بردار و ادامه بده.';
    }
    if ($currentOneBased >= $total) {
        return 'به پایان نزدیک شده‌ای. همین قدم آخر را هم با حضور کامل بردار.';
    }
    if ($currentOneBased === 1) {
        return 'اولین قدم را برداشته‌ای. مسیر روشن است؛ ادامه بده.';
    }
    if ($total > 0 && ($currentOneBased / $total) >= 0.5) {
        return 'جلسه ' . $cur . ' از ' . $tot . ' را می‌گذرانی. بیش از نیمه راه آمده‌ای؛ ادامه بده.';
    }

    return 'جلسه ' . $cur . ' از ' . $tot . ' است. هر هفته یک قدم جلوتر — ادامه بده.';
}

function workshop_path_progress(array $steps, bool $offline): array
{
    $total = count($steps);
    $current = 0;
    $started = false;
    $finished = false;
    if ($offline) {
        $noted = 0;
        foreach ($steps as $i => $step) {
            if (trim((string) ($step['patient_note'] ?? '')) !== '') {
                $noted = $i + 1;
            }
        }
        $started = $noted > 0;
        $finished = $total > 0 && $noted === $total;
        $current = $finished ? $total : min($total, $noted + 1);
    } else {
        $reached = 0;
        foreach ($steps as $i => $step) {
            $state = (string) ($step['state'] ?? '');
            if (in_array($state, ['past', 'today'], true)) {
                $reached = $i + 1;
                $started = true;
            }
        }
        $current = $reached;
        $lastState = $total > 0 ? (string) ($steps[$total - 1]['state'] ?? '') : '';
        $finished = $total > 0 && $reached === $total && $lastState === 'past';
        if ($current < 1 && $started === false) {
            $current = 0;
        }
    }

    return [
        'current' => $current,
        'total' => $total,
        'started' => $started,
        'finished' => $finished,
        'message' => workshop_path_motivation($current > 0 ? $current : 1, $total, $finished, $started),
    ];
}

function workshop_path_build_steps(array $sessions, array $notesMap, string $workshopType, string $today): array
{
    $offline = function_exists('workshop_is_offline') && workshop_is_offline($workshopType);
    $steps = [];
    foreach ($sessions as $i => $session) {
        if (!is_array($session)) {
            continue;
        }
        $sid = (string) ($session['id'] ?? '');
        if ($sid === '') {
            continue;
        }
        $date = (string) ($session['session_date'] ?? '');
        $visual = workshop_path_date_state($date, $today);
        $canWritePatient = $offline || in_array($visual, ['past', 'today', 'open'], true);
        $displayState = $offline && $visual === 'future' ? 'open' : $visual;
        $patientNote = trim((string) ($notesMap[$sid]['patient']['body'] ?? ''));
        $instructorNote = trim((string) ($notesMap[$sid]['instructor']['body'] ?? ''));
        $steps[] = [
            'id' => $sid,
            'index' => $i + 1,
            'title' => (string) ($session['title'] ?? ('جلسه ' . ($i + 1))),
            'date' => $date,
            'date_fa' => $date !== '' ? to_jalali_label($date) : '',
            'state' => $displayState,
            'visual' => $displayState,
            'patient_note' => $patientNote,
            'instructor_note' => $instructorNote,
            'can_write_patient' => $canWritePatient,
            'can_write_instructor' => true,
        ];
    }

    return $steps;
}

function workshop_path_enrollment_row(PDO $pdo, string $enrollmentId): ?array
{
    $stmt = $pdo->prepare("
      SELECT e.id, e.workshop_id, e.patient_id, e.status,
             w.title, w.type, w.session_interval, w.starts_at, w.ends_at,
             w.status AS workshop_status, w.doctor_id,
             u.name AS patient_name,
             du.name AS doctor_name
      FROM workshop_enrollments e
      JOIN workshops w ON w.id = e.workshop_id
      JOIN users u ON u.id = e.patient_id
      JOIN doctor_profiles dp ON dp.id = w.doctor_id
      JOIN users du ON du.id = dp.user_id
      WHERE e.id = ?
      LIMIT 1
    ");
    $stmt->execute([$enrollmentId]);
    $row = $stmt->fetch();

    return is_array($row) ? $row : null;
}

function workshop_path_sync_sessions(PDO $pdo, array $enrollment): array
{
    require_once __DIR__ . '/workshop_sessions.php';
    workshop_sessions_sync(
        $pdo,
        (string) ($enrollment['workshop_id'] ?? ''),
        (string) ($enrollment['type'] ?? 'OFFLINE'),
        (string) ($enrollment['starts_at'] ?? date('Y-m-d H:i:s')),
        (string) ($enrollment['ends_at'] ?? date('Y-m-d H:i:s')),
        [],
        function_exists('workshop_session_interval_normalize')
            ? workshop_session_interval_normalize((string) ($enrollment['session_interval'] ?? 'WEEKLY'))
            : 'WEEKLY'
    );

    return workshop_sessions_list($pdo, (string) ($enrollment['workshop_id'] ?? ''));
}

function workshop_path_context(PDO $pdo, array $enrollment): array
{
    $today = date('Y-m-d');
    $sessions = workshop_path_sync_sessions($pdo, $enrollment);
    $notes = workshop_path_notes_map($pdo, (string) ($enrollment['id'] ?? ''));
    $type = (string) ($enrollment['type'] ?? '');
    $steps = workshop_path_build_steps($sessions, $notes, $type, $today);
    $offline = function_exists('workshop_is_offline') && workshop_is_offline($type);
    $progress = workshop_path_progress($steps, $offline);

    return [
        'enrollment' => $enrollment,
        'steps' => $steps,
        'progress' => $progress,
        'offline' => $offline,
        'today' => $today,
    ];
}

function workshop_path_load_patient(PDO $pdo, string $patientId, string $enrollmentId): ?array
{
    if ($enrollmentId === '' || $patientId === '') {
        return null;
    }
    $row = workshop_path_enrollment_row($pdo, $enrollmentId);
    if (!$row || (string) ($row['patient_id'] ?? '') !== $patientId) {
        return null;
    }
    if (!in_array((string) ($row['status'] ?? ''), workshop_path_member_statuses(), true)) {
        return null;
    }

    return workshop_path_context($pdo, $row);
}

function workshop_path_load_doctor(PDO $pdo, string $doctorProfileId, string $enrollmentId): ?array
{
    if ($enrollmentId === '' || $doctorProfileId === '') {
        return null;
    }
    $row = workshop_path_enrollment_row($pdo, $enrollmentId);
    if (!$row || (string) ($row['doctor_id'] ?? '') !== $doctorProfileId) {
        return null;
    }
    if (!in_array((string) ($row['status'] ?? ''), workshop_path_member_statuses(), true)) {
        return null;
    }

    return workshop_path_context($pdo, $row);
}

function workshop_path_save_note(
    PDO $pdo,
    string $enrollmentId,
    string $sessionId,
    string $kind,
    string $body,
    string $authorUserId
): void {
    ensure_workshop_path_notes_schema($pdo);
    if (!in_array($kind, ['patient', 'instructor'], true)) {
        throw new RuntimeException('نوع یادداشت نامعتبر است.');
    }
    $body = trim($body);
    $len = function_exists('mb_strlen') ? mb_strlen($body) : strlen($body);
    if ($len > 4000) {
        throw new RuntimeException('یادداشت خیلی طولانی است.');
    }
    $enr = $pdo->prepare('SELECT workshop_id FROM workshop_enrollments WHERE id=? LIMIT 1');
    $enr->execute([$enrollmentId]);
    $workshopId = (string) ($enr->fetchColumn() ?: '');
    if ($workshopId === '') {
        throw new RuntimeException('ثبت‌نام یافت نشد.');
    }
    $sess = $pdo->prepare('SELECT id FROM workshop_sessions WHERE id=? AND workshop_id=? LIMIT 1');
    $sess->execute([$sessionId, $workshopId]);
    if (!$sess->fetch()) {
        throw new RuntimeException('جلسه یافت نشد.');
    }

    $found = $pdo->prepare('SELECT id FROM workshop_path_notes WHERE enrollment_id=? AND session_id=? AND kind=? LIMIT 1');
    $found->execute([$enrollmentId, $sessionId, $kind]);
    $noteId = (string) ($found->fetchColumn() ?: '');

    if ($body === '') {
        if ($noteId !== '') {
            $pdo->prepare('DELETE FROM workshop_path_notes WHERE id=?')->execute([$noteId]);
        }
        return;
    }

    if ($noteId !== '') {
        $pdo->prepare('UPDATE workshop_path_notes SET body=?, author_user_id=? WHERE id=?')
            ->execute([$body, $authorUserId, $noteId]);
        return;
    }

    $pdo->prepare('
      INSERT INTO workshop_path_notes (id, enrollment_id, session_id, kind, body, author_user_id)
      VALUES (?,?,?,?,?,?)
    ')->execute([cuid(), $enrollmentId, $sessionId, $kind, $body, $authorUserId]);
}

function workshop_path_render(array $ctx, string $mode): string
{
    $mode = $mode === 'doctor' ? 'doctor' : 'patient';
    $enrollment = is_array($ctx['enrollment'] ?? null) ? $ctx['enrollment'] : [];
    $steps = is_array($ctx['steps'] ?? null) ? $ctx['steps'] : [];
    $progress = is_array($ctx['progress'] ?? null) ? $ctx['progress'] : [];
    $postUrl = (string) ($ctx['post_url'] ?? '');
    $enrollmentId = (string) ($enrollment['id'] ?? '');
    $current = (int) ($progress['current'] ?? 0);

    ob_start();
    ?>
<div class="workshop-path-wrap">
  <div class="workshop-path-motivate" role="status">
    <strong><?= $mode === 'doctor' ? 'مسیر این مراجع' : 'مسیر تو در این دوره' ?></strong>
    <?php if ((int) ($progress['total'] ?? 0) > 0): ?>
      <span class="workshop-path-motivate-count">
        جلسه <?= e(to_fa_digits((string) max(1, $current))) ?> از <?= e(to_fa_digits((string) $progress['total'])) ?>
      </span>
    <?php endif; ?>
    <p><?= e((string) ($progress['message'] ?? '')) ?></p>
  </div>

  <?php if ($steps === []): ?>
    <p class="muted">هنوز جلسه‌ای برای این دوره تعریف نشده است.</p>
  <?php else: ?>
    <ol class="workshop-path">
      <?php foreach ($steps as $step): ?>
        <?php
          $state = (string) ($step['visual'] ?? $step['state'] ?? 'open');
          $isCurrent = $current > 0 && (int) ($step['index'] ?? 0) === $current;
          $classes = 'workshop-path-step is-' . preg_replace('/[^a-z]/', '', $state);
          if ($isCurrent) {
              $classes .= ' is-current';
          }
        ?>
        <li class="<?= e($classes) ?>" id="step-<?= e((string) ($step['id'] ?? '')) ?>">
          <span class="workshop-path-dot" aria-hidden="true">
            <?php if ($state === 'past'): ?>✓<?php elseif ($state === 'today' || $isCurrent): ?>●<?php else: ?>○<?php endif; ?>
          </span>
          <div class="workshop-path-card">
            <div class="workshop-path-card-head">
              <strong><?= e((string) ($step['title'] ?? 'جلسه')) ?></strong>
              <span class="badge"><?= e(workshop_path_state_label($state)) ?></span>
            </div>
            <?php if (!empty($step['date_fa'])): ?>
              <div class="muted" style="font-size:.85rem;margin-top:.25rem"><?= e((string) $step['date_fa']) ?></div>
            <?php endif; ?>

            <?php if (!empty($ctx['show_media']) && $mode === 'patient'): ?>
              <?= workshop_path_media_html(is_array($step['files'] ?? null) ? $step['files'] : [], $ctx) ?>
            <?php endif; ?>

            <?php if ($mode === 'patient'): ?>
              <?php if ((string) ($step['instructor_note'] ?? '') !== ''): ?>
                <div class="workshop-path-teacher">
                  <span class="workshop-path-note-label">یادداشت درمانگر برای شما</span>
                  <p><?= nl2br(e((string) $step['instructor_note'])) ?></p>
                </div>
              <?php endif; ?>
              <?php if (!empty($step['can_write_patient'])): ?>
                <form class="workshop-path-form" method="post" action="<?= e($postUrl) ?>">
                  <?= csrf_field() ?>
                  <input type="hidden" name="enrollment_id" value="<?= e($enrollmentId) ?>">
                  <input type="hidden" name="session_id" value="<?= e((string) ($step['id'] ?? '')) ?>">
                  <label class="workshop-path-note-label" for="patient-note-<?= e((string) ($step['id'] ?? '')) ?>">یادداشت من از این جلسه</label>
                  <textarea class="input" id="patient-note-<?= e((string) ($step['id'] ?? '')) ?>" name="body" rows="3" maxlength="4000" placeholder="اگر دوست دارید از این جلسه چیزی برای خودتان بنویسید…"><?= e((string) ($step['patient_note'] ?? '')) ?></textarea>
                  <button class="btn btn-primary btn-sm" type="submit">ذخیره یادداشت</button>
                </form>
              <?php else: ?>
                <p class="muted workshop-path-locked">این جلسه هنوز نرسیده؛ بعد از برگزاری می‌توانید یادداشت بنویسید.</p>
              <?php endif; ?>
            <?php else: ?>
              <div class="workshop-path-teacher">
                <span class="workshop-path-note-label">یادداشت مراجع</span>
                <?php if ((string) ($step['patient_note'] ?? '') !== ''): ?>
                  <p><?= nl2br(e((string) $step['patient_note'])) ?></p>
                <?php else: ?>
                  <p class="muted" style="margin:0">هنوز چیزی ننوشته است.</p>
                <?php endif; ?>
              </div>
              <form class="workshop-path-form" method="post" action="<?= e($postUrl) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="enrollment_id" value="<?= e($enrollmentId) ?>">
                <input type="hidden" name="session_id" value="<?= e((string) ($step['id'] ?? '')) ?>">
                <label class="workshop-path-note-label" for="instructor-note-<?= e((string) ($step['id'] ?? '')) ?>">یادداشت خصوصی برای این نفر</label>
                <textarea class="input" id="instructor-note-<?= e((string) ($step['id'] ?? '')) ?>" name="body" rows="3" maxlength="4000" placeholder="فقط همین مراجع این یادداشت را می‌بیند…"><?= e((string) ($step['instructor_note'] ?? '')) ?></textarea>
                <button class="btn btn-primary btn-sm" type="submit">ذخیره یادداشت</button>
              </form>
            <?php endif; ?>
          </div>
        </li>
      <?php endforeach; ?>
    </ol>
  <?php endif; ?>
</div>
    <?php
    return (string) ob_get_clean();
}

function workshop_path_media_html(array $files, array $ctx): string
{
    $user = is_array($ctx['user'] ?? null) ? $ctx['user'] : [];
    $watermark = (string) ($ctx['watermark'] ?? '');
    $video = is_array($files['VIDEO'] ?? null) ? $files['VIDEO'] : null;
    $audio = is_array($files['AUDIO'] ?? null) ? $files['AUDIO'] : null;
    $pdf = is_array($files['PDF'] ?? null) ? $files['PDF'] : null;
    if (!$video && !$audio && !$pdf) {
        return '<p class="muted workshop-path-locked">برای این جلسه هنوز فایلی بارگذاری نشده است.</p>';
    }

    ob_start();
    ?>
<div class="workshop-path-media" data-offline-protect>
  <?php if ($video): ?>
    <div class="wm-video-box">
      <video
        controls
        playsinline
        preload="metadata"
        controlsList="nodownload noplaybackrate noremoteplayback"
        disablePictureInPicture
        disableRemotePlayback
        oncontextmenu="return false;"
        src="<?= e(workshop_media_stream_url((string) $video['id'], $user)) ?>"
      ></video>
      <div class="wm-overlay" aria-hidden="true">
        <?php for ($i = 0; $i < 15; $i++): ?>
          <span><?= e($watermark) ?></span>
        <?php endfor; ?>
      </div>
    </div>
  <?php endif; ?>
  <?php if ($audio): ?>
    <div class="offline-audio-box" data-audio-id="<?= e((string) $audio['id']) ?>">
      <p class="muted offline-audio-status" id="audio-status-<?= e((string) $audio['id']) ?>">برای پخش صوت، دکمه زیر را بزنید.</p>
      <button type="button" class="btn btn-primary btn-sm audio-play-btn" data-audio-id="<?= e((string) $audio['id']) ?>">پخش صوت</button>
      <audio
        id="audio-<?= e((string) $audio['id']) ?>"
        class="protected-audio"
        controls
        controlsList="nodownload noplaybackrate noremoteplayback"
        preload="none"
        oncontextmenu="return false;"
        style="width:100%;margin-top:.5rem;display:none"
      ></audio>
      <p class="muted offline-audio-wm">واترمارک: <?= e($watermark) ?> — دانلود و ضبط صفحه غیرفعال است.</p>
    </div>
  <?php endif; ?>
  <?php if ($pdf): ?>
    <div class="wm-pdf-box">
      <div class="wm-pdf-frame">
        <iframe src="<?= e(workshop_media_stream_url((string) $pdf['id'], $user)) ?>" title="پی‌دی‌اف جلسه"></iframe>
        <div class="wm-overlay" aria-hidden="true">
          <?php for ($i = 0; $i < 12; $i++): ?>
            <span><?= e($watermark) ?></span>
          <?php endfor; ?>
        </div>
      </div>
      <p class="muted" style="font-size:.75rem;margin:.35rem 0 0">فقط مشاهده داخل پنل — دانلود پی‌دی‌اف بسته است. واترمارک: <?= e($watermark) ?></p>
    </div>
  <?php endif; ?>
</div>
    <?php
    return (string) ob_get_clean();
}

function workshop_path_attach_media(array $ctx, array $sessionsWithMedia): array
{
    $byId = [];
    foreach ($sessionsWithMedia as $session) {
        if (!is_array($session)) {
            continue;
        }
        $byId[(string) ($session['id'] ?? '')] = $session;
    }
    $steps = [];
    foreach (($ctx['steps'] ?? []) as $step) {
        if (!is_array($step)) {
            continue;
        }
        $sid = (string) ($step['id'] ?? '');
        $step['files'] = is_array($byId[$sid]['files'] ?? null) ? $byId[$sid]['files'] : [];
        $steps[] = $step;
    }
    $ctx['steps'] = $steps;
    $ctx['show_media'] = true;

    return $ctx;
}

function workshop_offline_protect_script(array $audioStreams = []): string
{
    $json = json_encode($audioStreams, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        $json = '{}';
    }

    return '<script src="' . e(url('/assets/js/workshop-offline-protect.js')) . '?v=20260907k"></script>'
        . '<script>window.workshopOfflineAudioStreams=' . $json . ';if(window.workshopOfflineProtect){window.workshopOfflineProtect();}</script>';
}
