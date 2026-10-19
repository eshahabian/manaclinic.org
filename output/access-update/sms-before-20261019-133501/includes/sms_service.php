<?php
declare(strict_types=1);

function sms_template_catalog(): array
{
    return [
        'appointment_booked'=>['ثبت و تأیید نوبت','مانا کلینیک: نوبت شما با {doctor} در {datetime} {status}'],
        'appointment_cancelled'=>['لغو نوبت','مانا کلینیک: نوبت شما با {doctor} در {datetime} لغو شد.'],
        'appointment_reminder'=>['یادآوری نوبت','یادآوری مانا کلینیک: نوبت شما با {doctor} در {datetime} برگزار می‌شود.'],
        'workshop_registered'=>['تأیید ثبت‌نام کارگاه','مانا کلینیک: ثبت‌نام کارگاه «{workshop}» تأیید شد. {datetime}؛ {place}'],
        'workshop_reminder'=>['یادآوری کارگاه','یادآوری مانا کلینیک: جلسه کارگاه «{workshop}» در {datetime}؛ {place}'],
        'workshop_announcement'=>['معرفی کارگاه','مانا کلینیک: کارگاه «{workshop}» از {datetime} برگزار می‌شود. برای ثبت‌نام به سایت کلینیک سر بزنید.'],
        'quiet'=>['پیگیری عدم مراجعه','مانا کلینیک: {message}'],
        'login_otp'=>['کد ورود',"مانا کلینیک\nکد ورود: {code}\nاعتبار: ۳ دقیقه\nاین کد را در اختیار دیگران نگذارید."],
    ];
}

function sms_template_render(PDO $pdo, string $kind, array $variables): string
{
    $catalog=sms_template_catalog();
    $template=mail_setting_get($pdo,'sms_template_'.$kind,'');
    if(trim((string)$template)==='')$template=$catalog[$kind][1];
    $map=[];foreach($variables as $key=>$value)$map['{'.$key.'}']=(string)$value;
    return strtr($template,$map);
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
        8 => 'درگاه پیامک ارسال را نپذیرفت (کد 8)؛ معنای کد باید با مستندات همان درگاه بررسی شود.',
        9 => 'سامانه پیامک در حال به‌روزرسانی است.',
        10 => 'وب‌سرویس پیامک غیرفعال است.',
        14 => 'تعرفه کاربر مشخص نشده است.',
        15 => 'ارسال تکراری؛ کمی بعد دوباره تلاش کنید.',
        16 => 'شماره موبایل گیرنده نامعتبر است.',
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
    if (mb_strlen($body) > 500) $body = mb_substr($body, 0, 497) . '…';
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
    if($context['entity']==='appointment'){
        $stmt=$pdo->prepare('SELECT starts_at,status,doctor_id FROM appointments WHERE id=?');$stmt->execute([$context['id']]);$a=$stmt->fetch();
        return $a && $a['starts_at']===$context['starts_at'] && $a['doctor_id']===$context['doctor_id'] && $a['status']===$context['status'];
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
 * WebOne REST: https://api.payamakapi.ir/api/v1/SMS/Send
 * با هدر X-API-KEY؛ بدنه From + ToNumber + Content + IsFlash.
 * بدون کلید API، مسیر قدیمی rest.payamakapi.ir با UserName/Password باقی می‌ماند.
 */
function sms_provider_send(array $config, string $phone, string $body): array
{
    $apiKey = trim((string) ($config['api_key'] ?? ''));
    if ($apiKey !== '') {
        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            'X-API-KEY: ' . $apiKey,
        ];
        // WebOne RestDocument: IsFlash must be present; omitting it returns HTTP 500.
        $payload = json_encode([
            'From' => (string) $config['sender'],
            'ToNumber' => $phone,
            'Content' => $body,
            'IsFlash' => false,
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $curl = curl_init('https://api.payamakapi.ir/api/v1/SMS/Send');
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $payload,
        ]);
        $raw = curl_exec($curl);
        $http = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        if ($raw === false) {
            return ['status' => 'UNKNOWN', 'error' => 'پاسخ قطعی از سرویس دریافت نشد؛ قبل از ارسال مجدد گزارش پنل بررسی شود.'];
        }
        $data = json_decode(trim((string) $raw), true);
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
                    'error' => 'وب‌وان روی ارسال با خط «' . $config['sender'] . '» خطا داد: ' . $detail
                        . '. شماره خط را از بخش «خطوط» پنل کپی کنید و فعال بودن وب‌سرویس/اعتبار را چک کنید.',
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
        $code = $resultCode ?? -1;
        return ['status' => 'FAILED', 'error' => sms_provider_error_message($code)];
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

function sms_run_worker(PDO $pdo): void
{
    ensure_outreach_schema($pdo);
    if(!(int)$pdo->query("SELECT GET_LOCK('mana_sms_collect',0)")->fetchColumn())return;
    try{sms_collect_events($pdo);}finally{$pdo->query("SELECT RELEASE_LOCK('mana_sms_collect')");}
    sms_dispatch_pending($pdo);
}
