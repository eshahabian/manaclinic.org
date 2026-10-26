<?php
declare(strict_types=1);
$scenario=$argv[1] ?? '';
$_SESSION=['_csrf'=>'expected'];
$_SERVER['HTTP_ACCEPT']='application/json';
$_SERVER['REQUEST_METHOD']='POST';
$_SERVER['HTTP_X_CSRF_TOKEN']=str_ends_with($scenario,'bad-csrf')?'wrong':(str_ends_with($scenario,'missing-csrf')?'':'expected');
function require_login(?array $roles=null): array {
    if ($GLOBALS['scenario']==='local-schedule-denied') return ['id'=>'patient','role'=>'PATIENT'];
    if ($GLOBALS['scenario']==='local-outsider') return ['id'=>'outsider','role'=>'DOCTOR'];
    if (in_array($GLOBALS['scenario'],['local-target-outsider','local-unassigned'],true)) return ['id'=>'doctor','role'=>'DOCTOR'];
    return ['id'=>'user','role'=>str_ends_with($GLOBALS['scenario'],'forbidden-role')?'SECRETARY':'DOCTOR'];
}
class DenialDb extends PDO {
    function __construct(){}
    function exec(string $sql):int|false{return 0;}
    function prepare(string $sql,array $options=[]):PDOStatement|false{return new DenialStatement($sql);}
    function query(string $sql,?int $mode=null,mixed ...$args):PDOStatement|false{return new DenialStatement($sql);}
}
class DenialStatement extends PDOStatement {
    function __construct(private string $sql){}
    function execute(?array $params=null):bool{return true;}
    function fetchColumn(int $column=0):mixed{return 1;}
    function fetch(int $mode=PDO::FETCH_DEFAULT,int $orientation=PDO::FETCH_ORI_NEXT,int $offset=0):mixed{
        if(str_contains($this->sql,'FROM video_call_rooms'))return $GLOBALS['scenario']==='local-missing-room'?false:
            ['id'=>'room-id','room_key'=>'dm-doctor-patient','kind'=>'direct','host_user_id'=>'doctor'];
        if(str_contains($this->sql,'FROM users WHERE id='))return ['id'=>'doctor','role'=>'DOCTOR','username'=>'ordinary','name'=>'Regular'];
        return false;
    }
    function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args):array{
        if($GLOBALS['scenario']==='local-unassigned' && str_contains($this->sql,'preferred_doctor_id'))return [];
        return str_contains($this->sql,'FROM users u')?[['id'=>'doctor','name'=>'Regular','role'=>'DOCTOR'],['id'=>'patient','name'=>'Patient','role'=>'PATIENT']]:[];
    }
}
register_shutdown_function(static function(){fwrite(STDERR,'HTTP '.http_response_code());});
require dirname(__DIR__,2).'/cpanel/includes/helpers.php';
if ($scenario==='settings-denied') {
    require dirname(__DIR__,2).'/cpanel/includes/staff_app.php';
    $user=['username'=>'other-admin','role'=>'ADMIN'];
    require dirname(__DIR__,2).'/cpanel/pages/staff_mana_call_settings.php';
} elseif (str_starts_with($scenario,'local-')) {
    if(in_array($scenario,['local-missing-room','local-outsider','local-schedule-denied','local-target-outsider','local-unassigned'],true)){
        $pdo=new DenialDb();$_POST=['action'=>'poll','room'=>'dm-doctor-patient'];
        if($scenario==='local-target-outsider')$_POST=['action'=>'send','room'=>'dm-doctor-patient','kind'=>'offer','target_id'=>'outsider'];
        if($scenario==='local-unassigned')$_POST=['action'=>'send','room'=>'dm-doctor-patient','kind'=>'offer','target_id'=>'patient'];
    }
    require dirname(__DIR__,2).'/cpanel/actions/video_signal_local.php';
} else {
    require dirname(__DIR__,2).'/cpanel/actions/livekit_token.php';
}
