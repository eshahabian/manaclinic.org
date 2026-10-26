<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/video_call.php';
require_once __DIR__.'/../includes/mana_local_call.php';
$user=require_login();
if (!video_call_allowed($user)) { http_response_code(403); exit('اجازه تماس ندارید.'); }
ensure_video_call_schema($pdo);
$room=mana_call_resolve_room($pdo,$user);
if (!$room || !video_call_user_can_access_room($pdo,$user,$room)) { http_response_code(403); exit('این جلسه در دسترس شما نیست.'); }
if ((!empty($_GET['peer']) || !empty($_GET['workshop'])) && video_call_is_clinician($user)) mana_call_bind_room($pdo,$room);
$target=mana_call_entry_url($pdo,$room,(string)($_GET['media']??'video'),!empty($_GET['answer']));
if (!empty($_GET['start'])) $target.='&start=1';
redirect($target);
