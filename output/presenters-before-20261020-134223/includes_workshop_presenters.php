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

function workshop_presenters_schema(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS workshop_presenters (
      workshop_id VARCHAR(32) NOT NULL, doctor_id VARCHAR(32) NOT NULL,
      assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY(workshop_id,doctor_id),
      FOREIGN KEY(workshop_id) REFERENCES workshops(id) ON DELETE CASCADE,
      FOREIGN KEY(doctor_id) REFERENCES doctor_profiles(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function workshop_presenters_save(PDO $pdo, string $workshopId, array $ids): void
{
    workshop_presenters_schema($pdo);
    $ids = array_values(array_unique(array_filter(array_map('strval', $ids))));
    if (!$ids) throw new RuntimeException('حداقل یک اجراکننده انتخاب کنید.');
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $q = $pdo->prepare("SELECT dp.id,u.name,u.phone FROM doctor_profiles dp JOIN users u ON u.id=dp.user_id WHERE dp.id IN ($marks) AND dp.is_active=1 AND dp.is_approved=1 AND COALESCE(u.is_disabled,0)=0");
    $q->execute($ids); $doctors = $q->fetchAll();
    if (count($doctors) !== count($ids)) throw new RuntimeException('درمانگر انتخاب‌شده معتبر نیست.');
    ensure_outreach_schema($pdo);
    $pdo->beginTransaction();
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
        }
        $pdo->prepare("DELETE FROM workshop_presenters WHERE workshop_id=? AND doctor_id NOT IN ($marks)")->execute(array_merge([$workshopId],$ids));
        $pdo->commit();
    } catch(Throwable $e) { if($pdo->inTransaction())$pdo->rollBack();throw $e; }
}
