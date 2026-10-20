<?php
declare(strict_types=1);

function sms_template_catalog(): array
{
    return [
        // مراجع — این قالب‌ها را تغییر ندهید مگر با تأیید صریح (مسیر پایدار وب‌وان).
        'appointment_booked'=>['ثبت و تأیید نوبت','مانا کلینیک: نوبت شما در {datetime} ثبت شد.'],
        'appointment_cancelled'=>['لغو نوبت','مانا کلینیک: نوبت شما در {datetime} لغو شد.'],
        'appointment_reminder'=>['یادآوری نوبت','مانا کلینیک: یادآوری نوبت {datetime}'],
        'workshop_registered'=>['تأیید ثبت‌نام کارگاه','مانا کلینیک: ثبت‌نام کارگاه از {datetime}'],
        'workshop_reminder'=>['یادآوری کارگاه','مانا کلینیک: یادآوری کارگاه {datetime}'],
        'workshop_announcement'=>['معرفی کارگاه','مانا کلینیک: کارگاه جدید از {datetime}'],
        // درمانگر همان نوبت انتخاب‌شده در پنل
        'doctor_appointment_booked'=>['اعلام نوبت به درمانگر','مانا کلینیک: نوبت {patient} {datetime}'],
        'doctor_appointment_cancelled'=>['اعلام لغو به درمانگر','مانا کلینیک: لغو {patient} {datetime}'],
        // خلاصه صبحگاهی (متن پویا از sms_doctor_daily_digest_body؛ این فقط برچسب ادمین است)
        'doctor_daily_digest'=>['خلاصه نوبت‌های روز برای درمانگر','مانا کلینیک: نوبت {date}: {slots}'],
        'quiet'=>['پیگیری عدم مراجعه','مانا کلینیک: {message}'],
        'login_otp'=>['کد ورود','کد ورود شما {code}'],
    ];
}

function sms_first_name(string $fullName): string
{
    $fullName = trim($fullName);
    if ($fullName === '') {
        return 'مراجع';
    }
    $bits = preg_split('/\s+/u', $fullName) ?: [];
    $first = trim((string) ($bits[0] ?? $fullName));

    return $first !== '' ? $first : 'مراجع';
}

function sms_template_render(PDO $pdo, string $kind, array $variables): string
{
    $catalog=sms_template_catalog();
    if (!isset($catalog[$kind])) {
        return '';
    }
    $template=mail_setting_get($pdo,'sms_template_'.$kind,'');
    if(trim((string)$template)==='')$template=$catalog[$kind][1];
    $isAppointment = str_starts_with($kind, 'appointment_');
    $isWorkshop = str_starts_with($kind, 'workshop_');
    $isDoctorAppt = str_starts_with($kind, 'doctor_appointment_');
    if ($kind === 'login_otp') {
        $candidate = trim((string) $template);
        if ($candidate === '' || str_contains($candidate, "\n") || !str_contains($candidate, '{code}')) {
            $template = $catalog[$kind][1];
        }
    } elseif ($isDoctorAppt) {
        $candidate = trim((string) $template);
        $bad = $candidate === ''
            || !str_contains($candidate, '{datetime}')
            || !str_contains($candidate, '{patient}')
            || str_contains($candidate, "\n")
            || mb_strlen($candidate) > 80;
        if ($bad) {
            $template = $catalog[$kind][1];
        }
        if (isset($variables['patient'])) {
            $variables['patient'] = sms_first_name((string) $variables['patient']);
        }
    } elseif ($isAppointment || $isWorkshop) {
        $candidate = trim((string) $template);
        // قالب‌های بلند/با اسم/با پزشک که بعد از تغییر متن خراب شدند → برگرد به کاتالوگ کوتاه.
        $bad = $candidate === ''
            || !str_contains($candidate, '{datetime}')
            || str_contains($candidate, '{patient}')
            || str_contains($candidate, '{doctor}')
            || str_contains($candidate, 'عزیز')
            || str_contains($candidate, "\n")
            || mb_strlen($candidate) > 80;
        if ($bad) {
            $template = $catalog[$kind][1];
        }
    }
    $map=[];foreach($variables as $key=>$value)$map['{'.$key.'}']=trim((string)$value);
    $body = trim(preg_replace('/\s+/u', ' ', strtr($template, $map)) ?? '');
    if (mb_strlen($body) > 300) {
        $body = mb_substr($body, 0, 297) . '…';
    }
    return $body;
}

/**
 * ارسال فوری یک پیامک و ثبت در outbox.
 * @return array{ok:bool,status:string,error?:string}
 */
function sms_send_immediate(
    PDO $pdo,
    string $phone,
    string $body,
    string $kind,
    string $dedup,
    ?string $expiresAt = null,
    array $context = []
): array {
    $mobile = sms_iran_mobile($phone);
    if ($mobile === null) {
        return ['ok' => false, 'status' => 'FAILED', 'error' => 'شماره موبایل معتبر نیست.'];
    }
    sms_queue_extended(
        $pdo,
        $mobile,
        $body,
        $kind,
        $dedup,
        date('Y-m-d H:i:s'),
        $expiresAt ?? date('Y-m-d H:i:s', time() + 86400),
        $context
    );
    $hash = hash('sha256', $dedup);
    $job = $pdo->prepare("SELECT id FROM sms_outbox WHERE dedup_key=? ORDER BY created_at DESC LIMIT 1");
    $job->execute([$hash]);
    $jobId = (string) ($job->fetchColumn() ?: '');
    $result = sms_provider_send(sms_connection_config($pdo), $mobile, $body);
    if ($jobId !== '') {
        $pdo->prepare("UPDATE sms_outbox SET status=?,provider_id=?,last_error=?,sent_at=IF(?='SENT',NOW(),NULL),attempts=attempts+1,claimed_at=NOW() WHERE id=?")
            ->execute([$result['status'], $result['id'] ?? null, $result['error'] ?? null, $result['status'], $jobId]);
    }
    return [
        'ok' => ($result['status'] ?? '') === 'SENT',
        'status' => (string) ($result['status'] ?? 'FAILED'),
        'error' => (string) ($result['error'] ?? ''),
    ];
}

/**
 * ارسال فوری پیامک ثبت/لغو نوبت به مراجع و درمانگر همان نوبت (بدون انتظار برای کرون).
 * @return array{ok:bool,status:string,error?:string,doctor_ok?:bool,doctor_status?:string,doctor_error?:string}
 */
function sms_notify_appointment_now(PDO $pdo, string $appointmentId, string $event): array
{
    $event = $event === 'cancelled' ? 'cancelled' : 'booked';
    $kind = $event === 'cancelled' ? 'appointment_cancelled' : 'appointment_booked';
    $doctorKind = $event === 'cancelled' ? 'doctor_appointment_cancelled' : 'doctor_appointment_booked';
    if (!sms_connection_ready($pdo)) {
        return ['ok' => false, 'status' => 'FAILED', 'error' => 'اتصال پیامک آماده نیست.'];
    }
    ensure_outreach_schema($pdo);
    $stmt = $pdo->prepare("SELECT a.id,a.status,a.starts_at,a.doctor_id,u.phone,u.name AS patient_name,d.name AS doctor_name,d.phone AS doctor_phone
        FROM appointments a
        JOIN users u ON u.id=a.patient_id
        JOIN doctor_profiles dp ON dp.id=a.doctor_id
        JOIN users d ON d.id=dp.user_id
        WHERE a.id=? LIMIT 1");
    $stmt->execute([$appointmentId]);
    $row = $stmt->fetch();
    if (!is_array($row)) {
        return ['ok' => false, 'status' => 'FAILED', 'error' => 'نوبت یافت نشد.'];
    }
    $phone = sms_iran_mobile((string) ($row['phone'] ?? ''));
    if ($phone === null) {
        return ['ok' => false, 'status' => 'FAILED', 'error' => 'شماره موبایل مراجع معتبر نیست.'];
    }
    $when = format_fa_datetime((string) ($row['starts_at'] ?? ''));
    $patientName = (string) ($row['patient_name'] ?? '');
    $body = sms_template_render($pdo, $kind, [
        'patient' => $patientName,
        'doctor' => (string) ($row['doctor_name'] ?? ''),
        'datetime' => $when,
        'status' => 'ثبت شد.',
    ]);
    $expires = $event === 'cancelled'
        ? date('Y-m-d H:i:s', time() + 86400)
        : (string) ($row['starts_at'] ?? date('Y-m-d H:i:s', time() + 86400));
    $result = sms_send_immediate(
        $pdo,
        $phone,
        $body,
        $kind,
        'appointment:' . $appointmentId . ':' . $event . ':' . (string) ($row['starts_at'] ?? ''),
        $expires,
        ['entity' => 'appointment', 'id' => $appointmentId, 'starts_at' => $row['starts_at'], 'status' => $row['status'], 'doctor_id' => $row['doctor_id'] ?? null, 'immediate' => 1]
    );

    $doctorResult = ['ok' => false, 'status' => 'SKIPPED', 'error' => 'شماره درمانگر موجود نیست.'];
    $doctorPhone = sms_iran_mobile((string) ($row['doctor_phone'] ?? ''));
    if ($doctorPhone !== null && $doctorPhone !== $phone) {
        $doctorBody = sms_template_render($pdo, $doctorKind, [
            'patient' => $patientName,
            'datetime' => $when,
        ]);
        $doctorResult = sms_send_immediate(
            $pdo,
            $doctorPhone,
            $doctorBody,
            $doctorKind,
            'appointment:' . $appointmentId . ':doctor:' . $event . ':' . (string) ($row['starts_at'] ?? ''),
            $expires,
            ['entity' => 'appointment_doctor', 'id' => $appointmentId, 'starts_at' => $row['starts_at'], 'status' => $row['status'], 'doctor_id' => $row['doctor_id'] ?? null, 'immediate' => 1]
        );
    }

    try {
        $fingerprint = hash('sha256', json_encode([(string) $row['status'], (string) $row['starts_at'], 'immediate']));
        sms_entity_save($pdo, 'appointment:' . $appointmentId, $fingerprint, (string) $row['status']);
    } catch (Throwable $ignored) {
    }
    return [
        'ok' => ($result['status'] ?? '') === 'SENT',
        'status' => (string) ($result['status'] ?? 'FAILED'),
        'error' => (string) ($result['error'] ?? ''),
        'doctor_ok' => ($doctorResult['status'] ?? '') === 'SENT',
        'doctor_status' => (string) ($doctorResult['status'] ?? 'FAILED'),
        'doctor_error' => (string) ($doctorResult['error'] ?? ''),
    ];
}

function sms_iran_mobile(string $input): ?string
{
    $number = normalize_phone($input);
    if (str_starts_with($number, '+98')) $number = '0' . substr($number, 3);
    elseif (str_starts_with($number, '0098')) $number = '0' . substr($number, 4);
    elseif (str_starts_with($number, '98') && strlen($number) === 12) $number = '0' . substr($number, 2);
    elseif (preg_match('/^9\d{9}$/D', $number)) $number = '0' . $number;
    return preg_match('/^09\d{9}$/D', $number) ? $number : null;
}

function sms_connection_config(PDO $pdo): array
{
    $privateFile = dirname(__DIR__) . '/config.sms.php';
    $private = is_file($privateFile) ? require $privateFile : [];
    if (!is_array($private)) {
        $private = [];
    }
    $result = [];
    foreach (['sender', 'username', 'password', 'api_key', 'enabled'] as $name) {
        $fallback = (string) ($private[$name] ?? '');
        $fromDb = mail_setting_get($pdo, 'sms_' . $name, null);
        // Empty DB values fall back to config.sms.php.
        $result[$name] = ($fromDb !== null && $fromDb !== '') ? (string) $fromDb : $fallback;
    }
    return $result;
}

function sms_connection_ready(PDO $pdo): bool
{
    $c = sms_connection_config($pdo);
    if ($c['enabled'] !== '1' || $c['sender'] === '') {
        return false;
    }
    if ($c['api_key'] !== '') {
        return true;
    }
    return $c['username'] !== '' && $c['password'] !== '';
}

function sms_provider_error_message(int $code): string
{
    $map = [
        0 => 'ارسال با موفقیت انجام شد.',
        1 => 'کلید API یا نام کاربری/رمز نامعتبر است.',
        2 => 'حساب پیامک مسدود شده است.',
        3 => 'شماره فرستنده نامعتبر است.',
        4 => 'محدودیت ارسال روزانه یا شماره فرستنده نامعتبر است.',
        5 => 'تعداد گیرندگان بیش از حد مجاز است.',
        6 => 'خط فرستنده غیرفعال است.',
        7 => 'متن پیامک شامل کلمات فیلترشده است.',
        8 => 'اعتبار پنل کافی نیست.',
        9 => 'سامانه پیامک در حال به‌روزرسانی است.',
        10 => 'وب‌سرویس پیامک غیرفعال است.',
        14 => 'تعرفه کاربر مشخص نشده است.',
        15 => 'ارسال تکراری؛ کمی بعد دوباره تلاش کنید.',
        16 => 'شماره موبایل گیرنده نامعتبر است.',
        17 => 'خط OTP برای این حساب یافت نشد.',
        19 => 'متن با الگوی پنل یکی نیست؛ بخش ثابت باید عین الگو باشد.',
        21 => 'IP سرور برای وب‌سرویس مجاز نیست؛ IP را در پنل وب‌وان ثبت کنید.',
        22 => 'احراز هویت پنل (کارت ملی) تکمیل نشده است.',
    ];
    return $map[$code] ?? ('ارسال پیامک ناموفق بود (کد ' . $code . ').');
}

function sms_status_label(string $status): string
{
    return ['PENDING'=>'در صف','PROCESSING'=>'در حال ارسال','SENT'=>'پذیرفته‌شده در پنل پیامک','FAILED'=>'ناموفق','UNKNOWN'=>'نیازمند بررسی گزارش سرویس','SKIPPED'=>'لغو یا منقضی‌شده'][$status] ?? $status;
}

function sms_service_ensure_schema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) return;
    foreach ([
        'due_at'=>'DATETIME NULL', 'expires_at'=>'DATETIME NULL',
        'dedup_key'=>'CHAR(64) NULL', 'context_json'=>'TEXT NULL',
        'attempts'=>'INT NOT NULL DEFAULT 0', 'claimed_at'=>'DATETIME NULL',
        'sent_at'=>'DATETIME NULL', 'provider_id'=>'VARCHAR(191) NULL',
        'last_error'=>'VARCHAR(255) NULL',
    ] as $column=>$definition) {
        if (!$pdo->query("SHOW COLUMNS FROM sms_outbox LIKE '$column'")->fetch()) $pdo->exec("ALTER TABLE sms_outbox ADD COLUMN $column $definition");
    }
    if (!$pdo->query("SHOW INDEX FROM sms_outbox WHERE Key_name='uq_sms_dedup'")->fetch()) {
        $pdo->exec('ALTER TABLE sms_outbox ADD UNIQUE KEY uq_sms_dedup(dedup_key)');
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS sms_entity_state (
        entity_key VARCHAR(100) PRIMARY KEY, fingerprint CHAR(64) NOT NULL,
        last_status VARCHAR(32) NOT NULL, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $ready = true;
}

function sms_queue_extended(PDO $pdo, string $phone, string $body, string $kind, ?string $key = null, ?string $dueAt = null, ?string $expiresAt = null, array $context = []): bool
{
    ensure_outreach_schema($pdo);
    $phone = sms_iran_mobile($phone);
    $body = trim($body);
    if ($phone === null || $body === '') return false;
    $maxLen = $kind === 'doctor_daily_digest' ? 1200 : 500;
    if (mb_strlen($body) > $maxLen) {
        $body = mb_substr($body, 0, $maxLen - 3) . '…';
    }
    $stmt = $pdo->prepare("INSERT IGNORE INTO sms_outbox(id,phone,body,kind,status,dedup_key,due_at,expires_at,context_json)
        VALUES(?,?,?,?,'PENDING',?,?,?,?)");
    $stmt->execute([cuid(),$phone,$body,$kind,$key !== null ? hash('sha256',$key) : null,$dueAt ?? date('Y-m-d H:i:s'),$expiresAt,$context ? json_encode($context,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR) : null]);
    return $stmt->rowCount() > 0;
}

function sms_entity_previous(PDO $pdo, string $key): ?array
{
    $stmt=$pdo->prepare('SELECT * FROM sms_entity_state WHERE entity_key=?');$stmt->execute([$key]);
    $row=$stmt->fetch();return is_array($row)?$row:null;
}

function sms_entity_save(PDO $pdo, string $key, string $fingerprint, string $status): void
{
    $pdo->prepare('INSERT INTO sms_entity_state(entity_key,fingerprint,last_status) VALUES(?,?,?) ON DUPLICATE KEY UPDATE fingerprint=VALUES(fingerprint),last_status=VALUES(last_status),updated_at=NOW()')->execute([$key,$fingerprint,$status]);
}

function sms_collect_events(PDO $pdo): void
{
    ensure_outreach_schema($pdo);
    $activated=mail_setting_get($pdo,'sms_events_started_at','');
    if ($activated==='') {$activated=date('Y-m-d H:i:s');mail_setting_set($pdo,'sms_events_started_at',$activated);}
    $appointments=$pdo->prepare("SELECT a.*,u.phone,u.name AS patient_name,d.name AS doctor_name
        FROM appointments a JOIN users u ON u.id=a.patient_id
        JOIN doctor_profiles dp ON dp.id=a.doctor_id JOIN users d ON d.id=dp.user_id
        WHERE a.starts_at>=DATE_SUB(NOW(),INTERVAL 2 DAY) OR a.updated_at>=DATE_SUB(NOW(),INTERVAL 2 DAY)");
    $appointments->execute();
    foreach ($appointments->fetchAll() ?: [] as $a) sms_plan_appointment($pdo,$a,$activated);
    $enrollments=$pdo->prepare("SELECT e.id AS enrollment_id,e.patient_id,e.status AS enrollment_status,e.enrolled_at,
        u.phone,u.name AS patient_name,w.* FROM workshop_enrollments e JOIN workshops w ON w.id=e.workshop_id
        JOIN users u ON u.id=e.patient_id WHERE w.ends_at>=NOW() OR e.updated_at>=DATE_SUB(NOW(),INTERVAL 2 DAY)");
    $enrollments->execute();
    foreach ($enrollments->fetchAll() ?: [] as $w) sms_plan_workshop($pdo,$w,$activated);
}

function sms_plan_appointment(PDO $pdo, array $a, string $activated): void
{
    $key='appointment:'.$a['id'];$old=sms_entity_previous($pdo,$key);
    $fingerprint=hash('sha256',json_encode([$a['status'],$a['starts_at'],$a['doctor_id'],$a['session_mode']??'IN_PERSON']));
    $when=format_fa_datetime((string)$a['starts_at']);
    $context=['entity'=>'appointment','id'=>$a['id'],'starts_at'=>$a['starts_at'],'status'=>$a['status'],'doctor_id'=>$a['doctor_id']];
    $vars=['patient'=>$a['patient_name'],'doctor'=>$a['doctor_name'],'datetime'=>$when,'status'=>$a['status']==='CONFIRMED'?'ثبت شد.':'برای بررسی ثبت شد.'];
    $pdo->beginTransaction();
    try {
        if (($old===null || $old['fingerprint']!==$fingerprint) && in_array($a['status'],['CONFIRMED','PENDING_APPROVAL'],true)
            && ($old!==null || $a['created_at']>=$activated) && strtotime($a['starts_at'])>time()) {
            sms_queue_extended($pdo,$a['phone'],sms_template_render($pdo,'appointment_booked',$vars),'appointment_booked',$key.':booked:'.$fingerprint,null,$a['starts_at'],$context);
        }
        if ($a['status']==='CANCELLED' && (($old!==null && $old['last_status']!=='CANCELLED') || ($old===null && $a['updated_at']>=$activated))) {
            sms_queue_extended($pdo,$a['phone'],sms_template_render($pdo,'appointment_cancelled',$vars),'appointment_cancelled',$key.':cancelled:'.$a['updated_at'],null,date('Y-m-d H:i:s',time()+86400),$context);
        }
        if ($a['status']==='CONFIRMED' && strtotime($a['starts_at'])>time() && strtotime($a['created_at'])<=strtotime($a['starts_at'])-86400) {
            $due=date('Y-m-d H:i:s',max(time(),strtotime($a['starts_at'])-86400));
            sms_queue_extended($pdo,$a['phone'],sms_template_render($pdo,'appointment_reminder',$vars),'appointment_reminder',$key.':reminder:'.$a['starts_at'].':'.$a['doctor_id'], $due,$a['starts_at'],$context);
        }
        sms_entity_save($pdo,$key,$fingerprint,$a['status']);$pdo->commit();
    } catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function sms_workshop_place(array $w): string
{
    if ($w['type']==='ONLINE') return 'آنلاین؛ ورود از پنل سایت';
    if ($w['type']==='OFFLINE') return 'محتوای ضبط‌شده؛ پنل سایت';
    return trim(preg_replace('/\s+/u',' ',strip_tags((string)($w['location']??''))) ?? '');
}

function sms_plan_workshop(PDO $pdo, array $w, string $activated): void
{
    $key='enrollment:'.$w['enrollment_id'];$old=sms_entity_previous($pdo,$key);
    $detailsHash=hash('sha256',json_encode([$w['starts_at'],$w['location'],$w['type'],$w['title']]));
    $fingerprint=hash('sha256',json_encode([$w['enrollment_status'],$detailsHash,$w['status']]));
    $context=['entity'=>'enrollment','id'=>$w['enrollment_id'],'starts_at'=>$w['starts_at'],'details_hash'=>$detailsHash];
    $active=in_array($w['enrollment_status'],['CONFIRMED','COMPLETED'],true)&&!in_array($w['status'],['CANCELLED','DRAFT','COMPLETED'],true);
    $place=sms_workshop_place($w);
    if ($active && ($old===null || $old['fingerprint']!==$fingerprint) && ($old!==null || $w['enrolled_at']>=$activated)) {
        sms_queue_extended($pdo,$w['phone'],sms_template_render($pdo,'workshop_registered',['patient'=>$w['patient_name'],'workshop'=>$w['title'],'datetime'=>format_fa_datetime($w['starts_at']),'place'=>$place]),'workshop_registered',$key.':registered:'.$fingerprint,null,$w['ends_at'],$context);
    }
    if ($active && $w['type']!=='OFFLINE') {
        $stmt=$pdo->prepare('SELECT id,session_date FROM workshop_sessions WHERE workshop_id=? ORDER BY session_date');$stmt->execute([$w['id']]);$sessions=$stmt->fetchAll()?:[];
        if (!$sessions) $sessions=[['id'=>'first','session_date'=>substr($w['starts_at'],0,10)]];
        foreach($sessions as $session){
            $start=$session['session_date'].' '.substr($w['starts_at'],11,8);
            if(strtotime($start)<=time() || strtotime($w['enrolled_at'])>strtotime($start)-86400)continue;
            $sessionContext=$context+['session_id'=>$session['id'],'session_start'=>$start];
            sms_queue_extended($pdo,$w['phone'],sms_template_render($pdo,'workshop_reminder',['patient'=>$w['patient_name'],'workshop'=>$w['title'],'datetime'=>format_fa_datetime($start),'place'=>$place]),'workshop_reminder',$key.':reminder:'.$session['id'].':'.$start.':'.$detailsHash,date('Y-m-d H:i:s',max(time(),strtotime($start)-86400)),$start,$sessionContext);
        }
    }
    sms_entity_save($pdo,$key,$fingerprint,$w['enrollment_status']);
}

function sms_job_still_valid(PDO $pdo, array $job): bool
{
    if (!empty($job['expires_at']) && strtotime($job['expires_at'])<=time())return false;
    $context=json_decode((string)($job['context_json']??''),true);
    if(!is_array($context)||!isset($context['entity']))return true;
    if($context['entity']==='appointment' || $context['entity']==='appointment_doctor'){
        $stmt=$pdo->prepare('SELECT starts_at,status,doctor_id FROM appointments WHERE id=?');$stmt->execute([$context['id']]);$a=$stmt->fetch();
        if(!$a || $a['starts_at']!==$context['starts_at'] || $a['status']!==$context['status'])return false;
        if(isset($context['doctor_id']) && $context['doctor_id']!==null && $context['doctor_id']!=='' && (string)$a['doctor_id']!==(string)$context['doctor_id'])return false;
        return true;
    }
    if($context['entity']==='workshop_presenter'){
        $q=$pdo->prepare('SELECT w.title,w.starts_at,w.ends_at,w.location,w.type,u.phone FROM workshop_presenters wp JOIN workshops w ON w.id=wp.workshop_id JOIN doctor_profiles dp ON dp.id=wp.doctor_id JOIN users u ON u.id=dp.user_id WHERE wp.workshop_id=? AND wp.doctor_id=? AND dp.is_active=1 AND dp.is_approved=1 AND COALESCE(u.is_disabled,0)=0');
        $q->execute([$context['workshop_id'],$context['doctor_id']]);$w=$q->fetch();
        return $w && sms_iran_mobile((string)$w['phone'])===$job['phone'] && hash('sha256',json_encode([$w['title'],$w['starts_at'],$w['ends_at'],$w['location'],$w['type']]))===($context['details_hash']??'');
    }
    if($context['entity']==='workshop_broadcast'){
        require_once __DIR__.'/workshop_broadcast.php';
        return workshop_broadcast_job_valid($pdo,$job,$context);
    }
    if($context['entity']==='doctor_digest'){
        return ($context['digest_date'] ?? '') === date('Y-m-d');
    }
    $stmt=$pdo->prepare('SELECT e.status,w.status AS workshop_status,w.starts_at,w.location,w.type,w.title FROM workshop_enrollments e JOIN workshops w ON w.id=e.workshop_id WHERE e.id=?');$stmt->execute([$context['id']]);$w=$stmt->fetch();
    if(!$w||!in_array($w['status'],['CONFIRMED','COMPLETED'],true)||in_array($w['workshop_status'],['DRAFT','CANCELLED','COMPLETED'],true)||$w['starts_at']!==$context['starts_at'])return false;
    if(hash('sha256',json_encode([$w['starts_at'],$w['location'],$w['type'],$w['title']]))!==$context['details_hash'])return false;
    if(isset($context['session_id'])&&$context['session_id']!=='first'){
        $stmt=$pdo->prepare('SELECT session_date FROM workshop_sessions WHERE id=?');$stmt->execute([$context['session_id']]);
        return $stmt->fetchColumn()===substr($context['session_start'],0,10);
    }
    return true;
}

/**
 * WebOne REST (RestDocument): https://api.payamakapi.ir/api/v1/SMS/Send
 * هدر X-API-KEY؛ بدنه From + ToNumber + Content (بدون IsFlash، مثل مسیر OTP پایدار).
 * بدون کلید API، مسیر قدیمی rest.payamakapi.ir با UserName/Password باقی می‌ماند.
 */
function sms_provider_parse_response(string $raw, int $http, string $sender): array
{
    if ($raw === '') {
        return ['status' => 'UNKNOWN', 'error' => 'پاسخ قطعی از سرویس دریافت نشد؛ قبل از ارسال مجدد گزارش پنل بررسی شود.'];
    }
    $data = json_decode(trim($raw), true);
    if ($http < 200 || $http >= 300) {
        $apiMsg = is_array($data) ? trim((string) ($data['message'] ?? $data['Message'] ?? '')) : '';
        $clientIp = is_array($data) ? trim((string) ($data['clientIp'] ?? $data['ClientIp'] ?? '')) : '';
        $traceId = is_array($data) ? trim((string) ($data['traceId'] ?? $data['TraceId'] ?? '')) : '';
        if ($apiMsg !== '' && stripos($apiMsg, 'IP') !== false) {
            return [
                'status' => 'FAILED',
                'error' => 'IP سرور برای وب‌سرویس مجاز نیست'
                    . ($clientIp !== '' ? (' (IP: ' . $clientIp . ')') : '')
                    . '. در پنل وب‌وان آی‌پی‌های مجاز REST را ثبت کنید.',
            ];
        }
        if ($http >= 500) {
            $detail = $apiMsg !== '' ? $apiMsg : 'خطای داخلی درگاه';
            if ($traceId !== '') {
                $detail .= ' (traceId: ' . $traceId . ')';
            }
            return [
                'status' => 'FAILED',
                'error' => 'وب‌وان روی ارسال با خط «' . $sender . '» خطا داد: ' . $detail
                    . '. شماره خط را از بخش «خطوط» پنل کپی کنید و فعال بودن وب‌سرویس/اعتبار را چک کنید.',
                'retryable_500' => true,
            ];
        }
        return ['status' => 'FAILED', 'error' => $apiMsg !== '' ? ($apiMsg . ' (HTTP ' . $http . ')') : ('درگاه پیامک پاسخ HTTP ' . $http . ' داد.')];
    }
    if (!is_array($data)) {
        return ['status' => 'UNKNOWN', 'error' => 'قالب پاسخ درگاه نیازمند بررسی است؛ ارسال خودکار تکرار نمی‌شود.'];
    }
    $succeeded = ($data['succeeded'] ?? $data['Succeeded'] ?? null);
    $ok = $succeeded === true || $succeeded === 1 || $succeeded === '1'
        || (is_string($succeeded) && strtolower(trim($succeeded)) === 'true');
    $hasCode = array_key_exists('resultCode', $data) || array_key_exists('ResultCode', $data);
    $resultCode = $hasCode ? (int) ($data['resultCode'] ?? $data['ResultCode']) : null;
    if ($resultCode !== null && $resultCode !== 0) {
        $ok = false;
    }
    if ($ok && ($resultCode === null || $resultCode === 0)) {
        $ref = trim((string) ($data['refId'] ?? $data['RefId'] ?? ''));
        return ['status' => 'SENT', 'id' => $ref !== '' ? $ref : null];
    }
    return ['status' => 'FAILED', 'error' => sms_provider_error_message($resultCode ?? -1)];
}

/**
 * درخواست خام به API وب‌وان (همان هدر/JSON هفت‌رخ).
 * @param array<string,mixed> $fields
 * @return array{status:string,id?:?string,error?:string,retryable_500?:bool}
 */
function sms_provider_request(string $apiKey, string $endpoint, array $fields, string $senderLabel): array
{
    $curl = curl_init('https://api.payamakapi.ir/api/v1/' . ltrim($endpoint, '/'));
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
            'X-API-KEY: ' . $apiKey,
        ],
        CURLOPT_POSTFIELDS => json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
    ]);
    $raw = curl_exec($curl);
    $http = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    if ($raw === false) {
        return ['status' => 'UNKNOWN', 'error' => 'پاسخ قطعی از سرویس دریافت نشد؛ قبل از ارسال مجدد گزارش پنل بررسی شود.'];
    }
    $parsed = sms_provider_parse_response((string) $raw, $http, $senderLabel);
    unset($parsed['retryable_500']);
    return $parsed;
}

function sms_provider_send(array $config, string $phone, string $body): array
{
    $apiKey = trim((string) ($config['api_key'] ?? ''));
    if ($apiKey !== '') {
        $sender = (string) $config['sender'];
        // عین هفت‌رخ (RestDocument): فقط From + ToNumber + Content — بدون IsFlash.
        return sms_provider_request($apiKey, 'SMS/Send', [
            'From' => $sender,
            'ToNumber' => $phone,
            'Content' => $body,
        ], $sender);
    }

    $curl = curl_init('https://rest.payamakapi.ir/api/v1/SMS/Send');
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode([
            'UserName' => $config['username'],
            'Password' => $config['password'],
            'From' => $config['sender'],
            'To' => $phone,
            'Message' => $body,
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
    ]);
    $raw = curl_exec($curl);
    $code = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    if ($raw === false || $code >= 500) {
        return ['status' => 'UNKNOWN', 'error' => 'پاسخ قطعی از سرویس دریافت نشد؛ قبل از ارسال مجدد گزارش پنل بررسی شود.'];
    }
    if ($code < 200 || $code >= 300) {
        return ['status' => 'FAILED', 'error' => 'درگاه پیامک پاسخ HTTP ' . $code . ' داد.'];
    }
    $result = json_decode(trim((string) $raw), true);
    if (is_int($result) || is_string($result)) {
        $id = (string) $result;
        if (ctype_digit($id) && (int) $id > 0) {
            return ['status' => 'SENT', 'id' => $id];
        }
        if (is_numeric($id)) {
            return ['status' => 'FAILED', 'error' => 'درگاه پیامک ارسال را نپذیرفت (کد ' . $id . ').'];
        }
    }
    return ['status' => 'UNKNOWN', 'error' => 'قالب پاسخ درگاه نیازمند بررسی است؛ ارسال خودکار تکرار نمی‌شود.'];
}

/**
 * ارسال کد ورود: اول همان مسیر متنی هفت‌رخ، بعد SmartOTP (Auto / خط پنل).
 * @return array{status:string,id?:?string,error?:string}
 */
function sms_provider_send_otp(array $config, string $phone, string $body): array
{
    $apiKey = trim((string) ($config['api_key'] ?? ''));
    if ($apiKey === '') {
        return sms_provider_send($config, $phone, $body);
    }
    $sender = (string) ($config['sender'] ?? '');
    $last = sms_provider_request($apiKey, 'SMS/Send', [
        'From' => $sender,
        'ToNumber' => $phone,
        'Content' => $body,
    ], $sender);
    if (($last['status'] ?? '') === 'SENT') {
        return $last;
    }
    // اگر خط/فرستنده یا مسیر متنی گیر کرد، مثل هفت‌رخ SmartOTP را امتحان کن.
    foreach (['Auto', $sender] as $otpSender) {
        if ($otpSender === '') {
            continue;
        }
        $try = sms_provider_request($apiKey, 'SMS/SmartOTP', [
            'OTPSender' => $otpSender,
            'ToNumber' => $phone,
            'Content' => $body,
        ], (string) $otpSender);
        if (($try['status'] ?? '') === 'SENT') {
            return $try;
        }
        $last = $try;
        // ۸ کمبود اعتبار / ۱۵ تکراری — ادامه بی‌فایده است.
        $err = (string) ($try['error'] ?? '');
        if (str_contains($err, 'اعتبار') || str_contains($err, 'تکراری')) {
            break;
        }
    }
    return $last;
}

function sms_dispatch_pending(PDO $pdo, int $limit=20): int
{
    if(!sms_connection_ready($pdo))return 0;
    if(!(int)$pdo->query("SELECT GET_LOCK('mana_sms_dispatch',0)")->fetchColumn())return 0;
    $sent=0;
    try{
        $pdo->exec("UPDATE sms_outbox SET status='UNKNOWN',last_error='اجرای قبلی قطع شده؛ گزارش سرویس قبل از ارسال مجدد بررسی شود.' WHERE status='PROCESSING' AND claimed_at<DATE_SUB(NOW(),INTERVAL 5 MINUTE)");
        $jobs=$pdo->query("SELECT * FROM sms_outbox WHERE status='PENDING' AND due_at IS NOT NULL AND due_at<=NOW() ORDER BY due_at,id LIMIT ".max(1,min(100,$limit)))->fetchAll()?:[];
        foreach($jobs as $job){
            $claim=$pdo->prepare("UPDATE sms_outbox SET status='PROCESSING',claimed_at=NOW(),attempts=attempts+1 WHERE id=? AND status='PENDING'");$claim->execute([$job['id']]);if(!$claim->rowCount())continue;
            if(!sms_job_still_valid($pdo,$job)){$pdo->prepare("UPDATE sms_outbox SET status='SKIPPED',last_error='نوبت یا ثبت‌نام تغییر کرده، لغو شده یا مهلت پیام گذشته است.' WHERE id=?")->execute([$job['id']]);continue;}
            $result=sms_provider_send(sms_connection_config($pdo),$job['phone'],$job['body']);
            $pdo->prepare('UPDATE sms_outbox SET status=?,provider_id=?,last_error=?,sent_at=IF(?=\'SENT\',NOW(),NULL) WHERE id=?')->execute([$result['status'],$result['id']??null,$result['error']??null,$result['status'],$job['id']]);
            if($result['status']==='SENT')$sent++;
        }
    }finally{$pdo->query("SELECT RELEASE_LOCK('mana_sms_dispatch')");}
    return $sent;
}

/**
 * متن خلاصه صبحگاهی نوبت‌های یک درمانگر.
 * تک‌خطی و کوتاه مثل قالب‌های پایدار نوبت — وب‌وان متن چندخطی/بلند را با کد ۸
 * («اعتبار کافی نیست») رد می‌کند حتی وقتی GetCredit اعتبار دارد.
 * @param list<string> $times مثلاً ['10:00','11:00']
 */
function sms_doctor_daily_digest_body(string $doctorName, string $dayLabel, array $times): string
{
    // $doctorName عمداً در متن نیست؛ گیرنده از شماره معلوم است و نام متن را بلند می‌کند.
    $slots = [];
    foreach ($times as $time) {
        $time = trim((string) $time);
        if ($time === '') {
            continue;
        }
        $slots[] = to_fa_digits($time);
    }
    if ($slots === []) {
        return 'مانا کلینیک: نوبتی برای ' . $dayLabel . ' نیست.';
    }
    $prefix = 'مانا کلینیک: نوبت ' . $dayLabel . ': ';
    $body = $prefix . implode('، ', $slots);
    while (mb_strlen($body) > 70 && count($slots) > 1) {
        array_pop($slots);
        $body = $prefix . implode('، ', $slots) . '…';
    }
    if (mb_strlen($body) > 70) {
        $body = mb_substr($body, 0, 67) . '…';
    }

    return $body;
}

/**
 * خلاصه نوبت‌های تأییدشدهٔ یک روز برای درمانگرها.
 *
 * @param list<string>|null $doctorIds اگر null باشد همه؛ وگرنه فقط همین پروفایل‌ها
 * @return list<array{doctor_id:string,name:string,phone:?string,times:list<string>,body:string,slot_count:int}>
 */
function sms_doctor_digests_for_date(PDO $pdo, string $ymd, ?array $doctorIds = null): array
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ymd)) {
        return [];
    }
    $doctorIds = $doctorIds === null ? null : array_values(array_filter(array_map('strval', $doctorIds)));
    if ($doctorIds !== null && $doctorIds === []) {
        return [];
    }

    $sql = "
      SELECT dp.id AS doctor_id, u.name AS doctor_name, u.phone AS doctor_phone, a.starts_at
      FROM appointments a
      JOIN doctor_profiles dp ON dp.id = a.doctor_id
      JOIN users u ON u.id = dp.user_id
      WHERE DATE(a.starts_at) = ?
        AND a.status = 'CONFIRMED'
        AND dp.is_active = 1
        AND dp.is_approved = 1
        AND u.is_disabled = 0
    ";
    $params = [$ymd];
    if ($doctorIds !== null) {
        $marks = implode(',', array_fill(0, count($doctorIds), '?'));
        $sql .= " AND dp.id IN ($marks)";
        foreach ($doctorIds as $id) {
            $params[] = $id;
        }
    }
    $sql .= ' ORDER BY dp.id ASC, a.starts_at ASC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll() ?: [];

    $dayLabel = function_exists('format_fa_day_long')
        ? format_fa_day_long($ymd . ' 12:00:00')
        : to_fa_digits($ymd);

    /** @var array<string,array{name:string,phone:string,times:list<string>}> $byDoctor */
    $byDoctor = [];
    foreach ($rows as $row) {
        $doctorId = (string) ($row['doctor_id'] ?? '');
        if ($doctorId === '') {
            continue;
        }
        if (!isset($byDoctor[$doctorId])) {
            $byDoctor[$doctorId] = [
                'name' => (string) ($row['doctor_name'] ?? ''),
                'phone' => (string) ($row['doctor_phone'] ?? ''),
                'times' => [],
            ];
        }
        $ts = strtotime((string) ($row['starts_at'] ?? ''));
        if (!$ts) {
            continue;
        }
        $byDoctor[$doctorId]['times'][] = date('H:i', $ts);
    }

    $out = [];
    foreach ($byDoctor as $doctorId => $pack) {
        $times = array_values(array_unique($pack['times']));
        if ($times === []) {
            continue;
        }
        $out[] = [
            'doctor_id' => $doctorId,
            'name' => $pack['name'],
            'phone' => sms_iran_mobile($pack['phone']),
            'times' => $times,
            'body' => sms_doctor_daily_digest_body($pack['name'], $dayLabel, $times),
            'slot_count' => count($times),
        ];
    }

    return $out;
}

/**
 * هر روز از ساعت ۷ صبح، برای درمانگرهایی که همان روز نوبت تأییدشده دارند یک پیامک صف می‌کند.
 */
function sms_queue_doctor_daily_digests(PDO $pdo): int
{
    if (!sms_connection_ready($pdo)) {
        return 0;
    }
    // قبل از ۷ صبح صف نشود (کرون هر دقیقه است).
    if ((int) date('G') < 7) {
        return 0;
    }
    ensure_outreach_schema($pdo);
    $today = date('Y-m-d');
    $dueAt = $today . ' 07:00:00';
    $expiresAt = $today . ' 23:59:59';
    $queued = 0;
    foreach (sms_doctor_digests_for_date($pdo, $today, null) as $pack) {
        if (($pack['phone'] ?? null) === null) {
            continue;
        }
        $key = 'doctor-digest:' . $pack['doctor_id'] . ':' . $today;
        if (sms_queue_extended(
            $pdo,
            (string) $pack['phone'],
            (string) $pack['body'],
            'doctor_daily_digest',
            $key,
            $dueAt,
            $expiresAt,
            ['entity' => 'doctor_digest', 'doctor_id' => $pack['doctor_id'], 'digest_date' => $today]
        )) {
            $queued++;
        }
    }

    return $queued;
}

/**
 * ارسال دستی خلاصه نوبت یک روز برای درمانگرهای انتخاب‌شده (فوری).
 *
 * @param list<string> $doctorIds
 * @return array{queued:int,sent:int,failed:int,skipped:int,errors:list<string>}
 */
function sms_send_doctor_digests_manual(PDO $pdo, string $ymd, array $doctorIds): array
{
    $result = ['queued' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0, 'errors' => []];
    if (!sms_connection_ready($pdo)) {
        $result['errors'][] = 'اتصال پیامک آماده نیست.';
        return $result;
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ymd)) {
        $result['errors'][] = 'تاریخ نامعتبر است.';
        return $result;
    }
    $doctorIds = array_values(array_unique(array_filter(array_map('strval', $doctorIds))));
    if ($doctorIds === []) {
        $result['errors'][] = 'حداقل یک درمانگر را انتخاب کنید.';
        return $result;
    }

    $packs = sms_doctor_digests_for_date($pdo, $ymd, $doctorIds);
    $found = [];
    $sendIndex = 0;
    foreach ($packs as $pack) {
        $found[(string) $pack['doctor_id']] = true;
        if (($pack['phone'] ?? null) === null) {
            $result['skipped']++;
            $result['errors'][] = 'شماره موبایل «' . ($pack['name'] ?: 'درمانگر') . '» معتبر نیست.';
            continue;
        }
        // فاصله بین ارسال‌ها تا وب‌وان burst را با کد ۸/۱۵ رد نکند
        if ($sendIndex > 0) {
            usleep(1_600_000);
        }
        $sendIndex++;
        $stamp = date('YmdHis');
        $key = 'doctor-digest-manual:' . $pack['doctor_id'] . ':' . $ymd . ':' . $stamp;
        $context = ['entity' => 'doctor_digest', 'doctor_id' => $pack['doctor_id'], 'digest_date' => $ymd, 'manual' => 1];
        $send = sms_send_immediate(
            $pdo,
            (string) $pack['phone'],
            (string) $pack['body'],
            'doctor_daily_digest',
            $key,
            $ymd . ' 23:59:59',
            $context
        );
        $err = trim((string) ($send['error'] ?? ''));
        if (
            empty($send['ok'])
            && $err !== ''
            && (str_contains($err, 'اعتبار') || str_contains($err, 'تکراری'))
        ) {
            usleep(2_500_000);
            $send = sms_send_immediate(
                $pdo,
                (string) $pack['phone'],
                (string) $pack['body'],
                'doctor_daily_digest',
                $key . ':retry',
                $ymd . ' 23:59:59',
                $context
            );
            $err = trim((string) ($send['error'] ?? ''));
        }
        $result['queued']++;
        if (!empty($send['ok'])) {
            $result['sent']++;
        } else {
            $result['failed']++;
            $result['errors'][] = ($pack['name'] ?: 'درمانگر') . ($err !== '' ? (': ' . $err) : ' — ارسال ناموفق');
        }
    }
    foreach ($doctorIds as $id) {
        if (!isset($found[$id])) {
            $result['skipped']++;
        }
    }
    if ($result['queued'] === 0 && $result['errors'] === []) {
        $result['errors'][] = 'برای این تاریخ و درمانگرهای انتخاب‌شده نوبت تأییدشده‌ای نیست.';
    }

    return $result;
}

/** فهرست درمانگرهای فعال برای انتخاب دستی خلاصه نوبت. */
function sms_digest_doctor_choices(PDO $pdo): array
{
    return $pdo->query("
      SELECT dp.id, u.name, u.phone
      FROM doctor_profiles dp
      JOIN users u ON u.id = dp.user_id
      WHERE dp.is_active = 1 AND dp.is_approved = 1 AND u.is_disabled = 0
      ORDER BY u.name ASC
    ")->fetchAll() ?: [];
}

function sms_run_worker(PDO $pdo): void
{
    ensure_outreach_schema($pdo);
    if(!(int)$pdo->query("SELECT GET_LOCK('mana_sms_collect',0)")->fetchColumn())return;
    try{
        sms_collect_events($pdo);
        sms_queue_doctor_daily_digests($pdo);
    }finally{$pdo->query("SELECT RELEASE_LOCK('mana_sms_collect')");}
    sms_dispatch_pending($pdo);
}

/** توکن ثابت برای کرون URL (هر دقیقه از هاست). */
function sms_cron_ensure_token(PDO $pdo): string
{
    ensure_mail_schema($pdo);
    $token = trim((string) mail_setting_get($pdo, 'sms_cron_token', ''));
    if ($token !== '' && preg_match('/^[a-f0-9]{32,64}$/D', $token)) {
        return $token;
    }
    $token = bin2hex(random_bytes(24));
    mail_setting_set($pdo, 'sms_cron_token', $token);
    return $token;
}

function sms_cron_url(PDO $pdo): string
{
    $path = '/cron/sms?token=' . rawurlencode(sms_cron_ensure_token($pdo));
    if (function_exists('seo_absolute_url')) {
        return seo_absolute_url($path);
    }
    global $config;
    $base = rtrim((string) (($config['app_url'] ?? '') ?: ($GLOBALS['config']['app_url'] ?? '')), '/');
    if ($base === '') {
        return url('/cron/sms') . '?token=' . rawurlencode(sms_cron_ensure_token($pdo));
    }

    return $base . $path;
}

function sms_worker_is_stale(PDO $pdo, int $maxAgeMinutes = 90): bool
{
    $seen = trim((string) mail_setting_get($pdo, 'sms_worker_last_run', ''));
    if ($seen === '') {
        return true;
    }
    $ts = strtotime($seen);
    if (!$ts) {
        return true;
    }

    return (time() - $ts) > ($maxAgeMinutes * 60);
}
