<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/workshop_broadcast.php';
$user=require_login(['ADMIN','DOCTOR','SECRETARY']);
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
if(!workshop_can_add_member($user)){http_response_code(403);echo json_encode(['ok'=>false,'error'=>'دسترسی ارسال پیامک گروهی ندارید.'],JSON_UNESCAPED_UNICODE);exit;}
try {
    $operation=post('operation');$workshopId=post('workshop_id');
    if($operation==='preview'){
        $body=trim(post('body'));if($body===''||mb_strlen($body)>500)throw new RuntimeException('متن پیام باید بین ۱ تا ۵۰۰ کاراکتر باشد.');
        $q=$pdo->prepare('SELECT title FROM workshops WHERE id=?');$q->execute([$workshopId]);$title=$q->fetchColumn();if($title===false)throw new RuntimeException('کارگاه پیدا نشد.');
        $selected=isset($_POST['selection_present'])?array_values(array_unique(array_map('strval',(array)($_POST['selected']??[])))):null;
        $members=workshop_broadcast_members($pdo,$workshopId,$selected);$count=count($members['phones']);if(!$count)throw new RuntimeException('این کارگاه عضو دارای موبایل معتبر برای ارسال ندارد.');
        foreach($_SESSION['workshop_broadcasts']??[] as $key=>$value)if(($value['created']??0)<time()-86400)unset($_SESSION['workshop_broadcasts'][$key]);
        $token=bin2hex(random_bytes(24));
        $_SESSION['workshop_broadcasts'][$token]=['actor'=>(string)$user['id'],'workshop'=>$workshopId,'body'=>$body,'selected'=>$selected,'members_hash'=>hash('sha256',json_encode($members['phones'])),'created'=>time(),'queued'=>false];
        echo json_encode(['ok'=>true,'token'=>$token,'count'=>$count,'invalid'=>$members['invalid'],'title'=>$title],JSON_UNESCAPED_UNICODE);exit;
    }
    $token=post('token');$batch=$_SESSION['workshop_broadcasts'][$token]??null;
    if(!$batch||$batch['actor']!==(string)$user['id']||$batch['workshop']!==$workshopId||$batch['created']<time()-86400)throw new RuntimeException('پیش‌نمایش معتبر نیست؛ دوباره پیش‌نمایش بگیرید.');
    if($operation==='send' && trim(post('body'))!==$batch['body'])throw new RuntimeException('متن پیام تغییر کرده است؛ دوباره پیش‌نمایش بگیرید.');
    if($operation==='send' && !$batch['queued']){
        if($batch['created']<time()-600)throw new RuntimeException('پیش‌نمایش منقضی شد؛ دوباره بررسی کنید.');
        if(!sms_connection_ready($pdo))throw new RuntimeException('درگاه پیامک آماده نیست.');
        $members=workshop_broadcast_members($pdo,$workshopId,$batch['selected']??null);
        if(!hash_equals($batch['members_hash'],hash('sha256',json_encode($members['phones']))))throw new RuntimeException('فهرست اعضا تغییر کرده است؛ دوباره پیش‌نمایش بگیرید.');
        ensure_outreach_schema($pdo);$pdo->beginTransaction();
        try{
            foreach($members['phones'] as $phone=>$ids){
                sms_queue_extended($pdo,(string)$phone,$batch['body'],'workshop_broadcast','workshop-broadcast:'.$token.':'.$phone,null,date('Y-m-d H:i:s',time()+86400),['entity'=>'workshop_broadcast','workshop_id'=>$workshopId,'members'=>$ids,'batch'=>$token]);
            }
            staff_log_action($pdo,(string)$user['id'],'workshop_broadcast','workshop',$workshopId,'recipients='.count($members['phones']));$pdo->commit();
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
        $_SESSION['workshop_broadcasts'][$token]['queued']=true;$batch['queued']=true;
    }elseif(!in_array($operation,['send','process','status'],true)){throw new RuntimeException('درخواست نامعتبر است.');}
    if(!$batch['queued'])throw new RuntimeException('ابتدا ارسال را تأیید کنید.');
    if($operation==='process')workshop_broadcast_process($pdo,$token);
    echo json_encode(['ok'=>true,'counts'=>workshop_broadcast_status($pdo,$token)],JSON_UNESCAPED_UNICODE);
}catch(RuntimeException $e){http_response_code(422);echo json_encode(['ok'=>false,'error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE);}
