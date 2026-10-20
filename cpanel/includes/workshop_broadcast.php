<?php
declare(strict_types=1);

function workshop_broadcast_members(PDO $pdo, string $workshopId): array
{
    $q=$pdo->prepare("SELECT e.patient_id,u.phone FROM workshop_enrollments e JOIN users u ON u.id=e.patient_id WHERE e.workshop_id=? AND e.status IN ('CONFIRMED','COMPLETED') AND COALESCE(u.is_disabled,0)=0 ORDER BY e.patient_id");
    $q->execute([$workshopId]);$phones=[];$invalid=0;
    foreach($q->fetchAll() as $row){$phone=sms_iran_mobile((string)$row['phone']);if($phone===null){$invalid++;continue;}$phones[$phone][]=(string)$row['patient_id'];}
    ksort($phones);return ['phones'=>$phones,'invalid'=>$invalid];
}

function workshop_broadcast_status(PDO $pdo, string $token): array
{
    $q=$pdo->prepare("SELECT status,COUNT(*) AS n FROM sms_outbox WHERE kind='workshop_broadcast' AND JSON_UNQUOTE(JSON_EXTRACT(context_json,'$.batch'))=? GROUP BY status");
    $q->execute([$token]);$counts=['SENT'=>0,'PENDING'=>0,'PROCESSING'=>0,'FAILED'=>0,'UNKNOWN'=>0,'SKIPPED'=>0];
    foreach($q->fetchAll() as $r)$counts[$r['status']]=(int)$r['n'];return $counts;
}

function workshop_broadcast_job_valid(PDO $pdo, array $job, array $context): bool
{
    $ids=$context['members']??[];if(!$ids||!is_array($ids))return false;
    $marks=implode(',',array_fill(0,count($ids),'?'));
    $q=$pdo->prepare("SELECT u.phone FROM workshop_enrollments e JOIN users u ON u.id=e.patient_id WHERE e.workshop_id=? AND e.patient_id IN ($marks) AND e.status IN ('CONFIRMED','COMPLETED') AND COALESCE(u.is_disabled,0)=0");
    $q->execute(array_merge([(string)($context['workshop_id']??'')],$ids));
    foreach($q->fetchAll() as $r)if(sms_iran_mobile((string)$r['phone'])===$job['phone'])return true;return false;
}

/** Process only this explicitly requested batch, sharing the worker's lock. */
function workshop_broadcast_process(PDO $pdo, string $token): void
{
    if(!(int)$pdo->query("SELECT GET_LOCK('mana_sms_dispatch',0)")->fetchColumn())return;
    try {
        $q=$pdo->prepare("SELECT * FROM sms_outbox WHERE kind='workshop_broadcast' AND status='PENDING' AND JSON_UNQUOTE(JSON_EXTRACT(context_json,'$.batch'))=? ORDER BY id LIMIT 1");$q->execute([$token]);$job=$q->fetch();if(!$job)return;
        $claim=$pdo->prepare("UPDATE sms_outbox SET status='PROCESSING',attempts=attempts+1,claimed_at=NOW() WHERE id=? AND status='PENDING'");$claim->execute([$job['id']]);if(!$claim->rowCount())return;
        if(!sms_job_still_valid($pdo,$job)){$pdo->prepare("UPDATE sms_outbox SET status='SKIPPED',last_error='عضویت یا شماره گیرنده تغییر کرده است.' WHERE id=?")->execute([$job['id']]);return;}
        try{$result=sms_provider_send(sms_connection_config($pdo),(string)$job['phone'],(string)$job['body']);}
        catch(Throwable $e){$result=['status'=>'UNKNOWN','error'=>'پاسخ قطعی از درگاه دریافت نشد.'];}
        $pdo->prepare("UPDATE sms_outbox SET status=?,provider_id=?,last_error=?,sent_at=IF(?='SENT',NOW(),NULL) WHERE id=?")
            ->execute([$result['status'],$result['id']??null,$result['error']??null,$result['status'],$job['id']]);
    } finally {$pdo->query("SELECT RELEASE_LOCK('mana_sms_dispatch')");}
}
