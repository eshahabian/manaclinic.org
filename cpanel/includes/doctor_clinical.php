<?php
declare(strict_types=1);

/** جداول پرونده خصوصی دکتر — فقط از پنل دکتر استفاده می‌شود */
function ensure_doctor_clinical_tables(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    $pdo->exec("
      CREATE TABLE IF NOT EXISTS doctor_patient_charts (
        id VARCHAR(32) PRIMARY KEY,
        doctor_id VARCHAR(32) NOT NULL,
        patient_id VARCHAR(32) NOT NULL,
        history_text MEDIUMTEXT NULL,
        first_name VARCHAR(80) NULL,
        last_name VARCHAR(80) NULL,
        therapist_name VARCHAR(120) NULL,
        birth_date DATE NULL,
        marital_status VARCHAR(20) NULL,
        chief_complaint TEXT NULL,
        residence VARCHAR(255) NULL,
        family_history TEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_doctor_patient_chart (doctor_id, patient_id),
        INDEX idx_chart_doctor (doctor_id)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $pdo->exec("
      CREATE TABLE IF NOT EXISTS doctor_session_notes (
        id VARCHAR(32) PRIMARY KEY,
        doctor_id VARCHAR(32) NOT NULL,
        patient_id VARCHAR(32) NOT NULL,
        appointment_id VARCHAR(32) NOT NULL,
        note_text MEDIUMTEXT NOT NULL,
        d_text MEDIUMTEXT NULL,
        a_text MEDIUMTEXT NULL,
        p_text MEDIUMTEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_session_note_appointment (appointment_id),
        INDEX idx_session_doctor_patient (doctor_id, patient_id)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $pdo->exec("
      CREATE TABLE IF NOT EXISTS doctor_highlights (
        id VARCHAR(32) PRIMARY KEY,
        doctor_id VARCHAR(32) NOT NULL,
        patient_id VARCHAR(32) NOT NULL,
        excerpt TEXT NOT NULL,
        remark TEXT NULL,
        color VARCHAR(20) NOT NULL DEFAULT 'yellow',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_hl_doctor_patient (doctor_id, patient_id)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $pdo->exec("
      CREATE TABLE IF NOT EXISTS patient_care_notes (
        id VARCHAR(32) PRIMARY KEY,
        patient_id VARCHAR(32) NOT NULL,
        body MEDIUMTEXT NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_patient_care_notes (patient_id, created_at)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $addColumn = static function (PDO $pdo, string $table, string $column, string $ddl): void {
        try {
            $has = $pdo->query("SHOW COLUMNS FROM {$table} LIKE " . $pdo->quote($column))->fetch();
            if (!$has) {
                $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$ddl}");
            }
        } catch (Throwable $ignored) {
        }
    };
    $addColumn($pdo, 'doctor_patient_charts', 'first_name', 'first_name VARCHAR(80) NULL AFTER history_text');
    $addColumn($pdo, 'doctor_patient_charts', 'last_name', 'last_name VARCHAR(80) NULL AFTER first_name');
    $addColumn($pdo, 'doctor_patient_charts', 'therapist_name', 'therapist_name VARCHAR(120) NULL AFTER last_name');
    $addColumn($pdo, 'doctor_patient_charts', 'birth_date', 'birth_date DATE NULL AFTER therapist_name');
    $addColumn($pdo, 'doctor_patient_charts', 'marital_status', 'marital_status VARCHAR(20) NULL AFTER birth_date');
    $addColumn($pdo, 'doctor_patient_charts', 'chief_complaint', 'chief_complaint TEXT NULL AFTER marital_status');
    $addColumn($pdo, 'doctor_patient_charts', 'residence', 'residence VARCHAR(255) NULL AFTER chief_complaint');
    $addColumn($pdo, 'doctor_patient_charts', 'family_history', 'family_history TEXT NULL AFTER residence');
    $addColumn($pdo, 'doctor_patient_charts', 'soap_subject', 'soap_subject MEDIUMTEXT NULL AFTER family_history');
    $addColumn($pdo, 'doctor_patient_charts', 'soap_object', 'soap_object MEDIUMTEXT NULL AFTER soap_subject');
    $addColumn($pdo, 'doctor_patient_charts', 'soap_assessment', 'soap_assessment MEDIUMTEXT NULL AFTER soap_object');
    $addColumn($pdo, 'doctor_patient_charts', 'soap_plan', 'soap_plan MEDIUMTEXT NULL AFTER soap_assessment');
    $addColumn($pdo, 'doctor_session_notes', 'd_text', 'd_text MEDIUMTEXT NULL AFTER note_text');
    $addColumn($pdo, 'doctor_session_notes', 'a_text', 'a_text MEDIUMTEXT NULL AFTER d_text');
    $addColumn($pdo, 'doctor_session_notes', 'p_text', 'p_text MEDIUMTEXT NULL AFTER a_text');
    $addColumn($pdo, 'doctor_session_notes', 's_text', 's_text MEDIUMTEXT NULL AFTER note_text');

    $ready = true;
}

/** برچسب‌های SOAP شرح حال بالینی */
function chart_soap_fields(): array
{
    return [
        'soap_subject' => [
            'key' => 'S',
            'en' => 'Subject',
            'fa' => 'ذهنی / گزارش مراجع',
            'placeholder' => 'آنچه مراجع می‌گوید: شکایت، احساس، تاریخچه مرتبط…',
        ],
        'soap_object' => [
            'key' => 'O',
            'en' => 'Object',
            'fa' => 'عینی / مشاهدات',
            'placeholder' => 'مشاهدات درمانگر، ظاهر، رفتار، یافته‌های عینی…',
        ],
        'soap_assessment' => [
            'key' => 'A',
            'en' => 'Assessment',
            'fa' => 'ارزیابی',
            'placeholder' => 'جمع‌بندی بالینی، فرضیه‌ها، فرمول‌بندی…',
        ],
        'soap_plan' => [
            'key' => 'P',
            'en' => 'Plan',
            'fa' => 'برنامه',
            'placeholder' => 'اهداف، مداخلات، تکالیف، پیگیری…',
        ],
    ];
}

function chart_soap_has_content(array $chart): bool
{
    foreach (array_keys(chart_soap_fields()) as $col) {
        if (trim(strip_tags((string) ($chart[$col] ?? ''))) !== '') {
            return true;
        }
    }

    return false;
}

/**
 * ادیتور SOAP: یک باکس clinical-editor + نوار ابزار + تب‌های Subject/Object/Assessment/Plan
 *
 * @param array<string, string> $values مقادیر HTML هر فیلد
 * @param array{id_prefix?:string,fields?:array,name_map?:array<string,string>} $opts
 */
function chart_soap_rich_editor_html(array $values, array $opts = []): string
{
    $fields = $opts['fields'] ?? chart_soap_fields();
    $idPrefix = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($opts['id_prefix'] ?? 'soap')) ?: 'soap';
    /** @var array<string, string> $nameMap کلید فیلد → name اینپوت */
    $nameMap = $opts['name_map'] ?? [];
    $firstKey = (string) (array_key_first($fields) ?? '');

    ob_start();
    ?>
    <div class="soap-rich" data-soap-rich id="<?= e($idPrefix) ?>-rich">
      <?= rich_editor_toolbar_html(['id' => $idPrefix . '-toolbar', 'data_rich_toolbar' => true]) ?>
      <div class="soap-rich-panes">
        <?php foreach ($fields as $col => $meta): ?>
          <?php
            $html = rich_html_for_display((string) ($values[$col] ?? ''));
            $inputName = $nameMap[$col] ?? $col;
            $isFirst = $col === $firstKey;
          ?>
          <div
            class="clinical-editor soap-rich-pane<?= $isFirst ? ' is-active' : '' ?>"
            id="<?= e($idPrefix . '-' . $col) ?>"
            contenteditable="true"
            role="textbox"
            data-rich-editor
            data-soap-pane="<?= e($col) ?>"
            data-placeholder="<?= e((string) ($meta['placeholder'] ?? '')) ?>"
            <?= $isFirst ? '' : ' hidden' ?>
          ><?= $html ?></div>
          <textarea name="<?= e($inputName) ?>" id="<?= e($idPrefix . '-' . $col) ?>-hidden" data-soap-hidden="<?= e($col) ?>" hidden><?= e((string) ($values[$col] ?? '')) ?></textarea>
        <?php endforeach; ?>
      </div>
      <div class="soap-tabs" role="tablist" aria-label="بخش‌های SOAP">
        <?php foreach ($fields as $col => $meta): ?>
          <button
            type="button"
            class="soap-tab<?= $col === $firstKey ? ' is-active' : '' ?>"
            role="tab"
            data-soap-tab="<?= e($col) ?>"
            aria-selected="<?= $col === $firstKey ? 'true' : 'false' ?>"
          >
            <span class="soap-tab-key"><?= e((string) $meta['key']) ?></span>
            <span class="soap-tab-en"><?= e((string) $meta['en']) ?></span>
          </button>
        <?php endforeach; ?>
      </div>
    </div>
    <?php

    return (string) ob_get_clean();
}

/**
 * نمایش فقط‌خواندنی SOAP با تب
 *
 * @param array<string, mixed> $row
 */
function chart_soap_readonly_html(array $row, ?array $fields = null): string
{
    $fields = $fields ?? chart_soap_fields();
    $firstKey = (string) (array_key_first($fields) ?? '');
    ob_start();
    ?>
    <div class="soap-rich soap-rich--readonly" data-soap-rich>
      <div class="soap-rich-panes">
        <?php foreach ($fields as $col => $meta): ?>
          <?php
            $raw = trim((string) ($row[$col] ?? ''));
            $isFirst = $col === $firstKey;
          ?>
          <div class="clinical-editor soap-rich-pane<?= $isFirst ? ' is-active' : '' ?>" data-soap-pane="<?= e($col) ?>"<?= $isFirst ? '' : ' hidden' ?>>
            <?php if ($raw !== ''): ?>
              <?= rich_html_for_display($raw) ?>
            <?php else: ?>
              <span class="muted">—</span>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
      <div class="soap-tabs" role="tablist">
        <?php foreach ($fields as $col => $meta): ?>
          <button type="button" class="soap-tab<?= $col === $firstKey ? ' is-active' : '' ?>" data-soap-tab="<?= e($col) ?>">
            <span class="soap-tab-key"><?= e((string) $meta['key']) ?></span>
            <span class="soap-tab-en"><?= e((string) $meta['en']) ?></span>
          </button>
        <?php endforeach; ?>
      </div>
    </div>
    <?php

    return (string) ob_get_clean();
}

function chart_marital_label(?string $status): string
{
    return match ((string) $status) {
        'single' => 'مجرد',
        'married' => 'متاهل',
        'other' => 'سایر',
        default => '—',
    };
}

function chart_split_patient_name(string $fullName): array
{
    $fullName = trim(preg_replace('/\s+/u', ' ', $fullName) ?? '');
    if ($fullName === '') {
        return ['', ''];
    }
    $parts = preg_split('/\s+/u', $fullName) ?: [];
    if (count($parts) === 1) {
        return [$parts[0], ''];
    }
    $last = array_pop($parts);

    return [implode(' ', $parts), (string) $last];
}

/** یادداشت‌های درمانگر برای مراجع (قابل‌دیدن در پروفایل) — جلسات فردی + مسیر کارگاه */
function patient_visible_therapist_notes(PDO $pdo, string $patientId): array
{
    ensure_doctor_clinical_tables($pdo);
    $out = [];
    try {
        $stmt = $pdo->prepare("
          SELECT n.note_text AS body, n.updated_at, n.created_at, a.starts_at,
                 u.name AS doctor_name, 'session' AS source, NULL AS workshop_title
          FROM doctor_session_notes n
          LEFT JOIN appointments a ON a.id = n.appointment_id
          LEFT JOIN doctor_profiles dp ON dp.id = n.doctor_id
          LEFT JOIN users u ON u.id = dp.user_id
          WHERE n.patient_id=? AND TRIM(IFNULL(n.note_text,'')) <> ''
        ");
        $stmt->execute([$patientId]);
        foreach ($stmt->fetchAll() ?: [] as $row) {
            if (is_array($row)) {
                $out[] = $row;
            }
        }
    } catch (Throwable $ignored) {
    }

    try {
        if (function_exists('ensure_workshop_path_notes_schema')) {
            ensure_workshop_path_notes_schema($pdo);
        }
        $ws = $pdo->prepare("
          SELECT pn.body, pn.updated_at, pn.created_at, ws.session_date AS starts_at,
                 u.name AS doctor_name, 'workshop' AS source, w.title AS workshop_title
          FROM workshop_path_notes pn
          INNER JOIN workshop_enrollments e ON e.id = pn.enrollment_id
          INNER JOIN workshops w ON w.id = e.workshop_id
          LEFT JOIN workshop_sessions ws ON ws.id = pn.session_id
          LEFT JOIN users u ON u.id = pn.author_user_id
          WHERE e.patient_id=? AND pn.kind='instructor' AND TRIM(IFNULL(pn.body,'')) <> ''
        ");
        $ws->execute([$patientId]);
        foreach ($ws->fetchAll() ?: [] as $row) {
            if (is_array($row)) {
                $out[] = $row;
            }
        }
    } catch (Throwable $ignored) {
    }

    usort($out, static function (array $a, array $b): int {
        $ta = (string) (($a['starts_at'] ?? '') ?: (($a['updated_at'] ?? '') ?: ($a['created_at'] ?? '')));
        $tb = (string) (($b['starts_at'] ?? '') ?: (($b['updated_at'] ?? '') ?: ($b['created_at'] ?? '')));
        return strcmp($tb, $ta);
    });

    return array_slice($out, 0, 100);
}

function patient_care_notes_list(PDO $pdo, string $patientId, int $limit = 100): array
{
    ensure_doctor_clinical_tables($pdo);
    $limit = max(1, min(200, $limit));
    try {
        $stmt = $pdo->prepare("
          SELECT * FROM patient_care_notes
          WHERE patient_id=?
          ORDER BY created_at DESC
          LIMIT {$limit}
        ");
        $stmt->execute([$patientId]);
        $rows = $stmt->fetchAll();
        return is_array($rows) ? $rows : [];
    } catch (Throwable $ignored) {
        return [];
    }
}

function patient_care_note_create(PDO $pdo, string $patientId, string $body): ?string
{
    ensure_doctor_clinical_tables($pdo);
    $body = trim($body);
    if ($patientId === '' || $body === '') {
        return null;
    }
    $id = cuid();
    $pdo->prepare('INSERT INTO patient_care_notes (id, patient_id, body) VALUES (?,?,?)')
        ->execute([$id, $patientId, $body]);

    return $id;
}

function patient_care_note_delete(PDO $pdo, string $patientId, string $noteId): bool
{
    ensure_doctor_clinical_tables($pdo);
    $stmt = $pdo->prepare('DELETE FROM patient_care_notes WHERE id=? AND patient_id=?');
    $stmt->execute([$noteId, $patientId]);

    return $stmt->rowCount() > 0;
}

function session_note_has_content(?array $note): bool
{
    if (!is_array($note)) {
        return false;
    }
    foreach (['note_text', 's_text', 'd_text', 'a_text', 'p_text'] as $key) {
        if (trim(strip_tags((string) ($note[$key] ?? ''))) !== '') {
            return true;
        }
    }

    return false;
}

/** فیلدهای SOAP هر جلسه (همان منطق شرح حال پرونده) */
function session_soap_fields(): array
{
    return [
        's_text' => [
            'key' => 'S',
            'en' => 'Subject',
            'fa' => 'ذهنی / گزارش مراجع',
            'placeholder' => 'آنچه مراجع در این جلسه گفت…',
        ],
        'd_text' => [
            'key' => 'O',
            'en' => 'Object',
            'fa' => 'عینی / مشاهدات',
            'placeholder' => 'مشاهدات و یافته‌های عینی این جلسه…',
        ],
        'a_text' => [
            'key' => 'A',
            'en' => 'Assessment',
            'fa' => 'ارزیابی',
            'placeholder' => 'ارزیابی و جمع‌بندی این جلسه…',
        ],
        'p_text' => [
            'key' => 'P',
            'en' => 'Plan',
            'fa' => 'برنامه',
            'placeholder' => 'برنامه و تکالیف تا جلسه بعد…',
        ],
    ];
}

/**
 * پرونده فقط برای درمانگر مسئول: ترجیحی، نوبت مشترک، یا کارگاه همین دکتر. ادمین مستثنی است.
 * @return array{patient: array, appointments: array}
 */
function require_doctor_patient_access(PDO $pdo, array $ctx, string $patientId): array
{
    ensure_doctor_clinical_tables($pdo);
    $doctorId = $ctx['profile']['id'];

    $patientStmt = $pdo->prepare("SELECT id, username, name, phone, email, preferred_doctor_id, created_at FROM users WHERE id=? AND role='PATIENT' LIMIT 1");
    $patientStmt->execute([$patientId]);
    $patient = $patientStmt->fetch();
    if (!$patient) {
        flash_set('error', 'مراجعه‌کننده یافت نشد.');
        redirect('/doctor/patients');
    }

    $check = $pdo->prepare('SELECT COUNT(*) FROM appointments WHERE doctor_id=? AND patient_id=?');
    $check->execute([$doctorId, $patientId]);
    $hasAppointment = (int) $check->fetchColumn() > 0;
    $isPreferred = (string) ($patient['preferred_doctor_id'] ?? '') === (string) $doctorId;
    $hasWorkshop = false;
    try {
        $ws = $pdo->prepare("
          SELECT COUNT(*) FROM workshop_enrollments e
          JOIN workshops w ON w.id = e.workshop_id
          " . doctor_workshop_host_join('w') . "
          WHERE e.patient_id=?
        ");
        $ws->execute([$doctorId, $patientId]);
        $hasWorkshop = (int) $ws->fetchColumn() > 0;
    } catch (Throwable $e) {
        $hasWorkshop = false;
    }
    $isAdmin = is_admin_user($ctx['user'] ?? null) || !empty($ctx['admin_mode']);
    $isLead = function_exists('doctor_has_shiva_access') && (
        doctor_has_shiva_access($ctx['user'] ?? null)
        || doctor_has_shiva_access([
            'name' => (string) ($ctx['profile']['name'] ?? ''),
            'username' => (string) ($ctx['user']['username'] ?? ''),
        ])
    );
    if (!$hasAppointment && !$isPreferred && !$hasWorkshop && !$isAdmin && !$isLead) {
        flash_set('error', 'دسترسی به پرونده این مراجعه‌کننده برای شما مجاز نیست.');
        redirect('/doctor/patients');
    }

    if ($isLead || $isAdmin) {
        $apps = $pdo->prepare('SELECT * FROM appointments WHERE patient_id=? ORDER BY starts_at DESC');
        $apps->execute([$patientId]);
    } else {
        $apps = $pdo->prepare('SELECT * FROM appointments WHERE doctor_id=? AND patient_id=? ORDER BY starts_at DESC');
        $apps->execute([$doctorId, $patientId]);
    }

    return [
        'patient' => $patient,
        'appointments' => $apps->fetchAll(),
    ];
}

/** شناسه گفتگوهای دستیار که قبلاً داخل شرح حال کپی شده‌اند */
function extract_assistant_session_ids_from_history(string $raw): array
{
    $ids = [];
    if (preg_match_all('/نسخه گفتگوی دستیار \(([^)]+)\)/u', $raw, $m)) {
        $ids = array_merge($ids, $m[1]);
    }
    if (preg_match_all('/شناسه جلسه:\s*([A-Za-z0-9_-]+)/u', $raw, $m2)) {
        $ids = array_merge($ids, $m2[1]);
    }
    $ids = array_values(array_unique(array_filter(array_map('trim', $ids))));
    return $ids;
}

/** گفتگوی دستیار را از متن شرح حال جدا می‌کند */
function strip_assistant_blocks_from_history(string $raw): string
{
    $text = (string) $raw;
    if (trim($text) === '') {
        return '';
    }
    $text = preg_replace(
        '/=== نسخه گفتگوی دستیار \([^)]+\) ===.*?(?=(?:=== نسخه گفتگوی دستیار|=== شرح‌حال اولیه|$))/su',
        '',
        $text
    ) ?? $text;
    $text = preg_replace(
        '/=== شرح‌حال اولیه[^\n<]*دستیار.*?(?:توجه: این متن تشخیص پزشکی نیست[^\n<]*|(?=(?:=== |$)))/su',
        '',
        $text
    ) ?? $text;
    $text = preg_replace('/— یادداشت منشی —.*?(?=(?:=== |$))/su', '', $text) ?? $text;
    $text = preg_replace('/درمانگر ارجاع‌شده:[^\n<]*/u', '', $text) ?? $text;
    $text = preg_replace('/<p[^>]*>\s*(?:<br\s*\/?>)?\s*<\/p>/iu', '', $text) ?? $text;
    $text = preg_replace('/(?:<br\s*\/?>\s*){3,}/iu', '<br><br>', $text) ?? $text;
    $text = preg_replace("/(?:\\r?\\n[ \\t]*){3,}/", "\n\n", $text) ?? $text;
    return trim($text);
}

/** شرح حال را از کپی گفتگوی دستیار پاک می‌کند و در دیتابیس می‌نویسد */
function doctor_chart_detach_assistant_history(PDO $pdo, array $chart, string $patientId): string
{
    $raw = (string) ($chart['history_text'] ?? '');
    $ids = extract_assistant_session_ids_from_history($raw);
    if ($ids && $patientId !== '') {
        foreach ($ids as $sid) {
            try {
                $pdo->prepare('UPDATE assistant_sessions SET patient_id=COALESCE(patient_id, ?) WHERE id=?')
                    ->execute([$patientId, $sid]);
            } catch (Throwable $e) {
                // جلسه ممکن است دیگر نباشد
            }
        }
    }
    $clean = strip_assistant_blocks_from_history($raw);
    if ($clean !== trim($raw) && !empty($chart['id'])) {
        $pdo->prepare('UPDATE doctor_patient_charts SET history_text=? WHERE id=?')
            ->execute([$clean, (string) $chart['id']]);
    }
    return $clean;
}

/** گفتگوهای ارسال‌شده دستیار برای یک مراجعه‌کننده */
function doctor_patient_assistant_sessions(PDO $pdo, string $patientId, array $extraIds = []): array
{
    if (function_exists('ensure_assistant_schema')) {
        ensure_assistant_schema($pdo);
    }
    $extraIds = array_values(array_unique(array_filter(array_map('strval', $extraIds))));
    $sql = "
      SELECT s.*, u.name AS patient_name, u.phone AS patient_phone
      FROM assistant_sessions s
      LEFT JOIN users u ON u.id = s.patient_id
      WHERE (
        (s.patient_id = ? AND s.status IN ('SENT','COMPLETED') )
    ";
    $params = [$patientId];
    if ($extraIds) {
        $place = implode(',', array_fill(0, count($extraIds), '?'));
        $sql .= " OR (s.id IN ({$place}) AND (s.patient_id = ? OR s.patient_id IS NULL))";
        $params = array_merge($params, $extraIds, [$patientId]);
    }
    $sql .= ') ORDER BY COALESCE(s.sent_at, s.created_at) DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * ماه‌های شمسی جلسات پرونده — فقط ماه‌هایی که مراجعه داشته (به‌علاوه ماه جاری).
 * @param array<int, array<string, mixed>> $appointments
 * @return array{months: array<string, array<string, mixed>>, default_id: string}
 */
function doctor_session_month_groups(array $appointments): array
{
    $pack = group_appointments_by_jalali_month($appointments, false);
    foreach ($pack['months'] as $id => $bucket) {
        $base = (string) ($bucket['tab_label'] ?? $bucket['short'] ?? '');
        $pack['months'][$id]['tab_label'] = 'جلسات ' . $base;
        $pack['months'][$id]['label'] = 'جلسات ' . (string) ($bucket['label'] ?? $base);
    }
    return $pack;
}

/**
 * ماه‌های شمسی گفتگوهای دستیار — فقط ماه‌هایی که گفتگو بوده.
 * @param array<int, array<string, mixed>> $sessions
 * @return array{months: array<string, array<string, mixed>>, default_id: string}
 */
function doctor_intake_month_groups(array $sessions): array
{
    $mapped = [];
    foreach ($sessions as $session) {
        $session['starts_at'] = (string) (($session['sent_at'] ?? '') ?: ($session['created_at'] ?? ''));
        $mapped[] = $session;
    }
    $pack = group_appointments_by_jalali_month($mapped, false);
    foreach ($pack['months'] as $id => $bucket) {
        if (empty($bucket['items'])) {
            unset($pack['months'][$id]);
            continue;
        }
        $base = (string) ($bucket['tab_label'] ?? $bucket['short'] ?? '');
        $pack['months'][$id]['tab_label'] = $base;
        $pack['months'][$id]['label'] = 'گفتگوهای ' . (string) ($bucket['label'] ?? $base);
    }
    if ($pack['months'] && !isset($pack['months'][$pack['default_id']])) {
        $keys = array_keys($pack['months']);
        $pack['default_id'] = (string) end($keys);
    }
    if (!$pack['months']) {
        $pack['default_id'] = '';
    }
    return $pack;
}

function get_or_create_patient_chart(PDO $pdo, string $doctorId, string $patientId): array
{
    ensure_doctor_clinical_tables($pdo);
    $stmt = $pdo->prepare('SELECT * FROM doctor_patient_charts WHERE doctor_id=? AND patient_id=? LIMIT 1');
    $stmt->execute([$doctorId, $patientId]);
    $row = $stmt->fetch();
    if ($row) {
        return $row;
    }
    $id = cuid();
    $pdo->prepare('INSERT INTO doctor_patient_charts (id, doctor_id, patient_id, history_text) VALUES (?,?,?,?)')
        ->execute([$id, $doctorId, $patientId, '']);
    $stmt->execute([$doctorId, $patientId]);
    return $stmt->fetch() ?: ['id' => $id, 'doctor_id' => $doctorId, 'patient_id' => $patientId, 'history_text' => ''];
}

/**
 * کارگاه‌هایی که این پروفایل درمانگر میزبان آن‌هاست.
 * workshops.doctor_id معمولاً doctor_profiles.id است؛ اگر به‌اشتباه user_id ذخیره شده باشد هم پیدا می‌شود.
 */
function doctor_workshop_host_join(string $workshopAlias = 'w'): string
{
    $w = $workshopAlias;

    return "INNER JOIN doctor_profiles host ON host.id = ? AND ({$w}.doctor_id = host.id OR {$w}.doctor_id = host.user_id)";
}

/**
 * ثبت‌نام روی خود کارگاه، یا اگر workshop_id به‌اشتباه شناسه جلسه باشد از طریق workshop_sessions.
 */
function doctor_enrollment_workshop_join(string $enrollmentAlias = 'e', string $workshopAlias = 'w'): string
{
    $e = $enrollmentAlias;
    $w = $workshopAlias;

    return "
      LEFT JOIN workshops {$w}_direct ON {$w}_direct.id = {$e}.workshop_id
      LEFT JOIN workshop_sessions {$w}_sess ON {$w}_sess.id = {$e}.workshop_id
      INNER JOIN workshops {$w} ON {$w}.id = COALESCE({$w}_direct.id, {$w}_sess.workshop_id)
    ";
}

function doctor_patient_enrollments_for_doctor(PDO $pdo, string $doctorId, string $patientId): array
{
    $sessionsFile = __DIR__ . '/workshop_sessions.php';
    if (is_file($sessionsFile)) {
        require_once $sessionsFile;
    }
    if (function_exists('ensure_workshop_schema')) {
        ensure_workshop_schema($pdo);
    }
    if (function_exists('ensure_workshop_sessions_schema')) {
        ensure_workshop_sessions_schema($pdo);
    }
    try {
        $stmt = $pdo->prepare("
          SELECT e.id, e.status, e.enrolled_at,
                 e.enrolled_at AS created_at,
                 w.id AS workshop_id, w.title, w.type, w.status AS workshop_status,
                 w.session_interval, w.starts_at, w.ends_at,
                 (SELECT COUNT(*) FROM workshop_sessions s WHERE s.workshop_id = w.id) AS session_count
          FROM workshop_enrollments e
          " . doctor_enrollment_workshop_join() . "
          " . doctor_workshop_host_join('w') . "
          WHERE e.patient_id=?
          ORDER BY e.enrolled_at DESC
        ");
        $stmt->execute([$doctorId, $patientId]);
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        try {
            $stmt = $pdo->prepare("
              SELECT e.id, e.status, e.enrolled_at,
                     e.enrolled_at AS created_at,
                     w.id AS workshop_id, w.title, w.type, w.status AS workshop_status,
                     w.session_interval, w.starts_at, w.ends_at,
                     0 AS session_count
              FROM workshop_enrollments e
              JOIN workshops w ON w.id = e.workshop_id
              " . doctor_workshop_host_join('w') . "
              WHERE e.patient_id=?
              ORDER BY e.enrolled_at DESC
            ");
            $stmt->execute([$doctorId, $patientId]);
            return $stmt->fetchAll();
        } catch (Throwable $ignored) {
            return [];
        }
    }
}

function doctor_patient_private_qa_for_doctor(PDO $pdo, string $doctorId, string $patientId): array
{
    if (function_exists('ensure_workshop_qa_schema')) {
        ensure_workshop_qa_schema($pdo);
    }
    try {
        $stmt = $pdo->prepare("
          SELECT q.id, q.body, q.created_at, q.author_kind, q.parent_id, q.workshop_id,
                 w.title AS workshop_title, au.name AS author_name
          FROM workshop_qa_posts q
          JOIN workshops w ON w.id = q.workshop_id
          " . doctor_workshop_host_join('w') . "
          JOIN users au ON au.id = q.author_user_id
          WHERE q.is_private=1
            AND (q.audience_user_id=? OR q.author_user_id=?)
          ORDER BY q.created_at DESC
          LIMIT 80
        ");
        $stmt->execute([$doctorId, $patientId, $patientId]);
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

function doctor_patient_path_notes_for_doctor(PDO $pdo, string $doctorId, string $patientId): array
{
    $sessionsFile = __DIR__ . '/workshop_sessions.php';
    if (is_file($sessionsFile)) {
        require_once $sessionsFile;
    }
    if (function_exists('ensure_workshop_path_notes_schema')) {
        ensure_workshop_path_notes_schema($pdo);
    }
    if (function_exists('ensure_workshop_sessions_schema')) {
        ensure_workshop_sessions_schema($pdo);
    }
    try {
        $stmt = $pdo->prepare("
          SELECT n.id, n.kind, n.body, n.updated_at, n.created_at, n.enrollment_id, n.session_id,
                 w.title AS workshop_title, w.id AS workshop_id,
                 s.title AS session_title
          FROM workshop_path_notes n
          JOIN workshop_enrollments e ON e.id = n.enrollment_id
          " . doctor_enrollment_workshop_join() . "
          " . doctor_workshop_host_join('w') . "
          LEFT JOIN workshop_sessions s ON s.id = n.session_id
          WHERE e.patient_id=?
          ORDER BY COALESCE(n.updated_at, n.created_at) DESC
          LIMIT 80
        ");
        $stmt->execute([$doctorId, $patientId]);
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        try {
            $stmt = $pdo->prepare("
              SELECT n.id, n.kind, n.body, n.updated_at, n.created_at, n.enrollment_id, n.session_id,
                     w.title AS workshop_title, w.id AS workshop_id,
                     s.title AS session_title
              FROM workshop_path_notes n
              JOIN workshop_enrollments e ON e.id = n.enrollment_id
              JOIN workshops w ON w.id = e.workshop_id
              " . doctor_workshop_host_join('w') . "
              LEFT JOIN workshop_sessions s ON s.id = n.session_id
              WHERE e.patient_id=?
              ORDER BY COALESCE(n.updated_at, n.created_at) DESC
              LIMIT 80
            ");
            $stmt->execute([$doctorId, $patientId]);
            return $stmt->fetchAll();
        } catch (Throwable $ignored) {
            return [];
        }
    }
}

function doctor_patient_call_stats(PDO $pdo, string $doctorUserId, string $patientId): array
{
    if ($doctorUserId === '' || $patientId === '') {
        return ['call_count' => 0, 'last_at' => null];
    }
    try {
        $stmt = $pdo->prepare('SELECT call_count, last_at FROM video_call_contact_stats WHERE host_user_id=? AND peer_user_id=? LIMIT 1');
        $stmt->execute([$doctorUserId, $patientId]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            return ['call_count' => 0, 'last_at' => null];
        }
        return [
            'call_count' => (int) ($row['call_count'] ?? 0),
            'last_at' => $row['last_at'] ?? null,
        ];
    } catch (Throwable $e) {
        return ['call_count' => 0, 'last_at' => null];
    }
}

/** HTML امن برای ادیتور شرح حال (bold / سایز / هایلایت) */
function sanitize_clinical_html(string $html): string
{
    return sanitize_rich_html($html);
}

function history_html_for_editor(?string $raw): string
{
    return rich_html_for_display($raw);
}
