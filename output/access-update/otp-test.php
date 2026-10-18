<?php
date_default_timezone_set('Asia/Tehran');
function mb_strlen($s){preg_match_all('/./us',$s,$m);return count($m[0]);}
function mb_substr($s,$start,$length=null){preg_match_all('/./us',$s,$m);return implode('',array_slice($m[0],$start,$length));}
$settings=[];
function mail_setting_get($pdo,$key,$fallback=''){return $GLOBALS['settings'][$key]??$fallback;}
require '/app/helpers.php';require '/app/outreach.php';require '/app/login_otp.php';
class SmsTestDatabase extends PDO{
 public array $limits=[],$challenges=[],$users=[];public array $states=[],$jobs=[],$sessions=[],$appointment=[],$enrollment=[];public bool $tx=false;
 public function __construct(){}
 public function inTransaction():bool{return $this->tx;}
 public function beginTransaction():bool{$this->tx=true;return true;}
 public function commit():bool{$this->tx=false;return true;}
 public function rollBack():bool{$this->tx=false;return true;}
 public function exec(string $sql):int|false{return 0;}
 public function prepare(string $sql,array $options=[]):PDOStatement|false{return new SmsTestStatement($this,$sql);}
 public function query(string $sql,?int $mode=null,mixed ...$args):PDOStatement|false{$s=new SmsTestStatement($this,$sql);$s->execute();return $s;}
}
class SmsTestStatement extends PDOStatement{
 private array $rows=[];private int $affected=0;
 public function __construct(private SmsTestDatabase $db,private string $sql){}
 public function execute(?array $p=null):bool{
  $p=$p??[];$this->rows=[];$this->affected=0;
  if(str_starts_with($this->sql,'INSERT IGNORE INTO login_otp_limits'))$this->db->limits[$p[0]]??=['bucket'=>$p[0],'started_at'=>$p[1],'hits'=>0];
  elseif(str_starts_with($this->sql,'SELECT * FROM login_otp_limits'))$this->rows=[$this->db->limits[$p[0]]];
  elseif(str_starts_with($this->sql,'UPDATE login_otp_limits'))$this->db->limits[$p[2]]=['bucket'=>$p[2],'started_at'=>$p[0],'hits'=>$p[1]];
  elseif(str_starts_with($this->sql,'SELECT * FROM login_otp WHERE'))$this->rows=isset($this->db->challenges[$p[0]])?[$this->db->challenges[$p[0]]]:[];
  elseif(str_starts_with($this->sql,'UPDATE login_otp SET attempts')){$this->db->challenges[$p[1]]['attempts']++;$this->db->challenges[$p[1]]['used']=$p[0];}
  elseif(str_starts_with($this->sql,'SELECT * FROM users WHERE id'))$this->rows=isset($this->db->users[$p[0]])?[$this->db->users[$p[0]]]:[];
  elseif(str_starts_with($this->sql,'SHOW '))$this->rows=[['exists'=>1]];
  elseif(str_starts_with($this->sql,'SELECT * FROM sms_entity_state'))$this->rows=isset($this->db->states[$p[0]])?[$this->db->states[$p[0]]]:[];
  elseif(str_starts_with($this->sql,'INSERT INTO sms_entity_state'))$this->db->states[$p[0]]=['fingerprint'=>$p[1],'last_status'=>$p[2]];
  elseif(str_starts_with($this->sql,'INSERT IGNORE INTO sms_outbox')){
   $key=$p[4]??$p[0];if(!isset($this->db->jobs[$key])){$this->db->jobs[$key]=['phone'=>$p[1],'body'=>$p[2],'kind'=>$p[3],'due_at'=>$p[5],'expires_at'=>$p[6],'context_json'=>$p[7]];$this->affected=1;}
  }
  elseif(str_starts_with($this->sql,'SELECT id,session_date'))$this->rows=$this->db->sessions;
  elseif(str_starts_with($this->sql,'SELECT starts_at,status,doctor_id'))$this->rows=$this->db->appointment?[$this->db->appointment]:[];
  elseif(str_starts_with($this->sql,'SELECT e.status,w.status'))$this->rows=$this->db->enrollment?[$this->db->enrollment]:[];
  elseif(str_starts_with($this->sql,'SELECT session_date')){foreach($this->db->sessions as $s)if($s['id']===$p[0])$this->rows=[['session_date'=>$s['session_date']]];}
  elseif(str_starts_with($this->sql,'SELECT phone FROM users'))$this->rows=[['phone'=>'09121234567'],['phone'=>'+989121234567'],['phone'=>'+971501234567']];
  return true;
 }
 public function fetch(int $mode=PDO::FETCH_DEFAULT,int $orientation=PDO::FETCH_ORI_NEXT,int $offset=0):mixed{return $this->rows[0]??false;}
 public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args):array{return $this->rows;}
 public function fetchColumn(int $column=0):mixed{return isset($this->rows[0])?array_values($this->rows[0])[$column]:false;}
 public function rowCount():int{return $this->affected;}
}
$checks=0;function check_otp($ok,$message){global $checks;if(!$ok)throw new RuntimeException($message);$checks++;}
$pdo=new SmsTestDatabase();$id=str_repeat('a',64);$pdo->users['u']=['id'=>'u','role'=>'PATIENT'];
$fresh=['id'=>$id,'user_id'=>'u','code_hash'=>password_hash('123456',PASSWORD_DEFAULT),'expires_at'=>date('Y-m-d H:i:s',time()+180),'attempts'=>0,'used'=>0];
$pdo->challenges[$id]=$fresh;
check_otp(login_otp_verify($pdo,$id,'123456')['id']==='u','Correct code accepted');
check_otp(login_otp_verify($pdo,$id,'123456')===null,'Replay refused');
$pdo->challenges[$id]=$fresh;$pdo->challenges[$id]['expires_at']=date('Y-m-d H:i:s',time()-1);
check_otp(login_otp_verify($pdo,$id,'123456')===null,'Expired code refused');
$pdo->challenges[$id]=$fresh;for($i=0;$i<5;$i++)check_otp(login_otp_verify($pdo,$id,'000000')===null,'Wrong code refused');
check_otp(login_otp_verify($pdo,$id,'123456')===null,'Correct code refused after five failures');
$pdo->challenges[$id]=$fresh;check_otp(login_otp_verify($pdo,$id,'123456x')===null,'Malformed code refused');
check_otp(login_otp_verify($pdo,str_repeat('b',64),'123456')===null,'Other session challenge refused');
for($i=0;$i<3;$i++)check_otp(login_otp_limit($pdo,'phone:test',3,600),'Three requests allowed');
check_otp(!login_otp_limit($pdo,'phone:test',3,600),'Fourth request refused across sessions');
$key=hash('sha256','phone:test');$pdo->limits[$key]['started_at']=time()-601;
check_otp(login_otp_limit($pdo,'phone:test',3,600),'Expired rate window resets');
echo 'PASS '.$checks.' OTP expiry, replay, guessing and request limit checks';