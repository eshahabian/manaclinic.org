<?php
declare(strict_types=1);

function workshop_presenter_message(array $workshop, string $name): string
{
    $start = strtotime((string) $workshop['starts_at']);
    $end = strtotime((string) $workshop['ends_at']);
    $days = ['یکشنبه', 'دوشنبه', 'سه‌شنبه', 'چهارشنبه', 'پنجشنبه', 'جمعه', 'شنبه'];
    $when = ($workshop['session_interval'] ?? '') === 'WEEKLY'
        ? $days[(int) date('w', $start)] . '‌ها'
        : format_fa_day_long((string) $workshop['starts_at']);
    $place = trim((string) ($workshop['location'] ?? ''));
    if (($workshop['type'] ?? '') === 'ONLINE') $place = 'به‌صورت آنلاین';
    if (($workshop['type'] ?? '') === 'OFFLINE') $place = 'به‌صورت آفلاین';
    if ($place === '') throw new RuntimeException('محل برگزاری کارگاه را ابتدا ثبت کنید.');
    return trim($name) . " گرامی\nحضور شما به‌عنوان اجراکننده کارگاه «" . $workshop['title'] . "» تأیید شد.\n"
        . $when . '، ساعت ' . to_fa_digits(date('H:i', $start)) . ' تا ' . to_fa_digits(date('H:i', $end))
        . "\nمحل برگزاری: " . $place . "\nکلینیک مانا";
}

function workshop_presenter_names(PDO $pdo, string $workshopId): array
{
    $q=$pdo->prepare('SELECT u.name FROM workshop_presenters wp JOIN doctor_profiles dp ON dp.id=wp.doctor_id JOIN users u ON u.id=dp.user_id WHERE wp.workshop_id=? AND dp.is_active=1 AND dp.is_approved=1 AND COALESCE(u.is_disabled,0)=0 ORDER BY u.name');
    $q->execute([$workshopId]);return $q->fetchAll(PDO::FETCH_COLUMN);
}

function workshop_presenters_dispatch(PDO $pdo, string $workshopId): array
{
    $counts=['SENT'=>0,'FAILED'=>0,'UNKNOWN'=>0,'SKIPPED'=>0];
    if(!sms_connection_ready($pdo)||(int)$pdo->query("SELECT GET_LOCK('mana_sms_dispatch',0)")->fetchColumn()!==1)return $counts;
    try {
        $q=$pdo->prepare("SELECT * FROM sms_outbox WHERE kind IN ('workshop_presenter','workshop_presenter_copy') AND status='PENDING' AND due_at<=NOW() AND JSON_UNQUOTE(JSON_EXTRACT(context_json,'$.workshop_id'))=? ORDER BY created_at,id LIMIT 30");
        $q->execute([$workshopId]);
        foreach($q->fetchAll() as $job){
            $claim=$pdo->prepare("UPDATE sms_outbox SET status='PROCESSING',attempts=attempts+1,claimed_at=NOW() WHERE id=? AND status='PENDING'");$claim->execute([$job['id']]);if(!$claim->rowCount())continue;
            if(!sms_job_still_valid($pdo,$job))$result=['status'=>'SKIPPED','error'=>'عضویت اجراکننده یا اطلاعات کارگاه تغییر کرده است.'];
            else {
                try{$result=sms_provider_send(sms_connection_config($pdo),(string)$job['phone'],(string)$job['body']);}
                catch(Throwable $e){$result=['status'=>'UNKNOWN','error'=>'پاسخ قطعی از درگاه دریافت نشد.'];}
            }
            $pdo->prepare("UPDATE sms_outbox SET status=?,provider_id=?,last_error=?,sent_at=IF(?='SENT',NOW(),NULL) WHERE id=?")->execute([$result['status'],$result['id']??null,$result['error']??null,$result['status'],$job['id']]);
            $counts[$result['status']]++;
        }
    } finally {$pdo->query("SELECT RELEASE_LOCK('mana_sms_dispatch')");}
    return $counts;
}

function workshop_presenters_schema(PDO $pdo): void
{
    static $ready=false; if($ready)return;
    $pdo->exec("CREATE TABLE IF NOT EXISTS workshop_presenters (
      workshop_id VARCHAR(32) NOT NULL, doctor_id VARCHAR(32) NOT NULL,
      assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY(workshop_id,doctor_id),
      FOREIGN KEY(workshop_id) REFERENCES workshops(id) ON DELETE CASCADE,
      FOREIGN KEY(doctor_id) REFERENCES doctor_profiles(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $ready=true;
}

function workshop_presenters_save(PDO $pdo, string $workshopId, array $ids): void
{
    if(!$pdo->inTransaction())workshop_presenters_schema($pdo);
    $ids = array_values(array_unique(array_filter(array_map('strval', $ids))));
    if (!$ids) throw new RuntimeException('حداقل یک اجراکننده انتخاب کنید.');
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $q = $pdo->prepare("SELECT dp.id,u.name,u.phone FROM doctor_profiles dp JOIN users u ON u.id=dp.user_id WHERE dp.id IN ($marks) AND dp.is_active=1 AND dp.is_approved=1 AND COALESCE(u.is_disabled,0)=0");
    $q->execute($ids); $doctors = $q->fetchAll();
    if (count($doctors) !== count($ids)) throw new RuntimeException('درمانگر انتخاب‌شده معتبر نیست.');
    $ownsTransaction=!$pdo->inTransaction();
    if($ownsTransaction){ensure_outreach_schema($pdo);$pdo->beginTransaction();}
    try {
        $q=$pdo->prepare('SELECT * FROM workshops WHERE id=? FOR UPDATE');$q->execute([$workshopId]);$workshop=$q->fetch();
        if (!$workshop) throw new RuntimeException('کارگاه پیدا نشد.');
        $q=$pdo->prepare('SELECT doctor_id FROM workshop_presenters WHERE workshop_id=?');$q->execute([$workshopId]);$old=$q->fetchAll(PDO::FETCH_COLUMN);
        foreach ($doctors as $doctor) {
            if (in_array($doctor['id'], $old, true)) continue;
            $phone=sms_iran_mobile((string)$doctor['phone']);
            if ($phone===null) throw new RuntimeException('شماره موبایل یکی از اجراکنندگان معتبر نیست.');
            $body=workshop_presenter_message($workshop,(string)$doctor['name']);
            $pdo->prepare('INSERT INTO workshop_presenters(workshop_id,doctor_id) VALUES (?,?)')->execute([$workshopId,$doctor['id']]);
            sms_queue_extended($pdo,$phone,$body,'workshop_presenter','workshop-presenter:'.cuid(),null,date('Y-m-d H:i:s',time()+86400),['entity'=>'workshop_presenter','workshop_id'=>$workshopId,'doctor_id'=>$doctor['id'],'details_hash'=>hash('sha256',json_encode([$workshop['title'],$workshop['starts_at'],$workshop['ends_at'],$workshop['location'],$workshop['type']]))]);
            $copyQuery=$pdo->query("SELECT id,phone FROM users WHERE LOWER(username)='eshahabian' AND COALESCE(is_disabled,0)=0 LIMIT 1");$copyUser=$copyQuery->fetch();
            $copyPhone=$copyUser?sms_iran_mobile((string)$copyUser['phone']):null;
            if($copyPhone!==null&&$copyPhone!==$phone){
                sms_queue_extended($pdo,$copyPhone,$body,'workshop_presenter_copy','workshop-presenter-copy:'.cuid(),null,date('Y-m-d H:i:s',time()+86400),['entity'=>'workshop_presenter','workshop_id'=>$workshopId,'doctor_id'=>$doctor['id'],'copy_for'=>$copyUser['id'],'details_hash'=>hash('sha256',json_encode([$workshop['title'],$workshop['starts_at'],$workshop['ends_at'],$workshop['location'],$workshop['type']]))]);
            }

        }
        $pdo->prepare("DELETE FROM workshop_presenters WHERE workshop_id=? AND doctor_id NOT IN ($marks)")->execute(array_merge([$workshopId],$ids));
        if($ownsTransaction)$pdo->commit();
    } catch(Throwable $e) { if($ownsTransaction&&$pdo->inTransaction())$pdo->rollBack();throw $e; }
}
