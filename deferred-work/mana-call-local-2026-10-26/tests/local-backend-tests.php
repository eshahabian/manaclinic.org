<?php
declare(strict_types=1);
$count=0;
function check($yes,$label) { global $count; if(!$yes) throw new RuntimeException($label);$count++; }
function rejects($fn,$label) { try{$fn();}catch(RuntimeException $e){check(true,$label);return;}check(false,$label); }
class LocalDb extends PDO {
    public string $mode='livekit';public array $routes=[];public bool $fail=false;
    function __construct(){}
    function exec(string $statement):int|false{return 0;}
    function query(string $query,?int $fetchMode=null,mixed ...$args):PDOStatement|false{return new LocalStatement($this,$query);}
    function prepare(string $query,array $options=[]):PDOStatement|false{return new LocalStatement($this,$query);}
}
class LocalStatement extends PDOStatement {
    public array $params=[];
    function __construct(private LocalDb $db,private string $query){}
    function execute(?array $params=null):bool{
        $this->params=$params??[];
        if(str_starts_with($this->query,'UPDATE mana_call_routing SET mode')){
            if($this->db->fail)throw new RuntimeException('write failed');$this->db->mode=$params[0];
        }
        if(str_starts_with($this->query,'INSERT INTO mana_call_room_routes'))$this->db->routes[$params[0]]=$params[1];
        return true;
    }
    function fetchColumn(int $column=0):mixed {
        if(str_contains($this->query,'schema_version'))return 1;
        if(str_contains($this->query,'room_routes'))return $this->db->routes[$this->params[0]]??false;
        return $this->db->mode;
    }
}
$config=['app_url'=>'https://clinic.example'];
putenv('MANA_LOCAL_TURN_URLS=turn:turn.clinic.example:3478?transport=udp,turns:turn.clinic.example:5349?transport=tcp');
putenv('MANA_LOCAL_TURN_SECRET=0123456789abcdef0123456789abcdef');
require dirname(__DIR__,2).'/cpanel/includes/mana_local_call.php';
require dirname(__DIR__,2).'/cpanel/includes/livekit.php';
$before=mana_livekit_config();
$pdo=new LocalDb();$room=['id'=>'stable-id','room_key'=>'dm-a-b'];$new=['id'=>'new-id','room_key'=>'dm-a-c'];
check(mana_call_mode($pdo)==='livekit','default keeps LiveKit');
mana_call_bind_room($pdo,$room);
check(mana_call_room_mode($pdo,$room)==='livekit','initial room binding');
mana_call_set_mode($pdo,'local');
check(mana_call_mode($pdo)==='local','select independent local mode');
check(mana_call_room_mode($pdo,$room)==='livekit','mode switch preserves existing session routing');
check(str_starts_with(mana_call_entry_url($pdo,$room),'/video-call-live?'),'existing invitation uses existing engine');
check(mana_call_room_mode($pdo,$new)==='local','new room follows local default');
mana_call_bind_room($pdo,$room);
check(mana_call_room_mode($pdo,$room)==='local','explicit new call rebinds the room');
check(str_starts_with(mana_call_entry_url($pdo,$room,'audio',true),'/video-call-local?'),'local URL');
check(str_contains(mana_call_entry_url($pdo,$room,'audio',true),'media=audio&answer=1'),'answer/media preserved');
mana_call_set_mode($pdo,'livekit');
check(mana_call_room_mode($pdo,$room)==='local','ongoing local room preserved on switch back');
mana_call_bind_room($pdo,$room);
check(mana_call_room_mode($pdo,$room)==='livekit','next call returns to original engine');
check(mana_livekit_config()===$before,'LiveKit settings unaffected by local mode');
$ice=mana_local_call_ice(['id'=>'patient']);
check(count($ice)===1 && count($ice[0]['urls'])===2,'only own relay URLs issued');
check(hash_equals(base64_encode(hash_hmac('sha1',$ice[0]['username'],'0123456789abcdef0123456789abcdef',true)),$ice[0]['credential']),'coturn REST HMAC');
check((int)explode(':',$ice[0]['username'])[0]>=time()+7199,'short-lived credentials');
check(!str_contains(json_encode($ice),'0123456789abcdef0123456789abcdef'),'master secret never sent');
check(!str_contains($ice[0]['username'],'patient'),'user identifier hashed');
rejects(fn()=>mana_local_call_ice(['id'=>'']),'anonymous relay issuance rejected');
foreach(['stun:stun.l.google.com:19302','turn:other.example:3478','turn:clinic.example.evil:3478',
    'turn:turn.clinic.example:0','turn:turn.clinic.example:99999','https://turn.clinic.example'] as $url){
    putenv('MANA_LOCAL_TURN_URLS='.$url);rejects(fn()=>mana_local_call_config(),'invalid or foreign URL rejected');
}
putenv('MANA_LOCAL_TURN_URLS=turn:turn.clinic.example:3478?transport=udp');
putenv('MANA_LOCAL_TURN_SECRET=short');
rejects(fn()=>mana_call_set_mode($pdo,'local'),'missing setup cannot activate');
check($pdo->mode==='livekit','failed activation preserves existing method');
rejects(fn()=>mana_call_set_mode($pdo,'bad'),'invalid mode rejected');
$pdo->fail=true;rejects(fn()=>mana_call_set_mode($pdo,'livekit'),'write failure surfaced');
check($pdo->mode==='livekit','write failure preserves method');
echo "PASS $count local-backend assertions (database double; original LiveKit configuration unchanged)\n";
