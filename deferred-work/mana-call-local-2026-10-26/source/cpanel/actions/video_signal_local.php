<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/video_call.php';
require_once __DIR__ . '/../includes/mana_local_call.php';
$user = require_login();
if (!video_call_allowed($user)) video_call_json(['ok'=>false, 'error'=>'اجازه تماس ندارید.'],403);
csrf_verify();
$body = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($body)) $body = $_POST;
$action = (string) ($body['action'] ?? 'poll');
if (!in_array($action, ['poll','send','inbox','ack_ring'], true)) video_call_json(['ok'=>false,'error'=>'درخواست نامعتبر است.'],400);
ensure_video_call_schema($pdo);
mana_local_call_schema($pdo);
$me = (string) $user['id'];
video_call_touch($pdo, $me);
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
if ($action === 'inbox') {
    // One same-origin inbox for both engines; no extra polling request needed.
    $st=$pdo->prepare("SELECT s.id,s.room_id,s.payload,s.engine,u.name AS sender_name,r.kind FROM (
        SELECT id,room_id,payload,sender_id,created_at,'local' AS engine FROM mana_local_signals
        WHERE target_id=? AND sender_id<>? AND kind='ringing' AND created_at>=DATE_SUB(NOW(),INTERVAL 45 SECOND)
        UNION ALL
        SELECT id,room_id,payload,sender_id,created_at,'livekit' AS engine FROM video_call_signals
        WHERE target_id=? AND sender_id<>? AND kind='ringing' AND created_at>=DATE_SUB(NOW(),INTERVAL 45 SECOND)
        ) s JOIN users u ON u.id=s.sender_id JOIN video_call_rooms r ON r.room_key=s.room_id
        ORDER BY s.created_at DESC LIMIT 20");
    $st->execute([$me,$me,$me,$me]);
    $items=[];
    foreach ($st->fetchAll() as $row) {
        $room=video_call_room_by_key($pdo,(string)$row['room_id']);
        if (!$room || !video_call_user_can_access_room($pdo,$user,$room)) continue;
        if (mana_call_room_mode($pdo,$room)!==$row['engine']) continue;
        $p=json_decode((string)$row['payload'],true) ?: [];
        $items[]=['id'=>($row['engine']==='local'?'local-':'').$row['id'],'room'=>$row['room_id'],'sender_name'=>$row['sender_name'],
            'media'=>($p['media']??'')==='audio'?'audio':'video','group'=>$row['kind']!=='direct','engine'=>$row['engine']];
    }
    video_call_json(['ok'=>true,'items'=>$items]);
}
$key=trim((string)($body['room']??''));
$room=$key!==''?video_call_room_by_key($pdo,$key):null;
if (!$room || !video_call_user_can_access_room($pdo,$user,$room)) video_call_json(['ok'=>false,'error'=>'این جلسه در دسترس شما نیست.'],403);
if ($action==='ack_ring') {
    $pdo->prepare("DELETE FROM mana_local_signals WHERE room_id=? AND target_id=? AND kind='ringing'")->execute([$key,$me]);
    video_call_json(['ok'=>true]);
}
if ($action==='poll') {
    $after=max(0,(int)($body['after']??0));
    $st=$pdo->prepare("SELECT seq,id,sender_id,target_id,kind,payload FROM mana_local_signals
        WHERE room_id=? AND sender_id<>? AND seq>? AND created_at>=DATE_SUB(NOW(),INTERVAL 90 SECOND)
        AND (target_id IS NULL OR target_id=?) ORDER BY seq ASC LIMIT 120");
    $st->execute([$key,$me,$after,$me]);
    $signals=[];$cursor=$after;
    foreach ($st->fetchAll() as $row) {
        $cursor=max($cursor,(int)$row['seq']);
        $signals[]=['id'=>$row['id'],'from'=>$row['sender_id'],'to'=>$row['target_id'],'kind'=>$row['kind'],
            'payload'=>json_decode((string)($row['payload']??''),true)];
    }
    video_call_json(['ok'=>true,'members'=>video_call_room_members_public($pdo,$room),'signals'=>$signals,'cursor'=>$cursor]);
}
$kind=(string)($body['kind']??'');
if (!in_array($kind,['offer','answer','ice','hangup','ringing','join','leave'],true)) video_call_json(['ok'=>false,'error'=>'پیام نامعتبر است.'],400);
if ($kind==='ringing' && !video_call_is_clinician($user)) video_call_json(['ok'=>false,'error'=>'فقط درمانگر تماس را شروع می‌کند.'],403);
if (in_array($kind,['ringing','offer'],true) && !mana_local_call_start_allowed($pdo,$user,$room)) {
    video_call_json(['ok'=>false,'error'=>'این مراجع در فهرست فعلی شما نیست.'],403);
}
$to=trim((string)($body['target_id']??''));
if ($to!=='') {
    $ids=array_column(video_call_room_members_public($pdo,$room),'id');
    if ($to===$me || !in_array($to,$ids,true)) video_call_json(['ok'=>false,'error'=>'مخاطب عضو جلسه نیست.'],403);
}
$payload=$body['payload']??null;
$encoded=$payload===null?null:json_encode($payload,JSON_UNESCAPED_UNICODE);
if ($encoded===false || strlen((string)$encoded)>65536) video_call_json(['ok'=>false,'error'=>'پیام بیش از حد بزرگ است.'],422);
if ($kind==='ringing') {
    $pdo->prepare("DELETE FROM mana_local_signals WHERE room_id=? AND target_id=? AND kind='ringing'")->execute([$key,$to]);
    // Remove termination messages from an older attempt before a fresh ring.
    $pdo->prepare("DELETE FROM mana_local_signals WHERE room_id=? AND kind IN ('hangup','leave')")->execute([$key]);
}
$pdo->prepare('INSERT INTO mana_local_signals (id,room_id,sender_id,target_id,kind,payload) VALUES (?,?,?,?,?,?)')
    ->execute([cuid(),$key,$me,$to!==''?$to:null,$kind,$encoded]);
$pdo->exec('DELETE FROM mana_local_signals WHERE created_at<DATE_SUB(NOW(),INTERVAL 5 MINUTE) LIMIT 200');
video_call_json(['ok'=>true]);
